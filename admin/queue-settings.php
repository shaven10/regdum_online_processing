<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/queue.php';
requireRole('admin');

ensureQueueSchema();

$errors = [];
$settings = getQueueSettings();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'close_waiting') {
        $closed = cancelRemainingWaitingTickets();
        auditLog('queue_close_waiting', 'queue_tickets', null, null, [
            'closed' => $closed,
            'date' => queueToday(),
        ]);
        setFlash('success', $closed . ' waiting number' . ($closed === 1 ? '' : 's') . ' closed for today.', [
            'title' => 'Queue Cleared',
        ]);
        redirect(APP_URL . '/admin/queue-settings.php');
    }

    $previous = $settings;
    $errors = saveQueueSettingsFromPost($_POST);
    $settings = getQueueSettings();
    auditLog('update_queue_settings', 'app_settings', null, $previous, $settings);

    if ($errors === []) {
        setFlash('success', 'Queuing parameters and window assignments saved.', [
            'title' => 'Queue Settings Saved',
            'context' => [
                'Windows' => (string) $settings['window_count'],
                'Status' => $settings['enabled'] ? 'Open' : 'Closed',
            ],
        ]);
        redirect(APP_URL . '/admin/queue-settings.php');
    }
}

$windows = getQueueWindows(true);
$staff = getQueueAssignableStaff();
$display = getQueueDisplayState();

$pageTitle = 'Queue Settings';
$activeNav = 'queue-settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2><i class="fas fa-list-ol"></i> Onsite Queuing</h2>
            <p class="text-muted" style="margin:.35rem 0 0">
                Set how many registrar windows are open and which registrar staff account serves each window.
                Requestors take a priority number on the kiosk page; the display board shows the number now being served.
            </p>
        </div>
        <div class="card-header-actions">
            <a href="<?= e(queueKioskUrl()) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-ticket-alt"></i> Get Number Page
            </a>
            <a href="<?= e(queueDisplayUrl()) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-tv"></i> Display Board
            </a>
            <a href="<?= APP_URL ?>/admin/queue-display-ads.php" class="btn btn-outline btn-sm">
                <i class="fas fa-images"></i> Advertisements
            </a>
            <a href="<?= e(queueMonitorUrl()) ?>" class="btn btn-outline btn-sm">
                <i class="fas fa-desktop"></i> Live Monitor
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="queue-settings-summary">
            <div>
                <span class="text-muted">Today</span>
                <strong><?= e($display['date_label']) ?></strong>
            </div>
            <div>
                <span class="text-muted">Waiting</span>
                <strong><?= (int) $display['waiting_count'] ?></strong>
            </div>
            <div>
                <span class="text-muted">Windows</span>
                <strong><?= count($windows) ?></strong>
            </div>
            <div>
                <span class="text-muted">Status</span>
                <strong><?= $settings['enabled'] ? 'Open' : 'Closed' ?></strong>
            </div>
        </div>

        <form method="POST" class="form-grid">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="queue_enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>>
                    Queue is open (requestors can get a priority number)
                </label>
            </div>

            <div class="form-row span-2">
                <div class="form-group">
                    <label for="queue_window_count">Number of windows</label>
                    <input type="number" id="queue_window_count" name="queue_window_count" min="1" max="12"
                           value="<?= (int) $settings['window_count'] ?>" required>
                    <small class="text-muted">1 to 12 service windows at the registrar.</small>
                </div>
                <div class="form-group">
                    <label for="queue_prefix">Ticket prefix <span class="text-muted">(optional)</span></label>
                    <input type="text" id="queue_prefix" name="queue_prefix" maxlength="4"
                           value="<?= e($settings['prefix']) ?>" placeholder="e.g. R" style="text-transform:uppercase">
                    <small class="text-muted">Shown as R-001 when set.</small>
                </div>
                <div class="form-group">
                    <label for="queue_pad">Number length</label>
                    <select id="queue_pad" name="queue_pad">
                        <?php foreach ([2 => '01', 3 => '001', 4 => '0001'] as $value => $sample): ?>
                            <option value="<?= $value ?>" <?= (int) $settings['pad'] === $value ? 'selected' : '' ?>>
                                <?= $value ?> digits (<?= e($sample) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="queue_require_name" value="1" <?= $settings['require_name'] ? 'checked' : '' ?>>
                    Require requestor name on the Get Number page
                </label>
            </div>
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="queue_sound_enabled" value="1" <?= $settings['sound_enabled'] ? 'checked' : '' ?>>
                    Play a chime on the display board when a new number is called
                </label>
            </div>

            <div class="span-2">
                <h3 class="queue-settings-subtitle">Window staff assignment</h3>
                <p class="text-muted">Each window is bound to one registrar or registrar staff user account. That account uses the Queue Window page to call the next number.</p>
                <?php if ($staff === []): ?>
                    <div class="alert alert-warning">
                        No active registrar or registrar staff accounts were found. Add users first in User Management.
                    </div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <th>Window</th>
                                <th>Display name</th>
                                <th>Assigned staff account</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($windows as $window): ?>
                                <tr>
                                    <td data-label="Window"><strong>#<?= (int) $window['window_number'] ?></strong></td>
                                    <td data-label="Display name">
                                        <input type="text" name="window_name[<?= (int) $window['id'] ?>]"
                                               value="<?= e((string) $window['name']) ?>" required>
                                    </td>
                                    <td data-label="Assigned staff">
                                        <select name="window_user[<?= (int) $window['id'] ?>]">
                                            <option value="0">— Unassigned —</option>
                                            <?php foreach ($staff as $person): ?>
                                                <option value="<?= (int) $person['id'] ?>"
                                                    <?= (int) ($window['assigned_user_id'] ?? 0) === (int) $person['id'] ? 'selected' : '' ?>>
                                                    <?= e($person['label']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="form-actions span-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Queue Settings
                </button>
            </div>
        </form>

        <form method="POST" class="queue-settings-close-form" onsubmit="return confirm('Cancel all remaining waiting numbers for today?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="close_waiting">
            <button type="submit" class="btn btn-outline">
                <i class="fas fa-ban"></i> Close remaining waiting numbers today
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
