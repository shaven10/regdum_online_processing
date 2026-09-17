<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/official-receipt.php';
requireRole('cashier');

ensureCashierRole();

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);
$mode = getOfficialReceiptPrintMode($userId);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $postedMode = normalizeOfficialReceiptPrintMode($_POST['or_print_mode'] ?? '');
    if (!in_array($postedMode, ['template', 'data_only'], true)) {
        $errors[] = 'Select a valid OR print mode.';
    }

    if ($errors === []) {
        $previous = $mode;
        $mode = saveOfficialReceiptPrintMode($userId, $postedMode);
        auditLog('update_or_print_mode', 'app_settings', $userId, ['mode' => $previous], ['mode' => $mode]);
        setFlash('success', 'OR print setting saved. Print Official Receipt will use '
            . officialReceiptPrintModeLabel($mode) . ' mode.');
        redirect(APP_URL . '/cashier/or-settings.php');
    }

    $mode = $postedMode;
}

$pageTitle = 'OR Print Settings';
$activeNav = 'or-settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Official Receipt Print Settings</h2></div>
    <div class="card-body">
        <p class="text-muted">
            Choose how Official Receipts print from Verify Payments. This setting is saved for your cashier account.
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

            <div class="form-group">
                <label>OR Print Mode *</label>
                <div class="or-print-mode-options">
                    <label class="or-print-mode-option">
                        <input type="radio" name="or_print_mode" value="template" <?= $mode === 'template' ? 'checked' : '' ?> required>
                        <span>
                            <strong>OR template</strong>
                            <small class="text-muted">Full AF51 layout with logos, labels, and borders.</small>
                        </span>
                    </label>
                    <label class="or-print-mode-option">
                        <input type="radio" name="or_print_mode" value="data_only" <?= $mode === 'data_only' ? 'checked' : '' ?>>
                        <span>
                            <strong>Data only</strong>
                            <small class="text-muted">Same field positions; template artwork hidden for physical OR paper.</small>
                        </span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save OR Settings
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Current Mode</h3></div>
    <div class="card-body">
        <p>
            Active print mode:
            <strong><?= e(officialReceiptPrintModeLabel($mode)) ?></strong>
        </p>
        <p class="text-muted" style="margin-bottom:0;">
            Paper size for both modes is 4.25 in × 8.5 in. Change this anytime before printing from Verify Payments.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
