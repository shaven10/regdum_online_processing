<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/queue.php';
requireRole('admin');

ensureQueueSchema();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        $deleted = deleteQueueDisplayAd((int) ($_POST['ad_id'] ?? 0));
        auditLog('delete_queue_display_ad', 'queue_display_ads', (int) ($_POST['ad_id'] ?? 0));
        setFlash($deleted ? 'success' : 'error', $deleted ? 'Advertisement removed from the display board.' : 'Advertisement was not found.', [
            'title' => $deleted ? 'Advertisement Removed' : 'Could Not Remove',
        ]);
        redirect(APP_URL . '/admin/queue-display-ads.php');
    }

    if ($action === 'move') {
        moveQueueDisplayAd((int) ($_POST['ad_id'] ?? 0), (string) ($_POST['direction'] ?? ''));
        redirect(APP_URL . '/admin/queue-display-ads.php');
    }

    $errors = saveQueueDisplayAdSettings($_POST);
    if (isset($_FILES['ad_images'])) {
        $errors = array_merge($errors, storeQueueDisplayAdUploads($_FILES['ad_images']));
    }
    auditLog('update_queue_display_ads', 'app_settings', null, null, [
        'enabled' => !empty($_POST['queue_ads_enabled']),
        'interval' => (int) ($_POST['queue_ads_interval'] ?? 8),
        'layout' => (string) ($_POST['queue_ads_layout'] ?? 'side'),
        'fit' => (string) ($_POST['queue_ads_fit'] ?? 'cover'),
    ]);

    if ($errors === []) {
        setFlash('success', 'Display board advertisement settings saved.', [
            'title' => 'Advertisements Saved',
        ]);
        redirect(APP_URL . '/admin/queue-display-ads.php');
    }
}

$settings = getQueueSettings();
$ads = getQueueDisplayAds(false);

$pageTitle = 'Display Board Ads';
$activeNav = 'queue-display-ads';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2><i class="fas fa-images"></i> Display Board Advertisements</h2>
            <p class="text-muted" style="margin:.35rem 0 0">
                Choose where advertisements appear on the display board and how each image fits that space. Images still rotate as a slideshow.
            </p>
        </div>
        <div class="card-header-actions">
            <a href="<?= e(queueDisplayUrl()) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-tv"></i> Display Board
            </a>
            <a href="<?= APP_URL ?>/admin/queue-settings.php" class="btn btn-outline btn-sm">
                <i class="fas fa-list-ol"></i> Queue Settings
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

        <form method="POST" enctype="multipart/form-data" class="queue-ad-manager">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="queue_ads_enabled" value="1" <?= $settings['ads_enabled'] ? 'checked' : '' ?>>
                    Show advertisements on the display board
                </label>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="queue_ads_interval">Seconds per image</label>
                    <input type="number" id="queue_ads_interval" name="queue_ads_interval" min="3" max="60"
                           value="<?= (int) $settings['ads_interval'] ?>" required>
                    <small class="text-muted">Each image stays on screen for this long before the next one. 3 to 60 seconds.</small>
                </div>
                <div class="form-group">
                    <label for="ad_images">Add images</label>
                    <input type="file" id="ad_images" name="ad_images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                    <small class="text-muted">JPG, PNG, WEBP, or GIF. Up to 8 MB each, 20 images total.</small>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="queue_ads_layout">Showing</label>
                    <select id="queue_ads_layout" name="queue_ads_layout" required>
                        <?php foreach (queueAdLayoutOptions() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $settings['ads_layout'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Where the slideshow sits next to the now-serving queue.</small>
                </div>
                <div class="form-group">
                    <label for="queue_ads_fit">Display fit</label>
                    <select id="queue_ads_fit" name="queue_ads_fit" required>
                        <?php foreach (queueAdFitOptions() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $settings['ads_fit'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">How each image fills the advertisement area.</small>
                </div>
            </div>

            <?php $previewImage = $ads[0]['url'] ?? ''; ?>
            <div class="queue-ad-preview queue-ads-layout-<?= e($settings['ads_layout']) ?> queue-ads-fit-<?= e($settings['ads_fit']) ?>" id="queueAdPreview" data-preview-image="<?= e($previewImage) ?>">
                <div class="queue-ad-preview-board">Queue</div>
                <div class="queue-ad-preview-ads">
                    <?php if ($previewImage !== ''): ?>
                        <img src="<?= e($previewImage) ?>" alt="Advertisement preview">
                    <?php else: ?>
                        <span>Advertisement</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Advertisements
                </button>
            </div>
        </form>

        <h3 class="queue-settings-subtitle">Slideshow order</h3>
        <?php if ($ads === []): ?>
            <div class="empty-state">
                <i class="fas fa-image"></i>
                <p>No advertisement images yet. The display board uses the full screen until you add one.</p>
            </div>
        <?php else: ?>
            <div class="queue-ad-grid queue-ads-fit-<?= e($settings['ads_fit']) ?>" id="queueAdGrid">
                <?php foreach ($ads as $index => $ad): ?>
                    <article class="queue-ad-card">
                        <img src="<?= e((string) $ad['url']) ?>" alt="<?= e((string) ($ad['original_name'] ?: 'Advertisement')) ?>">
                        <div class="queue-ad-card-body">
                            <p>
                                <strong><?= $index + 1 ?></strong>
                                <br><small class="text-muted"><?= e((string) ($ad['original_name'] ?: 'Image')) ?></small>
                            </p>
                            <div class="queue-ad-card-actions">
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="move">
                                    <input type="hidden" name="ad_id" value="<?= (int) $ad['id'] ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="btn btn-outline btn-sm" <?= $index === 0 ? 'disabled' : '' ?> title="Move earlier">
                                        <i class="fas fa-arrow-up"></i>
                                    </button>
                                </form>
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="move">
                                    <input type="hidden" name="ad_id" value="<?= (int) $ad['id'] ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="btn btn-outline btn-sm" <?= $index === count($ads) - 1 ? 'disabled' : '' ?> title="Move later">
                                        <i class="fas fa-arrow-down"></i>
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Remove this advertisement from the display board?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="ad_id" value="<?= (int) $ad['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" title="Remove">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const layout = document.getElementById('queue_ads_layout');
    const fit = document.getElementById('queue_ads_fit');
    const preview = document.getElementById('queueAdPreview');
    const grid = document.getElementById('queueAdGrid');
    const layouts = ['side', 'side-left', 'top', 'bottom'];
    const fits = ['cover', 'contain', 'fill'];

    function applyClass(el, prefix, value, allowed) {
        if (!el) return;
        allowed.forEach(function (name) { el.classList.remove(prefix + name); });
        el.classList.add(prefix + (allowed.indexOf(value) >= 0 ? value : allowed[0]));
    }

    function syncPreview() {
        applyClass(preview, 'queue-ads-layout-', layout ? layout.value : 'side', layouts);
        applyClass(preview, 'queue-ads-fit-', fit ? fit.value : 'cover', fits);
        applyClass(grid, 'queue-ads-fit-', fit ? fit.value : 'cover', fits);
    }

    layout?.addEventListener('change', syncPreview);
    fit?.addEventListener('change', syncPreview);
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
