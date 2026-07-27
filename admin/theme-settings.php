<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/theme.php';
requireRole('admin');

$currentKey = getThemePresetKey();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $selected = trim((string) ($_POST['theme_preset'] ?? ''));

    if (!isValidThemePreset($selected)) {
        $errors[] = 'Please select a valid theme.';
    } else {
        $previous = $currentKey;
        saveThemePreset($selected);
        auditLog('update_theme_preset', 'app_settings', null, ['theme_preset' => $previous], ['theme_preset' => $selected]);
        setFlash('success', 'Theme updated. The new colors are now applied across the system.');
        redirect(APP_URL . '/admin/theme-settings.php');
    }
}

$pageTitle = 'Theme Manager';
$activeNav = 'theme';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-palette"></i> Theme Manager</h2></div>
    <div class="card-body">
        <p class="text-muted">
            Choose the color theme for the landing page, login screens, and all user dashboards.
            Changes apply immediately after saving.
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

        <form method="POST" class="theme-manager-form">
            <?= csrfField() ?>
            <div class="theme-preset-grid">
                <?php renderThemePresetCards($currentKey); ?>
            </div>
            <div class="theme-manager-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Theme
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Live Preview</h3></div>
    <div class="card-body">
        <div class="theme-live-preview">
            <div class="theme-preview-sidebar">
                <div class="theme-preview-brand"></div>
                <div class="theme-preview-nav is-active"></div>
                <div class="theme-preview-nav"></div>
                <div class="theme-preview-nav"></div>
            </div>
            <div class="theme-preview-main">
                <div class="theme-preview-topbar"></div>
                <div class="theme-preview-button btn btn-primary">Primary Button</div>
                <div class="theme-preview-button btn btn-outline">Outline Button</div>
                <span class="badge badge-processing">Processing</span>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const cards = document.querySelectorAll('.theme-preset-card');
    cards.forEach(function (card) {
        card.addEventListener('click', function () {
            cards.forEach(function (item) {
                item.classList.remove('is-selected');
                const badge = item.querySelector('.theme-preset-badge');
                if (badge) badge.remove();
            });
            card.classList.add('is-selected');
            const copy = card.querySelector('.theme-preset-copy');
            if (copy && !card.querySelector('.theme-preset-badge')) {
                const badge = document.createElement('span');
                badge.className = 'theme-preset-badge';
                badge.textContent = 'Selected';
                card.appendChild(badge);
            }
            const input = card.querySelector('input[type="radio"]');
            if (input) input.checked = true;
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
