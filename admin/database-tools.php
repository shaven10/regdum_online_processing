<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database-tools.php';
requireRole('admin');

ensureDatabaseToolsSchema();

$user = currentUser();
$errors = [];
$activeTab = trim((string) ($_GET['tab'] ?? 'backups'));
$allowedTabs = ['backups', 'full', 'restore_point', 'custom', 'restore'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'backups';
}

if (($_GET['action'] ?? '') === 'download') {
    $backupId = (int) ($_GET['backup_id'] ?? 0);
    try {
        databaseToolsDownloadBackup($backupId);
    } catch (Throwable $e) {
        setFlash('error', $e->getMessage());
        redirect(APP_URL . '/admin/database-tools.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Invalid session token. Please try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));

        try {
            if ($action === 'create_full_backup') {
                $result = databaseToolsCreateBackup('full', 'Full Database Backup', [], (int) $user['id']);
                auditLog('database_backup_create', 'database_backup', $result['id'], null, [
                    'type'     => 'full',
                    'filename' => $result['filename'],
                ]);
                setFlash('success', 'Full database backup created (' . databaseToolsFormatBytes((int) $result['size']) . ').');
                redirect(APP_URL . '/admin/database-tools.php?tab=backups');
            }

            if ($action === 'create_restore_point') {
                $label = trim((string) ($_POST['restore_point_label'] ?? ''));
                $notes = trim((string) ($_POST['restore_point_notes'] ?? ''));
                if ($label === '') {
                    throw new InvalidArgumentException('Restore point name is required.');
                }

                $result = databaseToolsCreateBackup('restore_point', $label, [], (int) $user['id'], $notes);
                auditLog('database_restore_point_create', 'database_backup', $result['id'], null, [
                    'label'    => $label,
                    'filename' => $result['filename'],
                ]);
                setFlash('success', 'Restore point "' . $label . '" created.');
                redirect(APP_URL . '/admin/database-tools.php?tab=backups');
            }

            if ($action === 'create_custom_backup') {
                $label = trim((string) ($_POST['custom_backup_label'] ?? ''));
                $tables = array_values(array_filter(array_map('strval', $_POST['tables'] ?? [])));
                if ($label === '') {
                    throw new InvalidArgumentException('Custom backup name is required.');
                }

                $result = databaseToolsCreateBackup('custom', $label, $tables, (int) $user['id']);
                auditLog('database_backup_create', 'database_backup', $result['id'], null, [
                    'type'     => 'custom',
                    'tables'   => $result['tables'],
                    'filename' => $result['filename'],
                ]);
                setFlash('success', 'Custom backup created for ' . count($result['tables']) . ' table(s).');
                redirect(APP_URL . '/admin/database-tools.php?tab=backups');
            }

            if ($action === 'restore_backup') {
                $backupId = (int) ($_POST['backup_id'] ?? 0);
                $confirm = strtoupper(trim((string) ($_POST['restore_confirm'] ?? '')));
                if ($confirm !== 'RESTORE') {
                    throw new InvalidArgumentException('Type RESTORE to confirm database restore.');
                }

                databaseToolsRestoreBackup($backupId, (int) $user['id']);
                setFlash('success', 'Database restore completed successfully.');
                redirect(APP_URL . '/admin/database-tools.php?tab=backups');
            }

            if ($action === 'delete_backup') {
                $backupId = (int) ($_POST['backup_id'] ?? 0);
                databaseToolsDeleteBackup($backupId, (int) $user['id']);
                setFlash('success', 'Backup deleted.');
                redirect(APP_URL . '/admin/database-tools.php?tab=backups');
            }

            if ($action === 'restore_upload') {
                $confirm = strtoupper(trim((string) ($_POST['restore_confirm'] ?? '')));
                if ($confirm !== 'RESTORE') {
                    throw new InvalidArgumentException('Type RESTORE to confirm database restore.');
                }

                databaseToolsRestoreUpload($_FILES['sql_file'] ?? [], (int) $user['id']);
                setFlash('success', 'Database restored from uploaded SQL file.');
                redirect(APP_URL . '/admin/database-tools.php?tab=backups');
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $activeTab = match ($action) {
                'create_full_backup'     => 'full',
                'create_restore_point'   => 'restore_point',
                'create_custom_backup'   => 'custom',
                'restore_backup', 'restore_upload' => 'restore',
                default                  => $activeTab,
            };
        }
    }
}

$backups = databaseToolsListBackups();
$tables = databaseToolsListTables();
$pageTitle = 'Database Tools';
$activeNav = 'database-tools';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2><i class="fas fa-database"></i> Database Tools</h2>
            <p class="text-muted" style="margin:.35rem 0 0">Backup, dump, restore, and manage restore points for <strong><?= e(DB_NAME) ?></strong>.</p>
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

        <div class="settings-segment-tabs db-tools-tabs" role="tablist" aria-label="Database tools">
            <a href="?tab=backups" class="settings-segment-tab<?= $activeTab === 'backups' ? ' is-active' : '' ?>">Saved Backups</a>
            <a href="?tab=full" class="settings-segment-tab<?= $activeTab === 'full' ? ' is-active' : '' ?>">Full Backup</a>
            <a href="?tab=restore_point" class="settings-segment-tab<?= $activeTab === 'restore_point' ? ' is-active' : '' ?>">Restore Point</a>
            <a href="?tab=custom" class="settings-segment-tab<?= $activeTab === 'custom' ? ' is-active' : '' ?>">Custom Backup</a>
            <a href="?tab=restore" class="settings-segment-tab<?= $activeTab === 'restore' ? ' is-active' : '' ?>">Restore</a>
        </div>

        <?php if ($activeTab === 'backups'): ?>
            <div class="db-tools-panel">
                <p class="text-muted">Stored backups are saved in <code>storage/database-backups</code> and are not web-accessible.</p>

                <?php if ($backups === []): ?>
                    <div class="empty-state">
                        <i class="fas fa-archive"></i>
                        <p>No backups yet. Create a full backup or restore point to get started.</p>
                    </div>
                <?php else: ?>
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>Type</th>
                                <th>Tables</th>
                                <th>Size</th>
                                <th>Created</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backups as $backup): ?>
                                <?php
                                $creator = trim(($backup['first_name'] ?? '') . ' ' . ($backup['last_name'] ?? ''));
                                ?>
                                <tr>
                                    <td data-label="Label">
                                        <strong><?= e($backup['label']) ?></strong>
                                        <?php if (!empty($backup['notes'])): ?>
                                            <br><small class="text-muted"><?= e($backup['notes']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Type"><?= e(databaseToolsBackupTypeLabel((string) $backup['backup_type'])) ?></td>
                                    <td data-label="Tables"><?= e(databaseToolsTablesSummary($backup['tables_json'] ?? null)) ?></td>
                                    <td data-label="Size"><?= e(databaseToolsFormatBytes((int) ($backup['file_size'] ?? 0))) ?></td>
                                    <td data-label="Created"><?= e(formatDateTime($backup['created_at'])) ?></td>
                                    <td data-label="Created By"><?= e($creator !== '' ? $creator : 'System') ?></td>
                                    <td data-label="Actions" class="payment-actions-cell">
                                        <a href="?action=download&amp;backup_id=<?= (int) $backup['id'] ?>" class="btn btn-sm btn-outline">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('Restore this backup? This will overwrite current database data.');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="restore_backup">
                                            <input type="hidden" name="backup_id" value="<?= (int) $backup['id'] ?>">
                                            <input type="hidden" name="restore_confirm" value="RESTORE">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="fas fa-undo"></i> Restore
                                            </button>
                                        </form>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('Delete this backup permanently?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_backup">
                                            <input type="hidden" name="backup_id" value="<?= (int) $backup['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php elseif ($activeTab === 'full'): ?>
            <div class="db-tools-panel">
                <div class="db-tools-callout">
                    <h3>Full Database Backup</h3>
                    <p>Create a complete SQL dump of all tables in <strong><?= e(DB_NAME) ?></strong>. The file is saved on the server and can be downloaded from Saved Backups.</p>
                    <ul>
                        <li>Includes structure and data for every table</li>
                        <li>Uses mysqldump when available, otherwise a PHP-generated SQL dump</li>
                        <li>Recommended before major updates or bulk imports</li>
                    </ul>
                </div>
                <form method="POST" class="db-tools-action-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_full_backup">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Full Backup Now
                    </button>
                </form>
            </div>
        <?php elseif ($activeTab === 'restore_point'): ?>
            <div class="db-tools-panel">
                <div class="db-tools-callout">
                    <h3>Create Restore Point</h3>
                    <p>Save a named snapshot of the entire database so you can roll back later from Saved Backups.</p>
                </div>
                <form method="POST" class="db-tools-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_restore_point">
                    <div class="form-row">
                        <label for="restore_point_label">Restore Point Name</label>
                        <input type="text" id="restore_point_label" name="restore_point_label" maxlength="120" placeholder="Before enrollment update" required>
                    </div>
                    <div class="form-row">
                        <label for="restore_point_notes">Notes (optional)</label>
                        <input type="text" id="restore_point_notes" name="restore_point_notes" maxlength="255" placeholder="Describe why this restore point was created">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-bookmark"></i> Create Restore Point
                    </button>
                </form>
            </div>
        <?php elseif ($activeTab === 'custom'): ?>
            <div class="db-tools-panel">
                <div class="db-tools-callout">
                    <h3>Custom Table Backup</h3>
                    <p>Export only selected tables into a SQL dump file.</p>
                </div>
                <form method="POST" class="db-tools-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_custom_backup">
                    <div class="form-row">
                        <label for="custom_backup_label">Backup Name</label>
                        <input type="text" id="custom_backup_label" name="custom_backup_label" maxlength="120" placeholder="Users and requests backup" required>
                    </div>
                    <div class="form-row">
                        <div class="db-tools-table-toolbar">
                            <label>Select Tables</label>
                            <button type="button" class="btn btn-outline btn-sm" id="dbToolsSelectAll">Select All</button>
                            <button type="button" class="btn btn-outline btn-sm" id="dbToolsClearAll">Clear All</button>
                        </div>
                        <div class="db-tools-table-grid">
                            <?php foreach ($tables as $table): ?>
                                <label class="settings-simple-check">
                                    <input type="checkbox" name="tables[]" value="<?= e($table) ?>">
                                    <span><?= e($table) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-table"></i> Create Custom Backup
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="db-tools-panel">
                <div class="db-tools-callout db-tools-callout-warning">
                    <h3>Restore Database</h3>
                    <p><strong>Warning:</strong> Restoring will overwrite existing data in the selected tables or the entire database depending on the SQL file.</p>
                    <p>Always create a restore point before running a restore.</p>
                </div>

                <div class="db-tools-restore-grid">
                    <section class="db-tools-restore-card">
                        <h4>Restore Saved Backup</h4>
                        <p class="text-muted">Choose a backup stored on this server.</p>
                        <?php if ($backups === []): ?>
                            <p class="text-muted">No saved backups available.</p>
                        <?php else: ?>
                            <form method="POST" class="db-tools-form">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="restore_backup">
                                <div class="form-row">
                                    <label for="restore_backup_id">Backup</label>
                                    <select id="restore_backup_id" name="backup_id" required>
                                        <option value="">Select backup...</option>
                                        <?php foreach ($backups as $backup): ?>
                                            <option value="<?= (int) $backup['id'] ?>">
                                                <?= e($backup['label']) ?> (<?= e(formatDateTime($backup['created_at'])) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-row">
                                    <label for="restore_confirm_saved">Type RESTORE to confirm</label>
                                    <input type="text" id="restore_confirm_saved" name="restore_confirm" placeholder="RESTORE" autocomplete="off" required>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-undo"></i> Restore Saved Backup
                                </button>
                            </form>
                        <?php endif; ?>
                    </section>

                    <section class="db-tools-restore-card">
                        <h4>Restore Uploaded SQL Dump</h4>
                        <p class="text-muted">Upload a <code>.sql</code> file from your computer.</p>
                        <form method="POST" enctype="multipart/form-data" class="db-tools-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="restore_upload">
                            <div class="form-row">
                                <label for="sql_file">SQL File</label>
                                <input type="file" id="sql_file" name="sql_file" accept=".sql,text/plain" required>
                            </div>
                            <div class="form-row">
                                <label for="restore_confirm_upload">Type RESTORE to confirm</label>
                                <input type="text" id="restore_confirm_upload" name="restore_confirm" placeholder="RESTORE" autocomplete="off" required>
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-file-upload"></i> Restore Uploaded File
                            </button>
                        </form>
                    </section>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectAll = document.getElementById('dbToolsSelectAll');
    var clearAll = document.getElementById('dbToolsClearAll');
    if (!selectAll || !clearAll) return;

    selectAll.addEventListener('click', function () {
        document.querySelectorAll('.db-tools-table-grid input[type="checkbox"]').forEach(function (input) {
            input.checked = true;
        });
    });

    clearAll.addEventListener('click', function () {
        document.querySelectorAll('.db-tools-table-grid input[type="checkbox"]').forEach(function (input) {
            input.checked = false;
        });
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
