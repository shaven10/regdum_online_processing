<?php

function redirect(string $url): void {
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }

    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<meta http-equiv="refresh" content="0;url=' . $safeUrl . '">';
    echo '<script>window.location.href=' . json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    echo '</head><body><p>Redirecting… <a href="' . $safeUrl . '">Continue</a></p></body></html>';
    exit;
}

function setFlash(string $type, string $message, array $options = []): void {
    $_SESSION['flash'] = array_merge([
        'type'    => $type,
        'message' => $message,
    ], $options);
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function appTimezone(): string {
    return defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Manila';
}

function appNow(): string {
    return (new DateTimeImmutable('now', new DateTimeZone(appTimezone())))->format('Y-m-d H:i:s');
}

function appToday(): string {
    return (new DateTimeImmutable('now', new DateTimeZone(appTimezone())))->format('Y-m-d');
}

/**
 * Parse a date/datetime string in the app timezone.
 */
function appDateTime(?string $value, ?string $fallback = null): ?DateTimeImmutable {
    $raw = trim((string) $value);
    if ($raw === '') {
        return $fallback !== null ? appDateTime($fallback) : null;
    }

    $tz = new DateTimeZone(appTimezone());
    try {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return new DateTimeImmutable($raw . ' 00:00:00', $tz);
        }
        return new DateTimeImmutable($raw, $tz);
    } catch (Exception $e) {
        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($tz);
    }
}

function formatDate(?string $date, string $format = 'M d, Y'): string {
    $dt = appDateTime($date);
    return $dt ? $dt->format($format) : '—';
}

function formatDateTime(?string $datetime): string {
    $dt = appDateTime($datetime);
    return $dt ? $dt->format('M d, Y h:i A') : '—';
}

function formatMoney(float $amount): string {
    return CURRENCY . ' ' . number_format($amount, 2);
}

function generateRequestNumber(): string {
    return 'REQ-' . date('Y') . '-' . strtoupper(substr(uniqid(), -6));
}

/**
 * Generate a readable temporary password for admin resets.
 */
function generateTemporaryPassword(int $length = 12): string {
    $length = max(PASSWORD_MIN_LENGTH, $length);
    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnopqrstuvwxyz';
    $digits = '23456789';
    $symbols = '!@#$%&*';
    $all = $upper . $lower . $digits . $symbols;

    $password = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    ];

    for ($i = count($password); $i < $length; $i++) {
        $password[] = $all[random_int(0, strlen($all) - 1)];
    }

    for ($i = count($password) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
    }

    return implode('', $password);
}

function normalizeVerificationCode(?string $code): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $code) ?? '');
}

function formatVerificationCode(?string $code): string {
    $normalized = normalizeVerificationCode($code);
    if ($normalized === '') {
        return '';
    }
    if (strlen($normalized) === 8) {
        return substr($normalized, 0, 4) . '-' . substr($normalized, 4, 4);
    }
    return strtoupper(trim((string) $code));
}

function isSimpleVerificationCode(?string $code): bool {
    return (bool) preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', formatVerificationCode($code));
}

function isVerificationCodeTaken(string $code): bool {
    $db = getDB();
    $normalized = normalizeVerificationCode($code);
    if ($normalized === '') {
        return false;
    }

    $stmt = $db->prepare("SELECT id FROM requests
        WHERE REPLACE(UPPER(verification_code), '-', '') = ?
        LIMIT 1");
    $stmt->execute([$normalized]);
    if ($stmt->fetch()) {
        return true;
    }

    try {
        $itemStmt = $db->prepare("SELECT id FROM request_items
            WHERE REPLACE(UPPER(verification_code), '-', '') = ?
            LIMIT 1");
        $itemStmt->execute([$normalized]);
        if ($itemStmt->fetch()) {
            return true;
        }
    } catch (Throwable $e) {
        // request_items may be unavailable on older installs
    }

    return false;
}

function generateVerificationCode(): string {
    // Short counter-friendly code: ABCD-2345 (no 0/O/1/I).
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;

    for ($attempt = 0; $attempt < 12; $attempt++) {
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $alphabet[random_int(0, $max)];
        }
        $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
        if (!isVerificationCodeTaken($code)) {
            return $code;
        }
    }

    return strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)) . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
}

function ensureSimpleVerificationCode(int $requestId): ?string {
    $db = getDB();
    $stmt = $db->prepare('SELECT verification_code FROM requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $current = $stmt->fetchColumn();
    if ($current === false) {
        return null;
    }

    if (isSimpleVerificationCode((string) $current)) {
        return formatVerificationCode((string) $current);
    }

    $code = generateVerificationCode();
    $db->prepare('UPDATE requests SET verification_code = ? WHERE id = ?')->execute([$code, $requestId]);

    try {
        $db->prepare('UPDATE request_items SET verification_code = ? WHERE request_id = ? AND (verification_code IS NULL OR verification_code = ?)')
            ->execute([$code, $requestId, $current]);
    } catch (Throwable $e) {
        // optional sync
    }

    return $code;
}

function statusBadge(string $status): string {
    $classes = [
        'submitted'              => 'badge-submitted',
        'under_review'           => 'badge-review',
        'awaiting_requirements'  => 'badge-review',
        'requirements_submitted' => 'badge-review',
        'needs_revision'         => 'badge-review',
        'requirements_verified'  => 'badge-payment',
        'payment_verified'       => 'badge-payment',
        'processing'       => 'badge-processing',
        'ready_for_pickup' => 'badge-ready',
        'shipped'          => 'badge-shipped',
        'completed'        => 'badge-completed',
        'rejected'         => 'badge-rejected',
        'cancelled'        => 'badge-rejected',
        'pending'          => 'badge-submitted',
        'verified'         => 'badge-completed',
    ];
    $class = $classes[$status] ?? 'badge-submitted';
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

function purposeLabel(string $purpose): string {
    $label = getRequestPurposeLabel($purpose);
    if ($label !== null) {
        return $label;
    }

    return ucwords(str_replace('_', ' ', $purpose));
}

function purposeOptions(?string $enrollmentStatus = null): array {
    return getActiveRequestPurposeCodes($enrollmentStatus);
}

function uploadFile(array $file, string $subdir = 'documents'): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;

    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;

    $dir = UPLOAD_PATH . '/' . $subdir;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $filename = uniqid() . '_' . time() . '.' . $ext;
    $path = $dir . '/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $path)) {
        return $subdir . '/' . $filename;
    }
    return null;
}

