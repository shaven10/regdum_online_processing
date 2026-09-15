<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/theme.php';

ensureQueueSchema();

$settings = getQueueSettings();
$error = null;
$issued = null;
$issuedId = (int) ($_GET['issued'] ?? 0);
if ($issuedId > 0) {
    $issued = findQueueTicket($issuedId);
    if (!$issued || $issued['queue_date'] !== queueToday()) {
        $issued = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $now = time();
    $lastIssue = (int) ($_SESSION['queue_last_issue_at'] ?? 0);
    if ($lastIssue > 0 && ($now - $lastIssue) < 8) {
        $error = 'Please wait a moment before getting another number.';
    } else {
        $result = issueQueueTicket($_POST);
        if (!empty($result['ticket']['id'])) {
            if (!empty($result['ok'])) {
                $_SESSION['queue_last_issue_at'] = $now;
                auditLog('queue_issue_ticket', 'queue_tickets', (int) $result['ticket']['id'], null, [
                    'ticket_code' => $result['ticket']['ticket_code'] ?? null,
                    'service_type' => $result['ticket']['service_type'] ?? null,
                ]);
            }
            redirect(queueKioskUrl() . '?issued=' . (int) $result['ticket']['id'] . (empty($result['ok']) ? '&exists=1' : ''));
        }
        $error = (string) ($result['error'] ?? 'Unable to issue a priority number.');
    }
}

$waitingCount = waitingQueueCount();
$formName = trim((string) ($_POST['requestor_name'] ?? ''));
$formStudent = trim((string) ($_POST['student_id'] ?? ''));
$formRequest = strtoupper(trim((string) ($_POST['request_number'] ?? '')));
$formService = (string) ($_POST['service_type'] ?? 'document_processing');
if (!array_key_exists($formService, queueServiceTypes())) {
    $formService = 'document_processing';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Get Priority Number - <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <?php renderThemeStyleTag(); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="queue-kiosk-page">
    <header class="queue-kiosk-top">
        <?= renderAppLogo('nav') ?>
        <div>
            <p class="queue-kiosk-office"><?= e(APP_NAME) ?></p>
            <p class="queue-kiosk-tagline"><?= e(APP_TAGLINE) ?></p>
        </div>
    </header>

    <main class="queue-kiosk-main">
        <?php if ($issued): ?>
            <section class="queue-ticket-card" id="queueTicketCard">
                <?php if (!empty($_GET['exists'])): ?>
                    <p class="queue-ticket-kicker">You already have a number today</p>
                <?php else: ?>
                    <p class="queue-ticket-kicker">Your priority number</p>
                <?php endif; ?>
                <p class="queue-ticket-code"><?= e((string) $issued['ticket_code']) ?></p>
                <p class="queue-ticket-meta"><?= e((string) $issued['service_label']) ?></p>
                <?php if (!empty($issued['requestor_name'])): ?>
                    <p class="queue-ticket-meta"><?= e((string) $issued['requestor_name']) ?></p>
                <?php endif; ?>
                <p class="queue-ticket-wait">
                    Please wait until this number is called on the display board, then proceed to the assigned window.
                </p>
                <p class="queue-ticket-date"><?= e(date('F j, Y g:i A', strtotime((string) $issued['created_at']))) ?></p>
                <div class="queue-ticket-actions no-print">
                    <button type="button" class="btn btn-primary btn-lg" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Number
                    </button>
                    <a href="<?= e(queueKioskUrl()) ?>" class="btn btn-outline btn-lg">Get another number</a>
                </div>
            </section>
        <?php else: ?>
            <section class="queue-kiosk-panel">
                <h1>Get a Priority Number</h1>
                <p>Take a number for onsite document processing at the Registrar's Office. Watch the display board for your turn.</p>

                <?php if (!$settings['enabled']): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-clock"></i>
                        Queuing is closed. Please wait for the office to open the queue.
                    </div>
                <?php else: ?>
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?= e($error) ?></div>
                    <?php endif; ?>

                    <p class="queue-kiosk-waiting">
                        <?= $waitingCount === 0 ? 'No one is waiting right now.' : ($waitingCount . ' requestor' . ($waitingCount === 1 ? '' : 's') . ' waiting') ?>
                    </p>

                    <form method="POST" class="queue-kiosk-form" id="queueKioskForm">
                        <?= csrfField() ?>
                        <div class="form-group">
                            <label for="service_type">Transaction</label>
                            <select id="service_type" name="service_type" required>
                                <?php foreach (queueServiceTypes() as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= $formService === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="requestor_name">Full name <?= $settings['require_name'] ? '' : '<span class="text-muted">(optional)</span>' ?></label>
                            <input type="text" id="requestor_name" name="requestor_name" value="<?= e($formName) ?>"
                                   <?= $settings['require_name'] ? 'required' : '' ?> autocomplete="name" placeholder="Juan Dela Cruz">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="student_id">Student ID <span class="text-muted">(optional)</span></label>
                                <input type="text" id="student_id" name="student_id" value="<?= e($formStudent) ?>"
                                       autocomplete="off" placeholder="2024-00001">
                            </div>
                            <div class="form-group">
                                <label for="request_number">Request number <span class="text-muted">(optional)</span></label>
                                <input type="text" id="request_number" name="request_number" value="<?= e($formRequest) ?>"
                                       autocomplete="off" placeholder="REQ-2026-XXXXXX" style="text-transform:uppercase">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg queue-kiosk-submit">
                            <i class="fas fa-ticket-alt"></i> Get Priority Number
                        </button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>

    <p class="queue-kiosk-foot no-print">
        <a href="<?= APP_URL ?>/track.php">Track onsite request</a>
        ·
        <a href="<?= APP_URL ?>/index.php">Home</a>
    </p>
    <script>
    document.getElementById('queueKioskForm')?.addEventListener('submit', function () {
        var btn = this.querySelector('.queue-kiosk-submit');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Issuing number...';
        }
    });
    </script>
</body>
</html>
