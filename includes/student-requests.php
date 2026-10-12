<?php

require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/onsite-request.php';

/**
 * Closed online request statuses. A student may also start another request
 * once cashier payment is verified, even if processing is still underway.
 *
 * @return list<string>
 */
function studentOnlineTerminalRequestStatuses(): array {
    return ['completed', 'rejected', 'cancelled'];
}

function isOnlineStudentRequest(array $request): bool {
    return !isOnsiteRequestChannel($request['request_channel'] ?? null);
}

function requestHasVerifiedPayment(int $requestId): bool {
    $stmt = getDB()->prepare("SELECT 1 FROM payments WHERE request_id = ? AND status = 'verified' LIMIT 1");
    $stmt->execute([$requestId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * @return array{id:int,request_number:string,status:string,created_at:string}|null
 */
function getStudentBlockingOnlineRequest(int $userId): ?array {
    if ($userId <= 0) {
        return null;
    }

    ensureRequestStatuses();
    $statuses = requestDocumentsEditableStatuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $params = array_merge([$userId], $statuses);

    $stmt = getDB()->prepare(
        "SELECT id, request_number, status, created_at
         FROM requests
         WHERE user_id = ?
           AND COALESCE(request_channel, 'online') <> 'onsite'
           AND status IN ($placeholders)
           AND NOT EXISTS (
               SELECT 1 FROM payments p
               WHERE p.request_id = requests.id AND p.status = 'verified'
           )
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

function studentCanCreateOnlineRequest(int $userId): bool {
    return getStudentBlockingOnlineRequest($userId) === null;
}

function requestRequiresRegistrarRequirementsFirst(int $requestId): bool {
    if ($requestId <= 0) {
        return false;
    }

    $db = getDB();
    $requestStmt = $db->prepare('SELECT copy_request_type, document_type_id FROM requests WHERE id = ?');
    $requestStmt->execute([$requestId]);
    $request = $requestStmt->fetch();
    if (!$request) {
        return false;
    }

    $copyType = normalizeRequirementCopyType((string) ($request['copy_request_type'] ?? 'first_request'));
    $itemStmt = $db->prepare('SELECT DISTINCT document_type_id FROM request_items WHERE request_id = ?');
    $itemStmt->execute([$requestId]);
    $documentTypeIds = array_map('intval', $itemStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($documentTypeIds === [] && (int) ($request['document_type_id'] ?? 0) > 0) {
        $documentTypeIds = [(int) $request['document_type_id']];
    }

    foreach ($documentTypeIds as $documentTypeId) {
        if (documentTypeRequiresRequirements($documentTypeId, $copyType)) {
            return true;
        }
    }

    return false;
}

function studentRequestCanProceedToPayment(?string $status, ?int $requestId = null): bool {
    $payableStatuses = ['submitted', 'under_review', 'requirements_verified', 'payment_verified'];
    if ($requestId && requestRequiresRegistrarRequirementsFirst($requestId)) {
        $payableStatuses = ['requirements_verified', 'payment_verified'];
    }

    return in_array((string) $status, $payableStatuses, true);
}

function studentRequestShowsPayAction(?string $status, ?int $requestId = null): bool {
    $payableStatuses = ['submitted', 'under_review', 'requirements_verified'];
    if ($requestId && requestRequiresRegistrarRequirementsFirst($requestId)) {
        $payableStatuses = ['requirements_verified'];
    }

    return in_array((string) $status, $payableStatuses, true);
}

function studentCanEditRequestDocuments(array $request, int $userId): bool {
    if ($userId <= 0 || (int) ($request['user_id'] ?? 0) !== $userId) {
        return false;
    }

    return canEditRequestDocumentsBeforePayment($request);
}

function requestDocumentEditorUrl(int $requestId, string $role = 'student'): string {
    if ($role === 'registrar') {
        return APP_URL . '/registrar/edit-request-documents.php?id=' . $requestId;
    }

    return APP_URL . '/student/new-request.php?request_id=' . $requestId;
}

function studentCanCancelOnlineRequest(array $request, ?int $userId = null): bool {
    if (!isOnlineStudentRequest($request)) {
        return false;
    }

    if ($userId !== null && (int) ($request['user_id'] ?? 0) !== $userId) {
        return false;
    }

    $status = (string) ($request['status'] ?? '');
    if (in_array($status, studentOnlineTerminalRequestStatuses(), true)) {
        return false;
    }

    if (in_array($status, ['payment_verified', 'processing', 'ready_for_pickup', 'shipped'], true)) {
        return false;
    }

    return !requestHasVerifiedPayment((int) ($request['id'] ?? 0));
}

/**
 * @return list<string>
 */
function autoCancelEligibleOnlineRequestStatuses(): array {
    return [
        'submitted',
        'under_review',
        'awaiting_requirements',
        'needs_revision',
        'requirements_submitted',
        'requirements_verified',
    ];
}

function rejectPendingPaymentsForRequest(int $requestId, string $note): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, notes FROM payments WHERE request_id = ? AND status = 'pending'");
    $stmt->execute([$requestId]);
    foreach ($stmt->fetchAll() as $payment) {
        $existing = trim((string) ($payment['notes'] ?? ''));
        $merged = $existing !== '' ? $existing . "\n" . $note : $note;
        $db->prepare("UPDATE payments SET status = 'rejected', notes = ? WHERE id = ?")
            ->execute([$merged, (int) $payment['id']]);
    }
}

/**
 * @return array{ok:bool,error?:string,request_number?:string}
 */
function cancelStudentOnlineRequest(int $requestId, int $userId, string $remarks = 'Cancelled by student before payment verification.'): array {
    ensureRequestStatuses();

    $stmt = getDB()->prepare('SELECT * FROM requests WHERE id = ? AND user_id = ?');
    $stmt->execute([$requestId, $userId]);
    $request = $stmt->fetch();
    if (!$request) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }

    if (!studentCanCancelOnlineRequest($request, $userId)) {
        return ['ok' => false, 'error' => 'This request can no longer be cancelled online. Payment may already be verified.'];
    }

    rejectPendingPaymentsForRequest($requestId, $remarks);
    updateRequestStatus($requestId, 'cancelled', $remarks, false);

    auditLog('student_cancel_request', 'requests', $requestId, ['status' => $request['status']], ['status' => 'cancelled']);

    return [
        'ok' => true,
        'request_number' => (string) ($request['request_number'] ?? ''),
    ];
}

function autoCancelStaleOnlineRequests(int $days = 3): int {
    ensureRequestStatuses();
    require_once __DIR__ . '/clearance.php';

    $days = max(1, $days);
    $cutoff = (new DateTimeImmutable(appNow()))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
    $statuses = autoCancelEligibleOnlineRequestStatuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));

    $stmt = getDB()->prepare(
        "SELECT r.id, r.request_number, r.status, r.user_id
         FROM requests r
         WHERE COALESCE(r.request_channel, 'online') <> 'onsite'
           AND r.status IN ($placeholders)
           AND r.created_at <= ?
           AND NOT EXISTS (
               SELECT 1 FROM payments p
               WHERE p.request_id = r.id AND p.status = 'verified'
           )
         ORDER BY r.id ASC"
    );
    $stmt->execute(array_merge($statuses, [$cutoff]));
    $rows = $stmt->fetchAll();

    $cancelled = 0;
    foreach ($rows as $row) {
        $requestId = (int) $row['id'];
        if (hasAssignedRequirement($requestId, 'online_clearance')) {
            continue;
        }

        $remarks = 'Automatically cancelled after ' . $days . ' days with no action.';
        rejectPendingPaymentsForRequest($requestId, $remarks);
        if (updateRequestStatus($requestId, 'cancelled', $remarks)) {
            auditLog('auto_cancel_request', 'requests', $requestId, ['status' => $row['status']], ['status' => 'cancelled', 'days' => $days]);
            $cancelled++;
        }
    }

    return $cancelled;
}

function maybeAutoCancelStaleOnlineRequests(int $days = 3): void {
    if (!function_exists('getAppSetting')) {
        require_once __DIR__ . '/compliance.php';
    }

    $lastRun = (int) getAppSetting('auto_cancel_online_requests_last_run', '0');
    if ($lastRun > 0 && (time() - $lastRun) < 3600) {
        return;
    }

    autoCancelStaleOnlineRequests($days);
    setAppSetting('auto_cancel_online_requests_last_run', (string) time());
}

/**
 * Compact request and payment rows for the student top bar.
 *
 * @return array{
 *     requests: list<array<string,mixed>>,
 *     open_count: int,
 *     payment_attention: int
 * }
 */
function getStudentHeaderReviewSummaries(int $userId): array {
    $empty = ['requests' => [], 'open_count' => 0, 'payment_attention' => 0];
    if ($userId <= 0) {
        return $empty;
    }

    if (!function_exists('ensureRequestItemsSchema')) {
        require_once __DIR__ . '/request-items.php';
    }
    ensureRequestItemsSchema();
    if (!function_exists('paymentMethodLabel')) {
        require_once __DIR__ . '/payments.php';
    }

    $db = getDB();
    $countStmt = $db->prepare("SELECT COUNT(*) FROM requests WHERE user_id = ? AND status NOT IN ('completed','rejected','cancelled')");
    $countStmt->execute([$userId]);
    $openCount = (int) $countStmt->fetchColumn();

    $stmt = $db->prepare("SELECT r.id, r.request_number, r.status, r.total_amount, r.updated_at,
            COALESCE(
                (SELECT GROUP_CONCAT(dt.name ORDER BY ri.sort_order, ri.id SEPARATOR ', ')
                 FROM request_items ri
                 JOIN document_types dt ON dt.id = ri.document_type_id
                 WHERE ri.request_id = r.id),
                dt0.name
            ) AS documents_summary,
            p.status AS payment_status,
            p.amount AS payment_amount,
            p.payment_method,
            p.or_number
        FROM requests r
        LEFT JOIN document_types dt0 ON dt0.id = r.document_type_id
        LEFT JOIN payments p ON p.id = (
            SELECT MAX(p2.id) FROM payments p2 WHERE p2.request_id = r.id
        )
        WHERE r.user_id = ? AND r.status <> 'cancelled'
        ORDER BY r.updated_at DESC
        LIMIT 5");
    $stmt->execute([$userId]);

    $requests = [];
    $paymentAttention = 0;
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $requestId = (int) $row['id'];
        $status = (string) ($row['status'] ?? '');
        $paymentStatus = trim((string) ($row['payment_status'] ?? ''));
        $canPay = studentRequestShowsPayAction($status, $requestId);
        if ($canPay || $paymentStatus === 'rejected') {
            $paymentAttention++;
        }

        $method = trim((string) ($row['payment_method'] ?? ''));
        $methodLabel = '';
        if ($method !== '') {
            $methodLabel = function_exists('paymentMethodLabel')
                ? paymentMethodLabel($method)
                : ucwords(str_replace('_', ' ', $method));
        }

        $requests[] = [
            'id' => $requestId,
            'request_number' => (string) ($row['request_number'] ?? ''),
            'status' => $status,
            'status_label' => function_exists('studentProgressStatusLabel')
                ? studentProgressStatusLabel($status)
                : ucwords(str_replace('_', ' ', $status)),
            'documents' => trim((string) ($row['documents_summary'] ?? '')) ?: 'Document request',
            'amount' => (float) ($row['payment_amount'] ?? $row['total_amount'] ?? 0),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'payment_status' => $paymentStatus,
            'payment_label' => studentHeaderPaymentSummaryLabel($paymentStatus, $canPay),
            'payment_method' => $methodLabel,
            'or_number' => trim((string) ($row['or_number'] ?? '')),
            'can_pay' => $canPay,
            'view_url' => APP_URL . '/student/request-view.php?id=' . $requestId,
            'pay_url' => APP_URL . '/student/payment.php?request_id=' . $requestId,
        ];
    }

    return [
        'requests' => $requests,
        'open_count' => $openCount,
        'payment_attention' => $paymentAttention,
    ];
}

function studentHeaderPaymentSummaryLabel(string $paymentStatus, bool $canPay): string {
    return match ($paymentStatus) {
        'pending' => 'Waiting for cashier verification',
        'verified' => 'Payment verified',
        'rejected' => 'Payment rejected',
        'refunded' => 'Refunded',
        default => $canPay ? 'Payment due' : 'Payment not open yet',
    };
}

function renderStudentBlockingOnlineRequestAlert(?array $blockingRequest): string {
    if (!$blockingRequest) {
        return '';
    }

    $viewUrl = APP_URL . '/student/request-view.php?id=' . (int) $blockingRequest['id'];
    $number = e($blockingRequest['request_number'] ?? '');
    $status = statusBadge((string) ($blockingRequest['status'] ?? ''));

    $editUrl = requestDocumentEditorUrl((int) $blockingRequest['id']);

    return '<div class="alert alert-warning">'
        . '<i class="fas fa-exclamation-triangle"></i> '
        . '<strong>Payment is not verified yet.</strong> '
        . 'Request <strong>' . $number . '</strong> (' . $status . ') is still open. '
        . 'You can add or remove documents until the cashier verifies payment. '
        . 'After payment is verified, you can submit another request. '
        . '<a href="' . e($editUrl) . '" class="btn btn-primary btn-sm" style="margin-left:.5rem;">Edit Documents</a> '
        . '<a href="' . e($viewUrl) . '" class="btn btn-outline btn-sm">View Request</a>'
        . '</div>';
}
