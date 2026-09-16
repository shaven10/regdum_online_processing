<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/request-items.php';
require_once __DIR__ . '/../includes/student-requests.php';
requireRole('student');

$user = currentUser();
ensureDeliveryMethods();
ensureRequestItemsSchema();
$profileCompletion = getStudentProfileCompletion($user['id']);
$blockingRequest = getStudentBlockingOnlineRequest((int) $user['id']);

$db = getDB();
$status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = ['r.user_id = ?'];
$params = [$user['id']];

if ($status) { $where[] = 'r.status = ?'; $params[] = $status; }
if ($search) { $where[] = '(r.request_number LIKE ? OR dt.name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(DISTINCT r.id) FROM requests r LEFT JOIN request_items ri ON ri.request_id = r.id LEFT JOIN document_types dt ON dt.id = COALESCE(ri.document_type_id, r.document_type_id) WHERE $whereClause");
$countStmt->execute($params);
$sortColumns = [
    'request_number' => ['type' => 'string', 'sql' => 'r.request_number'],
    'documents_summary' => ['type' => 'string', 'sql' => 'documents_summary'],
    'total_copies' => ['type' => 'number', 'sql' => 'total_copies', 'default_dir' => 'desc'],
    'total_amount' => ['type' => 'number', 'sql' => 'r.total_amount', 'default_dir' => 'desc'],
    'status' => ['type' => 'string', 'sql' => 'r.status'],
    'created_at' => ['type' => 'date', 'sql' => 'r.created_at', 'default_dir' => 'desc'],
];
$sortState = resolveRecordsSort($sortColumns, 'created_at', 'desc');
$listFilters = array_merge(
    ['status' => $status, 'search' => $search],
    recordsSortFilterParams($sortState)
);
$requestsPaging = recordsListPaging(
    (int) $countStmt->fetchColumn(),
    $listFilters,
    'studentRequestsFilterForm',
    'request',
    'requests'
);
$pag = $requestsPaging['pag'];
$sortQuery = $listFilters;
if ($requestsPaging['per_page'] !== ITEMS_PER_PAGE) {
    $sortQuery['per_page'] = $requestsPaging['per_page'];
}

$orderBy = recordsSqlOrderBy($sortState, 'r.created_at DESC');
$stmt = $db->prepare("SELECT r.*,
        GROUP_CONCAT(DISTINCT dt.name ORDER BY ri.sort_order, ri.id SEPARATOR ', ') AS documents_summary,
        COUNT(DISTINCT ri.id) AS document_count,
        COALESCE(SUM(ri.copies), r.copies, 1) AS total_copies
    FROM requests r
    LEFT JOIN request_items ri ON ri.request_id = r.id
    LEFT JOIN document_types dt ON dt.id = COALESCE(ri.document_type_id, r.document_type_id)
    WHERE $whereClause
    GROUP BY r.id
    ORDER BY {$orderBy}
    LIMIT {$requestsPaging['limit']} OFFSET {$requestsPaging['offset']}");
$stmt->execute($params);
$requests = $stmt->fetchAll();

$statusOptions = [
    'submitted', 'under_review', 'awaiting_requirements', 'needs_revision',
    'requirements_submitted', 'requirements_verified', 'payment_verified',
    'processing', 'ready_for_pickup', 'shipped', 'completed', 'rejected', 'cancelled',
];

$pageTitle = 'My Requests';
$activeNav = 'requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2>Request History</h2>
        <?php if ($profileCompletion['complete'] && !$blockingRequest): ?>
            <a href="new-request.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New Request</a>
        <?php elseif ($blockingRequest): ?>
            <a href="request-view.php?id=<?= (int) $blockingRequest['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-file-alt"></i> Active Request</a>
        <?php else: ?>
            <a href="profile.php" class="btn btn-primary btn-sm"><i class="fas fa-user-edit"></i> Complete Profile</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?= renderStudentProfileIncompleteAlert($profileCompletion) ?>
        <?= renderStudentBlockingOnlineRequestAlert($blockingRequest) ?>
        <form method="GET" class="filter-bar" id="studentRequestsFilterForm">
            <?= recordsSortFormFields($sortState) ?>
            <input type="text" name="search" placeholder="Search by request # or document..." value="<?= e($search) ?>">
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach ($statusOptions as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(studentProgressStatusLabel($s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        </form>
        <?= $requestsPaging['meta_html'] ?>

        <?php if (empty($requests)): ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No requests found.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table student-requests-table data-table-responsive">
                    <thead>
                        <tr>
                            <?= renderRecordsSortHeader('Request #', 'request_number', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Document', 'documents_summary', $sortState, $sortQuery) ?>
                            <th>Progress</th>
                            <?= renderRecordsSortHeader('Copies', 'total_copies', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Amount', 'total_amount', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Status', 'status', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Date', 'created_at', $sortState, $sortQuery) ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                            <td data-label="Document">
                                <?= e($req['documents_summary'] ?: ($req['document_name'] ?? '—')) ?>
                                <?php if ((int) ($req['document_count'] ?? 0) > 1): ?>
                                    <br><small class="text-muted"><?= (int) $req['document_count'] ?> documents in batch</small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Progress"><?= renderStudentProgressMini($req['status'], (int) $req['id']) ?></td>
                            <td data-label="Copies"><?= (int) ($req['total_copies'] ?? $req['copies'] ?? 1) ?></td>
                            <td data-label="Amount"><?= formatMoney((float)$req['total_amount']) ?></td>
                            <td data-label="Status"><?= statusBadge($req['status']) ?></td>
                            <td data-label="Date"><?= formatDate($req['created_at']) ?></td>
                            <td data-label="Actions" class="action-cell-buttons">
                                <a href="request-view.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline">View</a>
                                <?php if ($req['status'] === 'requirements_verified'): ?>
                                    <a href="payment.php?request_id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">Pay</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $requestsPaging['html'] ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