function sendNotification(int $userId, string $title, string $message, string $type = 'info', ?string $link = null): void {
    $db = getDB();
    $stmt = $db->prepare('INSERT INTO notifications (user_id, title, message, type, link) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $title, $message, $type, $link]);
}

function notifyUsersByRole(string $roleName, string $title, string $message, string $type = 'info', ?string $link = null): int {
    $db = getDB();
    $roleId = $db->prepare('SELECT id FROM roles WHERE name = ?');
    $roleId->execute([$roleName]);
    $id = $roleId->fetchColumn();
    if (!$id) {
        return 0;
    }

    $users = $db->prepare('SELECT id FROM users WHERE role_id = ? AND is_active = 1');
    $users->execute([$id]);
    $count = 0;
    foreach ($users->fetchAll() as $row) {
        sendNotification((int) $row['id'], $title, $message, $type, $link);
        $count++;
    }

    return $count;
}

function notifyRegistrarsNewRequest(int $requestId, string $requestNumber, string $studentName, int $documentCount = 1): void {
    $docLabel = $documentCount === 1 ? '1 document' : $documentCount . ' documents';
    notifyUsersByRole(
        'registrar',
        'New Incoming Request',
        $studentName . ' submitted request ' . $requestNumber . ' (' . $docLabel . ') for review.',
        'info',
        APP_URL . '/registrar/verify-request.php?id=' . $requestId
    );
    notifyUsersByRole(
        'admin',
        'New Incoming Request',
        $studentName . ' submitted request ' . $requestNumber . ' (' . $docLabel . ').',
        'info',
        APP_URL . '/admin/request-manage.php?id=' . $requestId
    );
}

function notifyCashiersNewPayment(int $requestId, string $requestNumber, string $studentName): void {
    notifyUsersByRole(
        'cashier',
        'New Payment to Verify',
        $studentName . ' submitted payment for request ' . $requestNumber . '.',
        'info',
        APP_URL . '/cashier/payments.php?status=pending'
    );
}

function getUnreadNotificationCount(int $userId): int {
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function getLatestUnreadNotifications(int $userId, int $limit = 5, int $afterId = 0): array {
    $db = getDB();
    $limit = max(1, min(20, $limit));
    if ($afterId > 0) {
        $stmt = $db->prepare('SELECT id, title, message, type, link, created_at
            FROM notifications
            WHERE user_id = ? AND is_read = 0 AND id > ?
            ORDER BY id DESC
            LIMIT ' . $limit);
        $stmt->execute([$userId, $afterId]);
    } else {
        $stmt = $db->prepare('SELECT id, title, message, type, link, created_at
            FROM notifications
            WHERE user_id = ? AND is_read = 0
            ORDER BY id DESC
            LIMIT ' . $limit);
        $stmt->execute([$userId]);
    }

    return $stmt->fetchAll();
}

function markAllNotificationsRead(int $userId): int {
    $db = getDB();
    $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

function markNotificationRead(int $userId, int $notificationId): bool {
    if ($notificationId <= 0) {
        return false;
    }
    $db = getDB();
    $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND is_read = 0');
    $stmt->execute([$notificationId, $userId]);
    return $stmt->rowCount() > 0;
}

function isSafeAppRedirect(?string $url): bool {
    $url = trim((string) $url);
    if ($url === '') {
        return false;
    }
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return true;
    }
    $app = rtrim(APP_URL, '/');
    return str_starts_with($url, $app . '/') || $url === $app;
}

function updateRequestStatus(int $requestId, string $newStatus, ?string $remarks = null): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT status, user_id, request_number FROM requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) return false;

    $oldStatus = $request['status'];
    $db->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ?')
       ->execute([$newStatus, appNow(), $requestId]);

    if (in_array($newStatus, ['processing', 'ready_for_pickup', 'shipped', 'completed'], true)) {
        ensureSimpleVerificationCode($requestId);
    }

    $current = currentUser();
    $userId = $current['id'] ?? null;
    $db->prepare('INSERT INTO request_status_history (request_id, old_status, new_status, changed_by, remarks) VALUES (?, ?, ?, ?, ?)')
       ->execute([$requestId, $oldStatus, $newStatus, $userId, $remarks]);

    $statusLabel = ucwords(str_replace('_', ' ', $newStatus));
    sendNotification(
        $request['user_id'],
        'Request Status Updated',
        "Your request {$request['request_number']} is now: {$statusLabel}",
        'info',
        APP_URL . '/student/request-view.php?id=' . $requestId
    );

    auditLog('status_change', 'requests', $requestId, ['status' => $oldStatus], ['status' => $newStatus]);
    return true;
}

function adminUpdateRequestStatus(int $requestId, string $newStatus, ?string $remarks = null): array {
    require_once __DIR__ . '/compliance.php';
    ensureRequestStatuses();

    $allowed = requestStatusOptions();
    if (!in_array($newStatus, $allowed, true)) {
        return ['ok' => false, 'error' => 'Invalid status selected.'];
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT status, request_number FROM requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $current = $stmt->fetch();
    if (!$current) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }

    if ($current['status'] === $newStatus) {
        return ['ok' => true, 'message' => 'Status is already set to ' . ucwords(str_replace('_', ' ', $newStatus)) . '.'];
    }

    try {
        updateRequestStatus($requestId, $newStatus, $remarks ?: 'Status updated by administrator');
        if ($newStatus === 'completed') {
            $db->prepare('UPDATE requests SET completed_at = COALESCE(completed_at, ?) WHERE id = ?')->execute([appNow(), $requestId]);
        }
        return [
            'ok' => true,
            'message' => 'Request ' . $current['request_number'] . ' updated to ' . ucwords(str_replace('_', ' ', $newStatus)) . '.',
        ];
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Unable to save status. The database may need the workflow migration (migrate-workflow.php).'];
    }
}

function normalizeAdminBatchRequestIds(array $rawIds): array {
    $ids = [];
    foreach ($rawIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function collectRequestUploadPaths(int $requestId): array {
    $db = getDB();
    $paths = [];

    $docs = $db->prepare('SELECT file_name FROM request_documents WHERE request_id = ?');
    $docs->execute([$requestId]);
    foreach ($docs->fetchAll(PDO::FETCH_COLUMN) as $path) {
        if ($path) {
            $paths[] = (string) $path;
        }
    }

    $payments = $db->prepare("SELECT receipt_path FROM payments WHERE request_id = ? AND receipt_path IS NOT NULL AND receipt_path != ''");
    $payments->execute([$requestId]);
    foreach ($payments->fetchAll(PDO::FETCH_COLUMN) as $path) {
        if ($path) {
            $paths[] = (string) $path;
        }
    }

    $pdf = $db->prepare('SELECT pdf_path FROM requests WHERE id = ?');
    $pdf->execute([$requestId]);
    $pdfPath = $pdf->fetchColumn();
    if ($pdfPath) {
        $paths[] = (string) $pdfPath;
    }

    return array_values(array_unique($paths));
}

function deleteStoredUploadFiles(array $paths): void {
    foreach ($paths as $path) {
        $fullPath = UPLOAD_PATH . '/' . ltrim((string) $path, '/');
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

function adminDeleteRequest(int $requestId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, request_number FROM requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }

    try {
        $paths = collectRequestUploadPaths($requestId);
        $db->prepare('DELETE FROM requests WHERE id = ?')->execute([$requestId]);
        deleteStoredUploadFiles($paths);
        auditLog('request_deleted', 'requests', $requestId, ['request_number' => $request['request_number']], null);
        return ['ok' => true, 'request_number' => $request['request_number']];
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Unable to delete request ' . $request['request_number'] . '.'];
    }
}

function adminBatchDeleteRequests(array $requestIds): array {
    $deleted = 0;
    $failed = [];

    foreach (normalizeAdminBatchRequestIds($requestIds) as $requestId) {
        $result = adminDeleteRequest($requestId);
        if ($result['ok']) {
            $deleted++;
        } else {
            $failed[] = $result['error'] ?? ('Request #' . $requestId);
        }
    }

    return [
        'deleted' => $deleted,
        'failed' => $failed,
        'ok' => $deleted > 0,
    ];
}

/**
 * Permanently delete a student account and related credential requests.
 *
 * @return array{ok:bool,error?:string,name?:string,requests_deleted?:int}
 */
function adminDeleteStudent(int $userId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT u.id, u.first_name, u.last_name, u.email, u.student_id, r.name AS role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.id = ?');
    $stmt->execute([$userId]);
    $student = $stmt->fetch();

    if (!$student || ($student['role_name'] ?? '') !== 'student') {
        return ['ok' => false, 'error' => 'Student account not found.'];
    }

    $displayName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));

    try {
        $profilePaths = [];
        $profileStmt = $db->prepare('SELECT valid_id_path, avatar FROM student_profiles WHERE user_id = ?');
        $profileStmt->execute([$userId]);
        $profile = $profileStmt->fetch() ?: [];
        foreach (['valid_id_path', 'avatar'] as $field) {
            if (!empty($profile[$field])) {
                $profilePaths[] = (string) $profile[$field];
            }
        }

        $requestStmt = $db->prepare('SELECT id FROM requests WHERE user_id = ? ORDER BY id ASC');
        $requestStmt->execute([$userId]);
        $requestIds = array_map('intval', $requestStmt->fetchAll(PDO::FETCH_COLUMN));

        $requestsDeleted = 0;
        foreach ($requestIds as $requestId) {
            $result = adminDeleteRequest($requestId);
            if (!$result['ok']) {
                return [
                    'ok' => false,
                    'error' => $result['error'] ?? ('Unable to delete request #' . $requestId . ' for this student.'),
                ];
            }
            $requestsDeleted++;
        }

        $db->prepare('DELETE FROM appointments WHERE user_id = ?')->execute([$userId]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

        deleteStoredUploadFiles($profilePaths);
        auditLog('delete_student', 'users', $userId, [
            'email' => $student['email'] ?? null,
            'student_id' => $student['student_id'] ?? null,
            'name' => $displayName,
            'requests_deleted' => $requestsDeleted,
        ], null);

        return [
            'ok' => true,
            'name' => $displayName !== '' ? $displayName : ($student['email'] ?? 'Student'),
            'requests_deleted' => $requestsDeleted,
        ];
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'Unable to delete student account.'];
    }
}

/**
 * @return array{ok:bool,deleted:int,failed:array,requests_deleted:int}
 */
function adminBatchDeleteStudents(array $userIds): array {
    $deleted = 0;
    $failed = [];
    $requestsDeleted = 0;

    foreach (normalizeAdminBatchRequestIds($userIds) as $userId) {
        $result = adminDeleteStudent($userId);
        if (!empty($result['ok'])) {
            $deleted++;
            $requestsDeleted += (int) ($result['requests_deleted'] ?? 0);
        } else {
            $failed[] = $result['error'] ?? ('Student #' . $userId);
        }
    }

    return [
        'ok' => $deleted > 0,
        'deleted' => $deleted,
        'failed' => $failed,
        'requests_deleted' => $requestsDeleted,
    ];
}

/**
 * Build WHERE clause pieces for admin student list filters.
 *
 * @return array{where: list<string>, params: list<mixed>}
 */
function buildAdminStudentFilterQuery(array $filters = []): array {
    $search = trim((string) ($filters['search'] ?? ''));
    $enrollmentStatus = trim((string) ($filters['enrollment_status'] ?? ''));
    $courseId = (int) ($filters['course_id'] ?? 0);
    $yearLevel = trim((string) ($filters['year_level'] ?? ''));
    $accountStatus = trim((string) ($filters['account'] ?? ''));
    $campusId = (int) ($filters['campus_id'] ?? 0);

    $where = ["r.name = 'student'"];
    $params = [];

    if ($search !== '') {
        $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.middle_name LIKE ?
                OR u.email LIKE ? OR u.student_id LIKE ? OR u.phone LIKE ?
                OR sp.course LIKE ? OR CONCAT(u.last_name, " ", u.first_name) LIKE ?
                OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }
    }

    if ($enrollmentStatus !== '' && array_key_exists($enrollmentStatus, enrollmentStatusOptions())) {
        $where[] = 'sp.enrollment_status = ?';
        $params[] = $enrollmentStatus;
    }

    if ($courseId > 0) {
        $where[] = 'sp.course_id = ?';
        $params[] = $courseId;
    }

    if ($yearLevel !== '') {
        $where[] = 'sp.year_level = ?';
        $params[] = $yearLevel;
    }

    if ($accountStatus === 'active') {
        $where[] = 'u.is_active = 1';
    } elseif ($accountStatus === 'inactive') {
        $where[] = 'u.is_active = 0';
    }

    if ($campusId > 0) {
        $where[] = 'sp.origin_campus_id = ?';
        $params[] = $campusId;
    }

    return ['where' => $where, 'params' => $params];
}

/**
 * @return list<int>
 */
function queryAdminStudentIds(array $filters = []): array {
    $db = getDB();
    $query = buildAdminStudentFilterQuery($filters);
    $whereClause = implode(' AND ', $query['where']);

    $stmt = $db->prepare("SELECT u.id
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        WHERE {$whereClause}
        ORDER BY u.id ASC");
    $stmt->execute($query['params']);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function countAdminStudents(array $filters = []): int {
    $db = getDB();
    $query = buildAdminStudentFilterQuery($filters);
    $whereClause = implode(' AND ', $query['where']);

    $stmt = $db->prepare("SELECT COUNT(*)
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        WHERE {$whereClause}");
    $stmt->execute($query['params']);

    return (int) $stmt->fetchColumn();
}

/**
 * Permanently delete students matching filters, or all students when $filters is empty.
 *
 * @return array{ok:bool,deleted:int,failed:array,requests_deleted:int,matched:int}
 */
function adminDeleteStudentsMatchingFilters(array $filters = []): array {
    $userIds = queryAdminStudentIds($filters);
    $result = adminBatchDeleteStudents($userIds);
    $result['matched'] = count($userIds);

    return $result;
}

function adminBatchUpdateRequestStatus(array $requestIds, string $newStatus, ?string $remarks = null): array {
    return batchUpdateRequestStatuses($requestIds, $newStatus, $remarks);
}

/**
 * Batch-update request workflow statuses (admin or registrar).
 *
 * @return array{updated:int,unchanged:int,failed:array,ok:bool}
 */
function batchUpdateRequestStatuses(array $requestIds, string $newStatus, ?string $remarks = null): array {
    $updated = 0;
    $unchanged = 0;
    $failed = [];

    foreach (normalizeAdminBatchRequestIds($requestIds) as $requestId) {
        $result = adminUpdateRequestStatus($requestId, $newStatus, $remarks);
        if (!($result['ok'] ?? false)) {
            $failed[] = $result['error'] ?? ('Request #' . $requestId);
            continue;
        }

        if (str_contains(strtolower($result['message'] ?? ''), 'already set')) {
            $unchanged++;
        } else {
            $updated++;
        }
    }

    return [
        'updated' => $updated,
        'unchanged' => $unchanged,
        'failed' => $failed,
        'ok' => $updated > 0 || ($unchanged > 0 && empty($failed)),
    ];
}

function processStudentPickupConfirmation(int $requestId, int $userId): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, status, delivery_method, request_number FROM requests WHERE id = ? AND user_id = ?');
    $stmt->execute([$requestId, $userId]);
    $request = $stmt->fetch();

    if (!$request || $request['status'] !== 'ready_for_pickup' || !in_array($request['delivery_method'], ['pickup', 'authorized_representative'], true)) {
        return false;
    }

    if (!function_exists('getRequestItems')) {
        require_once __DIR__ . '/request-items.php';
    }

    $db->prepare("UPDATE request_items
        SET item_status = 'completed', completed_at = COALESCE(completed_at, NOW())
        WHERE request_id = ? AND item_status <> 'completed'")
       ->execute([$requestId]);

    updateRequestStatus($requestId, 'completed', 'Student confirmed document pickup on-site');
    $db->prepare('UPDATE requests SET completed_at = NOW() WHERE id = ?')->execute([$requestId]);

    auditLog('student_pickup_confirmed', 'requests', $requestId);
    return true;
}

function calculateRequestFee(int $docTypeId, int $copies, ?array $authItems = null): float {
    $db = getDB();
    $stmt = $db->prepare('SELECT base_fee, requires_documentary_stamp, fee_per_set, requires_auth_document_type FROM document_types WHERE id = ?');
    $stmt->execute([$docTypeId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        return 0;
    }

    $copies = max(1, $copies);
    if (!empty($doc['requires_auth_document_type']) && is_array($authItems) && !empty($authItems)) {
        $total = (float) $doc['base_fee'] * max(1, totalAuthenticationSets($authItems));
    } elseif (!empty($doc['fee_per_set'])) {
        $total = (float) $doc['base_fee'];
    } else {
        $total = (float) $doc['base_fee'] * $copies;
    }
    if (!empty($doc['requires_documentary_stamp'])) {
        $total += documentStampFeeAmount();
    }

    return $total;
}

function calculateMultipleRequestFees(array $docTypeIds, int $copies): float {
    $total = 0.0;
    foreach (array_unique(array_filter(array_map('intval', $docTypeIds))) as $docTypeId) {
        $total += calculateRequestFee($docTypeId, $copies);
    }
    return $total;
}

function validateActiveDocumentTypeIds(array $docTypeIds): array {
    $db = getDB();
    $valid = [];
    foreach (array_unique(array_filter(array_map('intval', $docTypeIds))) as $docTypeId) {
        $stmt = $db->prepare('SELECT id FROM document_types WHERE id = ? AND is_active = 1');
        $stmt->execute([$docTypeId]);
        if ($stmt->fetch()) {
            $valid[] = $docTypeId;
        }
    }
    return $valid;
}

function attachUploadedRequestDocuments(int $requestId, array $uploadedFiles): void {
    $db = getDB();
    foreach ($uploadedFiles as $file) {
        $db->prepare('INSERT INTO request_documents (request_id, file_name, original_name, file_type, file_size) VALUES (?, ?, ?, ?, ?)')
           ->execute([$requestId, $file['path'], $file['original_name'], $file['type'], $file['size']]);
    }
}

function storeRequestUploads(array $filesInput): array {
    $stored = [];
    if (empty($filesInput['name'][0])) {
        return $stored;
    }

    foreach ($filesInput['name'] as $i => $name) {
        if (empty($name)) {
            continue;
        }
        $file = [
            'name'     => $name,
            'type'     => $filesInput['type'][$i],
            'tmp_name' => $filesInput['tmp_name'][$i],
            'error'    => $filesInput['error'][$i],
            'size'     => $filesInput['size'][$i],
        ];
        $path = uploadFile($file, 'request_docs');
        if ($path) {
            $stored[] = [
                'path'          => $path,
                'original_name' => $name,
                'type'          => $file['type'],
                'size'          => $file['size'],
            ];
        }
    }

    return $stored;
}

function paginate(int $total, int $page, int $perPage = ITEMS_PER_PAGE): array {
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    return [
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
        'offset'      => ($page - 1) * $perPage,
    ];
}

function paginationPageUrl(string $baseUrl, int $page): string {
    $baseUrl = trim($baseUrl);
    if ($baseUrl === '' || $baseUrl === '?') {
        return '?page=' . $page;
    }

    $baseUrl = rtrim($baseUrl, '?&');
    if ($baseUrl === '') {
        return '?page=' . $page;
    }

    if (preg_match('/([?&])page=\d+/', $baseUrl)) {
        return (string) preg_replace('/([?&])page=\d+/', '${1}page=' . $page, $baseUrl, 1);
    }

    return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'page=' . $page;
}

function paginationWindow(int $current, int $total, int $radius = 1): array {
    if ($total <= 7) {
        return range(1, $total);
    }

    $pages = [1];
    $start = max(2, $current - $radius);
    $end = min($total - 1, $current + $radius);

    if ($start > 2) {
        $pages[] = null;
    }
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($end < $total - 1) {
        $pages[] = null;
    }
    $pages[] = $total;

    return $pages;
}

function paginationLinks(array $pag, string $baseUrl): string {
    $totalPages = (int) ($pag['total_pages'] ?? 1);
    $page = (int) ($pag['page'] ?? 1);
    if ($totalPages <= 1) {
        return '';
    }

    $html = '<nav class="pagination" aria-label="Pagination">';
    $html .= '<p class="pagination-status">Page ' . $page . ' of ' . $totalPages . '</p>';
    $html .= '<ul>';

    if ($page > 1) {
        $html .= '<li class="pagination-nav"><a href="' . e(paginationPageUrl($baseUrl, $page - 1)) . '" aria-label="Previous page">Prev</a></li>';
    } else {
        $html .= '<li class="pagination-nav is-disabled"><span aria-disabled="true">Prev</span></li>';
    }

    foreach (paginationWindow($page, $totalPages) as $item) {
        if ($item === null) {
            $html .= '<li class="pagination-ellipsis" aria-hidden="true"><span>&hellip;</span></li>';
            continue;
        }

        $classes = 'pagination-page' . ($item === $page ? ' active' : '');
        $current = $item === $page ? ' aria-current="page"' : '';
        $html .= '<li class="' . $classes . '"><a href="' . e(paginationPageUrl($baseUrl, (int) $item)) . '"' . $current . '>' . (int) $item . '</a></li>';
    }

    if ($page < $totalPages) {
        $html .= '<li class="pagination-nav"><a href="' . e(paginationPageUrl($baseUrl, $page + 1)) . '" aria-label="Next page">Next</a></li>';
    } else {
        $html .= '<li class="pagination-nav is-disabled"><span aria-disabled="true">Next</span></li>';
    }

    $html .= '</ul></nav>';
    return $html;
}

function recordsPerPageOptions(): array {
    return [15, 25, 50, 100];
}

function normalizeRecordsPerPage(int $perPage): int {
    $allowed = recordsPerPageOptions();
    return in_array($perPage, $allowed, true) ? $perPage : ITEMS_PER_PAGE;
}

function studentRecordsPerPageOptions(): array {
    return recordsPerPageOptions();
}

function normalizeStudentRecordsPerPage(int $perPage): int {
    return normalizeRecordsPerPage($perPage);
}

function recordsSortDirection(string $dir): string {
    return strtolower(trim($dir)) === 'asc' ? 'asc' : 'desc';
}

/**
 * Resolve active sort column/direction from the request.
 *
 * Column map keys are public sort ids. Optional keys per column:
 * - type: string|number|date (default string)
 * - sql: SQL expression(s) for ORDER BY
 * - get: callable(array $row): mixed for in-memory sorting
 * - default_dir: asc|desc when this column is first selected
 *
 * @param array<string, array{type?:string, sql?:string, get?:callable, default_dir?:string}> $columns
 * @return array{
 *   sort:string,
 *   dir:string,
 *   columns:array,
 *   default_sort:string,
 *   default_dir:string
 * }
 */
function resolveRecordsSort(array $columns, string $defaultSort, string $defaultDir = 'desc'): array {
    $defaultDir = recordsSortDirection($defaultDir);
    if ($columns === []) {
        return [
            'sort' => $defaultSort,
            'dir' => $defaultDir,
            'columns' => [],
            'default_sort' => $defaultSort,
            'default_dir' => $defaultDir,
        ];
    }

    if (!isset($columns[$defaultSort])) {
        $defaultSort = (string) array_key_first($columns);
    }

    $sort = trim((string) ($_GET['sort'] ?? $defaultSort));
    if ($sort === '' || !isset($columns[$sort])) {
        $sort = $defaultSort;
    }

    if (!array_key_exists('dir', $_GET) || trim((string) $_GET['dir']) === '') {
        $dir = recordsSortDirection((string) ($columns[$sort]['default_dir'] ?? $defaultDir));
    } else {
        $dir = recordsSortDirection((string) $_GET['dir']);
    }

    return [
        'sort' => $sort,
        'dir' => $dir,
        'columns' => $columns,
        'default_sort' => $defaultSort,
        'default_dir' => $defaultDir,
    ];
}

/**
 * @param array{sort:string, dir:string, default_sort?:string, default_dir?:string} $sortState
 * @return array{sort?:string, dir?:string}
 */
function recordsSortFilterParams(array $sortState): array {
    $sort = (string) ($sortState['sort'] ?? '');
    $dir = recordsSortDirection((string) ($sortState['dir'] ?? 'desc'));
    $defaultSort = (string) ($sortState['default_sort'] ?? '');
    $defaultDir = recordsSortDirection((string) ($sortState['default_dir'] ?? 'desc'));

    if ($sort === '' || ($sort === $defaultSort && $dir === $defaultDir)) {
        return [];
    }

    return [
        'sort' => $sort,
        'dir' => $dir,
    ];
}

/**
 * Hidden fields so filter / per-page submits keep the active column sort.
 *
 * @param array{sort:string, dir:string} $sortState
 */
function recordsSortFormFields(array $sortState): string {
    $sort = trim((string) ($sortState['sort'] ?? ''));
    $dir = recordsSortDirection((string) ($sortState['dir'] ?? 'desc'));
    if ($sort === '') {
        return '';
    }

    return '<input type="hidden" name="sort" value="' . e($sort) . '">'
        . '<input type="hidden" name="dir" value="' . e($dir) . '">';
}

function recordsSortComparable(mixed $value, string $type): mixed {
    if ($value === null || $value === '') {
        return match ($type) {
            'number', 'date' => 0,
            default => '',
        };
    }

    return match ($type) {
        'number' => (float) $value,
        'date' => strtotime((string) $value) ?: 0,
        default => mb_strtolower(trim((string) $value)),
    };
}

/**
 * @param array<string,mixed> $row
 * @param array{type?:string, get?:callable} $column
 */
function recordsSortRowValue(array $row, string $key, array $column): mixed {
    if (isset($column['get']) && is_callable($column['get'])) {
        return ($column['get'])($row);
    }
    return $row[$key] ?? null;
}

/**
 * @param list<array<string,mixed>> $items
 * @param array{sort:string, dir:string, columns:array} $sortState
 * @return list<array<string,mixed>>
 */
function sortRecordList(array $items, array $sortState): array {
    $sort = (string) ($sortState['sort'] ?? '');
    $columns = $sortState['columns'] ?? [];
    if ($sort === '' || !isset($columns[$sort]) || $items === []) {
        return array_values($items);
    }

    $column = $columns[$sort];
    $type = (string) ($column['type'] ?? 'string');
    $factor = recordsSortDirection((string) ($sortState['dir'] ?? 'desc')) === 'asc' ? 1 : -1;

    usort($items, static function ($a, $b) use ($sort, $column, $type, $factor): int {
        if (!is_array($a) || !is_array($b)) {
            return 0;
        }
        $av = recordsSortComparable(recordsSortRowValue($a, $sort, $column), $type);
        $bv = recordsSortComparable(recordsSortRowValue($b, $sort, $column), $type);
        if ($av == $bv) {
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        }
        return ($av <=> $bv) * $factor;
    });

    return array_values($items);
}

/**
 * Build a safe ORDER BY clause from a resolved sort state.
 *
 * @param array{sort:string, dir:string, columns:array} $sortState
 */
function recordsSqlOrderBy(array $sortState, string $fallbackSql): string {
    $sort = (string) ($sortState['sort'] ?? '');
    $columns = $sortState['columns'] ?? [];
    if ($sort === '' || empty($columns[$sort]['sql'])) {
        return $fallbackSql;
    }

    $dir = strtoupper(recordsSortDirection((string) ($sortState['dir'] ?? 'desc')));
    $parts = array_map('trim', explode(',', (string) $columns[$sort]['sql']));
    $ordered = [];
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $part = (string) preg_replace('/\s+(ASC|DESC)$/i', '', $part);
        $ordered[] = $part . ' ' . $dir;
    }

    return $ordered === [] ? $fallbackSql : implode(', ', $ordered);
}

/**
 * Clickable table header for column sorting. Resets to page 1.
 *
 * @param array{sort:string, dir:string, columns:array} $sortState
 * @param array<string,mixed> $query Current list filters (without page)
 */
function renderRecordsSortHeader(string $label, string $column, array $sortState, array $query = [], string $tag = 'th'): string {
    $tag = in_array($tag, ['th', 'span', 'div'], true) ? $tag : 'th';
    if (!isset($sortState['columns'][$column])) {
        return '<' . $tag . '>' . e($label) . '</' . $tag . '>';
    }

    $isActive = ($sortState['sort'] ?? '') === $column;
    $colDef = $sortState['columns'][$column];
    $type = (string) ($colDef['type'] ?? 'string');

    if ($isActive) {
        $nextDir = recordsSortDirection((string) ($sortState['dir'] ?? 'desc')) === 'asc' ? 'desc' : 'asc';
    } else {
        $nextDir = recordsSortDirection((string) ($colDef['default_dir'] ?? ($type === 'string' ? 'asc' : 'desc')));
    }

    unset($query['page']);
    $perPage = normalizeRecordsPerPage((int) ($query['per_page'] ?? ITEMS_PER_PAGE));
    unset($query['per_page']);
    $query['sort'] = $column;
    $query['dir'] = $nextDir;
    $url = recordsListBaseUrl(recordsListQuery($query, $perPage));

    $icon = 'fa-sort';
    $ariaSort = 'none';
    if ($isActive) {
        $icon = ($sortState['dir'] ?? '') === 'asc' ? 'fa-sort-up' : 'fa-sort-down';
        $ariaSort = ($sortState['dir'] ?? '') === 'asc' ? 'ascending' : 'descending';
    }

    $classes = 'sortable-col' . ($isActive ? ' is-sorted is-sorted-' . e((string) $sortState['dir']) : '');

    return '<' . $tag . ' class="' . $classes . '" aria-sort="' . $ariaSort . '">'
        . '<a class="sortable-link" href="' . e($url) . '" title="Sort by ' . e($label) . '">'
        . '<span>' . e($label) . '</span>'
        . '<i class="fas ' . $icon . '" aria-hidden="true"></i>'
        . '</a></' . $tag . '>';
}

/**
 * @param array<string,mixed> $filters
 * @return array<string,string>
 */
function recordsListQuery(array $filters, int $perPage): array {
    $query = [];
    foreach ($filters as $key => $value) {
        if ($value === null || $value === '' || $value === false) {
            continue;
        }
        if (is_int($value) && $value === 0) {
            continue;
        }
        $query[(string) $key] = (string) $value;
    }
    if ($perPage !== ITEMS_PER_PAGE) {
        $query['per_page'] = (string) $perPage;
    }

    return $query;
}

function recordsListBaseUrl(array $query): string {
    return $query === [] ? '?' : ('?' . http_build_query($query));
}

function renderRecordsShowingMeta(array $pag, string $singular = 'record', string $plural = 'records'): string {
    $total = (int) ($pag['total'] ?? 0);
    if ($total <= 0) {
        return '';
    }

    $from = (int) $pag['offset'] + 1;
    $to = min((int) $pag['offset'] + (int) $pag['per_page'], $total);
    $noun = $total === 1 ? $singular : $plural;

    return '<div class="records-filter-meta students-filter-meta">'
        . '<span>' . $total . ' ' . e($noun) . '</span>'
        . '<span>Showing ' . $from . '–' . $to . '</span>'
        . '</div>';
}

function renderRecordsPaginationBar(array $pag, string $baseUrl, int $perPage, string $formId = 'recordsFilterForm'): string {
    $html = '<div class="records-pagination-bar students-pagination-bar">';
    $html .= '<label class="records-per-page students-per-page">Per page ';
    $html .= '<select name="per_page" form="' . e($formId) . '" aria-label="Records per page">';
    foreach (recordsPerPageOptions() as $option) {
        $selected = $option === $perPage ? ' selected' : '';
        $html .= '<option value="' . $option . '"' . $selected . '>' . $option . '</option>';
    }
    $html .= '</select></label>';
    $html .= paginationLinks($pag, $baseUrl);
    $html .= '</div>';
    return $html;
}

function renderStudentRecordsPagination(array $pag, string $baseUrl, int $perPage, string $formId = 'studentsFilterForm'): string {
    return renderRecordsPaginationBar($pag, $baseUrl, $perPage, $formId);
}

/**
 * @param array<string,mixed> $filters
 * @return array{
 *   pag:array,
 *   per_page:int,
 *   base_url:string,
 *   form_id:string,
 *   html:string,
 *   meta_html:string,
 *   total:int,
 *   offset:int,
 *   limit:int
 * }
 */
function recordsListPaging(int $total, array $filters = [], string $formId = 'recordsFilterForm', string $singular = 'record', string $plural = 'records'): array {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = normalizeRecordsPerPage((int) ($_GET['per_page'] ?? ITEMS_PER_PAGE));
    $pag = paginate($total, $page, $perPage);
    $query = recordsListQuery($filters, $perPage);
    $baseUrl = recordsListBaseUrl($query);

    return [
        'pag' => $pag,
        'per_page' => $perPage,
        'base_url' => $baseUrl,
        'form_id' => $formId,
        'html' => renderRecordsPaginationBar($pag, $baseUrl, $perPage, $formId),
        'meta_html' => renderRecordsShowingMeta($pag, $singular, $plural),
        'total' => $pag['total'],
        'offset' => $pag['offset'],
        'limit' => $pag['per_page'],
    ];
}

/**
 * @param list<mixed> $items
 * @param array<string,mixed> $filters
 * @return array{
 *   items:list<mixed>,
 *   pag:array,
 *   per_page:int,
 *   base_url:string,
 *   form_id:string,
 *   html:string,
 *   meta_html:string,
 *   total:int,
 *   offset:int,
 *   limit:int
 * }
 */
function paginateRecordList(array $items, array $filters = [], string $formId = 'recordsFilterForm', string $singular = 'record', string $plural = 'records'): array {
    $paging = recordsListPaging(count($items), $filters, $formId, $singular, $plural);
    $paging['items'] = array_values(array_slice($items, $paging['offset'], $paging['limit']));
    return $paging;
}

function generateQRCodeData(string $verificationCode, string $requestNumber): string {
    return APP_URL . '/verify.php?code=' . urlencode($verificationCode) . '&ref=' . urlencode($requestNumber);
}

function exportCSV(array $headers, array $rows, string $filename): void {
    if (headers_sent($file, $line)) {
        throw new RuntimeException('Cannot export CSV because output already started in ' . $file . ' on line ' . $line . '.');
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

function getStatusCounts(): array {
    $db = getDB();
    $stmt = $db->query('SELECT status, COUNT(*) as count FROM requests GROUP BY status');
    $counts = [];
    while ($row = $stmt->fetch()) {
        $counts[$row['status']] = (int) $row['count'];
    }
    return $counts;
}

function getDashboardStats(): array {
    $db = getDB();
    $stats = [];

    $stats['total_requests'] = (int) $db->query('SELECT COUNT(*) FROM requests')->fetchColumn();
    $stats['pending'] = (int) $db->query("SELECT COUNT(*) FROM requests WHERE status NOT IN ('completed','rejected','cancelled')")->fetchColumn();
    $stats['completed'] = (int) $db->query("SELECT COUNT(*) FROM requests WHERE status = 'completed'")->fetchColumn();
    $stats['today'] = (int) $db->query('SELECT COUNT(*) FROM requests WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $stats['month'] = (int) $db->query('SELECT COUNT(*) FROM requests WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())')->fetchColumn();
    $stats['revenue'] = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'verified'")->fetchColumn();
    $stats['month_revenue'] = (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'verified' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();
    $stats['students'] = (int) $db->query("SELECT COUNT(*) FROM users WHERE role_id = 1")->fetchColumn();

    return $stats;
}

function validateRequired(array $fields, array $data): array {
    $errors = [];
    foreach ($fields as $field => $label) {
        if (empty(trim($data[$field] ?? ''))) {
            $errors[$field] = "$label is required.";
        }
    }
    return $errors;
}

function fullName(array $user): string {
    $name = $user['first_name'];
    if (!empty($user['middle_name'])) $name .= ' ' . substr($user['middle_name'], 0, 1) . '.';
    $name .= ' ' . $user['last_name'];
    return $name;
}

/**
 * Student records display name: LASTNAME, FIRSTNAME MIDDLENAME
 */
function studentRecordName(array $user): string {
    $last = normalizePersonName($user['last_name'] ?? '');
    $first = normalizePersonName($user['first_name'] ?? '');
    $middle = normalizePersonName($user['middle_name'] ?? '');
    $given = trim($first . ($middle !== '' ? ' ' . $middle : ''));

    if ($last !== '' && $given !== '') {
        return $last . ', ' . $given;
    }

    return $last !== '' ? $last : $given;
}

/**
 * Normalize a person name field to uppercase for consistent storage/display.
 */
function normalizePersonName(?string $name): string {
    $name = trim((string) $name);
    if ($name === '') {
        return '';
    }

    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($name, 'UTF-8');
    }

    return strtoupper($name);
}

function statCardLink(string $url, string $iconClass, string $icon, string $value, string $label): string {
    return '<a href="' . e($url) . '" class="stat-card stat-card-link">'
        . '<div class="stat-icon ' . e($iconClass) . '"><i class="fas ' . e($icon) . '"></i></div>'
        . '<div class="stat-info"><h3>' . $value . '</h3><p>' . e($label) . '</p></div>'
        . '</a>';
}

require_once __DIR__ . '/student.php';
require_once __DIR__ . '/programs.php';
require_once __DIR__ . '/campuses.php';
require_once __DIR__ . '/student-view.php';
require_once __DIR__ . '/document-rules.php';
require_once __DIR__ . '/purpose-suggestions.php';
