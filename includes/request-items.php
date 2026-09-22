<?php

function ensureRequestItemsSchema(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS request_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        document_type_id TINYINT UNSIGNED NOT NULL,
        copies INT NOT NULL DEFAULT 1,
        request_school_year VARCHAR(20) NULL,
        request_semester VARCHAR(30) NULL,
        request_soa_assessment_scope VARCHAR(30) NULL,
        request_soa_remarks VARCHAR(255) NULL,
        item_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        item_status ENUM('pending_assignment','processing','ready_for_pickup','completed') DEFAULT 'pending_assignment',
        assigned_to INT UNSIGNED NULL,
        release_date DATE NULL,
        release_time TIME NULL,
        pickup_date DATE NULL,
        pickup_time TIME NULL,
        verification_code VARCHAR(64) NULL,
        qr_code_path VARCHAR(255) NULL,
        pdf_path VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        completed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (document_type_id) REFERENCES document_types(id),
        FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
        KEY idx_request_items_request (request_id),
        KEY idx_request_items_assigned (assigned_to)
    )");

    $itemCol = $db->query("SHOW COLUMNS FROM request_assigned_requirements LIKE 'request_item_id'")->fetch();
    if (!$itemCol) {
        $db->exec('ALTER TABLE request_assigned_requirements ADD COLUMN request_item_id INT UNSIGNED NULL AFTER request_id');
        $db->exec('ALTER TABLE request_assigned_requirements ADD CONSTRAINT fk_assigned_requirements_item FOREIGN KEY (request_item_id) REFERENCES request_items(id) ON DELETE CASCADE');
    }

    $authItemCol = $db->query("SHOW COLUMNS FROM request_authentication_items LIKE 'request_item_id'")->fetch();
    if (!$authItemCol) {
        $db->exec('ALTER TABLE request_authentication_items ADD COLUMN request_item_id INT UNSIGNED NULL AFTER request_id');
        $db->exec('ALTER TABLE request_authentication_items ADD CONSTRAINT fk_auth_items_item FOREIGN KEY (request_item_id) REFERENCES request_items(id) ON DELETE CASCADE');
    }

    $docTypeCol = $db->query("SHOW COLUMNS FROM requests LIKE 'document_type_id'")->fetch();
    if ($docTypeCol && strtoupper((string) $docTypeCol['Null']) === 'NO') {
        $db->exec('ALTER TABLE requests MODIFY document_type_id TINYINT UNSIGNED NULL');
    }

    backfillRequestItemsFromRequests();
}

function backfillRequestItemsFromRequests(): void {
    $db = getDB();
    $stmt = $db->query('SELECT r.* FROM requests r LEFT JOIN request_items ri ON ri.request_id = r.id WHERE ri.id IS NULL');
    $requests = $stmt->fetchAll();

    foreach ($requests as $request) {
        if (empty($request['document_type_id'])) {
            continue;
        }

        $itemStatus = mapRequestStatusToItemStatus($request['status'] ?? 'submitted');
        $insert = $db->prepare('INSERT INTO request_items (
            request_id, document_type_id, copies, request_school_year, request_semester,
            request_soa_assessment_scope, request_soa_remarks, item_amount, item_status,
            assigned_to, release_date, release_time, pickup_date, pickup_time,
            verification_code, qr_code_path, pdf_path, sort_order, completed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');

        $insert->execute([
            $request['id'],
            $request['document_type_id'],
            $request['copies'] ?? 1,
            $request['request_school_year'] ?? null,
            $request['request_semester'] ?? null,
            $request['request_soa_assessment_scope'] ?? null,
            $request['request_soa_remarks'] ?? null,
            $request['total_amount'] ?? 0,
            $itemStatus,
            $request['assigned_to'] ?? null,
            $request['release_date'] ?? null,
            $request['release_time'] ?? null,
            $request['pickup_date'] ?? null,
            $request['pickup_time'] ?? null,
            $request['verification_code'] ?? null,
            $request['qr_code_path'] ?? null,
            $request['pdf_path'] ?? null,
            !empty($request['completed_at']) ? $request['completed_at'] : null,
        ]);
    }
}

function mapRequestStatusToItemStatus(string $requestStatus): string {
    return match ($requestStatus) {
        'processing' => 'processing',
        'ready_for_pickup', 'shipped' => 'ready_for_pickup',
        'completed' => 'completed',
        'payment_verified' => 'pending_assignment',
        default => 'pending_assignment',
    };
}

function getRequestItems(int $requestId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT ri.*, dt.name as document_name, dt.code as document_code, dt.processing_days,
            s.first_name as staff_first, s.last_name as staff_last
        FROM request_items ri
        JOIN document_types dt ON ri.document_type_id = dt.id
        LEFT JOIN users s ON ri.assigned_to = s.id
        WHERE ri.request_id = ?
        ORDER BY ri.sort_order, ri.id');
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
}

function getRequestItem(int $itemId): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT ri.*, dt.name as document_name, dt.code as document_code, dt.processing_days,
            r.request_number, r.user_id, r.status as request_status, r.purpose, r.delivery_method,
            u.first_name, u.last_name, u.email, u.student_id,
            s.first_name as staff_first, s.last_name as staff_last
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.id
        JOIN document_types dt ON ri.document_type_id = dt.id
        JOIN users u ON r.user_id = u.id
        LEFT JOIN users s ON ri.assigned_to = s.id
        WHERE ri.id = ?');
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    return $item ?: null;
}

function getRequestItemCount(int $requestId): int {
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM request_items WHERE request_id = ?');
    $stmt->execute([$requestId]);
    return (int) $stmt->fetchColumn();
}

/**
 * School year / semester suffix for a request line item (or legacy request row).
 */
function formatRequestItemTermSuffix(array $item): string {
    if (!function_exists('semesterLabel')) {
        require_once __DIR__ . '/student.php';
    }

    $schoolYear = trim((string) ($item['request_school_year'] ?? ''));
    $semester = trim((string) ($item['request_semester'] ?? ''));
    $parts = [];
    if ($schoolYear !== '') {
        $parts[] = $schoolYear;
    }
    if ($semester !== '') {
        $parts[] = semesterLabel($semester);
    }

    return $parts !== [] ? ' (' . implode(' · ', $parts) . ')' : '';
}

/**
 * @param int $maxNames Max document names to show; 0 or less shows all
 */
function formatRequestItemsSummary(array $items, int $maxNames = 2): string {
    if (empty($items)) {
        return '—';
    }

    $names = array_map(static function (array $item): string {
        return (string) ($item['document_name'] ?? 'Document') . formatRequestItemTermSuffix($item);
    }, $items);

    if ($maxNames <= 0 || count($names) <= $maxNames) {
        return implode(', ', $names);
    }

    $shown = array_slice($names, 0, $maxNames);
    $remaining = count($names) - $maxNames;
    return implode(', ', $shown) . ' +' . $remaining . ' more';
}

function createRequestItem(
    int $requestId,
    int $documentTypeId,
    int $copies,
    float $itemAmount,
    int $sortOrder,
    ?string $schoolYear = null,
    ?string $semester = null,
    ?string $soaScope = null,
    ?string $soaRemarks = null
): int {
    $db = getDB();
    $verificationCode = generateVerificationCode();
    $stmt = $db->prepare('INSERT INTO request_items (
        request_id, document_type_id, copies, request_school_year, request_semester,
        request_soa_assessment_scope, request_soa_remarks, item_amount, verification_code, sort_order
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $requestId,
        $documentTypeId,
        $copies,
        $schoolYear,
        $semester,
        $soaScope,
        $soaRemarks,
        $itemAmount,
        $verificationCode,
        $sortOrder,
    ]);

    return (int) $db->lastInsertId();
}

function refreshRequestTotalAmount(int $requestId): void {
    $db = getDB();
    $stmt = $db->prepare('SELECT COALESCE(SUM(item_amount), 0) FROM request_items WHERE request_id = ?');
    $stmt->execute([$requestId]);
    $total = (float) $stmt->fetchColumn();
    $db->prepare('UPDATE requests SET total_amount = ? WHERE id = ?')->execute([$total, $requestId]);
    syncPendingPaymentAmount($requestId);
}

function isTorDocumentCode(?string $code): bool {
    return strtoupper(trim((string) $code)) === 'TOR';
}

