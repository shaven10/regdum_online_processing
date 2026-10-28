<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/queue.php';
requireRole('admin');

ensureQueueSchema();

$errors = [];
$settings = getQueueSettings();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete_chime') {
        $chimeId = (int) ($_POST['chime_id'] ?? 0);
        $deleted = deleteQueueChimeUpload($chimeId);
        auditLog('delete_queue_chime', 'queue_chime_uploads', $chimeId > 0 ? $chimeId : null);
        setFlash($deleted ? 'success' : 'error', $deleted ? 'Uploaded chime removed.' : 'That chime was not found.', [
            'title' => $deleted ? 'Chime Removed' : 'Could Not Remove',
        ]);
        redirect(APP_URL . '/admin/queue-settings.php');
    }

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
    $uploaded = [];
    $errors = saveQueueSettingsFromPost($_POST);
    if (isset($_FILES['chime_audio'])) {
        $uploaded = storeQueueChimeUpload($_FILES['chime_audio']);
        if (!empty($uploaded['error'])) {
            $errors[] = (string) $uploaded['error'];
        } elseif (!empty($uploaded['id'])) {
            setAppSetting('queue_chime', 'file-' . (int) $uploaded['id']);
            auditLog('upload_queue_chime', 'queue_chime_uploads', (int) $uploaded['id']);
        }
    }
    $settings = getQueueSettings();
    auditLog('update_queue_settings', 'app_settings', null, $previous, $settings);

    if ($errors === []) {
        $savedMessage = !empty($uploaded['id'])
            ? 'Queuing parameters saved. The uploaded clip is now the chime.'
            : 'Queuing parameters and window assignments saved.';
        setFlash('success', $savedMessage, [
            'title' => 'Queue Settings Saved',
            'context' => [
                'Windows' => (string) $settings['window_count'],
                'Status' => $settings['enabled'] ? 'Open' : 'Closed',
            ],
        ]);
        redirect(APP_URL . '/admin/queue-settings.php');
    }
}

