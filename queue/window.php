<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/queue.php';
requireRole('registrar', 'staff', 'admin');

ensureQueueSchema();

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);
$window = getQueueWindowForUser($userId);
$isAdmin = hasRole('admin');
$requestedWindow = (int) ($_POST['window_id'] ?? $_GET['window_id'] ?? 0);

if ($isAdmin && $requestedWindow > 0) {
    $adminWindow = getQueueWindow($requestedWindow);
    if ($adminWindow && !empty($adminWindow['is_active'])) {
        $window = $adminWindow;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && $window) {
    $action = (string) ($_POST['action'] ?? '');
    $windowId = (int) $window['id'];
    $result = ['ok' => false, 'error' => 'Unknown action.'];

    if ($action === 'call_next') {
        $result = callNextQueueTicket($windowId, $userId);
    } elseif ($action === 'call_ticket') {
        $result = serveQueueTicket((int) ($_POST['ticket_id'] ?? 0), $windowId, $userId);
    } elseif ($action === 'recall') {
        $result = recallQueueTicket($windowId, $userId);
    } elseif ($action === 'complete') {
        $result = completeQueueTicket($windowId, $userId);
    } elseif ($action === 'skip') {
        $result = skipQueueTicket($windowId, $userId);
    }

    if (!empty($result['ok'])) {
        $code = (string) ($result['ticket']['ticket_code'] ?? '');
        $messages = [
            'call_next' => 'Now serving ' . $code . '.',
            'call_ticket' => 'Now serving ' . $code . '.',
            'recall' => 'Recalled ' . $code . ' on the display board.',
            'complete' => $code . ' completed. Call the next number.',
            'skip' => $code . ' skipped. Call the next number.',
        ];
        setFlash('success', $messages[$action] ?? 'Queue updated.');
    } else {
        setFlash('error', (string) ($result['error'] ?? 'Unable to update the queue.'));
    }

    $redirect = queueWindowUrl();
    if ($isAdmin && (int) ($window['id'] ?? 0) > 0) {
        $redirect .= '?window_id=' . (int) $window['id'];
    }
    redirect($redirect);
}

$state = $window ? getQueueWindowConsoleState((int) $window['id']) : null;
$current = $state['current'] ?? null;
$waiting = $state['waiting'] ?? [];

$pageTitle = $window ? ((string) $window['name'] . ' Queue') : 'Queue Window';
$activeNav = 'queue-window';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$window): ?>
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-store"></i> Queue Window</h2>
    </div>
    <div class="card-body">
        <div class="empty-state">
            <i class="fas fa-user-lock"></i>
            <p>Your account is not assigned to a service window.</p>
            <p class="text-muted">Ask an administrator to bind your user account to a window in Queue Settings.</p>
            <?php if (hasRole('registrar', 'admin')): ?>
                <a href="<?= e(queueMonitorUrl()) ?>" class="btn btn-outline" style="margin-top:.75rem">Open queue monitor</a>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
                <a href="<?= APP_URL ?>/admin/queue-settings.php" class="btn btn-primary" style="margin-top:.75rem">Queue Settings</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php else: ?>

<div class="queue-window-layout">
    <div class="card queue-window-current-card">
        <div class="card-header">
            <div>
                <h2><i class="fas fa-store"></i> <?= e((string) $window['name']) ?></h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Signed in as <?= e(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?>.
                    Call the next priority number, then complete or skip when the requestor has been served.
                </p>
            </div>
            <div class="card-header-actions">
                <a href="<?= e(queueDisplayUrl()) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
                    <i class="fas fa-tv"></i> Display
                </a>
                <?php if (hasRole('registrar', 'admin')): ?>
                    <a href="<?= e(queueMonitorUrl()) ?>" class="btn btn-outline btn-sm">
                        <i class="fas fa-desktop"></i> All windows
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <p class="queue-window-now-label">Now serving</p>
            <p class="queue-window-now-number"><?= e((string) ($current['ticket_code'] ?? '---')) ?></p>
            <?php if ($current): ?>
                <div class="queue-window-current-meta">
                    <span><?= e((string) $current['service_label']) ?></span>
                    <?php if (!empty($current['requestor_name'])): ?>
                        <span><?= e((string) $current['requestor_name']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($current['student_id'])): ?>
                        <span>ID <?= e((string) $current['student_id']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($current['request_number'])): ?>
                        <span><?= e((string) $current['request_number']) ?></span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="text-muted">No requestor at this window. Call the next number when you are ready.</p>
            <?php endif; ?>

            <div class="queue-window-actions">
                <form method="POST">
                    <?= csrfField() ?>
                    <?php if ($isAdmin): ?>
                        <input type="hidden" name="window_id" value="<?= (int) $window['id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="action" value="call_next">
                    <button type="submit" class="btn btn-primary btn-lg" <?= $current ? 'disabled' : '' ?>>
                        <i class="fas fa-bullhorn"></i> Call Next
                    </button>
                </form>
                <form method="POST">
                    <?= csrfField() ?>
                    <?php if ($isAdmin): ?>
                        <input type="hidden" name="window_id" value="<?= (int) $window['id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="action" value="recall">
                    <button type="submit" class="btn btn-outline" <?= $current ? '' : 'disabled' ?>>
                        <i class="fas fa-redo"></i> Recall
                    </button>
                </form>
                <form method="POST">
                    <?= csrfField() ?>
                    <?php if ($isAdmin): ?>
                        <input type="hidden" name="window_id" value="<?= (int) $window['id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="action" value="complete">
                    <button type="submit" class="btn btn-primary" <?= $current ? '' : 'disabled' ?>>
                        <i class="fas fa-check"></i> Complete
                    </button>
                </form>
                <form method="POST">
                    <?= csrfField() ?>
                    <?php if ($isAdmin): ?>
                        <input type="hidden" name="window_id" value="<?= (int) $window['id'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="action" value="skip">
                    <button type="submit" class="btn btn-outline" <?= $current ? '' : 'disabled' ?>
                        onclick="return confirm('Skip this number if the requestor is not present?');">
                        <i class="fas fa-forward"></i> Skip / No-show
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Waiting queue</h2>
            <span class="badge badge-review"><?= (int) ($state['waiting_count'] ?? 0) ?></span>
        </div>
        <div class="card-body">
            <?php if ($waiting === []): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <p>No one is waiting.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <th>Number</th>
                                <th>Transaction</th>
                                <th>Requestor</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($waiting as $ticket): ?>
                                <tr>
                                    <td data-label="Number"><strong><?= e((string) $ticket['ticket_code']) ?></strong></td>
                                    <td data-label="Transaction"><?= e((string) $ticket['service_label']) ?></td>
                                    <td data-label="Requestor">
                                        <?= e((string) ($ticket['requestor_name'] ?: 'Walk-in')) ?>
                                        <?php if (!empty($ticket['student_id'])): ?>
                                            <br><small class="text-muted"><?= e((string) $ticket['student_id']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Action">
                                        <form method="POST">
                                            <?= csrfField() ?>
                                            <?php if ($isAdmin): ?>
                                                <input type="hidden" name="window_id" value="<?= (int) $window['id'] ?>">
                                            <?php endif; ?>
                                            <input type="hidden" name="action" value="call_ticket">
                                            <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline" <?= $current ? 'disabled' : '' ?>>
                                                Call
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <p class="text-muted" style="margin-top:1rem">This page refreshes with the waiting list. Complete the current number before calling the next.</p>
        </div>
    </div>
</div>

<meta http-equiv="refresh" content="20">
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
