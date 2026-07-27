<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) redirect(dashboardUrl());

$pageTitle = 'Forgot Password';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $email = trim($_POST['email'] ?? '');
    if ($email) {
        $token = generateResetToken($email);
        if ($token) {
            $resetLink = APP_URL . '/auth/reset-password.php?token=' . $token;
            // In production, send email. For demo, show link.
            $_SESSION['reset_link_demo'] = $resetLink;
        }
        $success = true;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <?= renderAppLogo('auth') ?>
            <h2>Reset Password</h2>
            <p>Enter your email to receive a reset link</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                If an account exists with that email, a reset link has been sent.
                <?php if (!empty($_SESSION['reset_link_demo'])): ?>
                    <br><br><strong>Demo reset link:</strong><br>
                    <a href="<?= e($_SESSION['reset_link_demo']) ?>"><?= e($_SESSION['reset_link_demo']) ?></a>
                    <?php unset($_SESSION['reset_link_demo']); ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form method="POST" class="auth-form">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
            </form>
        <?php endif; ?>

        <p class="auth-footer"><a href="login.php">Back to login</a><br><a href="<?= APP_URL ?>/index.php">Back to Home</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
