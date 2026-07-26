<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) redirect(dashboardUrl());

$token = $_GET['token'] ?? '';
$pageTitle = 'Reset Password';
$success = false;
$errors = [];

if (!$token) {
    setFlash('error', 'Invalid reset link.');
    redirect(APP_URL . '/auth/forgot-password.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors['password'] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    } elseif ($password !== $confirm) {
        $errors['password_confirm'] = 'Passwords do not match.';
    } else {
        if (resetPassword($token, $password)) {
            setFlash('success', 'Password reset successful! Please sign in.');
            redirect(APP_URL . '/auth/login.php');
        } else {
            $errors['general'] = 'Invalid or expired reset link.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <?= renderAppLogo('auth') ?>
            <h2>Set New Password</h2>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-error"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <?= csrfField() ?>
            <div class="form-group">
                <label for="password">New Password</label>
                <input type="password" id="password" name="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
                <?php if (!empty($errors['password'])): ?><span class="field-error"><?= e($errors['password']) ?></span><?php endif; ?>
            </div>
            <div class="form-group">
                <label for="password_confirm">Confirm Password</label>
                <input type="password" id="password_confirm" name="password_confirm" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
