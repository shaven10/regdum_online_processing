<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/queue.php';
requireRole('registrar', 'admin');

ensureQueueSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && (string) ($_POST['action'] ?? '') === 'save_announcement') {
    $previous = getQueueSettings()['announcement'];
    $errors = saveQueueAnnouncement((string) ($_POST['queue_announcement'] ?? ''));
    $saved = getQueueSettings()['announcement'];
    auditLog('update_queue_announcement', 'app_settings', null, ['announcement' => $previous], ['announcement' => $saved]);
    if ($errors !== []) {
        setFlash('error', $errors[0], ['title' => 'Announcement Shortened']);
    } elseif ($saved === '') {
        setFlash('success', 'The moving announcement was cleared from the public monitor.', ['title' => 'Announcement Cleared']);
    } else {
        setFlash('success', 'The moving announcement is now on the public monitor.', ['title' => 'Announcement Updated']);
    }
    redirect(queueMonitorUrl());
}

$display = getQueueDisplayState();
$myWindow = getQueueWindowForUser((int) (currentUser()['id'] ?? 0));

$pageTitle = 'Queue Monitor';
$activeNav = 'queue-monitor';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card" data-queue-monitor data-queue-status-url="<?= e(queueStatusUrl()) ?>" data-queue-admin="<?= hasRole('admin') ? '1' : '0' ?>" data-queue-window-url="<?= e(queueWindowUrl()) ?>">
    <div class="card-header">
        <div>
            <h2><i class="fas fa-desktop"></i> Onsite Queue Monitor</h2>
            <p class="text-muted" style="margin:.35rem 0 0">
                Live view of registrar windows and waiting priority numbers for <?= e($display['date_label']) ?>.
            </p>
        </div>
        <div class="card-header-actions">
            <?php if ($myWindow): ?>
                <a href="<?= e(queueWindowUrl()) ?>" class="btn btn-primary btn-sm">
                    <i class="fas fa-store"></i> My Window
                </a>
            <?php endif; ?>
            <a href="<?= e(queueKioskUrl()) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-ticket-alt"></i> Get Number
            </a>
            <a href="<?= e(queueDisplayUrl()) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-tv"></i> Display Board
            </a>
            <?php if (hasRole('admin')): ?>
                <a href="<?= APP_URL ?>/admin/queue-settings.php" class="btn btn-outline btn-sm">
                    <i class="fas fa-cog"></i> Settings
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$display['enabled']): ?>
            <div class="alert alert-warning">Queuing is currently closed in admin settings.</div>
        <?php endif; ?>

        <div class="queue-monitor-windows" data-queue-windows>
            <?php foreach ($display['windows'] as $window): ?>
                <article class="queue-monitor-window<?= empty($window['ticket_code']) ? ' is-idle' : '' ?>">
                    <h3><?= e((string) $window['name']) ?></h3>
                    <p class="queue-monitor-now-label">Now serving</p>
                    <p class="queue-monitor-number"><?= e((string) ($window['ticket_code'] ?: '---')) ?></p>
                    <?php if (!empty($window['ticket_code'])): ?>
                        <p class="queue-monitor-requestor"><?= e(queueRequestorDisplayName($window['requestor_name'] ?? '')) ?></p>
                    <?php endif; ?>
                    <p class="text-muted"><?= e((string) ($window['staff'] !== '' ? $window['staff'] : 'Unassigned')) ?></p>
                    <?php if (hasRole('admin')): ?>
                        <a href="<?= e(queueWindowUrl() . '?window_id=' . (int) $window['id']) ?>" class="btn btn-sm btn-outline">Open window</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>

        <h3 class="queue-settings-subtitle">Waiting (<span data-queue-waiting-count><?= (int) $display['waiting_count'] ?></span>)</h3>
        <ol class="queue-monitor-waiting" data-queue-waiting>
            <?php if ($display['waiting'] === []): ?>
                <li class="is-empty">No one is waiting.</li>
            <?php else: ?>
                <?php foreach ($display['waiting'] as $ticket): ?>
                    <li>
                        <strong><?= e((string) $ticket['ticket_code']) ?></strong>
                        <?= e(queueRequestorDisplayName($ticket['requestor_name'] ?? '')) ?>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
            </ol>

        <form method="POST" class="queue-monitor-announcement">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_announcement">
            <h3 class="queue-settings-subtitle">Public monitor announcement</h3>
            <p class="text-muted">This message scrolls along the bottom of the public display. Leave it blank to hide it. Now on the board: <strong data-queue-announcement-live><?= e($display['announcement'] !== '' ? $display['announcement'] : 'No announcement') ?></strong></p>
            <div class="form-group">
                <label for="queue_announcement">Announcement text</label>
                <textarea id="queue_announcement" name="queue_announcement" maxlength="280" rows="2" placeholder="Example: Please wait for your number, then proceed to the window shown."><?= e($display['announcement']) ?></textarea>
                <small class="text-muted">Up to 280 characters. The public monitor updates within a few seconds.</small>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-bullhorn"></i> Update announcement
            </button>
        </form>
    </div>
</div>

<script src="<?= APP_URL ?>/assets/js/queue-chimes.js"></script>
<script src="<?= APP_URL ?>/assets/js/queue-display.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
