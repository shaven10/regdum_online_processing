<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clearance.php';
requireRole('admin');

$user = currentUser();
$db = getDB();
ensureClearanceSchema();

$editId = (int) ($_GET['edit'] ?? 0);
$search = trim($_GET['search'] ?? '');
$editUser = null;

$roles = $db->query('SELECT * FROM roles WHERE name != "student" ORDER BY id')->fetchAll();
$roleNames = [];
foreach ($roles as $role) {
    $roleNames[(int) $role['id']] = $role['name'];
}

$clearanceRoleId = null;
foreach ($roles as $role) {
    if ($role['name'] === 'clearance_officer') {
        $clearanceRoleId = (int) $role['id'];
        break;
    }
}

$departments = $db->query('SELECT * FROM clearance_departments WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();

function countStaffUserActivity(PDO $db, int $userId): int {
    $tables = [
        'SELECT COUNT(*) FROM requests WHERE assigned_to = ?',
        'SELECT COUNT(*) FROM payments WHERE verified_by = ?',
        'SELECT COUNT(*) FROM request_status_history WHERE changed_by = ?',
        'SELECT COUNT(*) FROM request_clearances WHERE cleared_by = ?',
        'SELECT COUNT(*) FROM request_compliance_summary WHERE verified_by = ?',
        'SELECT COUNT(*) FROM request_assigned_requirements WHERE verified_by = ?',
        'SELECT COUNT(*) FROM request_compliance WHERE verified_by = ?',
    ];

    $total = 0;
    foreach ($tables as $sql) {
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);
        $total += (int) $stmt->fetchColumn();
    }

    return $total;
}

function isLastActiveAdmin(PDO $db, int $userId): bool {
    $stmt = $db->prepare('SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?');
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() !== 'admin') {
        return false;
    }

    $count = (int) $db->query("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'admin' AND u.is_active = 1")->fetchColumn();
    return $count <= 1;
}

if ($editId) {
    $stmt = $db->prepare('SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ? AND r.name != "student"');
    $stmt->execute([$editId]);
    $editUser = $stmt->fetch();
    if (!$editUser) {
        setFlash('error', 'User account not found.');
        redirect(APP_URL . '/admin/users.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $roleId = (int) ($_POST['role_id'] ?? 2);
        $firstName = normalizePersonName($_POST['first_name'] ?? '');
        $lastName = normalizePersonName($_POST['last_name'] ?? '');
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $clearanceDeptId = (int) ($_POST['clearance_department_id'] ?? 0) ?: null;

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
        if ($action === 'create' && $password === '') {
            $errors[] = 'Password is required for new accounts.';
        }
        if ($password !== '' && strlen($password) < PASSWORD_MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        }
        if (!isset($roleNames[$roleId])) {
            $errors[] = 'Invalid role selected.';
        }
        if (($roleNames[$roleId] ?? '') === 'clearance_officer' && !$clearanceDeptId) {
            $errors[] = 'Clearance department is required for clearance officers.';
        }
        if (($roleNames[$roleId] ?? '') !== 'clearance_officer') {
            $clearanceDeptId = null;
        }

        if ($action === 'update' && !(int) ($_POST['user_id'] ?? 0)) {
            $errors[] = 'User record could not be identified. Please try again.';
        }

        $targetUserId = $action === 'update' ? (int) ($_POST['user_id'] ?? 0) : 0;
        if ($action === 'update' && $targetUserId === $user['id'] && !$isActive) {
            $errors[] = 'You cannot deactivate your own account.';
        }
        if ($action === 'update' && $targetUserId && isLastActiveAdmin($db, $targetUserId) && (!$isActive || ($roleNames[$roleId] ?? '') !== 'admin')) {
            $errors[] = 'Cannot change or deactivate the last active admin account.';
        }

        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    $db->prepare('INSERT INTO users (role_id, clearance_department_id, email, password, first_name, last_name, is_active, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, 1)')
                       ->execute([$roleId, $clearanceDeptId, $email, $hash, $firstName, $lastName, $isActive]);
                    $newId = (int) $db->lastInsertId();
                    auditLog('create_user', 'users', $newId);
                    setFlash('success', 'User account created.');
                } else {
                    $params = [$roleId, $clearanceDeptId, $email, $firstName, $lastName, $isActive];
                    $sql = 'UPDATE users SET role_id = ?, clearance_department_id = ?, email = ?, first_name = ?, last_name = ?, is_active = ?';
                    if ($password !== '') {
                        $sql .= ', password = ?';
                        $params[] = password_hash($password, PASSWORD_BCRYPT);
                    }
                    $sql .= ' WHERE id = ?';
                    $params[] = $targetUserId;
                    $db->prepare($sql)->execute($params);
                    auditLog('update_user', 'users', $targetUserId);
                    setFlash('success', 'User account updated.');
                }
            } catch (PDOException $e) {
                setFlash('error', str_contains($e->getMessage(), 'Duplicate') ? 'Email already exists.' : 'Unable to save user account.');
            }
        } else {
            setFlash('error', implode(' ', $errors));
        }

        redirect(APP_URL . '/admin/users.php' . ($action === 'update' && $targetUserId ? '?edit=' . $targetUserId : ''));
    }

    if ($action === 'toggle') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($userId === $user['id']) {
            setFlash('error', 'You cannot deactivate your own account.');
        } elseif ($userId && isLastActiveAdmin($db, $userId)) {
            setFlash('error', 'Cannot deactivate the last active admin account.');
        } else {
            $db->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ? AND id != ?')->execute([$userId, $user['id']]);
            auditLog('toggle_user_status', 'users', $userId);
            setFlash('success', 'User status updated.');
        }
        redirect(APP_URL . '/admin/users.php');
    }

    if ($action === 'delete') {
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId === $user['id']) {
            setFlash('error', 'You cannot delete your own account.');
        } elseif ($userId && isLastActiveAdmin($db, $userId)) {
            setFlash('error', 'Cannot delete the last active admin account.');
        } elseif ($userId) {
            $activity = countStaffUserActivity($db, $userId);
            if ($activity > 0) {
                $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$userId]);
                auditLog('deactivate_user', 'users', $userId);
                setFlash('warning', 'User has system activity and was deactivated instead of deleted.');
            } else {
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
                auditLog('delete_user', 'users', $userId);
                setFlash('success', 'User account deleted.');
            }
        }

        redirect(APP_URL . '/admin/users.php');
    }
}

