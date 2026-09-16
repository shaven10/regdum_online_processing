<?php

require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/onsite-request.php';

/**
 * Terminal online request statuses — student may submit a new online request.
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
    $terminal = studentOnlineTerminalRequestStatuses();
    $placeholders = implode(',', array_fill(0, count($terminal), '?'));
    $params = array_merge([$userId], $terminal);

    $stmt = getDB()->prepare(
        "SELECT id, request_number, status, created_at
         FROM requests
         WHERE user_id = ?
           AND COALESCE(request_channel, 'online') <> 'onsite'
           AND status NOT IN ($placeholders)
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
    updateRequestStatus($requestId, 'cancelled', $remarks);

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

function renderStudentBlockingOnlineRequestAlert(?array $blockingRequest): string {
    if (!$blockingRequest) {
        return '';
    }

    $viewUrl = APP_URL . '/student/request-view.php?id=' . (int) $blockingRequest['id'];
    $number = e($blockingRequest['request_number'] ?? '');
    $status = statusBadge((string) ($blockingRequest['status'] ?? ''));

    return '<div class="alert alert-warning">'
        . '<i class="fas fa-exclamation-triangle"></i> '
        . '<strong>One active request at a time.</strong> '
        . 'You already have request <strong>' . $number . '</strong> (' . $status . '). '
        . 'Cancel it before submitting a new online request. '
        . '<a href="' . e($viewUrl) . '" class="btn btn-outline btn-sm" style="margin-left:.5rem;">View Request</a>'
        . '</div>';
}