function isTorDocumentTypeId(int $documentTypeId): bool {
    if ($documentTypeId <= 0) {
        return false;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT code FROM document_types WHERE id = ? LIMIT 1');
    $stmt->execute([$documentTypeId]);
    return isTorDocumentCode($stmt->fetchColumn() ?: null);
}

function isTorRequestItem(array $item): bool {
    return isTorDocumentCode($item['document_code'] ?? null);
}

function torDocumentStampAmountForType(int $documentTypeId): float {
    if ($documentTypeId <= 0) {
        return 0.0;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT requires_documentary_stamp FROM document_types WHERE id = ? LIMIT 1');
    $stmt->execute([$documentTypeId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['requires_documentary_stamp'])) {
        return 0.0;
    }

    return documentStampFeeAmount();
}

function torItemBaseAmount(array $item): float {
    $total = (float) ($item['item_amount'] ?? 0);
    $stamp = torDocumentStampAmountForType((int) ($item['document_type_id'] ?? 0));

    return max(0, round($total - $stamp, 2));
}

function torLineTotalFromBaseAmount(int $documentTypeId, float $baseAmount): float {
    return max(0, round($baseAmount + torDocumentStampAmountForType($documentTypeId), 2));
}

function canModifyRequestItemAmounts(?string $requestStatus): bool {
    return in_array((string) $requestStatus, [
        'submitted',
        'under_review',
        'awaiting_requirements',
        'needs_revision',
        'requirements_submitted',
        'requirements_verified',
    ], true);
}

function resolveTorItemAmountOverride(int $documentTypeId, float $calculatedAmount, array $overrides): float {
    if (!isTorDocumentTypeId($documentTypeId)) {
        return $calculatedAmount;
    }

    if (!array_key_exists($documentTypeId, $overrides) && !array_key_exists((string) $documentTypeId, $overrides)) {
        return $calculatedAmount;
    }

    $raw = $overrides[$documentTypeId] ?? $overrides[(string) $documentTypeId] ?? '';
    $raw = trim((string) $raw);
    if ($raw === '') {
        return $calculatedAmount;
    }

    if (!is_numeric($raw)) {
        return $calculatedAmount;
    }

    return max(0, round((float) $raw, 2));
}

function syncPendingPaymentAmount(int $requestId): void {
    $db = getDB();
    $stmt = $db->prepare('SELECT total_amount FROM requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $total = (float) $stmt->fetchColumn();

    $db->prepare("UPDATE payments SET amount = ? WHERE request_id = ? AND status = 'pending'")
       ->execute([$total, $requestId]);
}

function updateRequestItemAmount(int $itemId, float $baseAmount, int $updatedBy, int $requestId): ?string {
    ensureRequestItemsSchema();

    $baseAmount = round($baseAmount, 2);
    if ($baseAmount < 0) {
        return 'Enter a valid TOR base amount.';
    }

    $item = getRequestItem($itemId);
    if (!$item || (int) ($item['request_id'] ?? 0) !== $requestId) {
        return 'TOR line item not found for this request.';
    }

    if (!isTorRequestItem($item)) {
        return 'Only TOR amounts can be modified from this screen.';
    }

    if (!canModifyRequestItemAmounts($item['request_status'] ?? null)) {
        return 'TOR amount can no longer be changed after payment has started.';
    }

    $db = getDB();
    $verifiedPayment = $db->prepare("SELECT id FROM payments WHERE request_id = ? AND status = 'verified' LIMIT 1");
    $verifiedPayment->execute([$requestId]);
    if ($verifiedPayment->fetch()) {
        return 'Payment has already been verified for this request.';
    }

    $documentTypeId = (int) ($item['document_type_id'] ?? 0);
    $stampAmount = torDocumentStampAmountForType($documentTypeId);
    $lineTotal = torLineTotalFromBaseAmount($documentTypeId, $baseAmount);
    $previousAmount = (float) ($item['item_amount'] ?? 0);
    $db->prepare('UPDATE request_items SET item_amount = ? WHERE id = ?')
       ->execute([$lineTotal, $itemId]);

    refreshRequestTotalAmount($requestId);

    auditLog('update_tor_item_amount', 'request_items', $itemId, [
        'previous_amount' => $previousAmount,
        'previous_base_amount' => torItemBaseAmount($item),
    ], [
        'base_amount' => $baseAmount,
        'stamp_amount' => $stampAmount,
        'line_total' => $lineTotal,
        'updated_by' => $updatedBy,
        'request_id' => $requestId,
    ]);

    return null;
}

function prepareRequestItemsAfterPayment(int $requestId): void {
    $db = getDB();
    $db->prepare("UPDATE request_items SET item_status = 'pending_assignment' WHERE request_id = ? AND item_status = 'pending_assignment'")
       ->execute([$requestId]);
}

function assignRequestItemProcessing(
    int $itemId,
    int $staffId,
    string $releaseDate,
    string $releaseTime,
    int $assignedBy
): bool {
    $item = getRequestItem($itemId);
    if (!$item || ($item['request_status'] ?? '') !== 'payment_verified') {
        if (!$item || !in_array($item['request_status'] ?? '', ['payment_verified', 'processing'], true)) {
            return false;
        }
    }

    if ($item['item_status'] !== 'pending_assignment' && $item['item_status'] !== 'processing') {
        return false;
    }

    if (!$staffId || !$releaseDate || !$releaseTime) {
        return false;
    }

    $db = getDB();
    $assigneeRole = $db->prepare('SELECT r.name AS role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ?');
    $assigneeRole->execute([$staffId]);
    $roleName = (string) ($assigneeRole->fetchColumn() ?: '');
    if ($roleName === 'accounting') {
        require_once __DIR__ . '/accounting.php';
        if (!isSoaDocumentAssignment($item)) {
            return false;
        }
    }

    $db->prepare('UPDATE request_items SET assigned_to = ?, release_date = ?, release_time = ?, pickup_date = ?, pickup_time = ?, item_status = ? WHERE id = ?')
       ->execute([$staffId, $releaseDate, $releaseTime, $releaseDate, $releaseTime, 'processing', $itemId]);

    syncRequestAssignmentSummary((int) $item['request_id']);
    syncRequestBatchStatus((int) $item['request_id']);

    sendNotification(
        (int) $item['user_id'],
        'Document Processing Started',
        'Processing has started for ' . ($item['document_name'] ?? 'a document') . ' in request ' . $item['request_number'] . '.',
        'info',
        APP_URL . '/student/request-view.php?id=' . (int) $item['request_id']
    );

    require_once __DIR__ . '/assignment-offices.php';
    sendNotification(
        $staffId,
        'New Assignment',
        'Document "' . ($item['document_name'] ?? '') . '" from request ' . $item['request_number'] . ' has been assigned to you.',
        'info',
        assignmentProcessUrlForUser($staffId, $itemId)
    );

    auditLog('request_item_assigned', 'request_items', $itemId, null, ['assigned_to' => $staffId, 'assigned_by' => $assignedBy]);
    return true;
}

/**
 * Assign all pending document items on each selected request to one staff member.
 *
 * @return array{ok:bool,assigned_requests:int,assigned_items:int,skipped:int,failed:array<int,string>}
 */
function batchAssignRequestsProcessing(
    array $requestIds,
    int $staffId,
    string $releaseDate,
    string $releaseTime,
    int $assignedBy
): array {
    if (!function_exists('normalizeAdminBatchRequestIds')) {
        require_once __DIR__ . '/functions.php';
    }

    $result = [
        'ok' => false,
        'assigned_requests' => 0,
        'assigned_items' => 0,
        'skipped' => 0,
        'failed' => [],
    ];

    $requestIds = normalizeAdminBatchRequestIds($requestIds);
    $releaseDate = trim($releaseDate);
    $releaseTime = trim($releaseTime);

    if ($requestIds === []) {
        $result['failed'][] = 'Select at least one request.';
        return $result;
    }
    if ($staffId <= 0) {
        $result['failed'][] = 'Select a staff assignee.';
        return $result;
    }
    if ($releaseDate === '' || $releaseTime === '') {
        $result['failed'][] = 'Release date and time are required.';
        return $result;
    }

    $db = getDB();
    $assignee = $db->prepare('SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = ? AND u.is_active = 1');
    $assignee->execute([$staffId]);
    if (!$assignee->fetch()) {
        $result['failed'][] = 'Selected assignee is not available.';
        return $result;
    }

    foreach ($requestIds as $requestId) {
        $reqStmt = $db->prepare('SELECT id, status, request_number FROM requests WHERE id = ?');
        $reqStmt->execute([$requestId]);
        $request = $reqStmt->fetch();
        if (!$request) {
            $result['failed'][] = 'Request #' . $requestId . ' not found.';
            continue;
        }

        $canAssign = $request['status'] === 'payment_verified'
            || ($request['status'] === 'processing' && requestHasPendingAssignmentItems($requestId));
        if (!$canAssign) {
            $result['skipped']++;
            $result['failed'][] = ($request['request_number'] ?? ('#' . $requestId))
                . ' is not awaiting staff assignment.';
            continue;
        }

        $items = getRequestItems($requestId);
        $pendingItems = array_values(array_filter(
            $items,
            static fn(array $item): bool => ($item['item_status'] ?? '') === 'pending_assignment'
        ));
        if ($pendingItems === [] && count($items) === 1 && ($items[0]['item_status'] ?? '') === 'processing' && empty($items[0]['assigned_to'])) {
            $pendingItems = $items;
        }
        if ($pendingItems === []) {
            $result['skipped']++;
            $result['failed'][] = ($request['request_number'] ?? ('#' . $requestId))
                . ' has no documents waiting for assignment.';
            continue;
        }

        $assignedForRequest = 0;
        foreach ($pendingItems as $item) {
            if (assignRequestItemProcessing((int) $item['id'], $staffId, $releaseDate, $releaseTime, $assignedBy)) {
                $assignedForRequest++;
                $result['assigned_items']++;
            }
        }

        if ($assignedForRequest > 0) {
            $result['assigned_requests']++;
            auditLog('request_batch_assigned', 'requests', $requestId, null, [
                'items_assigned' => $assignedForRequest,
                'assigned_to' => $staffId,
                'batch' => true,
            ]);
        } else {
            $result['failed'][] = ($request['request_number'] ?? ('#' . $requestId))
                . ' could not be assigned (check document office rules).';
        }
    }

    $result['ok'] = $result['assigned_requests'] > 0;
    // Keep failure list useful but not huge when many skips.
    if (count($result['failed']) > 20) {
        $extra = count($result['failed']) - 20;
        $result['failed'] = array_slice($result['failed'], 0, 20);
        $result['failed'][] = '…and ' . $extra . ' more issue' . ($extra === 1 ? '' : 's') . '.';
    }

    return $result;
}

function updateRequestItemStatus(int $itemId, string $status): bool {
    if (!in_array($status, ['processing', 'ready_for_pickup', 'completed'], true)) {
        return false;
    }

    $item = getRequestItem($itemId);
    if (!$item) {
        return false;
    }

    $db = getDB();
    $completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
    $db->prepare('UPDATE request_items SET item_status = ?, completed_at = ? WHERE id = ?')
       ->execute([$status, $completedAt, $itemId]);

    syncRequestBatchStatus((int) $item['request_id']);
    return true;
}

/**
 * Batch-update assigned document items for a processor.
 * Targets are limited to ready_for_pickup (from processing) and completed (from ready_for_pickup).
 *
 * @param list<int|string> $requestIds
 * @param list<string>|null $allowedDocumentCodes
 * @return array{updated:int,skipped:int,failed:list<string>,ok:bool}
 */
function batchUpdateAssignedRequestItemStatuses(
    array $requestIds,
    string $newStatus,
    int $staffId,
    ?array $allowedDocumentCodes = null
): array {
    $result = [
        'updated' => 0,
        'skipped' => 0,
        'failed' => [],
        'ok' => false,
    ];

    if (!in_array($newStatus, ['ready_for_pickup', 'completed'], true)) {
        $result['failed'][] = 'Status can only be updated to Ready for Pickup or Completed.';
        return $result;
    }

    if ($staffId <= 0) {
        $result['failed'][] = 'Invalid processor account.';
        return $result;
    }

    $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds), static fn(int $id): bool => $id > 0)));
    if ($requestIds === []) {
        $result['failed'][] = 'Select at least one assignment.';
        return $result;
    }

    $allowedCodes = null;
    if (is_array($allowedDocumentCodes) && $allowedDocumentCodes !== []) {
        $allowedCodes = array_values(array_filter(array_map(
            static fn($code): string => strtoupper(trim((string) $code)),
            $allowedDocumentCodes
        )));
        if ($allowedCodes === []) {
            $allowedCodes = null;
        }
    }

    $requiredCurrent = $newStatus === 'ready_for_pickup' ? 'processing' : 'ready_for_pickup';
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $stmt = getDB()->prepare(
        "SELECT ri.id, ri.item_status, ri.request_id, r.request_number, dt.name AS document_name, dt.code AS document_code
         FROM request_items ri
         JOIN requests r ON r.id = ri.request_id
         JOIN document_types dt ON dt.id = ri.document_type_id
         WHERE ri.assigned_to = ?
           AND ri.request_id IN ($placeholders)
         ORDER BY r.request_number ASC, ri.id ASC"
    );
    $stmt->execute(array_merge([$staffId], $requestIds));
    $rows = $stmt->fetchAll();

    if ($rows === []) {
        $result['failed'][] = 'No assignments found for the selected requests.';
        return $result;
    }

    foreach ($rows as $row) {
        $itemId = (int) ($row['id'] ?? 0);
        $requestNumber = (string) ($row['request_number'] ?? ('#' . (int) ($row['request_id'] ?? 0)));
        $documentName = (string) ($row['document_name'] ?? 'Document');
        $label = $requestNumber . ' — ' . $documentName;
        $currentStatus = (string) ($row['item_status'] ?? '');

        if ($allowedCodes !== null) {
            $code = strtoupper(trim((string) ($row['document_code'] ?? '')));
            if (!in_array($code, $allowedCodes, true)) {
                $result['skipped']++;
                continue;
            }
        }

        if ($currentStatus === $newStatus) {
            $result['skipped']++;
            continue;
        }

        if ($currentStatus !== $requiredCurrent) {
            $result['skipped']++;
            continue;
        }

        if (!updateRequestItemStatus($itemId, $newStatus)) {
            $result['failed'][] = 'Unable to update ' . $label . '.';
            continue;
        }

        $result['updated']++;
    }

    $result['ok'] = $result['updated'] > 0 || ($result['skipped'] > 0 && $result['failed'] === []);
    return $result;
}

