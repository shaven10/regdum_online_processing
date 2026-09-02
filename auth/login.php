<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    redirect(dashboardUrl());
}

$pageTitle = 'Login';
$errors = [];
$mode = (string) ($_GET['mode'] ?? $_POST['login_mode'] ?? 'email');
if (!in_array($mode, ['email', 'student'], true)) {
    $mode = 'email';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors['general'] = 'Invalid request. Please try again.';
    } else {
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $privacyConsent = !empty($_POST['privacy_consent']);
        $mode = (string) ($_POST['login_mode'] ?? 'email');
        if (!in_array($mode, ['email', 'student'], true)) {
            $mode = 'email';
        }

        if ($identifier === '' || $password === '') {
            $errors['general'] = $mode === 'student'
                ? 'Student ID and password are required.'
                : 'Email and password are required.';
        } elseif ($mode === 'student') {
            $student = findActiveEnrolledStudentByStudentId($identifier);
            if (!$student) {
                $errors['general'] = 'Student ID not found in the active enrollment list. Please contact the registrar office.';
            } elseif (empty($student['privacy_consent_at']) && !$privacyConsent) {
                $errors['general'] = 'You must accept the Data Privacy Consent to continue.';
            } elseif (loginByStudentId($identifier, $password)) {
                if (empty($student['privacy_consent_at'])) {
                    ensurePrivacyConsentSchema();
                    getDB()->prepare('UPDATE users SET privacy_consent_at = NOW() WHERE id = ?')
                        ->execute([(int) $student['id']]);
                }
                setFlash('success', 'Welcome back!');
                redirect(dashboardUrl());
            } else {
                $errors['general'] = 'Invalid Student ID or password.';
            }
        } elseif (loginWithCredentials($identifier, $password)) {
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

        <div class="auth-login-tabs" role="tablist" aria-label="Login options">
            <a href="login.php?mode=email" class="auth-login-tab<?= $mode === 'email' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $mode === 'email' ? 'true' : 'false' ?>">Email</a>
            <a href="login.php?mode=student" class="auth-login-tab<?= $mode === 'student' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $mode === 'student' ? 'true' : 'false' ?>">Student ID</a>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-error"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <?= csrfField() ?>
            <input type="hidden" name="login_mode" value="<?= e($mode) ?>">
            <?php if ($mode === 'student'): ?>
                <p class="auth-student-info-link">
                    <button type="button" class="link-button" data-open-student-login-info>
                        <i class="fas fa-circle-info"></i> Default login credentials
                    </button>
                </p>
                <div class="form-group">
                    <label for="identifier"><i class="fas fa-id-card"></i> Student ID Number</label>
                    <input type="text" id="identifier" name="identifier" value="<?= e($_POST['identifier'] ?? '') ?>" placeholder="808401000XXXXX" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Password</label>
                    <input type="password" id="password" name="password" placeholder="808401000XXXXX" required autocomplete="current-password">
                </div>
                <div class="privacy-consent-panel">
                    <h3><i class="fas fa-shield-halved"></i> Data Privacy Consent</h3>
                    <div class="privacy-consent-text"><?= nl2br(e(dataPrivacyConsentText())) ?></div>
                    <label class="checkbox-label privacy-consent-check">
                        <input type="checkbox" id="privacy_consent" name="privacy_consent" value="1"
                            <?= !empty($_POST['privacy_consent']) ? 'checked' : '' ?>>
                        <span>I have read, understood, and agree to the Data Privacy Consent above.</span>
                    </label>
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label for="identifier"><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" id="identifier" name="identifier" value="<?= e($_POST['identifier'] ?? '') ?>" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password"><i class="fas fa-lock"></i> Password</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <div class="form-actions">
                    <a href="forgot-password.php" class="link">Forgot password?</a>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-block">Sign In</button>
        </form>

        <p class="auth-footer">
            <?php if ($mode !== 'student'): ?>
                Officially enrolled on the current semester? <a href="login.php?mode=student">Sign in with Student ID</a><br>
                Don't have an account? <a href="register.php">Register here</a><br>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/index.php">Back to Home</a>
        </p>
    </div>
</div>

<?php if ($mode === 'student'): ?>
<div class="admin-form-modal auth-student-info-modal" id="studentLoginInfoModal" aria-hidden="true">
    <div class="admin-form-modal-overlay" data-close-student-login-info></div>
    <div class="admin-form-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="studentLoginInfoTitle">
        <div class="admin-form-modal-header">
            <div>
                <span class="admin-form-modal-eyebrow">Student ID login</span>
                <h2 class="admin-form-modal-title" id="studentLoginInfoTitle">Default login credentials</h2>
            </div>
            <button type="button" class="admin-form-modal-close" data-close-student-login-info aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="admin-form-modal-body">
            <p>For officially enrolled students on the active list, the default login credentials are:</p>
            <ul class="auth-student-info-list">
                <li><strong>Student ID Number</strong> — use this as your username</li>
                <li><strong>Password</strong> — use the same Student ID Number on first sign-in</li>
            </ul>
            <p class="text-muted" style="margin:0">Example format: <code>808401000XXXXX</code></p>
        </div>
        <div class="admin-form-modal-footer">
            <button type="button" class="btn btn-primary" data-close-student-login-info>I understand</button>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('studentLoginInfoModal');
    if (!modal) return;

    function openModal() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('[data-open-student-login-info]').forEach(function (el) {
        el.addEventListener('click', openModal);
    });
    modal.querySelectorAll('[data-close-student-login-info]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });

    openModal();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
