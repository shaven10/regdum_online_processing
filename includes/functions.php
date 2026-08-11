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

function formatDate(?string $date, string $format = 'M d, Y'): string {
    if (!$date) return '—';
    return date($format, strtotime($date));
}

function formatDateTime(?string $datetime): string {
    if (!$datetime) return '—';
    return date('M d, Y h:i A', strtotime($datetime));
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
    $db->prepare('UPDATE requests SET status = ?, updated_at = NOW() WHERE id = ?')
       ->execute([$newStatus, $requestId]);

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
            $db->prepare('UPDATE requests SET completed_at = COALESCE(completed_at, NOW()) WHERE id = ?')->execute([$requestId]);
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

function adminBatchUpdateRequestStatus(array $requestIds, string $newStatus, ?string $remarks = null): array {
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
    $totalPages = max(1, ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    return [
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
        'offset'      => ($page - 1) * $perPage,
    ];
}

function paginationLinks(array $pag, string $baseUrl): string {
    if ($pag['total_pages'] <= 1) return '';
    $html = '<nav class="pagination"><ul>';
    for ($i = 1; $i <= $pag['total_pages']; $i++) {
        $active = $i === $pag['page'] ? ' class="active"' : '';
        $html .= '<li' . $active . '><a href="' . $baseUrl . '&page=' . $i . '">' . $i . '</a></li>';
    }
    $html .= '</ul></nav>';
    return $html;
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
    $stats['pending'] = (int) $db->query("SELECT COUNT(*) FROM requests WHERE status NOT IN ('completed','rejected')")->fetchColumn();
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
require_once __DIR__ . '/document-rules.php';
require_once __DIR__ . '/purpose-suggestions.php';