function syncRequestBatchStatus(int $requestId): void {
    $db = getDB();
    $stmt = $db->prepare('SELECT item_status FROM request_items WHERE request_id = ?');
    $stmt->execute([$requestId]);
    $statuses = array_column($stmt->fetchAll(), 'item_status');

    if (empty($statuses)) {
        return;
    }

    $requestStmt = $db->prepare('SELECT status FROM requests WHERE id = ?');
    $requestStmt->execute([$requestId]);
    $currentStatus = (string) ($requestStmt->fetchColumn() ?: '');

    if (!in_array($currentStatus, ['payment_verified', 'processing', 'ready_for_pickup', 'shipped', 'completed'], true)) {
        return;
    }

    $allCompleted = count(array_filter($statuses, static fn($s) => $s === 'completed')) === count($statuses);
    $allReadyOrDone = count(array_filter($statuses, static fn($s) => in_array($s, ['ready_for_pickup', 'completed'], true))) === count($statuses);
    $anyProcessing = count(array_filter($statuses, static fn($s) => in_array($s, ['processing', 'ready_for_pickup'], true))) > 0;

    if ($allCompleted) {
        if ($currentStatus !== 'completed') {
            updateRequestStatus($requestId, 'completed', 'All documents in this request have been released');
            $db->prepare('UPDATE requests SET completed_at = NOW() WHERE id = ?')->execute([$requestId]);
        }
        return;
    }

    if ($allReadyOrDone) {
        if ($currentStatus !== 'ready_for_pickup') {
            updateRequestStatus($requestId, 'ready_for_pickup', 'All documents are ready for pickup');
        }
        return;
    }

    if ($anyProcessing) {
        if ($currentStatus !== 'processing') {
            updateRequestStatus($requestId, 'processing', 'Document processing in progress');
        }
    }
}

