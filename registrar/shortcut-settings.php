<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/module-shortcuts.php';
requireRole('registrar');

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);
$shortcuts = getModuleShortcuts($userId, 'registrar');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $postedShortcuts = $_POST['shortcuts'] ?? [];
    if (!is_array($postedShortcuts)) {
        $postedShortcuts = [];
    }

    $result = saveModuleShortcuts($userId, 'registrar', $postedShortcuts);
    if (!$result['ok']) {
        $errors = $result['errors'];
        foreach ($postedShortcuts as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $shortcuts[$key] = normalizeModuleShortcut($value);
            }
        }
    } else {
        $shortcuts = $result['shortcuts'];
        auditLog('update_module_shortcuts', 'app_settings', $userId, null, [
            'role' => 'registrar',
            'shortcuts' => $shortcuts,
        ]);
        setFlash('success', 'Module shortcut keys saved for your registrar account.');
        redirect(APP_URL . '/registrar/shortcut-settings.php');
    }
}

$pageTitle = 'Shortcut Settings';
$activeNav = 'shortcut-settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-keyboard"></i> Module Shortcut Keys</h2></div>
    <div class="card-body">
        <p class="text-muted">
            Assign keyboard shortcuts to open registrar modules quickly. Shortcuts work on any registrar page while you are logged in.
        </p>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" class="form-grid">
            <?= csrfField() ?>
            <?php renderModuleShortcutSettingsFields('registrar', $shortcuts); ?>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Shortcuts
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
