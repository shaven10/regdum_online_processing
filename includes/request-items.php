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

function formatRequestItemsSummary(array $items, int $maxNames = 2): string {
    if (empty($items)) {
        return '—';
    }

    $names = array_map(static fn(array $item): string => (string) ($item['document_name'] ?? 'Document'), $items);
    if (count($names) <= $maxNames) {
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
    $db = getDB();
    $where = ['ri.assigned_to = ?'];
    $params = [$staffId];

    if ($status !== '') {
        $where[] = 'ri.item_status = ?';
        $params[] = $status;
    } else {
        $where[] = "ri.item_status IN ('processing', 'ready_for_pickup')";
    }

    $sql = 'SELECT ri.*, dt.name as document_name, r.request_number, r.status as request_status,
            u.first_name, u.last_name, u.student_id
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.id
        JOIN document_types dt ON ri.document_type_id = dt.id
        JOIN users u ON r.user_id = u.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ri.updated_at DESC, ri.id DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function requestItemStatusLabel(string $status): string {
    return match ($status) {
        'pending_assignment' => 'Awaiting Assignment',
        'processing' => 'Processing',
        'ready_for_pickup' => 'Ready for Pickup',
        'completed' => 'Completed',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function requestItemStatusBadge(string $status): string {
    $class = match ($status) {
        'pending_assignment' => 'badge-orange',
        'processing' => 'badge-blue',
        'ready_for_pickup' => 'badge-green',
        'completed' => 'badge-gray',
        default => 'badge-gray',
    };

    return '<span class="badge ' . $class . '">' . e(requestItemStatusLabel($status)) . '</span>';
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
        $html .= '<div class="detail-item"><label>Semester</label><span>' . e($item['request_semester'] ?? '—') . '</span></div>';
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
