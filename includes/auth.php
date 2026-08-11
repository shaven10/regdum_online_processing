<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/functions.php';

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        setFlash('warning', 'Please log in to continue.');
        redirect(APP_URL . '/auth/login.php');
    }
    checkSessionTimeout();
}

function checkSessionTimeout(): void {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
        logout();
        setFlash('warning', 'Your session has expired. Please log in again.');
        redirect(APP_URL . '/auth/login.php');
    }
    $_SESSION['last_activity'] = time();
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $db = getDB();
        $stmt = $db->prepare('SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ? AND u.is_active = 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) logout();
    }
    return $user;
}

function hasRole(string ...$roles): bool {
    $user = currentUser();
    if (!$user) return false;
    return in_array($user['role_name'], $roles);
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!hasRole(...$roles)) {
        setFlash('error', 'You do not have permission to access this page.');
        redirect(dashboardUrl());
    }
}

function dashboardUrl(): string {
    $user = currentUser();
    if (!$user) return APP_URL . '/auth/login.php';
    return match ($user['role_name']) {
        'admin'              => APP_URL . '/admin/dashboard.php',
        'cashier'            => APP_URL . '/cashier/dashboard.php',
        'accounting'         => APP_URL . '/accounting/dashboard.php',
        'registrar'          => APP_URL . '/registrar/dashboard.php',
        'clearance_officer'  => APP_URL . '/clearance/dashboard.php',
        'staff'              => APP_URL . '/staff/dashboard.php',
        default              => APP_URL . '/student/dashboard.php',
    };
}

function login(string $email, string $password): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.email = ? AND u.is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role_name'];
        $_SESSION['last_activity'] = time();

        $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
        auditLog('login', 'users', $user['id']);
        return true;
    }
    return false;
}

function logout(): void {
    if (isLoggedIn()) {
        auditLog('logout', 'users', $_SESSION['user_id']);
    }
    session_unset();
    session_destroy();
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function register(array $data): int|false {
    $db = getDB();
    ensurePrivacyConsentSchema();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? OR student_id = ?');
    $stmt->execute([$data['email'], $data['student_id'] ?? '']);
    if ($stmt->fetch()) return false;

    $hash = password_hash($data['password'], PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (role_id, email, password, student_id, first_name, last_name, middle_name, phone, privacy_consent_at) VALUES (1, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        $data['email'],
        $hash,
        $data['student_id'] ?? null,
        $data['first_name'],
        $data['last_name'],
        $data['middle_name'] ?? null,
        $data['phone'] ?? null,
    ]);
    $userId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO student_profiles (user_id) VALUES (?)')->execute([$userId]);
    auditLog('register', 'users', $userId, null, ['privacy_consent' => true]);
    return $userId;
}

function ensurePrivacyConsentSchema(): void {
    $db = getDB();
    $exists = $db->query("SHOW COLUMNS FROM users LIKE 'privacy_consent_at'")->fetch();
    if (!$exists) {
        $db->exec('ALTER TABLE users ADD COLUMN privacy_consent_at DATETIME NULL AFTER last_login');
    }
}

function dataPrivacyConsentText(): string {
    return 'By creating an account with ' . APP_NAME . ', you acknowledge and consent to the collection, use, storage, and processing of your personal information—including your name, student ID, contact details, uploaded documents, and transaction records—for legitimate registrar-related purposes such as identity verification, document request processing, payment confirmation, and official communications.

Your information will be accessed only by authorized personnel and will be protected using appropriate organizational and technical safeguards. You may request access to or correction of your personal data in accordance with applicable data privacy laws.

Registration cannot proceed unless you agree to this Data Privacy Consent.';
}

function generateResetToken(string $email): ?string {
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) return null;

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $db->prepare('UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?')
       ->execute([$token, $expires, $user['id']]);
    return $token;
}

function resetPassword(string $token, string $password): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()');
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) return false;

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?')
       ->execute([$hash, $user['id']]);
    auditLog('password_reset', 'users', $user['id']);
    return true;
}

function auditLog(string $action, ?string $entityType = null, ?int $entityId = null, ?array $oldValues = null, ?array $newValues = null): void {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        $entityType,
        $entityId,
        $oldValues ? json_encode($oldValues) : null,
        $newValues ? json_encode($newValues) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}