function getStaffAssignedItems(int $staffId, string $status = ''): array {
    require_once __DIR__ . '/payments.php';
    if (!function_exists('ensureOnsiteRequestSchema')) {
        require_once __DIR__ . '/onsite-request.php';
    }
    ensurePaymentVerificationSchema();
    ensureOnsiteRequestSchema();

    $db = getDB();
    $where = ['ri.assigned_to = ?'];
    $params = [$staffId];

    if ($status !== '') {
        $where[] = 'ri.item_status = ?';
        $params[] = $status;
    } else {
        $where[] = "ri.item_status IN ('processing', 'ready_for_pickup')";
    }

    $sql = 'SELECT ri.*, dt.name as document_name, dt.code as document_code, r.request_number, r.status as request_status,
            r.request_channel, r.onsite_batch_key,
            r.release_date AS request_release_date, r.release_time AS request_release_time,
            r.request_school_year AS request_level_school_year, r.request_semester AS request_level_semester,
            u.first_name, u.last_name, u.middle_name, u.student_id,
            sp.course, sp.year_level, sp.enrollment_status,
            ap.code AS program_code, ap.name AS program_name
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.id
        JOIN document_types dt ON ri.document_type_id = dt.id
        JOIN users u ON r.user_id = u.id
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN academic_programs ap ON ap.id = sp.course_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ri.updated_at DESC, ri.id DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        if (empty($row['release_date']) && !empty($row['request_release_date'])) {
            $row['release_date'] = $row['request_release_date'];
            $row['release_time'] = $row['request_release_time'] ?? null;
        }
    }
    unset($row);

    return decorateAssignedItemsWithPaymentMethod(decorateAssignedItemsWithRequestDocuments($rows));
}

/**
 * Documents on a request assigned to a specific processor account.
 *
 * @param list<string>|null $allowedDocumentCodes
 * @return list<array<string,mixed>>
 */
function getProcessorAssignedItemsForRequest(int $requestId, int $staffId, ?array $allowedDocumentCodes = null): array {
    if ($requestId <= 0 || $staffId <= 0) {
        return [];
    }

    $allowedCodes = null;
    if (is_array($allowedDocumentCodes) && $allowedDocumentCodes !== []) {
        $allowedCodes = array_values(array_filter(array_map(
            static fn($code): string => strtoupper(trim((string) $code)),
            $allowedDocumentCodes
        )));
        if ($allowedCodes === []) {
            $allowedCodes = null;
        }
    }

    return array_values(array_filter(getRequestItems($requestId), static function (array $item) use ($staffId, $allowedCodes): bool {
        if ((int) ($item['assigned_to'] ?? 0) !== $staffId) {
            return false;
        }

        if ($allowedCodes === null) {
            return true;
        }

        return in_array(strtoupper(trim((string) ($item['document_code'] ?? ''))), $allowedCodes, true);
    }));
}

function assignedRequestDocumentName(array $item): string {
    $name = trim((string) ($item['document_name'] ?? 'Document'));
    return $name !== '' ? $name : 'Document';
}

function assignedRequestDocumentAbbreviation(array $item): string {
    $code = strtoupper(trim((string) ($item['document_code'] ?? '')));
    return $code !== '' ? $code : assignedRequestDocumentName($item);
}

function assignedRequestCopyLabel(int $copies): string {
    $copies = max(1, $copies);
    return $copies . ' cop' . ($copies === 1 ? 'y' : 'ies');
}

function assignedItemTermLabel(array $item): string {
    $year = assignedItemSchoolYear($item);
    $semester = assignedItemSemester($item);
    $parts = [];
    if ($year !== '—') {
        $parts[] = $year;
    }
    if ($semester !== '—') {
        $parts[] = $semester;
    }

    return implode(' · ', $parts);
}

/**
 * @return list<array{id:int,name:string,full_name:string,code:string,copies:int,copies_label:string,term:string,status:string,label:string}>
 */
function assignedRequestDocumentEntries(array $items): array {
    $entries = [];
    foreach ($items as $item) {
        $fullName = assignedRequestDocumentName($item);
        $abbrev = assignedRequestDocumentAbbreviation($item);
        $year = assignedItemSchoolYear($item);
        $semester = assignedItemSemester($item);
        $copies = max(1, (int) ($item['copies'] ?? 1));
        $termParts = [];
        if ($year !== '—') {
            $termParts[] = $year;
        }
        if ($semester !== '—') {
            $termParts[] = $semester;
        }
        $term = $termParts !== [] ? implode(' · ', $termParts) : '';
        $label = $abbrev;
        if ($year !== '—') {
            $label .= '-' . $year;
        }
        if ($semester !== '—') {
            $label .= ' · ' . $semester;
        }
        if ($copies > 1) {
            $label .= ' ×' . $copies;
        }
        $entries[] = [
            'id' => (int) ($item['id'] ?? 0),
            'name' => $abbrev,
            'full_name' => $fullName,
            'code' => $abbrev !== $fullName ? $abbrev : '',
            'copies' => $copies,
            'copies_label' => $copies > 1 ? '×' . $copies : '',
            'year' => $year !== '—' ? $year : '',
            'term' => $term,
            'status' => trim((string) ($item['item_status'] ?? '')),
            'label' => $label,
        ];
    }

    return $entries;
}

/**
 * @return list<string>
 */
function assignedRequestDocumentLabels(array $items): array {
    return array_map(
        static fn(array $entry): string => $entry['label'],
        assignedRequestDocumentEntries($items)
    );
}

function assignedItemSchoolYear(array $item): string {
    $value = trim((string) ($item['request_school_year'] ?? ''));
    if ($value === '') {
        $value = trim((string) ($item['request_level_school_year'] ?? ''));
    }

    return $value !== '' ? $value : '—';
}

function assignedItemSemester(array $item): string {
    if (!function_exists('semesterLabel')) {
        require_once __DIR__ . '/student.php';
    }

    $value = trim((string) ($item['request_semester'] ?? ''));
    if ($value === '') {
        $value = trim((string) ($item['request_level_semester'] ?? ''));
    }
    if ($value === '') {
        return '—';
    }

    $label = semesterLabel($value);
    return $label !== '—' ? $label : $value;
}

function assignedItemDocumentSummary(array $item): string {
    $summary = trim((string) ($item['document_summary'] ?? ''));
    if ($summary !== '') {
        return $summary;
    }

    $labels = $item['document_labels'] ?? [];
    if ($labels !== []) {
        return implode(', ', $labels);
    }

    $name = trim((string) ($item['document_name'] ?? ''));
    return $name !== '' ? $name : '—';
}

