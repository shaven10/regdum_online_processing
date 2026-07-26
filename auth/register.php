<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) redirect(dashboardUrl());

$pageTitle = 'Register';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors['general'] = 'Invalid request.';
    } else {
        $data = [
            'email'       => trim($_POST['email'] ?? ''),
            'password'    => $_POST['password'] ?? '',
            'first_name'  => trim($_POST['first_name'] ?? ''),
            'last_name'   => trim($_POST['last_name'] ?? ''),
            'middle_name' => trim($_POST['middle_name'] ?? ''),
            'student_id'  => trim($_POST['student_id'] ?? ''),
            'phone'       => trim($_POST['phone'] ?? ''),
        ];

        $errors = validateRequired([
            'email'      => 'Email',
            'password'   => 'Password',
            'first_name' => 'First name',
            'last_name'  => 'Last name',
            'student_id' => 'Student ID',
        ], $data);

        if (strlen($data['password']) < PASSWORD_MIN_LENGTH) {
            $errors['password'] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        }
        if ($data['password'] !== ($_POST['password_confirm'] ?? '')) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address.';
        }
        if (empty($_POST['privacy_consent'])) {
            $errors['privacy_consent'] = 'You must accept the Data Privacy Consent to create an account.';
        }

        if (empty($errors)) {
            $userId = register($data);
            if ($userId) {
                setFlash('success', 'Registration successful! Please sign in.');
                redirect(APP_URL . '/auth/login.php');
            } else {
                $errors['general'] = 'Email or Student ID already registered.';
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="auth-container">
    <div class="auth-card auth-card-wide">
        <div class="auth-logo">
            <?= renderAppLogo('auth') ?>
            <h2>Create Account</h2>
            <p>Register as a student/requester</p>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-error"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <?= csrfField() ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name *</label>
                    <input type="text" id="first_name" name="first_name" value="<?= e($_POST['first_name'] ?? '') ?>" required>
                    <?php if (!empty($errors['first_name'])): ?><span class="field-error"><?= e($errors['first_name']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name *</label>
                    <input type="text" id="last_name" name="last_name" value="<?= e($_POST['last_name'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="middle_name">Middle Name</label>
                    <input type="text" id="middle_name" name="middle_name" value="<?= e($_POST['middle_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="student_id">Student ID *</label>
                    <input type="text" id="student_id" name="student_id" value="<?= e($_POST['student_id'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required>
                    <?php if (!empty($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" value="<?= e($_POST['phone'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>">
                    <?php if (!empty($errors['password'])): ?><span class="field-error"><?= e($errors['password']) ?></span><?php endif; ?>
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirm Password *</label>
                    <input type="password" id="password_confirm" name="password_confirm" required>
                    <?php if (!empty($errors['password_confirm'])): ?><span class="field-error"><?= e($errors['password_confirm']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="privacy-consent-panel">
                <h3><i class="fas fa-shield-halved"></i> Data Privacy Consent</h3>
                <div class="privacy-consent-text"><?= nl2br(e(dataPrivacyConsentText())) ?></div>
                <label class="checkbox-label privacy-consent-check">
                    <input type="checkbox" id="privacy_consent" name="privacy_consent" value="1"
                        <?= !empty($_POST['privacy_consent']) ? 'checked' : '' ?> required>
                    <span>I have read, understood, and agree to the Data Privacy Consent above. *</span>
                </label>
                <?php if (!empty($errors['privacy_consent'])): ?><span class="field-error"><?= e($errors['privacy_consent']) ?></span><?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block" id="registerSubmitBtn">Create Account</button>
        </form>

        <p class="auth-footer">Already have an account? <a href="login.php">Sign in</a></p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
