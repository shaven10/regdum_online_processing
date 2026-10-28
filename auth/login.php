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

        <p class="auth-student-info-link">
            <button type="button" class="link-button" data-open-login-instructions>
                <i class="fas fa-circle-info"></i> Login instructions
            </button>
        </p>

        <form method="POST" class="auth-form">
            <?= csrfField() ?>
            <input type="hidden" name="login_mode" value="<?= e($mode) ?>">
            <?php if ($mode === 'student'): ?>
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

<div class="admin-form-modal auth-instructions-modal" id="loginInstructionsModal" aria-hidden="true" data-default-tab="<?= $mode === 'student' ? 'active' : 'alumni' ?>">
    <div class="admin-form-modal-overlay" data-close-login-instructions></div>
    <div class="admin-form-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="loginInstructionsTitle">
        <div class="admin-form-modal-header">
            <div>
                <span class="admin-form-modal-eyebrow">Before you sign in</span>
                <h2 class="admin-form-modal-title" id="loginInstructionsTitle">Login instructions</h2>
            </div>
            <button type="button" class="admin-form-modal-close" data-close-login-instructions aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="admin-form-modal-body">
            <div class="auth-instructions-tabs" role="tablist" aria-label="Client instructions">
                <button type="button" class="auth-instructions-tab is-active" role="tab" id="loginInstructionsTabActive" data-instruction-tab="active" aria-selected="true" aria-controls="loginInstructionsActive">Active clients</button>
                <button type="button" class="auth-instructions-tab" role="tab" id="loginInstructionsTabAlumni" data-instruction-tab="alumni" aria-selected="false" aria-controls="loginInstructionsAlumni">Inactive / Graduate</button>
            </div>

            <div class="auth-instructions-panel" id="loginInstructionsActive" role="tabpanel" aria-labelledby="loginInstructionsTabActive" data-instruction-panel="active">
                <p>Use this if you are officially enrolled this semester and your Student ID is on the active enrollment list.</p>
                <ol class="auth-student-info-list">
                    <li>Open the <strong>Student ID</strong> tab on this page.</li>
                    <li>Enter your <strong>Student ID Number</strong> as the username.</li>
                    <li>On first sign-in, the password is the same <strong>Student ID Number</strong>.</li>
                    <li>Read and accept the Data Privacy Consent, then choose <strong>Sign In</strong>.</li>
                    <li>Complete your profile if the system asks for it, then submit your document request.</li>
                </ol>
                <p class="text-muted">Example Student ID: <code>808401000XXXXX</code>. If that ID is not on the active list, follow the Inactive / Graduate instructions or contact the registrar office.</p>
            </div>

            <div class="auth-instructions-panel" id="loginInstructionsAlumni" role="tabpanel" aria-labelledby="loginInstructionsTabAlumni" data-instruction-panel="alumni" hidden>
                <p>Use this if you have already graduated, or if you are no longer enrolled. Student ID login is only for the active enrollment list.</p>
                <ol class="auth-student-info-list">
                    <li>Choose <strong>Register here</strong> and create an account with your name, Student ID, email, and your own password.</li>
                    <li>Return to this page and stay on the <strong>Email</strong> tab.</li>
                    <li>Sign in with the email and password you registered.</li>
                    <li>Open your profile and set your status to <strong>Graduated</strong> or <strong>Inactive</strong>.</li>
                    <li>Graduates enter the course, year graduated, and origin campus. Inactive clients enter the last course, last school year enrolled, and origin campus.</li>
                    <li>After the profile is complete, submit your document request.</li>
                </ol>
                <p class="text-muted">Student ID sign-in will say the ID was not found if you are not on the current active list.</p>
            </div>
        </div>
        <div class="admin-form-modal-footer">
            <button type="button" class="btn btn-primary" data-close-login-instructions>I understand</button>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('loginInstructionsModal');
    if (!modal) return;

    var storageKey = 'regdum-login-instructions-seen';
    var defaultTab = modal.getAttribute('data-default-tab') === 'active' ? 'active' : 'alumni';

    function showTab(name) {
        modal.querySelectorAll('[data-instruction-tab]').forEach(function (tab) {
            var selected = tab.getAttribute('data-instruction-tab') === name;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
        });
        modal.querySelectorAll('[data-instruction-panel]').forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-instruction-panel') !== name;
        });
    }

    function openModal() {
        showTab(defaultTab);
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        try { sessionStorage.setItem(storageKey, '1'); } catch (err) { /* ignore */ }
    }

    document.querySelectorAll('[data-open-login-instructions]').forEach(function (el) {
        el.addEventListener('click', openModal);
    });
    modal.querySelectorAll('[data-instruction-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            showTab(tab.getAttribute('data-instruction-tab'));
        });
    });
    modal.querySelectorAll('[data-close-login-instructions]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });

    var hasError = <?= !empty($errors['general']) ? 'true' : 'false' ?>;
    var seen = false;
    try { seen = sessionStorage.getItem(storageKey) === '1'; } catch (err) { seen = false; }
    if (!hasError && !seen) {
        openModal();
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
