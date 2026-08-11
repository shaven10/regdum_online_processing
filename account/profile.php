<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clearance.php';
requireRole('admin', 'registrar', 'staff', 'cashier', 'accounting', 'clearance_officer');

$user = currentUser();
$db = getDB();
ensureClearanceSchema();
$pageTitle = 'My Profile';
$activeNav = 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $firstName = normalizePersonName($_POST['first_name'] ?? '');
        $lastName = normalizePersonName($_POST['last_name'] ?? '');
        $middleName = normalizePersonName($_POST['middle_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        $errors = [];
        if ($firstName === '') {
            $errors[] = 'First name is required.';
        }
        if ($lastName === '') {
            $errors[] = 'Last name is required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }

        if (empty($errors)) {
            $dup = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
            $dup->execute([$email, $user['id']]);
            if ($dup->fetch()) {
                $errors[] = 'That email is already used by another account.';
            }
        }

        if (!empty($errors)) {
            setFlash('error', implode(' ', $errors), ['title' => 'Profile Not Updated']);
        } else {
            try {
                $db->prepare('UPDATE users SET first_name = ?, last_name = ?, middle_name = ?, email = ?, phone = ? WHERE id = ?')
                   ->execute([
                       $firstName,
                       $lastName,
                       $middleName !== '' ? $middleName : null,
                       $email,
                       $phone !== '' ? $phone : null,
                       $user['id'],
                   ]);
                auditLog('staff_profile_update', 'users', (int) $user['id']);
                setFlash('success', 'Your profile has been updated.', [
                    'title' => 'Profile Updated',
                    'context' => [
                        'Name' => trim($firstName . ($middleName !== '' ? ' ' . $middleName : '') . ' ' . $lastName),
                        'Email' => $email,
                    ],
                ]);
            } catch (PDOException $e) {
                setFlash('error', str_contains($e->getMessage(), 'Duplicate')
                    ? 'That email is already used by another account.'
                    : 'Unable to update profile.', [
                    'title' => 'Profile Not Updated',
                ]);
            }
        }

        redirect(APP_URL . '/account/profile.php');
    }

    if ($action === 'update_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $storedHash = (string) ($stmt->fetchColumn() ?: '');

        $errors = [];
        if ($currentPassword === '' || !password_verify($currentPassword, $storedHash)) {
            $errors[] = 'Current password is incorrect.';
        }
        if (strlen($newPassword) < PASSWORD_MIN_LENGTH) {
            $errors[] = 'New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password confirmation does not match.';
        }
        if ($newPassword !== '' && $currentPassword !== '' && hash_equals($currentPassword, $newPassword)) {
            $errors[] = 'New password must be different from your current password.';
        }

        if (!empty($errors)) {
            setFlash('error', implode(' ', $errors), ['title' => 'Password Not Updated']);
        } else {
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $db->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?')
               ->execute([$hash, $user['id']]);
            auditLog('staff_password_update', 'users', (int) $user['id']);
            setFlash('success', 'Your password has been updated.', [
                'title' => 'Password Updated',
                'next_step' => 'Use your new password the next time you sign in.',
            ]);
        }

        redirect(APP_URL . '/account/profile.php');
    }

    setFlash('error', 'Unknown action.', ['title' => 'Action Failed']);
    redirect(APP_URL . '/account/profile.php');
}

// Fresh profile data after possible updates
$stmt = $db->prepare('SELECT u.*, r.name AS role_name, cd.name AS department_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN clearance_departments cd ON u.clearance_department_id = cd.id
    WHERE u.id = ?');
$stmt->execute([$user['id']]);
$profile = $stmt->fetch() ?: $user;

$assignedProgram = null;
if (!empty($profile['clearance_program_id'])) {
    require_once __DIR__ . '/../includes/programs.php';
    $assignedProgram = getAcademicProgramById((int) $profile['clearance_program_id']);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="grid-2 account-profile-layout">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Profile Information</h2>
                <p class="text-muted" style="margin:.35rem 0 0">Update your name, email, and contact details.</p>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" class="form-grid">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_profile">

                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" class="input-uppercase" autocapitalize="characters"
                            value="<?= e($profile['first_name'] ?? '') ?>" required maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" class="input-uppercase" autocapitalize="characters"
                            value="<?= e($profile['last_name'] ?? '') ?>" required maxlength="100">
                    </div>
                </div>

                <div class="form-group">
                    <label for="middle_name">Middle Name</label>
                    <input type="text" id="middle_name" name="middle_name" class="input-uppercase" autocapitalize="characters"
                        value="<?= e($profile['middle_name'] ?? '') ?>" maxlength="100">
                </div>

                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" value="<?= e($profile['email'] ?? '') ?>" required maxlength="255">
                </div>

                <div class="form-group">
                    <label for="phone">Phone</label>
                    <input type="text" id="phone" name="phone" value="<?= e($profile['phone'] ?? '') ?>" maxlength="20" placeholder="Optional contact number">
                </div>

                <div class="detail-grid account-profile-meta">
                    <div class="detail-item">
                        <label>Role</label>
                        <span><?= e(ucwords(str_replace('_', ' ', (string) ($profile['role_name'] ?? '')))) ?></span>
                    </div>
                    <?php if (!empty($profile['department_name'])): ?>
                        <div class="detail-item">
                            <label>Department</label>
                            <span><?= e($profile['department_name']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($assignedProgram): ?>
                        <div class="detail-item">
                            <label>Assigned Course</label>
                            <span><?= e($assignedProgram['code'] . ' — ' . $assignedProgram['name']) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="detail-item">
                        <label>Last Login</label>
                        <span><?= !empty($profile['last_login']) ? e(formatDateTime($profile['last_login'])) : '—' ?></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Profile
                </button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Update Password</h2>
                <p class="text-muted" style="margin:.35rem 0 0">Choose a new password for your account.</p>
            </div>
        </div>
        <div class="card-body">
            <form method="POST" class="form-grid" id="accountPasswordForm" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_password">

                <div class="form-group">
                    <label for="current_password">Current Password *</label>
                    <div class="password-input-wrap">
                        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                        <button type="button" class="password-toggle-btn" data-toggle-password="current_password" aria-label="Show password" title="Show password" aria-pressed="false">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password *</label>
                    <div class="password-input-wrap">
                        <input type="password" id="new_password" name="new_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                        <button type="button" class="password-toggle-btn" data-toggle-password="new_password" aria-label="Show password" title="Show password" aria-pressed="false">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                    <small class="text-muted">Minimum <?= PASSWORD_MIN_LENGTH ?> characters.</small>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password *</label>
                    <div class="password-input-wrap">
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
                        <button type="button" class="password-toggle-btn" data-toggle-password="confirm_password" aria-label="Show password" title="Show password" aria-pressed="false">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-key"></i> Update Password
                </button>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const inputId = btn.getAttribute('data-toggle-password');
            const input = document.getElementById(inputId);
            if (!input) return;
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            const icon = btn.querySelector('i');
            if (icon) icon.className = visible ? 'fas fa-eye-slash' : 'fas fa-eye';
            btn.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            btn.setAttribute('title', visible ? 'Hide password' : 'Show password');
            btn.setAttribute('aria-pressed', visible ? 'true' : 'false');
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
