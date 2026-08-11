<?php

require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/onsite-request.php';
require_once __DIR__ . '/request-items.php';

/**
 * @return list<string>
 */
function registrarRequestStatusOptions(): array {
    return [
        'submitted',
        'under_review',
        'awaiting_requirements',
        'requirements_submitted',
        'needs_revision',
        'requirements_verified',
        'payment_verified',
        'processing',
        'ready_for_pickup',
        'shipped',
        'completed',
        'rejected',
    ];
}

function requestChannelLabel(?string $channel): string {
    return isOnsiteRequestChannel($channel) ? 'Onsite' : 'Online';
}

function registrarRequestDocumentUrl(array $row): ?string {
    $id = (int) ($row['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    if (isOnsiteRequestChannel($row['request_channel'] ?? null)) {
        return APP_URL . '/registrar/onsite-request-slip.php?id=' . $id;
    }

    return APP_URL . '/registrar/claim-stub.php?id=' . $id;
}

function registrarRequestDocumentLabel(array $row): string {
    return isOnsiteRequestChannel($row['request_channel'] ?? null)
        ? 'Onsite Request Slip'
        : 'Claim Stub';
}

/**
 * @return array{where:string,params:array,period:array,channel:string,status:string,search:string}
 */
function buildRegistrarRequestReportFilters(array $filters): array {
    ensureOnsiteRequestSchema();

    $period = resolvePaymentReportPeriod(
        (string) ($filters['period'] ?? 'daily'),
        (string) ($filters['date'] ?? date('Y-m-d'))
    );

    $channel = strtolower(trim((string) ($filters['channel'] ?? '')));
    if (!in_array($channel, ['online', 'onsite'], true)) {
        $channel = '';
    }

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '' && !in_array($status, registrarRequestStatusOptions(), true)) {
        $status = '';
    }

    $search = trim((string) ($filters['search'] ?? ''));

    $where = ['DATE(r.created_at) BETWEEN ? AND ?'];
    $params = [$period['from'], $period['to']];

    if ($channel !== '') {
        $where[] = 'r.request_channel = ?';
        $params[] = $channel;
    }

    if ($status !== '') {
        $where[] = 'r.status = ?';
        $params[] = $status;
    }

    if ($search !== '') {
        $where[] = '(r.request_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?
            OR u.student_id LIKE ? OR u.email LIKE ?
            OR CONCAT(u.first_name, \' \', u.last_name) LIKE ?
            OR dt.name LIKE ? OR r.purpose LIKE ? OR r.purpose_other LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }

    return [
        'where' => implode(' AND ', $where),
        'params' => $params,
        'period' => $period,
        'channel' => $channel,
        'status' => $status,
        'search' => $search,
    ];
}

/**
 * @return array{
 *   rows:list<array<string,mixed>>,
 *   total:int,
 *   summary:array<string,mixed>,
 *   period:array,
 *   filters:array,
 *   pagination:array
 * }
 */
function getRegistrarRequestReportData(array $filters, ?int $page = null, ?int $perPage = null): array {
    ensureOnsiteRequestSchema();
    ensureRequestItemsSchema();

    $db = getDB();
    $built = buildRegistrarRequestReportFilters($filters);
    $where = $built['where'];
    $params = $built['params'];

    $countStmt = $db->prepare('SELECT COUNT(*) FROM requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN document_types dt ON r.document_type_id = dt.id
        WHERE ' . $where);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $summaryStmt = $db->prepare("SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(r.total_amount), 0) AS total_amount,
            SUM(CASE WHEN r.request_channel = 'onsite' THEN 1 ELSE 0 END) AS onsite_count,
            SUM(CASE WHEN COALESCE(r.request_channel, 'online') <> 'onsite' THEN 1 ELSE 0 END) AS online_count,
            SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN r.status IN ('processing','ready_for_pickup','shipped') THEN 1 ELSE 0 END) AS processing_count,
            SUM(CASE WHEN r.status IN ('submitted','under_review','awaiting_requirements','requirements_submitted','needs_revision') THEN 1 ELSE 0 END) AS pending_review_count
        FROM requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN document_types dt ON r.document_type_id = dt.id
        WHERE " . $where);
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch() ?: [];

    $sql = 'SELECT r.id, r.request_number, r.status, r.purpose, r.purpose_other, r.copy_request_type,
                   r.total_amount, r.request_channel, r.created_at, r.verification_code,
                   u.first_name, u.last_name, u.student_id, u.email, u.phone,
                   dt.name AS document_name, dt.code AS document_code,
                   sp.course, sp.year_level,
                   cb.first_name AS created_by_first, cb.last_name AS created_by_last
            FROM requests r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN document_types dt ON r.document_type_id = dt.id
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            LEFT JOIN users cb ON r.created_by = cb.id
            WHERE ' . $where . '
            ORDER BY r.created_at DESC, r.id DESC';

    $pagination = [
        'page' => 1,
        'per_page' => $perPage ?? ITEMS_PER_PAGE,
        'total' => $total,
        'total_pages' => 1,
    ];

    if ($page !== null && $perPage !== null) {
        $pagination = paginate($total, $page, $perPage);
        $offset = max(0, ($pagination['page'] - 1) * $pagination['per_page']);
        $sql .= ' LIMIT ' . (int) $pagination['per_page'] . ' OFFSET ' . (int) $offset;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $requestId = (int) $row['id'];
        $items = getRequestItems($requestId);
        $row['items'] = $items;
        $row['document_summary'] = formatRequestItemsSummary($items, 2);
        if ($row['document_summary'] === '—' && !empty($row['document_name'])) {
            $row['document_summary'] = (string) $row['document_name'];
        }
        $row['channel_label'] = requestChannelLabel($row['request_channel'] ?? null);
        $row['document_link'] = registrarRequestDocumentUrl($row);
        $row['document_link_label'] = registrarRequestDocumentLabel($row);
        $createdBy = trim(($row['created_by_first'] ?? '') . ' ' . ($row['created_by_last'] ?? ''));
        $row['created_by_name'] = $createdBy !== '' ? $createdBy : null;
    }
    unset($row);

    return [
        'rows' => $rows,
        'total' => $total,
        'summary' => $summary,
        'period' => $built['period'],
        'filters' => [
            'period' => $built['period']['period'],
            'date' => $built['period']['date'],
            'channel' => $built['channel'],
            'status' => $built['status'],
            'search' => $built['search'],
        ],
        'pagination' => $pagination,
    ];
}

/**
 * @return list<string>
 */
function registrarRequestReportExportHeaders(): array {
    return [
        'Request #',
        'Mode',
        'Requestor',
        'Student ID',
        'Documents',
        'Status',
        'Amount',
        'Created',
        'Document Link',
    ];
}

/**
 * @return list<string|int|float>
 */
function mapRegistrarRequestReportExportRow(array $row): array {
    return [
        $row['request_number'] ?? '',
        $row['channel_label'] ?? requestChannelLabel($row['request_channel'] ?? null),
        trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        $row['student_id'] ?? '',
        $row['document_summary'] ?? ($row['document_name'] ?? ''),
        ucwords(str_replace('_', ' ', (string) ($row['status'] ?? ''))),
        (float) ($row['total_amount'] ?? 0),
        $row['created_at'] ?? '',
        $row['document_link'] ?? '',
    ];
}
