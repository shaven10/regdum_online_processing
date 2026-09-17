<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/official-receipt.php';
require_once __DIR__ . '/../includes/module-shortcuts.php';
requireRole('cashier');

ensureCashierRole();

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);
$mode = getOfficialReceiptPrintMode($userId);
$claimAfterVerify = isClaimSlipAfterVerifyEnabled($userId);
$shortcuts = getModuleShortcuts($userId, 'cashier');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $section = trim((string) ($_POST['settings_section'] ?? 'all'));

    if ($section === 'print' || $section === 'all') {
        $postedMode = normalizeOfficialReceiptPrintMode($_POST['or_print_mode'] ?? '');
        if (!in_array($postedMode, ['template', 'data_only'], true)) {
            $errors[] = 'Select a valid OR print mode.';
        }
        $postedClaim = isset($_POST['claim_slip_after_verify']) && (string) $_POST['claim_slip_after_verify'] === '1';
    } else {
        $postedMode = $mode;
        $postedClaim = $claimAfterVerify;
    }

    if ($section === 'shortcuts' || $section === 'all') {
        $postedShortcuts = $_POST['shortcuts'] ?? [];
        if (!is_array($postedShortcuts)) {
            $postedShortcuts = [];
        }
        $shortcutResult = saveModuleShortcuts($userId, 'cashier', $postedShortcuts);
        if (!$shortcutResult['ok']) {
            $errors = array_merge($errors, $shortcutResult['errors']);
        } else {
            $shortcuts = $shortcutResult['shortcuts'];
        }
    }

    if ($errors === [] && ($section === 'print' || $section === 'all')) {
        $previousMode = $mode;
        $previousClaim = $claimAfterVerify;
        $mode = saveOfficialReceiptPrintMode($userId, $postedMode);
        $claimAfterVerify = saveClaimSlipAfterVerifyEnabled($userId, $postedClaim);
        auditLog('update_cashier_print_settings', 'app_settings', $userId, [
            'or_mode' => $previousMode,
            'claim_slip_after_verify' => $previousClaim,
        ], [
            'or_mode' => $mode,
            'claim_slip_after_verify' => $claimAfterVerify,
        ]);
    }

    if ($errors === [] && ($section === 'shortcuts' || $section === 'all')) {
        auditLog('update_module_shortcuts', 'app_settings', $userId, null, [
            'role' => 'cashier',
            'shortcuts' => $shortcuts,
        ]);
    }

    if ($errors === []) {
        $messages = [];
        if ($section === 'print' || $section === 'all') {
            $messages[] = 'OR print mode: ' . officialReceiptPrintModeLabel($mode);
            $messages[] = 'Claim slip after verification: ' . ($claimAfterVerify ? 'enabled' : 'disabled');
        }
        if ($section === 'shortcuts' || $section === 'all') {
            $messages[] = 'Module shortcuts saved.';
        }
        setFlash('success', implode(' ', $messages));
        redirect(APP_URL . '/cashier/or-settings.php');
    }

    if ($section === 'print' || $section === 'all') {
        $mode = $postedMode ?? $mode;
        $claimAfterVerify = $postedClaim ?? $claimAfterVerify;
    }
    if (($section === 'shortcuts' || $section === 'all') && isset($postedShortcuts) && is_array($postedShortcuts)) {
        foreach ($postedShortcuts as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $shortcuts[$key] = normalizeModuleShortcut($value);
            }
        }
    }
}

$pageTitle = 'OR / Print Settings';
$activeNav = 'or-settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Official Receipt &amp; Claim Slip</h2></div>
    <div class="card-body">
        <p class="text-muted">
            Choose how Official Receipts print after payment verification, and whether a claim slip should open next.
            These settings are saved for your cashier account.
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
            <input type="hidden" name="settings_section" value="print">

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

            <div class="form-group">
                <label>Claim Slip After Verification *</label>
                <div class="or-print-mode-options">
                    <label class="or-print-mode-option">
                        <input type="radio" name="claim_slip_after_verify" value="1" <?= $claimAfterVerify ? 'checked' : '' ?> required>
                        <span>
                            <strong>Enabled</strong>
                            <small class="text-muted">After verifying payment, open the claim slip for printing (after the OR).</small>
                        </span>
                    </label>
                    <label class="or-print-mode-option">
                        <input type="radio" name="claim_slip_after_verify" value="0" <?= !$claimAfterVerify ? 'checked' : '' ?>>
                        <span>
                            <strong>Disabled</strong>
                            <small class="text-muted">Only open the Official Receipt after verification. Claim slip stays available from payments.</small>
                        </span>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Print Settings
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-keyboard"></i> Module Shortcut Keys</h2></div>
    <div class="card-body">
        <p class="text-muted">
            Assign keyboard shortcuts to jump between cashier modules. Shortcuts work on any cashier page while you are logged in.
        </p>
        <form method="POST" class="form-grid">
            <?= csrfField() ?>
            <input type="hidden" name="settings_section" value="shortcuts">
            <?php renderModuleShortcutSettingsFields('cashier', $shortcuts); ?>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Shortcuts
            </button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Current Print Settings</h3></div>
    <div class="card-body">
        <p>
            OR print mode:
            <strong><?= e(officialReceiptPrintModeLabel($mode)) ?></strong>
        </p>
        <p>
            Claim slip after verification:
            <strong><?= $claimAfterVerify ? 'Enabled' : 'Disabled' ?></strong>
        </p>
        <p class="text-muted" style="margin-bottom:0;">
            OR paper size is legal with the receipt sheet at the top. Change these anytime before verifying payments.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