$where = ['r.name != "student"'];
$params = [];
if ($search) {
    $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR r.name LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
$whereClause = implode(' AND ', $where);

$stmt = $db->prepare("SELECT u.*, r.name as role_name, cd.name as department_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN clearance_departments cd ON u.clearance_department_id = cd.id
    WHERE $whereClause
    ORDER BY u.created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();

$userActivity = [];
foreach ($users as $account) {
    $userActivity[(int) $account['id']] = countStaffUserActivity($db, (int) $account['id']);
}

$pageTitle = 'User Management';
$activeNav = 'users';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="settings-list-page">
    <div class="card users-list-card">
        <div class="card-header">
            <h2>Staff & Admin Accounts</h2>
            <button type="button" class="btn btn-primary btn-sm" data-open-admin-form="create">
                <i class="fas fa-user-plus"></i> Create User
            </button>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar users-filter-bar">
                <input type="text" name="search" placeholder="Search name, email, or role..." value="<?= e($search) ?>">
                <button type="submit" class="btn btn-outline btn-sm">Search</button>
                <?php if ($search): ?>
                    <a href="users.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </form>

            <?php if (empty($users)): ?>
                <div class="empty-state"><i class="fas fa-users"></i><p>No user accounts found.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-responsive users-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $account): ?>
                            <?php $isSelf = (int) $account['id'] === (int) $user['id']; ?>
                            <tr>
                                <td data-label="Name">
                                    <strong><?= e($account['first_name'] . ' ' . $account['last_name']) ?></strong>
                                    <?php if ($isSelf): ?>
                                        <br><small class="text-muted">You</small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Email"><?= e($account['email']) ?></td>
                                <td data-label="Role">
                                    <?= ucfirst(str_replace('_', ' ', $account['role_name'])) ?>
                                    <?php if ($account['department_name']): ?>
                                        <br><small class="text-muted"><?= e($account['department_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status">
                                    <?= $account['is_active'] ? '<span class="badge badge-completed">Active</span>' : '<span class="badge badge-rejected">Inactive</span>' ?>
                                </td>
                                <td data-label="Last Login"><?= $account['last_login'] ? formatDateTime($account['last_login']) : '—' ?></td>
                                <td data-label="Actions" class="action-cell">
                                    <div class="action-cell-buttons">
                                        <button type="button" <?= adminSettingsIconBtnAttrs('edit') ?> data-admin-form-edit="<?= adminFormRecordAttr([
                                            'user_id' => (int) $account['id'],
                                            'first_name' => $account['first_name'],
                                            'last_name' => $account['last_name'],
                                            'email' => $account['email'],
                                            'role_id' => (int) $account['role_id'],
                                            'clearance_department_id' => $account['clearance_department_id'] ?? '',
                                            'is_active' => (int) $account['is_active'],
                                            'is_self' => $isSelf,
                                        ]) ?>"><?= adminSettingsIconBtnContent('edit') ?></button>
                                        <?php if (!$isSelf): ?>
                                        <form method="POST">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="user_id" value="<?= $account['id'] ?>">
                                            <?php $toggleAction = $account['is_active'] ? 'deactivate' : 'activate'; ?>
                                            <button type="submit" <?= adminSettingsIconBtnAttrs($toggleAction) ?>><?= adminSettingsIconBtnContent($toggleAction) ?></button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('<?= ($userActivity[(int) $account['id']] ?? 0) > 0 ? 'This user has system activity and will be deactivated only. Continue?' : 'Delete this user account permanently?' ?>')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= $account['id'] ?>">
                                            <button type="submit" <?= adminSettingsIconBtnAttrs('delete', 'danger') ?>><?= adminSettingsIconBtnContent('delete') ?></button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php renderAdminFormModalOpen('User Management', 'Create Staff/Admin User'); ?>
<form method="POST" class="form-grid users-form" id="userAccountForm" data-admin-form
    data-create-title="Create Staff/Admin User"
    data-update-title="Update User Account"
    data-create-submit-label="Create Account"
    data-update-submit-label="Update Account"
    data-create-submit-icon="fa-user-plus"
    data-update-submit-icon="fa-save"
    data-id-field="user_id">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="user_id" value="">

    <div class="form-row">
        <div class="form-group">
            <label for="first_name">First Name *</label>
            <input type="text" id="first_name" name="first_name" class="input-uppercase" autocapitalize="characters" required maxlength="100">
        </div>
        <div class="form-group">
            <label for="last_name">Last Name *</label>
            <input type="text" id="last_name" name="last_name" class="input-uppercase" autocapitalize="characters" required maxlength="100">
        </div>
    </div>

    <div class="form-group">
        <label for="email">Email *</label>
        <input type="email" id="email" name="email" required maxlength="255">
    </div>

    <div class="form-group">
        <label for="password">Password <span data-admin-form-password-hint>(required)</span></label>
        <input type="password" id="password" name="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
        <small class="text-muted" data-admin-form-password-note>Minimum <?= PASSWORD_MIN_LENGTH ?> characters.</small>
    </div>

    <div class="form-group">
        <label for="role_id">Role *</label>
        <select id="role_id" name="role_id" required data-clearance-role-id="<?= (int) $clearanceRoleId ?>">
            <?php foreach ($roles as $role): ?>
                <option value="<?= $role['id'] ?>"><?= ucfirst(str_replace('_', ' ', $role['name'])) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group" id="clearanceDepartmentGroup" hidden>
        <label for="clearance_department_id">Clearance Department *</label>
        <select id="clearance_department_id" name="clearance_department_id">
            <option value="">— Select Department —</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?= $dept['id'] ?>"><?= e($dept['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="form-group">
        <label class="checkbox-label">
            <input type="checkbox" name="is_active" value="1" data-default-checked>
            Active account
        </label>
        <input type="hidden" name="is_active" value="1" disabled>
        <small class="text-muted" data-admin-form-self-notice hidden>You cannot deactivate your own account.</small>
    </div>

    <?php renderAdminFormModalFooter('Create Account', 'fa-user-plus'); ?>
</form>
<?php renderAdminFormModalClose(); ?>

<?php if ($editUser): ?>
<script>window.__ADMIN_FORM_EDIT__ = <?= json_encode([
    'user_id' => (int) $editUser['id'],
    'first_name' => $editUser['first_name'],
    'last_name' => $editUser['last_name'],
    'email' => $editUser['email'],
    'role_id' => (int) $editUser['role_id'],
    'clearance_department_id' => $editUser['clearance_department_id'] ?? '',
    'is_active' => (int) $editUser['is_active'],
    'is_self' => (int) $editUser['id'] === (int) $user['id'],
], JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('userAccountForm');
    const roleSelect = document.getElementById('role_id');
    const deptGroup = document.getElementById('clearanceDepartmentGroup');
    const deptSelect = document.getElementById('clearance_department_id');
    const passwordHint = form ? form.querySelector('[data-admin-form-password-hint]') : null;
    const passwordNote = form ? form.querySelector('[data-admin-form-password-note]') : null;

    function syncClearanceDepartment() {
        if (!roleSelect || !deptGroup) return;
        const clearanceRoleId = roleSelect.dataset.clearanceRoleId;
        const show = clearanceRoleId && String(roleSelect.value) === clearanceRoleId;
        deptGroup.hidden = !show;
        if (deptSelect) {
            deptSelect.required = show;
            if (!show) deptSelect.value = '';
        }
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', syncClearanceDepartment);
    }

    if (form) {
        form.addEventListener('adminformpopulated', function (e) {
            syncClearanceDepartment();
            const isUpdate = e.detail.mode === 'update';
            if (passwordHint) passwordHint.textContent = isUpdate ? '(optional)' : '(required)';
            if (passwordNote) passwordNote.textContent = isUpdate
                ? 'Leave blank to keep the current password.'
                : 'Minimum <?= PASSWORD_MIN_LENGTH ?> characters.';
        });
    }

    syncClearanceDepartment();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
