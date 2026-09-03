<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/student.php';
require_once __DIR__ . '/campuses.php';
require_once __DIR__ . '/programs.php';

function ensureExternalApiSchema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db = getDB();
    ensureRequirementDefaultsSchema();

    $db->exec("CREATE TABLE IF NOT EXISTS api_keys (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        description TEXT NULL,
        key_prefix VARCHAR(12) NOT NULL,
        key_hash VARCHAR(255) NOT NULL,
        key_encrypted TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_used_at TIMESTAMP NULL DEFAULT NULL,
        expires_at TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_api_keys_prefix (key_prefix),
        INDEX idx_api_keys_active (is_active),
        CONSTRAINT fk_api_keys_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $encryptedCol = $db->query("SHOW COLUMNS FROM api_keys LIKE 'key_encrypted'")->fetch();
    if (!$encryptedCol) {
        $db->exec('ALTER TABLE api_keys ADD COLUMN key_encrypted TEXT NULL AFTER key_hash');
    }

    if (getAppSetting('external_api_encryption_secret', '') === '') {
        setAppSetting('external_api_encryption_secret', bin2hex(random_bytes(32)));
    }

    if (getAppSetting('external_api_enabled', '') === '') {
        setAppSetting('external_api_enabled', '0');
    }
    if (getAppSetting('external_api_cors_origins', '') === '') {
        setAppSetting('external_api_cors_origins', '');
    }

    $db->exec("CREATE TABLE IF NOT EXISTS api_request_logs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        api_key_id INT UNSIGNED NULL,
        endpoint VARCHAR(120) NOT NULL,
        http_method VARCHAR(10) NOT NULL DEFAULT 'GET',
        query_string TEXT NULL,
        status_code SMALLINT UNSIGNED NOT NULL,
        response_ok TINYINT(1) NOT NULL DEFAULT 0,
        result_count INT UNSIGNED NULL,
        error_message VARCHAR(255) NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        response_time_ms INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_api_request_logs_created (created_at),
        INDEX idx_api_request_logs_key (api_key_id),
        INDEX idx_api_request_logs_status (status_code),
        CONSTRAINT fk_api_request_logs_key FOREIGN KEY (api_key_id) REFERENCES api_keys(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function isExternalApiEnabled(): bool {
    ensureExternalApiSchema();
    return getAppSetting('external_api_enabled', '0') === '1';
}

function getExternalApiCorsOrigins(): array {
    ensureExternalApiSchema();
    $raw = trim(getAppSetting('external_api_cors_origins', ''));
    if ($raw === '' || $raw === '*') {
        return $raw === '*' ? ['*'] : [];
    }

    $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
    $origins = [];
    foreach ($parts as $part) {
        $origin = trim($part);
        if ($origin !== '') {
            $origins[] = $origin;
        }
    }

    return array_values(array_unique($origins));
}

function saveExternalApiSettings(bool $enabled, string $corsOrigins): void {
    ensureExternalApiSchema();
    setAppSetting('external_api_enabled', $enabled ? '1' : '0');
    setAppSetting('external_api_cors_origins', trim($corsOrigins));
}

function generateApiKeyPlaintext(): string {
    return 'rd_' . bin2hex(random_bytes(24));
}

function hashApiKey(string $plaintext): string {
    return password_hash($plaintext, PASSWORD_BCRYPT);
}

function apiKeyPrefix(string $plaintext): string {
    return substr($plaintext, 0, 12);
}

function externalApiEncryptionKey(): string {
    ensureExternalApiSchema();
    $secret = getAppSetting('external_api_encryption_secret', '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        setAppSetting('external_api_encryption_secret', $secret);
    }

    return hash('sha256', $secret, true);
}

function encryptStoredApiKey(string $plaintext): string {
    $key = externalApiEncryptionKey();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Unable to encrypt API key.');
    }

    return base64_encode($iv . $tag . $cipher);
}

function decryptStoredApiKey(string $encrypted): ?string {
    $raw = base64_decode($encrypted, true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plaintext = openssl_decrypt($cipher, 'aes-256-gcm', externalApiEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);

    return $plaintext === false ? null : $plaintext;
}

function formatMaskedApiKey(string $prefix): string {
    $prefix = trim($prefix);
    if ($prefix === '') {
        return str_repeat('•', 12);
    }

    return $prefix . str_repeat('•', max(12, 52 - strlen($prefix)));
}

function createExternalApiKey(string $name, string $description, int $createdBy): array {
    ensureExternalApiSchema();

    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('API key name is required.');
    }

    $plaintext = generateApiKeyPlaintext();
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO api_keys (name, description, key_prefix, key_hash, key_encrypted, created_by)
        VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $name,
        trim($description) !== '' ? trim($description) : null,
        apiKeyPrefix($plaintext),
        hashApiKey($plaintext),
        encryptStoredApiKey($plaintext),
        $createdBy > 0 ? $createdBy : null,
    ]);

    return [
        'id' => (int) $db->lastInsertId(),
        'name' => $name,
        'key' => $plaintext,
        'key_prefix' => apiKeyPrefix($plaintext),
    ];
}

