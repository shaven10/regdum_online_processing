<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
requireRole('cashier');

ensureCashierRole();

$details = getBankTransferDetails();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    [$details, $errors] = validateBankTransferDetailsInput($_POST);

    if ($errors === []) {
        saveBankTransferDetails($details);
        auditLog('update_bank_transfer_details', 'app_settings', null, null, $details);
        setFlash('success', 'Bank transfer details updated. Students will see these on the payment page.');
        redirect(APP_URL . '/cashier/bank-settings.php');
    }
}

$pageTitle = 'Bank Transfer Settings';
$activeNav = 'bank-settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Bank Transfer Details</h2></div>
    <div class="card-body">
        <p class="text-muted">
            These details are shown to students when they choose bank transfer on the payment page.
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
                <label for="bank_name">Bank Name *</label>
                <input type="text" id="bank_name" name="bank_name" maxlength="120" required
                    value="<?= e($details['bank_name']) ?>" placeholder="e.g. Land Bank of the Philippines">
            </div>

            <div class="form-group">
                <label for="account_name">Account Name *</label>
                <input type="text" id="account_name" name="account_name" maxlength="120" required
                    value="<?= e($details['account_name']) ?>" placeholder="e.g. J.H. Cerilles State College">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="account_number">Account Number *</label>
                    <input type="text" id="account_number" name="account_number" maxlength="60" required
                        value="<?= e($details['account_number']) ?>" placeholder="e.g. 1234-5678-9012">
                </div>
                <div class="form-group">
                    <label for="branch">Branch</label>
                    <input type="text" id="branch" name="branch" maxlength="120"
                        value="<?= e($details['branch']) ?>" placeholder="e.g. Main Campus Branch">
                </div>
            </div>

            <div class="form-group">
                <label for="instructions">Instructions for Students</label>
                <textarea id="instructions" name="instructions" rows="3" maxlength="500"
                    placeholder="Optional notes such as deposit slip requirements or office hours."><?= e($details['instructions']) ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Bank Details
            </button>
        </form>
    </div>
</div>

<?php if (bankTransferDetailsConfigured()): ?>
<div class="card">
    <div class="card-header"><h3>Student Preview</h3></div>
    <div class="card-body">
        <?= renderStudentBankTransferDetailsHtml() ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