$chimeUploads = getQueueChimeUploads();
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

        <form method="POST" enctype="multipart/form-data" class="form-grid">
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
            <div class="span-2 queue-voice-settings" id="queue-voice-settings"
                 data-voice-uri="<?= e($settings['voice_uri']) ?>"
                 data-voice-name="<?= e($settings['voice_name']) ?>"
                 data-voice-lang="<?= e($settings['voice_lang']) ?>">
                <h3 class="queue-settings-subtitle">Voice announcements</h3>
                <p class="text-muted">Turn announcements on here and choose the voice the queue monitor uses when a number is called.</p>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="queue_sound_enabled" name="queue_sound_enabled" value="1" <?= $settings['sound_enabled'] ? 'checked' : '' ?>>
                        Announce the number and requestor name when a number is called
                    </label>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="queue_voice_uri">Voice</label>
                        <select id="queue_voice_uri" name="queue_voice_uri">
                            <option value="">Default English voice</option>
                        </select>
                        <small class="text-muted" id="queue-voice-note">Voices listed here are installed on this computer. Save the settings so the queue monitor uses the same voice when it is available there.</small>
                    </div>
                    <div class="form-group">
                        <label for="queue-voice-enable">Try the selected voice</label>
                        <div>
                            <button type="button" class="btn btn-outline" id="queue-voice-enable">
                                <i class="fas fa-volume-up"></i> Enable voice
                            </button>
                        </div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="queue_chime">Chime sound</label>
                        <select id="queue_chime" name="queue_chime">
                            <optgroup label="Built-in">
                                <?php foreach (queueChimeOptions() as $chimeId => $chimeLabel): ?>
                                    <option value="<?= e($chimeId) ?>" <?= $settings['chime'] === $chimeId ? 'selected' : '' ?>>
                                        <?= e($chimeLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php if ($chimeUploads !== []): ?>
                                <optgroup label="Uploaded">
                                    <?php foreach ($chimeUploads as $upload): ?>
                                        <?php $uploadValue = 'file-' . (int) $upload['id']; ?>
                                        <option value="<?= e($uploadValue) ?>" data-url="<?= e((string) $upload['url']) ?>" <?= $settings['chime'] === $uploadValue ? 'selected' : '' ?>>
                                            <?= e((string) $upload['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                        <small class="text-muted">Played on the queue monitor before the spoken number and name.</small>
                    </div>
                    <div class="form-group">
                        <label for="queue-chime-preview">Hear the chime</label>
                        <div>
                            <button type="button" class="btn btn-outline" id="queue-chime-preview">
                                <i class="fas fa-bell"></i> Play chime
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label id="queue-chime-volume-heading">Chime volume</label>
                        <div class="queue-chime-volume">
                            <button type="button" class="btn btn-outline" id="queue-chime-volume-down" aria-label="Volume down">
                                <i class="fas fa-volume-down"></i> Down
                            </button>
                            <strong id="queue-chime-volume-label" aria-live="polite"><?= (int) $settings['chime_volume'] ?>%</strong>
                            <button type="button" class="btn btn-outline" id="queue-chime-volume-up" aria-label="Volume up">
                                <i class="fas fa-volume-up"></i> Up
                            </button>
                        </div>
                        <input type="hidden" name="queue_chime_volume" id="queue_chime_volume" value="<?= (int) $settings['chime_volume'] ?>">
                        <small class="text-muted">Each step changes the chime by 10%. Save so the monitor uses this level.</small>
                    </div>
                    <div class="form-group">
                        <label for="chime_audio">Upload a short chime</label>
                        <input type="file" id="chime_audio" name="chime_audio" accept="audio/mpeg,audio/wav,.mp3,.wav">
                        <small class="text-muted">MP3 or WAV, up to 15 seconds and 2 MB. Saving with a file makes that clip the chime.</small>
                    </div>
                </div>
                <?php if ($chimeUploads !== []): ?>
                    <ul class="queue-chime-uploads">
                        <?php foreach ($chimeUploads as $upload): ?>
                            <li>
                                <span><?= e((string) $upload['name']) ?> <span class="text-muted">(<?= e(rtrim(rtrim(number_format((float) $upload['duration_seconds'], 1), '0'), '.')) ?>s)</span></span>
                                <button type="submit" class="btn btn-sm btn-outline" form="delete-chime-<?= (int) $upload['id'] ?>" onclick="return confirm('Remove this uploaded chime?');">Remove</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <input type="hidden" name="queue_voice_name" id="queue_voice_name" value="<?= e($settings['voice_name']) ?>">
                <input type="hidden" name="queue_voice_lang" id="queue_voice_lang" value="<?= e($settings['voice_lang']) ?>">
            </div>

            <div class="span-2">
                <h3 class="queue-settings-subtitle">Moving announcement</h3>
                <p class="text-muted">This line scrolls along the bottom of the public monitor. Leave it blank to hide the announcement. Registrar staff can also change it from the Queue Monitor.</p>
                <div class="form-group">
                    <label for="queue_announcement">Announcement text</label>
                    <textarea id="queue_announcement" name="queue_announcement" maxlength="280" rows="2" placeholder="Example: Office hours today are 8:00 AM to 5:00 PM. Please have your claim stub ready."><?= e($settings['announcement']) ?></textarea>
                    <small class="text-muted">Up to 280 characters. Saving applies it on the public monitor within a few seconds.</small>
                </div>
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

<?php foreach ($chimeUploads as $upload): ?>
    <form id="delete-chime-<?= (int) $upload['id'] ?>" method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_chime">
        <input type="hidden" name="chime_id" value="<?= (int) $upload['id'] ?>">
    </form>
<?php endforeach; ?>
<script src="<?= APP_URL ?>/assets/js/queue-chimes.js"></script>
<script src="<?= APP_URL ?>/assets/js/queue-voice-settings.js"></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