function stashNewExternalApiKeyForDisplay(string $name, string $key): void {
    $_SESSION['external_api_key_display'] = [
        'name' => $name,
        'key' => $key,
        'created_at' => time(),
    ];
}

function pullNewExternalApiKeyForDisplay(): ?array {
    $data = $_SESSION['external_api_key_display'] ?? null;
    if (!is_array($data)) {
        return null;
    }

    if (time() - (int) ($data['created_at'] ?? 0) > 900) {
        unset($_SESSION['external_api_key_display']);
        return null;
    }

    return [
        'name' => (string) ($data['name'] ?? ''),
        'key' => (string) ($data['key'] ?? ''),
    ];
}

function clearNewExternalApiKeyDisplay(): void {
    unset($_SESSION['external_api_key_display']);
}

function listExternalApiKeys(): array {
    ensureExternalApiSchema();
    $db = getDB();
    $stmt = $db->query('SELECT k.*, CONCAT(u.first_name, " ", u.last_name) AS created_by_name
        FROM api_keys k
        LEFT JOIN users u ON u.id = k.created_by
        ORDER BY k.created_at DESC, k.id DESC');

    return $stmt->fetchAll() ?: [];
}

function findExternalApiKeyById(int $id): ?array {
    ensureExternalApiSchema();
    if ($id <= 0) {
        return null;
    }

    $stmt = getDB()->prepare('SELECT * FROM api_keys WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function setExternalApiKeyActive(int $id, bool $active): bool {
    ensureExternalApiSchema();
    $stmt = getDB()->prepare('UPDATE api_keys SET is_active = ? WHERE id = ?');
    $stmt->execute([$active ? 1 : 0, $id]);

    return $stmt->rowCount() > 0;
}

function deleteExternalApiKey(int $id): bool {
    ensureExternalApiSchema();
    $stmt = getDB()->prepare('DELETE FROM api_keys WHERE id = ?');
    $stmt->execute([$id]);

    return $stmt->rowCount() > 0;
}

function getExternalApiKeyForAdminView(int $id): ?array {
    $key = findExternalApiKeyById($id);
    if (!$key) {
        return null;
    }

    $plaintext = null;
    if (!empty($key['key_encrypted'])) {
        $plaintext = decryptStoredApiKey((string) $key['key_encrypted']);
    }

    return [
        'id' => (int) ($key['id'] ?? 0),
        'name' => (string) ($key['name'] ?? ''),
        'description' => (string) ($key['description'] ?? ''),
        'key_prefix' => (string) ($key['key_prefix'] ?? ''),
        'is_active' => !empty($key['is_active']),
        'created_at' => (string) ($key['created_at'] ?? ''),
        'last_used_at' => (string) ($key['last_used_at'] ?? ''),
        'key' => $plaintext,
        'viewable' => is_string($plaintext) && $plaintext !== '',
        'masked_key' => formatMaskedApiKey((string) ($key['key_prefix'] ?? '')),
    ];
}

function regenerateExternalApiKey(int $id, int $adminId): array {
    ensureExternalApiSchema();
    $key = findExternalApiKeyById($id);
    if (!$key) {
        throw new InvalidArgumentException('API key not found.');
    }

    $plaintext = generateApiKeyPlaintext();
    $db = getDB();
    $db->prepare('UPDATE api_keys
        SET key_prefix = ?, key_hash = ?, key_encrypted = ?, last_used_at = NULL
        WHERE id = ?')
       ->execute([
           apiKeyPrefix($plaintext),
           hashApiKey($plaintext),
           encryptStoredApiKey($plaintext),
           $id,
       ]);

    auditLog('regenerate_api_key', 'api_keys', $id, [
        'name' => $key['name'] ?? '',
        'key_prefix' => $key['key_prefix'] ?? '',
    ], [
        'name' => $key['name'] ?? '',
        'key_prefix' => apiKeyPrefix($plaintext),
        'regenerated_by' => $adminId,
    ]);

    return [
        'id' => $id,
        'name' => (string) ($key['name'] ?? ''),
        'key' => $plaintext,
        'key_prefix' => apiKeyPrefix($plaintext),
    ];
}

function extractApiKeyFromRequest(): string {
    $headerKey = trim((string) ($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($headerKey !== '') {
        return $headerKey;
    }

    $auth = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
        return trim($matches[1]);
    }

    return trim((string) ($_GET['api_key'] ?? ''));
}

function authenticateExternalApiRequest(): ?array {
    ensureExternalApiSchema();

    if (!isExternalApiEnabled()) {
        return null;
    }

    $plaintext = extractApiKeyFromRequest();
    if ($plaintext === '' || strlen($plaintext) < 20) {
        return null;
    }

    $prefix = apiKeyPrefix($plaintext);
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM api_keys
        WHERE key_prefix = ? AND is_active = 1
        ORDER BY id DESC');
    $stmt->execute([$prefix]);
    $candidates = $stmt->fetchAll() ?: [];

    foreach ($candidates as $row) {
        if (!password_verify($plaintext, (string) ($row['key_hash'] ?? ''))) {
            continue;
        }

        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $db->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);

        return $row;
    }

    return null;
}

function externalApiClientIp(): string {
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        if (str_contains($value, ',')) {
            $value = trim(explode(',', $value)[0]);
        }
        if ($value !== '') {
            return substr($value, 0, 45);
        }
    }

    return '';
}

function externalApiUserAgent(): string {
    return substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 500);
}

function externalApiSanitizedQueryString(): string {
    $params = $_GET;
    unset($params['api_key']);
    if ($params === []) {
        return '';
    }

    return substr((string) http_build_query($params), 0, 2000);
}

function logExternalApiRequest(array $data): void {
    ensureExternalApiSchema();
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO api_request_logs
        (api_key_id, endpoint, http_method, query_string, status_code, response_ok, result_count, error_message, ip_address, user_agent, response_time_ms)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        isset($data['api_key_id']) && (int) $data['api_key_id'] > 0 ? (int) $data['api_key_id'] : null,
        substr((string) ($data['endpoint'] ?? 'unknown'), 0, 120),
        substr(strtoupper((string) ($data['http_method'] ?? 'GET')), 0, 10),
        ($data['query_string'] ?? null) !== null && $data['query_string'] !== '' ? (string) $data['query_string'] : null,
        max(0, (int) ($data['status_code'] ?? 500)),
        !empty($data['response_ok']) ? 1 : 0,
        isset($data['result_count']) ? max(0, (int) $data['result_count']) : null,
        isset($data['error_message']) && $data['error_message'] !== '' ? substr((string) $data['error_message'], 0, 255) : null,
        ($data['ip_address'] ?? '') !== '' ? substr((string) $data['ip_address'], 0, 45) : null,
        ($data['user_agent'] ?? '') !== '' ? substr((string) $data['user_agent'], 0, 500) : null,
        isset($data['response_time_ms']) ? max(0, (int) $data['response_time_ms']) : null,
    ]);
}

