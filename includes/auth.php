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

function authStudentRoleId(): int {
    static $id = null;
    if ($id === null) {
        $id = (int) getDB()->query("SELECT id FROM roles WHERE name = 'student' LIMIT 1")->fetchColumn();
        if ($id <= 0) {
            $id = 1;
        }
    }
    return $id;
}

function normalizeLoginStudentId(string $studentId): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $studentId) ?? '');
}

function studentDefaultPassword(string $studentId): string {
    $password = trim($studentId);
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $password = str_pad($password, PASSWORD_MIN_LENGTH, 'X');
    }
    return $password;
}

function findActiveEnrolledStudentByStudentId(string $studentId): ?array {
    $studentId = trim($studentId);
    if ($studentId === '') {
        return null;
    }

    $normalized = normalizeLoginStudentId($studentId);
    $db = getDB();
    $stmt = $db->prepare('SELECT u.*, r.name AS role_name, sp.enrollment_status
        FROM users u
        JOIN roles r ON r.id = u.role_id
        JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.role_id = ?
          AND u.is_active = 1
          AND sp.enrollment_status = ?
          AND u.student_id IS NOT NULL
          AND u.student_id != ""
          AND (
              u.student_id = ?
              OR REPLACE(REPLACE(REPLACE(UPPER(u.student_id), "-", ""), " ", ""), "/", "") = ?
          )
        LIMIT 1');
    $stmt->execute([authStudentRoleId(), 'enrolled', $studentId, $normalized]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function establishUserSession(array $user): void {
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['role'] = (string) $user['role_name'];
    $_SESSION['last_activity'] = time();

    getDB()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([(int) $user['id']]);
    auditLog('login', 'users', (int) $user['id']);
}

function loginByEmail(string $email, string $password): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT u.*, r.name as role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.email = ? AND u.is_active = 1');
    $stmt->execute([trim($email)]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        establishUserSession($user);
        return true;
    }
    return false;
}

function loginByStudentId(string $studentId, string $password): bool {
    $user = findActiveEnrolledStudentByStudentId($studentId);
    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    establishUserSession($user);
    return true;
}

function loginWithCredentials(string $identifier, string $password): bool {
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return false;
    }

    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        return loginByEmail($identifier, $password);
    }

    return loginByStudentId($identifier, $password);
}

function signupActiveStudentById(string $studentId, bool $privacyConsent): array {
    if (!$privacyConsent) {
        return ['ok' => false, 'error' => 'You must accept the Data Privacy Consent to continue.'];
    }

    $student = findActiveEnrolledStudentByStudentId($studentId);
    if (!$student) {
        return ['ok' => false, 'error' => 'Student ID not found in the active enrollment list. Please contact the registrar office.'];
    }

    $defaultPassword = studentDefaultPassword((string) ($student['student_id'] ?? $studentId));
    if (!password_verify($defaultPassword, $student['password'])) {
        return ['ok' => false, 'error' => 'This Student ID already has a customized password. Please sign in using the Student ID login option.'];
    }

    ensurePrivacyConsentSchema();
    getDB()->prepare('UPDATE users SET privacy_consent_at = NOW() WHERE id = ? AND privacy_consent_at IS NULL')
        ->execute([(int) $student['id']]);

    establishUserSession($student);
    auditLog('student_id_signup', 'users', (int) $student['id']);

    return ['ok' => true];
}

function login(string $email, string $password): bool {
    return loginWithCredentials($email, $password);
}

function logout(): void {
    if (isLoggedIn()) {
        try {
            auditLog('logout', 'users', (int) $_SESSION['user_id']);
        } catch (Throwable $e) {
            // Never block sign-out if audit logging fails.
        }
    }
    session_unset();
    session_destroy();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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

function ensureAuditLogsSchema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(50) NULL,
        entity_id INT UNSIGNED NULL,
        old_values JSON NULL,
        new_values JSON NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Older installs marked these columns NOT NULL, which breaks logout/login audits.
    try {
        $db->exec('ALTER TABLE audit_logs
            MODIFY old_values LONGTEXT NULL,
            MODIFY new_values LONGTEXT NULL,
            MODIFY ip_address VARCHAR(45) NULL,
            MODIFY user_agent TEXT NULL');
    } catch (Throwable $e) {
        // Ignore if privileges or dialect differ; insert path below remains defensive.
    }
}

function auditLog(string $action, ?string $entityType = null, ?int $entityId = null, ?array $oldValues = null, ?array $newValues = null): void {
    ensureAuditLogsSchema();
    $db = getDB();

    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    if ($userId !== null && $userId > 0) {
        $exists = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
        $exists->execute([$userId]);
        if (!$exists->fetchColumn()) {
            $userId = null;
        }
    } else {
        $userId = null;
    }

    $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        $oldValues !== null ? json_encode($oldValues) : null,
        $newValues !== null ? json_encode($newValues) : null,
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
