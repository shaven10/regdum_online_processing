<?php

require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/request-items.php';
require_once __DIR__ . '/onsite-request.php';

/**
 * @return array{where:string,params:list<mixed>,period:array,channel:string}
 */
function buildDocumentStatisticsReportFilters(array $filters): array {
    ensureRequestItemsSchema();
    ensureOnsiteRequestSchema();

    $period = resolvePaymentReportPeriod(
        (string) ($filters['period'] ?? 'monthly'),
        (string) ($filters['date'] ?? appToday()),
        (string) ($filters['date_from'] ?? ''),
        (string) ($filters['date_to'] ?? '')
    );

    $channel = strtolower(trim((string) ($filters['channel'] ?? '')));
    if (!in_array($channel, ['online', 'onsite'], true)) {
        $channel = '';
    }

    $where = ["DATE(r.created_at) BETWEEN ? AND ?", "r.status <> 'rejected'"];
    $params = [$period['from'], $period['to']];

    if ($channel === 'onsite') {
        $where[] = "r.request_channel = 'onsite'";
    } elseif ($channel === 'online') {
        $where[] = "COALESCE(r.request_channel, 'online') <> 'onsite'";
    }

    return [
        'where' => implode(' AND ', $where),
        'params' => $params,
        'period' => $period,
        'channel' => $channel,
    ];
}

function documentStatisticsPercent(int $part, int $total): int {
    if ($total <= 0 || $part <= 0) {
        return 0;
    }

    return (int) round(($part / $total) * 100);
}

/**
 * Document request counts and personnel assignment counts for requests submitted in the period.
 *
 * @return array{
 *   period:array,
 *   channel:string,
 *   summary:array<string,int>,
 *   documents:list<array<string,mixed>>,
 *   personnel:list<array<string,mixed>>
 * }
 */