function listExternalApiRequestLogs(array $filters = [], int $page = 1, int $perPage = 20): array {
    ensureExternalApiSchema();
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    $keyId = (int) ($filters['api_key_id'] ?? 0);
    if ($keyId > 0) {
        $where[] = 'l.api_key_id = ?';
        $params[] = $keyId;
    }

    $status = (string) ($filters['status'] ?? '');
    if ($status === 'success') {
        $where[] = 'l.response_ok = 1';
    } elseif ($status === 'error') {
        $where[] = 'l.response_ok = 0';
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $db->prepare("SELECT COUNT(*) FROM api_request_logs l WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $pag = paginate($total, $page, $perPage);

    $stmt = $db->prepare("SELECT l.*, k.name AS api_key_name, k.key_prefix
        FROM api_request_logs l
        LEFT JOIN api_keys k ON k.id = l.api_key_id
        WHERE {$whereSql}
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT " . (int) $pag['per_page'] . ' OFFSET ' . (int) $pag['offset']);
    $stmt->execute($params);

    return [
        'logs' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'page' => (int) $pag['page'],
        'per_page' => (int) $pag['per_page'],
        'total_pages' => (int) $pag['total_pages'],
    ];
}

function getExternalApiRequestLogStats(): array {
    ensureExternalApiSchema();
    $db = getDB();
    $row = $db->query('SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN response_ok = 1 THEN 1 ELSE 0 END) AS success_count,
            SUM(CASE WHEN response_ok = 0 THEN 1 ELSE 0 END) AS error_count,
            SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS last_24h
        FROM api_request_logs')->fetch();

    return [
        'total' => (int) ($row['total'] ?? 0),
        'success_count' => (int) ($row['success_count'] ?? 0),
        'error_count' => (int) ($row['error_count'] ?? 0),
        'last_24h' => (int) ($row['last_24h'] ?? 0),
    ];
}

function dispatchExternalApiGetRequest(string $endpoint, callable $handler): void {
    handleExternalApiPreflight();
    applyExternalApiCorsHeaders();

    $startedAt = microtime(true);
    $logBase = [
        'endpoint' => $endpoint,
        'http_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'query_string' => externalApiSanitizedQueryString(),
        'ip_address' => externalApiClientIp(),
        'user_agent' => externalApiUserAgent(),
    ];

    if (($logBase['http_method'] ?? 'GET') !== 'GET') {
        $logBase['status_code'] = 405;
        $logBase['response_ok'] = false;
        $logBase['error_message'] = 'Method not allowed. Use GET.';
        $logBase['response_time_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        logExternalApiRequest($logBase);
        sendExternalApiJson(['ok' => false, 'error' => $logBase['error_message']], 405);
    }

    if (!isExternalApiEnabled()) {
        $logBase['status_code'] = 503;
        $logBase['response_ok'] = false;
        $logBase['error_message'] = 'External API is disabled.';
        $logBase['response_time_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        logExternalApiRequest($logBase);
        sendExternalApiJson(['ok' => false, 'error' => $logBase['error_message']], 503);
    }

    $apiKey = authenticateExternalApiRequest();
    if (!$apiKey) {
        $logBase['status_code'] = 401;
        $logBase['response_ok'] = false;
        $logBase['error_message'] = 'Invalid or missing API key.';
        $logBase['response_time_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        logExternalApiRequest($logBase);
        sendExternalApiJson(['ok' => false, 'error' => $logBase['error_message']], 401);
    }

    $logBase['api_key_id'] = (int) ($apiKey['id'] ?? 0);
    $result = $handler($apiKey);
    $payload = $result['payload'] ?? ['ok' => false, 'error' => 'Invalid handler response.'];
    $statusCode = (int) ($result['status_code'] ?? 200);
    $responseOk = !empty($payload['ok']);
    $resultCount = isset($result['result_count']) ? (int) $result['result_count'] : null;

    $logBase['status_code'] = $statusCode;
    $logBase['response_ok'] = $responseOk;
    $logBase['result_count'] = $resultCount;
    $logBase['error_message'] = $responseOk ? null : (string) ($payload['error'] ?? 'Request failed.');
    $logBase['response_time_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
    logExternalApiRequest($logBase);

    sendExternalApiJson($payload, $statusCode);
}

function sendExternalApiJson(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function applyExternalApiCorsHeaders(): void {
    $origins = getExternalApiCorsOrigins();
    if ($origins === []) {
        return;
    }

    $requestOrigin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if (in_array('*', $origins, true)) {
        header('Access-Control-Allow-Origin: *');
    } elseif ($requestOrigin !== '' && in_array($requestOrigin, $origins, true)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
    header('Access-Control-Max-Age: 86400');
}

function handleExternalApiPreflight(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'OPTIONS') {
        return;
    }

    applyExternalApiCorsHeaders();
    http_response_code(204);
    exit;
}

function formatExternalApiStudent(array $row): array {
    return [
        'id' => (int) ($row['id'] ?? 0),
        'student_id' => (string) ($row['student_id'] ?? ''),
        'first_name' => (string) ($row['first_name'] ?? ''),
        'last_name' => (string) ($row['last_name'] ?? ''),
        'middle_name' => (string) ($row['middle_name'] ?? ''),
        'full_name' => studentRecordName($row),
        'email' => (string) ($row['email'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'course' => (string) ($row['course'] ?? ''),
        'course_id' => isset($row['course_id']) ? (int) $row['course_id'] : null,
        'program_code' => (string) ($row['program_code'] ?? ''),
        'year_level' => (string) ($row['year_level'] ?? ''),
        'section' => (string) ($row['section'] ?? ''),
        'major' => (string) ($row['major'] ?? ''),
        'sex' => (string) ($row['sex'] ?? ''),
        'enrollment_status' => (string) ($row['enrollment_status'] ?? ''),
        'current_academic_year' => (string) ($row['current_academic_year'] ?? ''),
        'current_semester' => (string) ($row['current_semester'] ?? ''),
        'origin_campus_id' => isset($row['origin_campus_id']) ? (int) $row['origin_campus_id'] : null,
        'origin_campus' => (string) ($row['campus_name'] ?? ''),
        'is_active' => !empty($row['is_active']),
    ];
}

function queryActiveStudentsForExternalApi(array $filters = [], int $page = 1, int $perPage = 50): array {
    ensureExternalApiSchema();
    ensureAcademicProgramsSchema();
    ensureCampusesSchema();

    $search = trim((string) ($filters['search'] ?? ''));
    $studentId = trim((string) ($filters['student_id'] ?? ''));
    $courseId = (int) ($filters['course_id'] ?? 0);
    $yearLevel = trim((string) ($filters['year_level'] ?? ''));
    $academicYear = trim((string) ($filters['academic_year'] ?? ''));
    $semester = trim((string) ($filters['semester'] ?? ''));
    $campusId = (int) ($filters['campus_id'] ?? 0);
    $perPage = max(1, min(100, $perPage));

    $db = getDB();
    $where = [
        'u.role_id = 1',
        'u.is_active = 1',
        "sp.enrollment_status = 'enrolled'",
    ];
    $params = [];

    if ($studentId !== '') {
        $where[] = 'u.student_id = ?';
        $params[] = $studentId;
    }

    if ($search !== '') {
        $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $prefix = $term . '%';
            $where[] = '(u.student_id LIKE ? OR u.student_id LIKE ? OR u.last_name LIKE ? OR u.first_name LIKE ?
                OR u.middle_name LIKE ? OR u.email LIKE ?
                OR CONCAT(u.last_name, ", ", u.first_name) LIKE ?
                OR CONCAT(u.first_name, " ", u.last_name) LIKE ?
                OR sp.course LIKE ?)';
            array_push($params, $prefix, $like, $prefix, $prefix, $like, $like, $like, $like, $like);
        }
    }

    if ($courseId > 0) {
        $where[] = 'sp.course_id = ?';
        $params[] = $courseId;
    }

    $yearOptions = yearLevelOptions();
    if ($yearLevel !== '' && isset($yearOptions[$yearLevel])) {
        $where[] = 'sp.year_level = ?';
        $params[] = $yearLevel;
    }

    if ($academicYear !== '') {
        $where[] = 'sp.current_academic_year = ?';
        $params[] = $academicYear;
    }

    $semesterOptions = semesterOptions();
    if ($semester !== '' && isset($semesterOptions[$semester])) {
        $where[] = 'sp.current_semester = ?';
        $params[] = $semester;
    }

    if ($campusId > 0) {
        $where[] = 'sp.origin_campus_id = ?';
        $params[] = $campusId;
    }

    $from = ' FROM users u
        JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN academic_programs ap ON ap.id = sp.course_id
        LEFT JOIN campuses c ON c.id = sp.origin_campus_id
        WHERE ' . implode(' AND ', $where);

    $countStmt = $db->prepare('SELECT COUNT(*)' . $from);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $pag = paginate($total, $page, $perPage);

    $stmt = $db->prepare('SELECT u.id, u.student_id, u.first_name, u.last_name, u.middle_name, u.email, u.phone, u.is_active,
            sp.course, sp.course_id, sp.year_level, sp.section, sp.major, sp.sex, sp.enrollment_status,
            sp.current_academic_year, sp.current_semester, sp.origin_campus_id,
            ap.code AS program_code, c.name AS campus_name' . $from . '
        ORDER BY u.last_name, u.first_name, u.middle_name, u.id
        LIMIT ' . (int) $pag['per_page'] . ' OFFSET ' . (int) $pag['offset']);
    $stmt->execute($params);

    return [
        'students' => $stmt->fetchAll() ?: [],
        'total' => $total,
        'page' => (int) $pag['page'],
        'per_page' => (int) $pag['per_page'],
        'total_pages' => (int) $pag['total_pages'],
    ];
}

function externalApiBaseUrl(): string {
    return rtrim(APP_URL, '/') . '/api/v1';
}

function externalApiActiveStudentsUrl(): string {
    return externalApiBaseUrl() . '/active-students.php';
}

function buildExternalApiDocumentationMarkdown(): string {
    ensureExternalApiSchema();
    ensureAcademicProgramsSchema();
    ensureCampusesSchema();

    $apiBaseUrl = externalApiBaseUrl();
    $endpointUrl = externalApiActiveStudentsUrl();
    $yearLevels = yearLevelOptions();
    $semesters = semesterOptions();
    $programs = getActiveAcademicPrograms();
    $campuses = getActiveCampuses();
    $generatedAt = date('Y-m-d H:i:s T');

    $lines = [];
    $lines[] = '# Active Students API Documentation';
    $lines[] = '';
    $lines[] = '**System:** ' . APP_NAME . ' — ' . APP_SYSTEM_NAME;
    $lines[] = '**Generated:** ' . $generatedAt;
    $lines[] = '';
    $lines[] = '## Overview';
    $lines[] = '';
    $lines[] = 'The Active Students API allows trusted external web applications to read enrolled student records.';
    $lines[] = 'Only students with an active account (`is_active = 1`) and enrollment status `enrolled` are returned.';
    $lines[] = 'Passwords and uploaded ID documents are never included in responses.';
    $lines[] = '';
    $lines[] = '## Base URL';
    $lines[] = '';
    $lines[] = '```';
    $lines[] = $apiBaseUrl;
    $lines[] = '```';
    $lines[] = '';
    $lines[] = '## Authentication';
    $lines[] = '';
    $lines[] = 'Every request must include a valid API key issued from the admin External API settings page.';
    $lines[] = '';
    $lines[] = 'Supported methods:';
    $lines[] = '';
    $lines[] = '- `X-API-Key: YOUR_API_KEY` header (recommended)';
    $lines[] = '- `Authorization: Bearer YOUR_API_KEY` header';
    $lines[] = '';
    $lines[] = '## Endpoint: List Active Students';
    $lines[] = '';
    $lines[] = '```http';
    $lines[] = 'GET ' . $endpointUrl;
    $lines[] = '```';
    $lines[] = '';
    $lines[] = '### Query parameters';
    $lines[] = '';
    $lines[] = '| Parameter | Type | Description |';
    $lines[] = '|-----------|------|-------------|';
    $lines[] = '| `page` | integer | Page number (default: 1) |';
    $lines[] = '| `per_page` | integer | Results per page, max 100 (default: 50) |';
    $lines[] = '| `search` | string | Search by name, student ID, email, or course |';
    $lines[] = '| `student_id` | string | Exact student ID match |';
    $lines[] = '| `course_id` | integer | Filter by academic program ID |';
    $lines[] = '| `year_level` | string | Filter by year level |';
    $lines[] = '| `academic_year` | string | Filter by current academic year |';
    $lines[] = '| `semester` | string | Filter by current semester |';
    $lines[] = '| `campus_id` | integer | Filter by origin campus ID |';
    $lines[] = '';
    $lines[] = '### Filter reference values';
    $lines[] = '';
    $lines[] = '#### Year levels';
    $lines[] = '';
    foreach ($yearLevels as $value => $label) {
        $lines[] = '- `' . $value . '` — ' . $label;
    }
    $lines[] = '';
    $lines[] = '#### Semesters';
    $lines[] = '';
    foreach ($semesters as $value => $label) {
        $lines[] = '- `' . $value . '` — ' . $label;
    }
    $lines[] = '';
    $lines[] = '#### Programs (`course_id`)';
    $lines[] = '';
    foreach ($programs as $program) {
        $lines[] = '- `' . (int) ($program['id'] ?? 0) . '` — ' . (string) ($program['name'] ?? '');
    }
    $lines[] = '';
    $lines[] = '#### Campuses (`campus_id`)';
    $lines[] = '';
    foreach ($campuses as $campus) {
        $lines[] = '- `' . (int) ($campus['id'] ?? 0) . '` — ' . (string) ($campus['name'] ?? '');
    }
    $lines[] = '';
    $lines[] = '### Example request';
    $lines[] = '';
    $lines[] = '```bash';
    $lines[] = 'curl -H "X-API-Key: YOUR_API_KEY" \\';
    $lines[] = '  "' . $endpointUrl . '?page=1&per_page=25&search=del%20a%20cruz"';
    $lines[] = '```';
    $lines[] = '';
    $lines[] = '### Example success response';
    $lines[] = '';
    $lines[] = '```json';
    $lines[] = '{';
    $lines[] = '  "ok": true,';
    $lines[] = '  "data": {';
    $lines[] = '    "students": [';
    $lines[] = '      {';
    $lines[] = '        "id": 42,';
    $lines[] = '        "student_id": "2024-00123",';
    $lines[] = '        "first_name": "Juan",';
    $lines[] = '        "last_name": "Dela Cruz",';
    $lines[] = '        "middle_name": "Santos",';
    $lines[] = '        "full_name": "Dela Cruz, Juan Santos",';
    $lines[] = '        "email": "juan@example.edu.ph",';
    $lines[] = '        "phone": "09171234567",';
    $lines[] = '        "course": "Bachelor of Science in Information Technology",';
    $lines[] = '        "course_id": 3,';
    $lines[] = '        "program_code": "BSIT",';
    $lines[] = '        "year_level": "2nd Year",';
    $lines[] = '        "section": "A",';
    $lines[] = '        "major": "",';
    $lines[] = '        "sex": "M",';
    $lines[] = '        "enrollment_status": "enrolled",';
    $lines[] = '        "current_academic_year": "2025-2026",';
    $lines[] = '        "current_semester": "1st_semester",';
    $lines[] = '        "origin_campus_id": 1,';
    $lines[] = '        "origin_campus": "Main Campus",';
    $lines[] = '        "is_active": true';
    $lines[] = '      }';
    $lines[] = '    ],';
    $lines[] = '    "pagination": {';
    $lines[] = '      "page": 1,';
    $lines[] = '      "per_page": 25,';
    $lines[] = '      "total": 1,';
    $lines[] = '      "total_pages": 1';
    $lines[] = '    }';
    $lines[] = '  }';
    $lines[] = '}';
    $lines[] = '```';
    $lines[] = '';
    $lines[] = '### Error responses';
    $lines[] = '';
    $lines[] = '| HTTP status | When | Example |';
    $lines[] = '|-------------|------|---------|';
    $lines[] = '| 401 | Missing or invalid API key | `{"ok": false, "error": "Invalid or missing API key."}` |';
    $lines[] = '| 503 | API disabled in admin settings | `{"ok": false, "error": "External API is disabled."}` |';
    $lines[] = '| 405 | Non-GET request | `{"ok": false, "error": "Method not allowed. Use GET."}` |';
    $lines[] = '';
    $lines[] = '### JavaScript fetch example';
    $lines[] = '';
    $lines[] = '```javascript';
    $lines[] = 'const response = await fetch("' . $endpointUrl . '?page=1&per_page=50", {';
    $lines[] = '  headers: {';
    $lines[] = '    "X-API-Key": "YOUR_API_KEY"';
    $lines[] = '  }';
    $lines[] = '});';
    $lines[] = 'const payload = await response.json();';
    $lines[] = 'if (payload.ok) {';
    $lines[] = '  console.log(payload.data.students);';
    $lines[] = '}';
    $lines[] = '```';
    $lines[] = '';
    $lines[] = '## CORS';
    $lines[] = '';
    $lines[] = 'If your external web app runs in a browser, add its origin to the allowed CORS origins list in admin External API settings.';
    $lines[] = '';
    $lines[] = '## Request logging';
    $lines[] = '';
    $lines[] = 'All API connections and requests are logged in the admin External API module for auditing and troubleshooting.';

    return implode("\n", $lines) . "\n";
}

function buildExternalApiDocumentationPdf(): string {
    require_once __DIR__ . '/simple-pdf.php';

    $markdown = buildExternalApiDocumentationMarkdown();
    $pdf = new SimplePdfDocument();
    $inCodeBlock = false;

    foreach (preg_split('/\r\n|\n|\r/', $markdown) ?: [] as $line) {
        $trimmed = rtrim($line);

        if (preg_match('/^```/', $trimmed)) {
            $inCodeBlock = !$inCodeBlock;
            if ($inCodeBlock) {
                $pdf->addBlankLine(2);
            }
            continue;
        }

        if ($inCodeBlock) {
            $pdf->addCodeLine($trimmed);
            continue;
        }

        if ($trimmed === '') {
            $pdf->addBlankLine();
            continue;
        }

        if (preg_match('/^# (.+)$/', $trimmed, $matches)) {
            $pdf->addHeading(externalApiDocPlainText($matches[1]), 1);
            continue;
        }

        if (preg_match('/^## (.+)$/', $trimmed, $matches)) {
            $pdf->addHeading(externalApiDocPlainText($matches[1]), 2);
            continue;
        }

        if (preg_match('/^### (.+)$/', $trimmed, $matches)) {
            $pdf->addHeading(externalApiDocPlainText($matches[1]), 3);
            continue;
        }

        if (preg_match('/^#### (.+)$/', $trimmed, $matches)) {
            $pdf->addHeading(externalApiDocPlainText($matches[1]), 4);
            continue;
        }

        if (preg_match('/^-\s+(.+)$/', $trimmed, $matches)) {
            $pdf->addBullet(externalApiDocPlainText($matches[1]));
            continue;
        }

        if (preg_match('/^\|[-| :]+\|$/', $trimmed)) {
            continue;
        }

        if (str_starts_with($trimmed, '|')) {
            $cells = array_values(array_filter(array_map('trim', explode('|', trim($trimmed, '|'))), static fn(string $v): bool => $v !== ''));
            if ($cells !== []) {
                $pdf->addParagraph(implode(' | ', array_map('externalApiDocPlainText', $cells)), 9);
            }
            continue;
        }

        $pdf->addParagraph(externalApiDocPlainText($trimmed));
    }

    return $pdf->output();
}

function externalApiDocPlainText(string $text): string {
    $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
    $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text) ?? $text;

    return trim($text);
}
