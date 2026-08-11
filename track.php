<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/onsite-request.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/request-items.php';
require_once __DIR__ . '/includes/clearance.php';
require_once __DIR__ . '/includes/compliance.php';

$ref = strtoupper(trim($_GET['ref'] ?? $_POST['ref'] ?? ''));
$code = trim($_GET['code'] ?? $_POST['code'] ?? '');
$studentId = trim($_GET['student_id'] ?? $_POST['student_id'] ?? '');
$searched = $ref !== '' || $code !== '';
$result = null;
$error = null;

if ($searched) {
    $digits = preg_replace('/\D+/', '', $code) ?? '';
    if ($code !== '' && !preg_match('/^\d{6}$/', $digits)) {
        $error = 'Enter a valid 6-digit cashier payment code, or leave it blank and use your request number.';
    } elseif ($ref === '' && $digits === '') {
        $error = 'Enter your request number or payment code from the onsite request slip.';
    } else {
        $result = lookupPublicOnsiteTracking($ref, $digits, $studentId !== '' ? $studentId : null);
        if (!$result) {
            $error = 'No onsite request found. Check your request number, payment code, and student ID, then try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Onsite Request - <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <?php
    require_once __DIR__ . '/includes/theme.php';
    renderThemeStyleTag();
    ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="landing-page">
<?php renderLandingNav('track'); ?>

<div class="container page-container onsite-track-page">
    <div class="onsite-track-intro">
        <h1><i class="fas fa-search-location"></i> Track Onsite Request</h1>
        <p>
            Check the status of your walk-in credential request and online clearance using the
            <strong>Request Number</strong> and/or <strong>6-digit Payment Code</strong> on your onsite request slip.
        </p>
    </div>

    <div class="card onsite-track-form-card">
        <div class="card-body">
            <form method="GET" class="onsite-track-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="ref">Request Number</label>
                        <input type="text" id="ref" name="ref" value="<?= e($ref) ?>"
                            placeholder="REQ-2026-XXXXXX" autocomplete="off" style="text-transform:uppercase">
                    </div>
                    <div class="form-group">
                        <label for="code">Payment Code</label>
                        <input type="text" id="code" name="code" value="<?= e($code) ?>"
                            placeholder="6-digit code" maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="student_id">Student / Requestor ID <span class="text-muted">(optional)</span></label>
                        <input type="text" id="student_id" name="student_id" value="<?= e($studentId) ?>"
                            placeholder="As printed on your slip" autocomplete="off">
                    </div>
                </div>
                <p class="text-muted onsite-track-form-hint">
                    Provide at least a request number or payment code. Student ID helps confirm the correct record.
                </p>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Track Request</button>
            </form>
        </div>
    </div>

    <?php if ($searched && $error): ?>
        <div class="verify-result invalid onsite-track-result">
            <div class="verify-icon"><i class="fas fa-times-circle"></i></div>
            <h2>Request Not Found</h2>
            <p><?= e($error) ?></p>
        </div>
    <?php elseif ($result): ?>
        <?php
        $request = $result['request'];
        $items = $result['items'];
        $payment = $result['payment'];
        $clearanceRequired = !empty($result['clearance_required']);
        $progress = $result['clearance_progress'];
        $requestId = (int) $request['id'];
        $studentName = trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
        $clearanceComplete = $clearanceRequired
            && (int) ($progress['total'] ?? 0) > 0
            && (int) ($progress['cleared'] ?? 0) >= (int) ($progress['total'] ?? 0);
        ?>
        <div class="onsite-track-result-panel">
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2>Request <?= e($request['request_number']) ?></h2>
                        <p class="text-muted" style="margin:.35rem 0 0">
                            Onsite walk-in · Submitted <?= e(formatDateTime($request['created_at'] ?? null)) ?>
                        </p>
                    </div>
                    <?= statusBadge((string) ($request['status'] ?? '')) ?>
                </div>
                <div class="card-body">
                    <div class="onsite-track-progress">
                        <?= renderStudentProgressMini((string) ($request['status'] ?? ''), $requestId) ?>
                        <?php if (($request['status'] ?? '') !== 'rejected'): ?>
                            <?= renderWorkflowTracker((string) ($request['status'] ?? '')) ?>
                        <?php endif; ?>
                        <p class="onsite-track-status-desc text-muted">
                            <?= e(studentProgressDescription((string) ($request['status'] ?? ''), $requestId)) ?>
                        </p>
                    </div>

                    <div class="onsite-track-next">
                        <i class="fas fa-arrow-right"></i>
                        <div>
                            <strong>What to do next</strong>
                            <p><?= e($result['next_hint']) ?></p>
                        </div>
                    </div>

                    <div class="detail-grid onsite-track-meta">
                        <div class="detail-item"><label>Requestor</label><span><?= e($studentName) ?></span></div>
                        <div class="detail-item"><label>Student / Requestor ID</label><span><?= e($request['student_id'] ?? '—') ?></span></div>
                        <div class="detail-item"><label>Course / Program</label><span><?= e($request['course'] ?? '—') ?></span></div>
                        <div class="detail-item"><label>Enrollment</label><span><?= e(enrollmentStatusLabel($request['enrollment_status'] ?? null)) ?></span></div>
                        <div class="detail-item"><label>Purpose</label><span><?= e(purposeLabel((string) ($request['purpose'] ?? ''))) ?></span></div>
                        <div class="detail-item"><label>Total Amount</label><span><strong><?= e(formatMoney((float) ($request['total_amount'] ?? 0))) ?></strong></span></div>
                    </div>
                </div>
            </div>

            <div class="grid-2 onsite-track-grid">
                <div class="card">
                    <div class="card-header"><h2><i class="fas fa-layer-group"></i> Documents</h2></div>
                    <div class="card-body">
                        <?php if (empty($items)): ?>
                            <p class="text-muted">No document items found for this request.</p>
                        <?php else: ?>
                            <div class="request-items-summary-list">
                                <?php foreach ($items as $item): ?>
                                    <div class="request-item-summary-row">
                                        <strong><?= e($item['document_name'] ?? 'Document') ?></strong>
                                        <span><?= (int) ($item['copies'] ?? 1) ?> cop<?= (int) ($item['copies'] ?? 1) === 1 ? 'y' : 'ies' ?></span>
                                        <?= requestItemStatusBadge($item['item_status'] ?? 'pending_assignment') ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h2><i class="fas fa-receipt"></i> Payment</h2></div>
                    <div class="card-body">
                        <?php if (!$payment): ?>
                            <p class="text-muted">No payment record found.</p>
                        <?php else: ?>
                            <div class="detail-grid">
                                <div class="detail-item"><label>Method</label><span><?= e(paymentMethodLabel($payment['payment_method'] ?? null)) ?></span></div>
                                <div class="detail-item"><label>Status</label><span><?= statusBadge((string) ($payment['status'] ?? '')) ?></span></div>
                                <div class="detail-item"><label>Amount</label><span><?= e(formatMoney((float) ($payment['amount'] ?? 0))) ?></span></div>
                                <div class="detail-item">
                                    <label><?= isOnsitePaymentMethod($payment['payment_method'] ?? null) ? 'Payment Code' : 'Reference' ?></label>
                                    <span><strong><?= e($payment['reference_number'] ?? '—') ?></strong></span>
                                </div>
                                <?php if (!empty($payment['or_number'])): ?>
                                    <div class="detail-item"><label>OR #</label><span><?= e($payment['or_number']) ?></span></div>
                                <?php endif; ?>
                                <?php if (!empty($payment['payment_date'])): ?>
                                    <div class="detail-item"><label>Payment Date</label><span><?= e(formatDate($payment['payment_date'])) ?></span></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($clearanceRequired): ?>
                <div class="card onsite-track-clearance-card">
                    <div class="card-header">
                        <div>
                            <h2><i class="fas fa-stamp"></i> Online Clearance Status</h2>
                            <p class="text-muted" style="margin:.35rem 0 0">
                                <?= $clearanceComplete
                                    ? 'All offices have cleared this request. You may proceed to the Cashier for payment.'
                                    : 'Clearance must be completed before the Cashier can verify payment.' ?>
                            </p>
                        </div>
                        <?php if ($clearanceComplete): ?>
                            <span class="badge badge-completed">Complete</span>
                        <?php else: ?>
                            <span class="badge badge-processing">
                                <?= (int) ($progress['cleared'] ?? 0) ?>/<?= (int) ($progress['total'] ?? 0) ?> cleared
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?= renderClearanceGrid($requestId, false) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php renderPublicPageFooter(); ?>
</body>
</html>
