<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/queue.php';
requireRole('registrar', 'admin');

ensureQueueSchema();

$display = getQueueDisplayState();
$myWindow = getQueueWindowForUser((int) (currentUser()['id'] ?? 0));

$pageTitle = 'Queue Monitor';
$activeNav = 'queue-monitor';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
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

        <div class="queue-monitor-windows">
            <?php foreach ($display['windows'] as $window): ?>
                <article class="queue-monitor-window<?= empty($window['ticket_code']) ? ' is-idle' : '' ?>">
                    <h3><?= e((string) $window['name']) ?></h3>
                    <p class="queue-monitor-number"><?= e((string) ($window['ticket_code'] ?: '---')) ?></p>
                    <p class="text-muted"><?= e((string) ($window['staff'] !== '' ? $window['staff'] : 'Unassigned')) ?></p>
                    <?php if (hasRole('admin')): ?>
                        <a href="<?= e(queueWindowUrl() . '?window_id=' . (int) $window['id']) ?>" class="btn btn-sm btn-outline">Open window</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>

        <h3 class="queue-settings-subtitle">Waiting (<?= (int) $display['waiting_count'] ?>)</h3>
        <?php if ($display['waiting'] === []): ?>
            <p class="text-muted">No one is waiting.</p>
        <?php else: ?>
            <ol class="queue-monitor-waiting">
                <?php foreach ($display['waiting'] as $ticket): ?>
                    <li>
                        <strong><?= e((string) $ticket['ticket_code']) ?></strong>
                        <?= e((string) $ticket['service_label']) ?>
                        <?php if ($ticket['requestor_name'] !== ''): ?>
                            — <?= e((string) $ticket['requestor_name']) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </div>
</div>

<meta http-equiv="refresh" content="8">
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