function getDocumentStatisticsReport(array $filters): array {
    $built = buildDocumentStatisticsReportFilters($filters);
    $db = getDB();
    $where = $built['where'];
    $params = $built['params'];

    $documentStmt = $db->prepare("SELECT dt.id, dt.name, dt.code,
            COUNT(ri.id) AS requested_count,
            COALESCE(SUM(ri.copies), 0) AS copies_total,
            SUM(CASE WHEN r.request_channel = 'onsite' THEN 1 ELSE 0 END) AS onsite_count,
            SUM(CASE WHEN COALESCE(r.request_channel, 'online') <> 'onsite' THEN 1 ELSE 0 END) AS online_count,
            SUM(CASE WHEN ri.item_status = 'pending_assignment' THEN 1 ELSE 0 END) AS awaiting_count,
            SUM(CASE WHEN ri.item_status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
            SUM(CASE WHEN ri.item_status = 'ready_for_pickup' THEN 1 ELSE 0 END) AS ready_count,
            SUM(CASE WHEN ri.item_status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN ri.assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned_count
        FROM request_items ri
        JOIN document_types dt ON dt.id = ri.document_type_id
        JOIN requests r ON r.id = ri.request_id
        WHERE {$where}
        GROUP BY dt.id, dt.name, dt.code
        ORDER BY requested_count DESC, dt.name ASC");
    $documentStmt->execute($params);
    $documents = $documentStmt->fetchAll() ?: [];

    $requestedTotal = 0;
    $copiesTotal = 0;
    $onlineTotal = 0;
    $onsiteTotal = 0;
    $awaitingTotal = 0;
    $processingTotal = 0;
    $readyTotal = 0;
    $completedTotal = 0;
    $unassignedTotal = 0;

    foreach ($documents as &$document) {
        $document['requested_count'] = (int) ($document['requested_count'] ?? 0);
        $document['copies_total'] = (int) ($document['copies_total'] ?? 0);
        $document['onsite_count'] = (int) ($document['onsite_count'] ?? 0);
        $document['online_count'] = (int) ($document['online_count'] ?? 0);
        $document['awaiting_count'] = (int) ($document['awaiting_count'] ?? 0);
        $document['processing_count'] = (int) ($document['processing_count'] ?? 0);
        $document['ready_count'] = (int) ($document['ready_count'] ?? 0);
        $document['completed_count'] = (int) ($document['completed_count'] ?? 0);
        $document['unassigned_count'] = (int) ($document['unassigned_count'] ?? 0);
        $requestedTotal += $document['requested_count'];
        $copiesTotal += $document['copies_total'];
        $onlineTotal += $document['online_count'];
        $onsiteTotal += $document['onsite_count'];
        $awaitingTotal += $document['awaiting_count'];
        $processingTotal += $document['processing_count'];
        $readyTotal += $document['ready_count'];
        $completedTotal += $document['completed_count'];
        $unassignedTotal += $document['unassigned_count'];
    }
    unset($document);

    foreach ($documents as &$document) {
        $document['share'] = documentStatisticsPercent((int) $document['requested_count'], $requestedTotal);
    }
    unset($document);

    $personnelStmt = $db->prepare("SELECT u.id, u.first_name, u.last_name, u.email, rl.name AS role_name,
            COUNT(ri.id) AS assigned_total,
            COALESCE(SUM(ri.copies), 0) AS copies_total,
            SUM(CASE WHEN ri.item_status = 'pending_assignment' THEN 1 ELSE 0 END) AS awaiting_count,
            SUM(CASE WHEN ri.item_status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
            SUM(CASE WHEN ri.item_status = 'ready_for_pickup' THEN 1 ELSE 0 END) AS ready_count,
            SUM(CASE WHEN ri.item_status = 'completed' THEN 1 ELSE 0 END) AS completed_count
        FROM request_items ri
        JOIN requests r ON r.id = ri.request_id
        JOIN users u ON u.id = ri.assigned_to
        JOIN roles rl ON rl.id = u.role_id
        WHERE {$where}
          AND ri.assigned_to IS NOT NULL
        GROUP BY u.id, u.first_name, u.last_name, u.email, rl.name
        ORDER BY assigned_total DESC, u.last_name ASC, u.first_name ASC");
    $personnelStmt->execute($params);
    $personnel = $personnelStmt->fetchAll() ?: [];

    $assignedTotal = 0;
    foreach ($personnel as &$person) {
        $person['assigned_total'] = (int) ($person['assigned_total'] ?? 0);
        $person['copies_total'] = (int) ($person['copies_total'] ?? 0);
        $person['awaiting_count'] = (int) ($person['awaiting_count'] ?? 0);
        $person['processing_count'] = (int) ($person['processing_count'] ?? 0);
        $person['ready_count'] = (int) ($person['ready_count'] ?? 0);
        $person['completed_count'] = (int) ($person['completed_count'] ?? 0);
        $person['name'] = trim((string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? ''));
        $person['role_label'] = ucwords(str_replace('_', ' ', (string) ($person['role_name'] ?? 'staff')));
        $assignedTotal += $person['assigned_total'];
    }
    unset($person);

    foreach ($personnel as &$person) {
        $person['share'] = documentStatisticsPercent((int) $person['assigned_total'], $requestedTotal);
    }
    unset($person);

    return [
        'period' => $built['period'],
        'channel' => $built['channel'],
        'summary' => [
            'requested_count' => $requestedTotal,
            'copies_total' => $copiesTotal,
            'online_count' => $onlineTotal,
            'onsite_count' => $onsiteTotal,
            'awaiting_count' => $awaitingTotal,
            'processing_count' => $processingTotal,
            'ready_count' => $readyTotal,
            'completed_count' => $completedTotal,
            'unassigned_count' => $unassignedTotal,
            'assigned_count' => $assignedTotal,
            'document_types' => count($documents),
            'personnel_count' => count($personnel),
        ],
        'documents' => $documents,
        'personnel' => $personnel,
    ];
}

function documentStatisticsShareHtml(int $share): string {
    return '<div class="registrar-processed-share">'
        . '<div class="status-bar" aria-hidden="true"><div class="status-bar-fill" style="width:' . $share . '%"></div></div>'
        . '<small class="text-muted">' . $share . '%</small>'
        . '</div>';
}

/**
 * @param list<array<string,mixed>> $documents
 */
function renderDocumentStatisticsDocumentsTable(array $documents, int $requestedTotal, bool $showShareBar = true): void {
    ?>
    <div class="table-wrap">
        <table class="data-table data-table-responsive document-statistics-table">
            <thead>
                <tr>
                    <th>Document</th>
                    <th class="num">Requested</th>
                    <th class="num">Copies</th>
                    <th class="num">Online</th>
                    <th class="num">Onsite</th>
                    <th class="num">Awaiting Assignment</th>
                    <th class="num">Processing</th>
                    <th class="num">Ready</th>
                    <th class="num">Completed</th>
                    <th>Share</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($documents === []): ?>
                    <tr>
                        <td colspan="10">No documents were requested in this period.</td>
                    </tr>
                <?php else: ?>
                    <?php $copiesTotal = 0; ?>
                    <?php foreach ($documents as $document): ?>
                        <?php $copiesTotal += (int) ($document['copies_total'] ?? 0); ?>
                        <?php $share = (int) ($document['share'] ?? 0); ?>
                        <tr>
                            <td data-label="Document">
                                <strong><?= e((string) ($document['name'] ?? 'Document')) ?></strong>
                                <?php if (trim((string) ($document['code'] ?? '')) !== ''): ?>
                                    <br><small class="text-muted"><?= e((string) $document['code']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="num" data-label="Requested"><strong><?= (int) $document['requested_count'] ?></strong></td>
                            <td class="num" data-label="Copies"><?= (int) $document['copies_total'] ?></td>
                            <td class="num" data-label="Online"><?= (int) $document['online_count'] ?></td>
                            <td class="num" data-label="Onsite"><?= (int) $document['onsite_count'] ?></td>
                            <td class="num" data-label="Awaiting Assignment"><?= (int) $document['awaiting_count'] ?></td>
                            <td class="num" data-label="Processing"><?= (int) $document['processing_count'] ?></td>
                            <td class="num" data-label="Ready"><?= (int) $document['ready_count'] ?></td>
                            <td class="num" data-label="Completed"><?= (int) $document['completed_count'] ?></td>
                            <td data-label="Share"><?= $showShareBar ? documentStatisticsShareHtml($share) : e($share . '%') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td data-label="Document"><strong>Total</strong></td>
                        <td class="num" data-label="Requested"><strong><?= $requestedTotal ?></strong></td>
                        <td class="num" data-label="Copies"><strong><?= $copiesTotal ?></strong></td>
                        <td colspan="7"></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * @param list<array<string,mixed>> $personnel
 */
function renderDocumentStatisticsPersonnelTable(array $personnel, bool $showShareBar = true): void {
    ?>
    <div class="table-wrap">
        <table class="data-table data-table-responsive document-statistics-table">
            <thead>
                <tr>
                    <th>Staff</th>
                    <th>Role</th>
                    <th class="num">Assigned</th>
                    <th class="num">Copies</th>
                    <th class="num">Not Started</th>
                    <th class="num">Processing</th>
                    <th class="num">Ready</th>
                    <th class="num">Completed</th>
                    <th>Share</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($personnel === []): ?>
                    <tr>
                        <td colspan="9">No documents in this period have been assigned to personnel.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($personnel as $person): ?>
                        <?php
                        $share = (int) ($person['share'] ?? 0);
                        $name = trim((string) ($person['name'] ?? ''));
                        ?>
                        <tr>
                            <td data-label="Staff">
                                <strong><?= e($name !== '' ? $name : 'Unknown') ?></strong>
                                <?php if (trim((string) ($person['email'] ?? '')) !== ''): ?>
                                    <br><small class="text-muted"><?= e((string) $person['email']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Role"><?= e((string) ($person['role_label'] ?? '')) ?></td>
                            <td class="num" data-label="Assigned"><strong><?= (int) $person['assigned_total'] ?></strong></td>
                            <td class="num" data-label="Copies"><?= (int) $person['copies_total'] ?></td>
                            <td class="num" data-label="Not Started"><?= (int) $person['awaiting_count'] ?></td>
                            <td class="num" data-label="Processing"><?= (int) $person['processing_count'] ?></td>
                            <td class="num" data-label="Ready"><?= (int) $person['ready_count'] ?></td>
                            <td class="num" data-label="Completed"><?= (int) $person['completed_count'] ?></td>
                            <td data-label="Share"><?= $showShareBar ? documentStatisticsShareHtml($share) : e($share . '%') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function documentStatisticsChannelLabel(string $channel): string {
    return match ($channel) {
        'online' => 'Online',
        'onsite' => 'Onsite',
        default => 'All modes',
    };
}

/**
 * @param array{documents:list<array<string,mixed>>,personnel:list<array<string,mixed>>,summary:array<string,int>,period:array,channel:string} $report
 */
function exportDocumentStatisticsReportCsv(array $report): void {
    $period = $report['period'];
    $filename = 'document_assignment_statistics_' . ($period['from'] ?? appToday()) . '_' . ($period['to'] ?? appToday()) . '.csv';

    if (headers_sent($file, $line)) {
        throw new RuntimeException('Cannot export CSV because output already started in ' . $file . ' on line ' . $line . '.');
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');

    $summary = $report['summary'];
    fputcsv($out, ['Document and Assignment Statistics']);
    fputcsv($out, ['Period', (string) ($period['label'] ?? '')]);
    fputcsv($out, ['From', (string) ($period['from'] ?? '')]);
    fputcsv($out, ['To', (string) ($period['to'] ?? '')]);
    fputcsv($out, ['Mode', documentStatisticsChannelLabel((string) ($report['channel'] ?? ''))]);
    fputcsv($out, ['Documents requested', (int) ($summary['requested_count'] ?? 0)]);
    fputcsv($out, ['Copies', (int) ($summary['copies_total'] ?? 0)]);
    fputcsv($out, ['Awaiting assignment', (int) ($summary['awaiting_count'] ?? 0)]);
    fputcsv($out, ['Assigned', (int) ($summary['assigned_count'] ?? 0)]);
    fputcsv($out, ['Completed', (int) ($summary['completed_count'] ?? 0)]);
    fputcsv($out, []);

    fputcsv($out, ['Documents Requested']);
    fputcsv($out, ['Document', 'Code', 'Requested', 'Copies', 'Online', 'Onsite', 'Awaiting Assignment', 'Processing', 'Ready for Pickup', 'Completed', 'Share %']);
    foreach ($report['documents'] as $document) {
        fputcsv($out, [
            (string) ($document['name'] ?? ''),
            (string) ($document['code'] ?? ''),
            (int) ($document['requested_count'] ?? 0),
            (int) ($document['copies_total'] ?? 0),
            (int) ($document['online_count'] ?? 0),
            (int) ($document['onsite_count'] ?? 0),
            (int) ($document['awaiting_count'] ?? 0),
            (int) ($document['processing_count'] ?? 0),
            (int) ($document['ready_count'] ?? 0),
            (int) ($document['completed_count'] ?? 0),
            (int) ($document['share'] ?? 0),
        ]);
    }

    fputcsv($out, []);
    fputcsv($out, ['Personnel Assignment']);
    fputcsv($out, ['Staff', 'Email', 'Role', 'Assigned', 'Copies', 'Not Started', 'Processing', 'Ready for Pickup', 'Completed', 'Share %']);
    foreach ($report['personnel'] as $person) {
        fputcsv($out, [
            (string) ($person['name'] ?? ''),
            (string) ($person['email'] ?? ''),
            (string) ($person['role_label'] ?? ''),
            (int) ($person['assigned_total'] ?? 0),
            (int) ($person['copies_total'] ?? 0),
            (int) ($person['awaiting_count'] ?? 0),
            (int) ($person['processing_count'] ?? 0),
            (int) ($person['ready_count'] ?? 0),
            (int) ($person['completed_count'] ?? 0),
            (int) ($person['share'] ?? 0),
        ]);
    }

    fclose($out);
    exit;
}