function renderAssignedDocumentLabelsHtml(array $item): string {
    $entries = $item['document_entries'] ?? [];
    if ($entries === []) {
        $labels = $item['document_labels'] ?? [];
        if ($labels === []) {
            return e(assignedItemDocumentSummary($item));
        }
        $entries = array_map(
            static fn(string $label): array => [
                'name' => $label,
                'full_name' => $label,
                'label' => $label,
                'term' => '',
                'copies' => 0,
                'copies_label' => '',
            ],
            $labels
        );
    }

    $html = '<div class="assigned-document-list">';
    foreach ($entries as $entry) {
        $abbrev = trim((string) ($entry['name'] ?? 'Document'));
        if ($abbrev === '') {
            $abbrev = 'Document';
        }
        $copiesLabel = trim((string) ($entry['copies_label'] ?? ''));
        $year = trim((string) ($entry['year'] ?? ''));
        $term = trim((string) ($entry['term'] ?? ''));
        $fullName = trim((string) ($entry['full_name'] ?? ''));
        $nameTitle = $fullName !== '' && strcasecmp($fullName, $abbrev) !== 0
            ? $fullName
            : '';
        $displayName = $copiesLabel !== '' ? $abbrev . ' ' . $copiesLabel : $abbrev;
        $termDisplay = '';
        if ($term !== '') {
            $termDisplay = ($year !== '' ? '-' : ' · ') . $term;
        }

        $html .= '<div class="assigned-document-item">';
        $html .= '<span class="assigned-document-name"'
            . ($nameTitle !== '' ? ' title="' . e($nameTitle) . '"' : '')
            . '>' . e($displayName) . '</span>';
        if ($termDisplay !== '') {
            $html .= '<span class="assigned-document-term">' . e($termDisplay) . '</span>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Label a grouped assignment row using only the supplied (assigned) documents.
 *
 * @param array<string,mixed> $row
 * @param list<array<string,mixed>> $sourceItems
 * @return array<string,mixed>
 */
function applyAssignedItemsDocumentLabels(array $row, array $sourceItems): array {
    if ($sourceItems === []) {
        $sourceItems = [$row];
    }

    usort($sourceItems, static function (array $left, array $right): int {
        $order = ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0));
        if ($order !== 0) {
            return $order;
        }

        return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
    });

    $entries = assignedRequestDocumentEntries($sourceItems);
    $row['document_entries'] = $entries;
    $row['document_labels'] = array_map(
        static fn(array $entry): string => $entry['label'],
        $entries
    );
    $row['document_summary'] = $row['document_labels'] !== []
        ? implode(', ', $row['document_labels'])
        : '—';

    return $row;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function decorateAssignedItemsWithRequestDocuments(array $rows): array {
    foreach ($rows as &$row) {
        $row = applyAssignedItemsDocumentLabels($row, [$row]);
    }
    unset($row);

    return $rows;
}

/**
 * Attach the latest payment method (and Single/Multiple scope) to assignment rows.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function decorateAssignedItemsWithPaymentMethod(array $rows): array {
    require_once __DIR__ . '/payments.php';

    $requestIds = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int) ($row['request_id'] ?? 0),
        $rows
    ))));

    $paymentsByRequest = [];
    if ($requestIds !== []) {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = $db->prepare(
            "SELECT p.id, p.request_id, p.payment_method, p.status, p.or_number, p.payment_date,
                    r.onsite_batch_key, r.request_channel
             FROM payments p
             JOIN requests r ON r.id = p.request_id
             WHERE p.request_id IN ($placeholders)
             ORDER BY (p.status = 'verified') DESC, p.id DESC"
        );
        $stmt->execute($requestIds);
        foreach ($stmt->fetchAll() as $payment) {
            $requestId = (int) ($payment['request_id'] ?? 0);
            if ($requestId > 0 && !isset($paymentsByRequest[$requestId])) {
                $paymentsByRequest[$requestId] = $payment;
            }
        }
        $decorated = decoratePaymentsWithBatchMeta(array_values($paymentsByRequest));
        $paymentsByRequest = [];
        foreach ($decorated as $payment) {
            $paymentsByRequest[(int) $payment['request_id']] = $payment;
        }
    }

    foreach ($rows as &$row) {
        $payment = $paymentsByRequest[(int) ($row['request_id'] ?? 0)] ?? null;
        if ($payment === null && trim((string) ($row['payment_method'] ?? '')) !== '') {
            $payment = $row;
        }

        if ($payment) {
            $row['payment_method'] = $payment['payment_method'] ?? ($row['payment_method'] ?? null);
            $row['or_number'] = trim((string) ($payment['or_number'] ?? ($row['or_number'] ?? '')));
            $row['payment_date'] = $payment['payment_date'] ?? ($row['payment_date'] ?? null);
            $row['is_multiple'] = !empty($payment['is_multiple']);
            $row['batch_size'] = (int) ($payment['batch_size'] ?? 1);
            $row['payment_method_label'] = paymentMethodLabel($row['payment_method'] ?? null);
            $row['payment_scope_label'] = paymentScopeLabel($payment);
            $row['payment_method_scope_label'] = paymentMethodScopeLabel($payment);
        } else {
            $row['or_number'] = trim((string) ($row['or_number'] ?? ''));
            $row['payment_date'] = $row['payment_date'] ?? null;
            $row['payment_method_label'] = '—';
            $row['payment_scope_label'] = '';
            $row['payment_method_scope_label'] = '—';
            $row['is_multiple'] = false;
            $row['batch_size'] = 1;
        }
    }
    unset($row);

    return $rows;
}

function assignedItemMethodLabel(array $item): string {
    $label = trim((string) ($item['payment_method_scope_label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    if (trim((string) ($item['payment_method'] ?? '')) !== '') {
        require_once __DIR__ . '/payments.php';
        return paymentMethodLabel($item['payment_method'] ?? null);
    }

    return '—';
}

function assignedItemOrNumber(array $item): string {
    $or = trim((string) ($item['or_number'] ?? ''));
    return $or !== '' ? $or : '—';
}

function assignedItemPaymentDateLabel(array $item): string {
    $date = $item['payment_date'] ?? null;
    if ($date === null || trim((string) $date) === '') {
        return '—';
    }

    return formatDate((string) $date);
}

function assignedItemReleaseLabel(array $item): string {
    if (empty($item['release_date'])) {
        return '—';
    }

    $label = formatDate((string) $item['release_date']);
    if (!empty($item['release_time'])) {
        $label .= ' · ' . date('g:i A', strtotime((string) $item['release_time']));
    }

    return $label;
}

function renderAssignedItemMethodHtml(array $item): string {
    $method = trim((string) ($item['payment_method_label'] ?? ''));
    if ($method === '' || $method === '—') {
        return '—';
    }

    $method = trim((string) preg_replace('/\s+payment$/i', '', $method));
    $scope = trim((string) ($item['payment_scope_label'] ?? ''));
    $html = '<div class="assigned-method">';
    $html .= '<small class="assigned-method-name">' . e($method !== '' ? $method : '—') . '</small>';
    if ($scope !== '') {
        $scopeText = !empty($item['is_multiple'])
            ? 'Multiple · ' . max(2, (int) ($item['batch_size'] ?? 2))
            : 'Single';
        $html .= '<small class="payment-scope-pill ' . (!empty($item['is_multiple']) ? 'is-multiple' : 'is-single') . '">'
            . e($scopeText)
            . '</small>';
    }
    $html .= '</div>';

    return $html;
}

function assignedStudentCourseLabel(array $item): string {
    $code = trim((string) ($item['program_code'] ?? ''));
    if ($code !== '') {
        return $code;
    }

    $course = trim((string) ($item['course'] ?? ''));
    return $course !== '' ? $course : '—';
}

function assignedStudentYearLabel(array $item): string {
    $year = trim((string) ($item['year_level'] ?? ''));
    return $year !== '' ? $year : '—';
}

function assignedStudentCourseYearLabel(array $item): string {
    $course = assignedStudentCourseLabel($item);
    $year = assignedStudentYearLabel($item);
    $parts = [];
    if ($course !== '—') {
        $parts[] = $course;
    }
    if ($year !== '—') {
        $parts[] = $year;
    }

    return $parts !== [] ? implode(' · ', $parts) : '—';
}

function assignedStudentEnrollmentLabel(array $item): string {
    if (!function_exists('enrollmentStatusLabel')) {
        require_once __DIR__ . '/student.php';
    }

    $label = trim(enrollmentStatusLabel($item['enrollment_status'] ?? null));
    return $label !== '' ? $label : '—';
}

function assignedStudentCourseYearEnrollmentLabel(array $item): string {
    $courseYear = assignedStudentCourseYearLabel($item);
    $enrollment = assignedStudentEnrollmentLabel($item);
    if ($courseYear === '—' && $enrollment === '—') {
        return '—';
    }
    if ($enrollment === '—') {
        return $courseYear;
    }
    if ($courseYear === '—') {
        return $enrollment;
    }

    return $courseYear . ' · ' . $enrollment;
}

function renderAssignedStudentCourseYearHtml(array $item): string {
    $course = assignedStudentCourseLabel($item);
    $year = assignedStudentYearLabel($item);
    $enrollment = assignedStudentEnrollmentLabel($item);
    $metaParts = [];
    if ($year !== '—') {
        $metaParts[] = $year;
    }
    if ($enrollment !== '—') {
        $metaParts[] = $enrollment;
    }

    if ($course === '—' && $metaParts === []) {
        return '—';
    }

    $html = '<div class="assigned-course-year">';
    if ($course !== '—') {
        $html .= '<div class="assigned-course-year-course">' . e($course) . '</div>';
    }
    if ($metaParts !== []) {
        $html .= '<small class="assigned-course-year-year text-muted">' . e(implode(' · ', $metaParts)) . '</small>';
    }
    $html .= '</div>';

    return $html;
}

function assignedStudentNameLabel(array $item): string {
    $name = studentRecordName($item);
    return $name !== '' ? $name : '—';
}

function assignedStudentIdLabel(array $item): string {
    $studentId = trim((string) ($item['student_id'] ?? ''));
    return $studentId !== '' ? $studentId : '—';
}

function assignedStudentNameIdLabel(array $item): string {
    $name = assignedStudentNameLabel($item);
    $studentId = assignedStudentIdLabel($item);
    if ($name === '—' && $studentId === '—') {
        return '—';
    }
    if ($studentId === '—') {
        return $name;
    }
    if ($name === '—') {
        return $studentId;
    }

    return $name . ' (' . $studentId . ')';
}

function renderAssignedStudentNameIdHtml(array $item): string {
    $name = assignedStudentNameLabel($item);
    $studentId = assignedStudentIdLabel($item);
    if ($name === '—' && $studentId === '—') {
        return '—';
    }
    if ($studentId === '—') {
        return e($name);
    }
    if ($name === '—') {
        return e($studentId);
    }

    return '<div class="assigned-student-name-id">'
        . '<div class="assigned-student-name">' . e($name) . '</div>'
        . '<small class="assigned-student-id text-muted">' . e($studentId) . '</small>'
        . '</div>';
}

function exportAssignedDocumentsCsv(array $items, string $filename = 'my_assignments.csv'): void {
    require_once __DIR__ . '/student.php';
    $rows = [];
    foreach ($items as $item) {
        $rows[] = [
            (string) ($item['request_number'] ?? ''),
            assignedItemDocumentSummary($item),
            assignedStudentNameIdLabel($item),
            assignedStudentCourseYearEnrollmentLabel($item),
            assignedItemReleaseLabel($item),
            assignedItemStatusesLabel($item),
        ];
    }

    exportCSV(
        ['Request #', 'Document/s Requested', 'Student', 'Course / Enrollment', 'Release Date', 'Status'],
        $rows,
        $filename
    );
}

function requestItemStatusLabel(string $status): string {
    return match ($status) {
        'pending_assignment' => 'Awaiting Assignment',
        'processing' => 'Processing',
        'ready_for_pickup' => 'Ready for Pickup',
        'completed' => 'Completed',
        'mixed' => 'Multiple statuses',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function requestItemStatusBadge(string $status): string {
    $class = match ($status) {
        'pending_assignment' => 'badge-orange',
        'processing' => 'badge-blue',
        'ready_for_pickup' => 'badge-green',
        'completed' => 'badge-gray',
        'mixed' => 'badge-orange',
        default => 'badge-gray',
    };

    return '<span class="badge ' . $class . '">' . e(requestItemStatusLabel($status)) . '</span>';
}

function assignedItemDocStatusLabel(array $item): string {
    $status = (string) ($item['item_status'] ?? '');
    if ($status === 'mixed' && !empty($item['item_status_detail'])) {
        return (string) $item['item_status_detail'];
    }

    return requestItemStatusLabel($status);
}

function assignedItemBatchStatusLabel(array $item): string {
    $status = trim((string) ($item['request_status'] ?? ''));
    return $status !== '' ? ucwords(str_replace('_', ' ', $status)) : '—';
}

function assignedItemStatusesLabel(array $item): string {
    $doc = assignedItemDocStatusLabel($item);
    $batch = assignedItemBatchStatusLabel($item);
    $parts = [];
    if ($doc !== '' && $doc !== '—') {
        $parts[] = 'Docs: ' . $doc;
    }
    if ($batch !== '' && $batch !== '—') {
        $parts[] = 'Batch: ' . $batch;
    }

    return $parts !== [] ? implode(' · ', $parts) : '—';
}

function renderAssignedStatusesHtml(array $item): string {
    $docStatus = (string) ($item['item_status'] ?? '');
    $batchStatus = trim((string) ($item['request_status'] ?? ''));
    $html = '<div class="assigned-status">';
    $html .= '<div class="assigned-status-row">';
    $html .= '<small class="assigned-status-label">Docs -</small>';
    $html .= requestItemStatusBadge($docStatus);
    if ($docStatus === 'mixed' && !empty($item['item_status_detail'])) {
        $html .= '<small class="assigned-status-detail text-muted">' . e((string) $item['item_status_detail']) . '</small>';
    }
    $html .= '</div>';
    if ($batchStatus !== '') {
        $html .= '<div class="assigned-status-row">';
        $html .= '<small class="assigned-status-label">Batch -</small>';
        $html .= statusBadge($batchStatus);
        $html .= '</div>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Collapse staff assignment rows so each request appears once (all documents listed together).
 *
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function groupStaffAssignedItemsByRequest(array $items): array {
    $groups = [];
    $order = [];

    foreach ($items as $item) {
        $requestId = (int) ($item['request_id'] ?? 0);
        if ($requestId <= 0) {
            $requestId = -1 * max(1, (int) ($item['id'] ?? 0));
        }

        if (!isset($groups[$requestId])) {
            $row = $item;
            $row['assigned_item_ids'] = [(int) ($item['id'] ?? 0)];
            $row['assigned_item_statuses'] = [trim((string) ($item['item_status'] ?? ''))];
            $row['assigned_items'] = [$item];
            $row['copies'] = (int) ($item['copies'] ?? 0);
            $groups[$requestId] = $row;
            $order[] = $requestId;
            continue;
        }

        $groups[$requestId]['assigned_item_ids'][] = (int) ($item['id'] ?? 0);
        $groups[$requestId]['assigned_item_statuses'][] = trim((string) ($item['item_status'] ?? ''));
        $groups[$requestId]['assigned_items'][] = $item;
        $groups[$requestId]['copies'] = (int) ($groups[$requestId]['copies'] ?? 0) + (int) ($item['copies'] ?? 0);

        if (trim((string) ($groups[$requestId]['or_number'] ?? '')) === '' && trim((string) ($item['or_number'] ?? '')) !== '') {
            $groups[$requestId]['or_number'] = $item['or_number'];
        }

        if (trim((string) ($groups[$requestId]['payment_date'] ?? '')) === '' && trim((string) ($item['payment_date'] ?? '')) !== '') {
            $groups[$requestId]['payment_date'] = $item['payment_date'];
        }

        $incomingRelease = trim((string) ($item['release_date'] ?? ''));
        $currentRelease = trim((string) ($groups[$requestId]['release_date'] ?? ''));
        if ($incomingRelease !== '' && ($currentRelease === '' || $incomingRelease < $currentRelease)) {
            $groups[$requestId]['release_date'] = $item['release_date'];
            $groups[$requestId]['release_time'] = $item['release_time'] ?? null;
        }

        $currentStatus = (string) ($groups[$requestId]['item_status'] ?? '');
        $newStatus = (string) ($item['item_status'] ?? '');
        // Prefer linking Process to an item still in processing.
        if ($currentStatus !== 'processing' && $newStatus === 'processing') {
            $groups[$requestId]['id'] = $item['id'];
            $groups[$requestId]['item_status'] = $newStatus;
            $groups[$requestId]['document_name'] = $item['document_name'] ?? $groups[$requestId]['document_name'];
            $groups[$requestId]['document_code'] = $item['document_code'] ?? $groups[$requestId]['document_code'];
        }
    }

    foreach ($groups as &$group) {
        $sourceItems = $group['assigned_items'] ?? [$group];
        $group = applyAssignedItemsDocumentLabels($group, $sourceItems);
        unset($group['assigned_items']);

        $statuses = array_values(array_unique(array_filter(
            $group['assigned_item_statuses'] ?? [],
            static fn(string $status): bool => $status !== ''
        )));
        unset($group['assigned_item_statuses']);

        if (count($statuses) > 1) {
            $group['item_status'] = 'mixed';
            $group['item_status_detail'] = implode(' · ', array_map('requestItemStatusLabel', $statuses));
        } elseif (count($statuses) === 1) {
            $group['item_status'] = $statuses[0];
            unset($group['item_status_detail']);
        }
    }
    unset($group);

    return array_map(static fn(int $requestId): array => $groups[$requestId], $order);
}

function buildReleaseScheduleForRequestItem(int $itemId, ?string $releaseDate = null, ?string $releaseTime = null): array {
    $item = getRequestItem($itemId);
    if (!$item) {
        return buildReleaseScheduleForRequest(0, 3, $releaseDate, $releaseTime);
    }

    return buildReleaseScheduleForRequest(
        (int) $item['request_id'],
        (int) ($item['processing_days'] ?? 3),
        $releaseDate ?? $item['release_date'] ?? null,
        $releaseTime ?? $item['release_time'] ?? null
    );
}

function getAssignedRequirementsForItem(int $requestId, ?int $requestItemId = null): array {
    $db = getDB();
    if ($requestItemId) {
        $stmt = $db->prepare('SELECT ar.*, rd.file_name, rd.original_name
            FROM request_assigned_requirements ar
            LEFT JOIN request_documents rd ON ar.document_id = rd.id
            WHERE ar.request_id = ? AND (ar.request_item_id = ? OR ar.request_item_id IS NULL)
            ORDER BY ar.sort_order, ar.id');
        $stmt->execute([$requestId, $requestItemId]);
        return $stmt->fetchAll();
    }

    return getAssignedRequirements($requestId);
}

function requestHasPendingAssignmentItems(int $requestId): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM request_items WHERE request_id = ? AND item_status = 'pending_assignment'");
    $stmt->execute([$requestId]);
    return (int) $stmt->fetchColumn() > 0;
}

function requestHasAssignedStaff(int $requestId): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM request_items WHERE request_id = ? AND assigned_to IS NOT NULL');
    $stmt->execute([$requestId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return true;
    }

    $legacy = $db->prepare('SELECT assigned_to FROM requests WHERE id = ?');
    $legacy->execute([$requestId]);
    return !empty($legacy->fetchColumn());
}

function syncRequestAssignmentSummary(int $requestId): void {
    $items = getRequestItems($requestId);
    $assigned = array_values(array_filter($items, static fn(array $item): bool => !empty($item['assigned_to'])));
    if (empty($assigned)) {
        return;
    }

    $primary = $assigned[0];
    $releaseDate = $primary['release_date'] ?? null;
    $releaseTime = $primary['release_time'] ?? null;

    foreach ($assigned as $item) {
        if (empty($item['release_date'])) {
            continue;
        }
        if (
            $releaseDate === null
            || $item['release_date'] < $releaseDate
            || ($item['release_date'] === $releaseDate && ($item['release_time'] ?? '') < (string) $releaseTime)
        ) {
            $releaseDate = $item['release_date'];
            $releaseTime = $item['release_time'] ?? null;
        }
    }

    $db = getDB();
    $db->prepare('UPDATE requests SET assigned_to = ?, release_date = ?, release_time = ?, pickup_date = ?, pickup_time = ? WHERE id = ?')
       ->execute([
           (int) $primary['assigned_to'],
           $releaseDate,
           $releaseTime,
           $releaseDate,
           $releaseTime,
           $requestId,
       ]);
}

function renderRequestItemDetailsHtml(array $item): string {
    $html = '<div class="request-item-detail-card">';
    $html .= '<div class="request-item-detail-header">';
    $html .= '<strong>' . e($item['document_name'] ?? 'Document') . '</strong>';
    $html .= requestItemStatusBadge($item['item_status'] ?? 'pending_assignment');
    $html .= '</div>';
    $html .= '<div class="detail-grid">';
    $html .= '<div class="detail-item"><label>Copies</label><span>' . (int) ($item['copies'] ?? 1) . '</span></div>';
    $html .= '<div class="detail-item"><label>Amount</label><span>' . e(formatMoney((float) ($item['item_amount'] ?? 0))) . '</span></div>';

    if (!empty($item['request_school_year']) || !empty($item['request_semester'])) {
        $html .= '<div class="detail-item"><label>School Year</label><span>' . e($item['request_school_year'] ?? '—') . '</span></div>';
        $html .= '<div class="detail-item"><label>Semester</label><span>' . e(semesterLabel($item['request_semester'] ?? null)) . '</span></div>';
    }

    if (!empty($item['staff_first'])) {
        $html .= '<div class="detail-item"><label>Assigned Staff</label><span>' . e($item['staff_first'] . ' ' . $item['staff_last']) . '</span></div>';
    }

    if (!empty($item['release_date'])) {
        $html .= '<div class="detail-item"><label>Release Schedule</label><span>' . e(formatDate($item['release_date'])) . ' at ' . e(date('g:i A', strtotime((string) $item['release_time']))) . '</span></div>';
    }

    $html .= '</div></div>';
    return $html;
}

/**
 * Load full request context for My Assignments process pages.
 *
 * @return array{request:array,items:array,payment:?array,documents:array,clearance_required:bool,clearance_progress:array}
 */
function loadAssignmentRequestContext(int $requestId): array {
    require_once __DIR__ . '/payments.php';
    require_once __DIR__ . '/compliance.php';
    require_once __DIR__ . '/clearance.php';
    require_once __DIR__ . '/campuses.php';
    require_once __DIR__ . '/student.php';
    require_once __DIR__ . '/onsite-request.php';

    ensureOnsiteRequestSchema();
    ensureCampusesSchema();

    $db = getDB();
    $stmt = $db->prepare('SELECT r.*,
            u.first_name, u.last_name, u.middle_name, u.email, u.student_id, u.phone,
            sp.course, sp.course_id, sp.year_level, sp.enrollment_status,
            sp.origin_campus_id, sp.year_graduated, sp.last_school_year, sp.current_semester,
            c.name as campus_name, c.code as campus_code
        FROM requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN campuses c ON sp.origin_campus_id = c.id
        WHERE r.id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch() ?: [];

    $paymentStmt = $db->prepare('SELECT * FROM payments WHERE request_id = ? ORDER BY created_at DESC LIMIT 1');
    $paymentStmt->execute([$requestId]);
    $payment = $paymentStmt->fetch() ?: null;

    $docsStmt = $db->prepare('SELECT * FROM request_documents WHERE request_id = ? ORDER BY uploaded_at ASC');
    $docsStmt->execute([$requestId]);
    $documents = $docsStmt->fetchAll();

    $clearanceRequired = hasAssignedRequirement($requestId, 'online_clearance');
    $clearanceProgress = $clearanceRequired ? getClearanceProgress($requestId) : ['total' => 0, 'cleared' => 0, 'onHold' => 0, 'pending' => 0];

    return [
        'request' => $request,
        'items' => getRequestItems($requestId),
        'payment' => $payment,
        'documents' => $documents,
        'clearance_required' => $clearanceRequired,
        'clearance_progress' => $clearanceProgress,
    ];
}

/**
 * Render detailed request + requestor info for assignment processors.
 */
function renderAssignmentRequestDetailsHtml(array $context, ?array $activeItem = null): string {
    $request = $context['request'] ?? [];
    if (!$request) {
        return '';
    }

    $items = $context['items'] ?? [];
    $payment = $context['payment'] ?? null;
    $documents = $context['documents'] ?? [];
    $clearanceRequired = !empty($context['clearance_required']);
    $progress = $context['clearance_progress'] ?? [];
    $activeItemId = (int) ($activeItem['id'] ?? 0);
    $processorId = (int) ($activeItem['assigned_to'] ?? 0);
    $assignedItems = $items;
    if ($processorId > 0) {
        $assignedItems = array_values(array_filter(
            $items,
            static fn(array $requestItem): bool => (int) ($requestItem['assigned_to'] ?? 0) === $processorId
        ));
    }
    $documentListItems = $processorId > 0 ? $assignedItems : $items;
    $documentListTitle = $processorId > 0
        ? 'Your Assigned Documents (' . count($documentListItems) . ')'
        : 'Documents in Request (' . count($documentListItems) . ')';
    $isOnsite = isOnsiteRequestChannel($request['request_channel'] ?? null);
    $isGraduated = isGraduatedEnrollment($request['enrollment_status'] ?? null);
    $isInactive = isInactiveEnrollment($request['enrollment_status'] ?? null);

    $studentName = trim(
        ($request['first_name'] ?? '')
        . (!empty($request['middle_name']) ? ' ' . $request['middle_name'] : '')
        . ' ' . ($request['last_name'] ?? '')
    );

    $purposeText = purposeLabel((string) ($request['purpose'] ?? ''));
    if (!empty($request['purpose_other'])) {
        $purposeText .= ' — ' . $request['purpose_other'];
    }

    $courseYear = trim((string) ($request['course'] ?? ''));
    if ($isGraduated && !empty($request['year_graduated'])) {
        $courseYear .= ($courseYear !== '' ? ' · ' : '') . 'Graduated ' . (int) $request['year_graduated'];
    } elseif ($isInactive) {
        $lastTerm = trim(
            (string) ($request['last_school_year'] ?? '')
            . (!empty($request['current_semester']) ? ' · ' . semesterLabel($request['current_semester']) : '')
        );
        if ($lastTerm !== '') {
            $courseYear .= ($courseYear !== '' ? ' · ' : '') . 'Last attended ' . $lastTerm;
        }
    } elseif (!empty($request['year_level'])) {
        $courseYear .= ($courseYear !== '' ? ' · ' : '') . $request['year_level'];
    }
    if ($courseYear === '') {
        $courseYear = '—';
    }

    $campusLabel = '—';
    if (!empty($request['campus_name'])) {
        $campusLabel = $request['campus_name'] . (!empty($request['campus_code']) ? ' (' . $request['campus_code'] . ')' : '');
    }

    ob_start();
    ?>
    <div class="assignment-request-details">
        <section class="assignment-detail-section">
            <h3><i class="fas fa-user-graduate"></i> Requestor</h3>
            <div class="detail-grid">
                <div class="detail-item"><label>Full Name</label><span><?= e($studentName !== '' ? $studentName : '—') ?></span></div>
                <div class="detail-item"><label>Student / Requestor ID</label><span><?= e($request['student_id'] ?? '—') ?></span></div>
                <div class="detail-item"><label>Email</label><span><?= e($request['email'] ?? '—') ?></span></div>
                <div class="detail-item"><label>Phone</label><span><?= e($request['phone'] ?? '—') ?></span></div>
                <div class="detail-item"><label>Enrollment Status</label><span><?= e(enrollmentStatusLabel($request['enrollment_status'] ?? null)) ?></span></div>
                <div class="detail-item"><label>Course / Program</label><span><?= e($courseYear) ?></span></div>
                <?php if ($isGraduated || $isInactive): ?>
                    <div class="detail-item"><label>Campus</label><span><?= e($campusLabel) ?></span></div>
                <?php endif; ?>
            </div>
        </section>

        <section class="assignment-detail-section">
            <h3><i class="fas fa-file-alt"></i> Request Information</h3>
            <div class="detail-grid">
                <div class="detail-item"><label>Request #</label><span><?= e($request['request_number'] ?? '—') ?></span></div>
                <div class="detail-item"><label>Channel</label><span><?= $isOnsite ? '<span class="badge badge-processing">Onsite Walk-in</span>' : '<span class="badge badge-review">Online</span>' ?></span></div>
                <div class="detail-item"><label>Batch Status</label><span><?= statusBadge((string) ($request['status'] ?? '')) ?></span></div>
                <div class="detail-item"><label>Submitted</label><span><?= e(formatDateTime($request['created_at'] ?? null)) ?></span></div>
                <div class="detail-item"><label>Purpose</label><span><?= e($purposeText) ?></span></div>
                <div class="detail-item"><label>Request Type</label><span><?= e(copyRequestTypeLabel($request['copy_request_type'] ?? null)) ?></span></div>
                <div class="detail-item"><label>Delivery</label><span><?= e(deliveryMethodLabel($request['delivery_method'] ?? null)) ?></span></div>
                <div class="detail-item"><label>Total Amount</label><span><strong><?= e(formatMoney((float) ($request['total_amount'] ?? 0))) ?></strong></span></div>
                <?php if (!empty($request['notes'])): ?>
                    <div class="detail-item full"><label>Notes</label><span><?= e($request['notes']) ?></span></div>
                <?php endif; ?>
                <?php if (($request['delivery_method'] ?? '') === 'authorized_representative'): ?>
                    <div class="detail-item"><label>Representative</label><span><?= e($request['representative_name'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Relationship</label><span><?= e($request['representative_relationship'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Rep. Phone</label><span><?= e($request['representative_phone'] ?? '—') ?></span></div>
                    <div class="detail-item"><label>Rep. ID No.</label><span><?= e($request['representative_id_number'] ?? '—') ?></span></div>
                <?php endif; ?>
            </div>
        </section>

        <?php
        $batchKey = trim((string) ($request['onsite_batch_key'] ?? ''));
        if ($batchKey !== '' && function_exists('renderOnsiteBatchRequestorsHtml')) {
            $batchViewUrl = (function_exists('hasRole') && hasRole('registrar', 'admin'))
                ? (APP_URL . '/registrar/verify-request.php')
                : '';
            echo renderOnsiteBatchRequestorsHtml($batchKey, (int) ($request['id'] ?? 0), $batchViewUrl);
        }
        ?>

        <?php if ($activeItem): ?>
        <section class="assignment-detail-section">
            <h3><i class="fas fa-tasks"></i> <?= count($assignedItems) > 1 ? 'Selected Assigned Document' : 'Assigned Document' ?></h3>
            <?= renderRequestItemDetailsHtml($activeItem) ?>
        </section>
        <?php endif; ?>

        <?php if (!empty($documentListItems) && ($processorId <= 0 || count($documentListItems) > 1)): ?>
        <section class="assignment-detail-section">
            <h3><i class="fas fa-layer-group"></i> <?= e($documentListTitle) ?></h3>
            <div class="request-items-summary-list">
                <?php foreach ($documentListItems as $requestItem): ?>
                    <?php $isActive = $activeItemId > 0 && (int) $requestItem['id'] === $activeItemId; ?>
                    <div class="request-item-summary-row<?= $isActive ? ' is-active-assignment' : '' ?>">
                        <strong><?= e($requestItem['document_name'] ?? 'Document') ?><?= $isActive ? ' <small class="text-muted">(this assignment)</small>' : '' ?></strong>
                        <span><?= (int) ($requestItem['copies'] ?? 1) ?> cop<?= (int) ($requestItem['copies'] ?? 1) === 1 ? 'y' : 'ies' ?></span>
                        <span><?= e(formatMoney((float) ($requestItem['item_amount'] ?? 0))) ?></span>
                        <?= requestItemStatusBadge($requestItem['item_status'] ?? 'pending_assignment') ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($payment): ?>
        <section class="assignment-detail-section">
            <h3><i class="fas fa-receipt"></i> Payment</h3>
            <div class="detail-grid">
                <div class="detail-item"><label>Method</label><span><?= e(paymentMethodLabel($payment['payment_method'] ?? null)) ?></span></div>
                <div class="detail-item"><label>Status</label><span><?= statusBadge((string) ($payment['status'] ?? '')) ?></span></div>
                <div class="detail-item"><label>Amount</label><span><?= e(formatMoney((float) ($payment['amount'] ?? 0))) ?></span></div>
                <div class="detail-item"><label><?= isOnsitePaymentMethod($payment['payment_method'] ?? null) ? 'Payment Code' : 'Reference' ?></label><span><?= e($payment['reference_number'] ?? '—') ?></span></div>
                <?php if (!empty($payment['or_number'])): ?>
                    <div class="detail-item"><label>OR #</label><span><?= e($payment['or_number']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($payment['payment_date'])): ?>
                    <div class="detail-item"><label>Payment Date</label><span><?= e(formatDate($payment['payment_date'])) ?></span></div>
                <?php endif; ?>
                <div class="detail-item"><label>Submitted</label><span><?= e(formatDateTime($payment['created_at'] ?? null)) ?></span></div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($clearanceRequired): ?>
        <section class="assignment-detail-section">
            <h3><i class="fas fa-stamp"></i> Online Clearance</h3>
            <p class="text-muted" style="margin:0 0 .75rem">
                Progress: <?= (int) ($progress['cleared'] ?? 0) ?>/<?= (int) ($progress['total'] ?? 0) ?> offices cleared
                <?php if (!empty($progress['onHold'])): ?>
                    · <?= (int) $progress['onHold'] ?> on hold
                <?php endif; ?>
            </p>
            <?= renderClearanceGrid((int) $request['id'], true) ?>
        </section>
        <?php endif; ?>

        <?php if (!empty($documents)): ?>
        <section class="assignment-detail-section">
            <h3><i class="fas fa-paperclip"></i> Uploaded Requirements</h3>
            <ul class="doc-list">
                <?php foreach ($documents as $doc): ?>
                    <li>
                        <a href="<?= UPLOAD_URL ?>/<?= e($doc['file_name']) ?>" target="_blank"><?= e($doc['original_name']) ?></a>
                        <small class="text-muted"><?= e(formatDateTime($doc['uploaded_at'] ?? null)) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}
