<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    redirect(dashboardUrl());
}

$pageTitle = 'Login';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors['general'] = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $errors['general'] = 'Email and password are required.';
        } elseif (login($email, $password)) {
            setFlash('success', 'Welcome back!');
            redirect(dashboardUrl());
        } else {
            $errors['general'] = 'Invalid email or password.';
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <?= renderAppLogo('auth') ?>
            <h2><?= e(APP_NAME) ?></h2>
            <p><?= e(APP_TAGLINE) ?></p>
            <p class="auth-logo-sub">Sign in to your account</p>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-error"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <?= csrfField() ?>
            <div class="form-group">
                <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                <input type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-actions">
                <a href="forgot-password.php" class="link">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Sign In</button>
        </form>

        <p class="auth-footer">Don't have an account? <a href="register.php">Register here</a><br><a href="<?= APP_URL ?>/index.php">Back to Home</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
