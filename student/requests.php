<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/request-items.php';
requireRole('student');

$user = currentUser();
ensureDeliveryMethods();
ensureRequestItemsSchema();
$profileCompletion = getStudentProfileCompletion($user['id']);

$db = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$where = ['r.user_id = ?'];
$params = [$user['id']];

if ($status) { $where[] = 'r.status = ?'; $params[] = $status; }
if ($search) { $where[] = '(r.request_number LIKE ? OR dt.name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(DISTINCT r.id) FROM requests r LEFT JOIN request_items ri ON ri.request_id = r.id LEFT JOIN document_types dt ON dt.id = COALESCE(ri.document_type_id, r.document_type_id) WHERE $whereClause");
$countStmt->execute($params);
$pag = paginate((int)$countStmt->fetchColumn(), $page);

$stmt = $db->prepare("SELECT r.*,
        GROUP_CONCAT(DISTINCT dt.name ORDER BY ri.sort_order, ri.id SEPARATOR ', ') AS documents_summary,
        COUNT(DISTINCT ri.id) AS document_count,
        COALESCE(SUM(ri.copies), r.copies, 1) AS total_copies
    FROM requests r
    LEFT JOIN request_items ri ON ri.request_id = r.id
    LEFT JOIN document_types dt ON dt.id = COALESCE(ri.document_type_id, r.document_type_id)
    WHERE $whereClause
    GROUP BY r.id
    ORDER BY r.created_at DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$requests = $stmt->fetchAll();

$statusOptions = [
    'submitted', 'under_review', 'awaiting_requirements', 'needs_revision',
    'requirements_submitted', 'requirements_verified', 'payment_verified',
    'processing', 'ready_for_pickup', 'shipped', 'completed', 'rejected',
];

$pageTitle = 'My Requests';
$activeNav = 'requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2>Request History</h2>
        <a href="<?= $profileCompletion['complete'] ? 'new-request.php' : 'profile.php' ?>" class="btn btn-primary btn-sm"><i class="fas fa-<?= $profileCompletion['complete'] ? 'plus' : 'user-edit' ?>"></i> <?= $profileCompletion['complete'] ? 'New Request' : 'Complete Profile' ?></a>
    </div>
    <div class="card-body">
        <?= renderStudentProfileIncompleteAlert($profileCompletion) ?>
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search by request # or document..." value="<?= e($search) ?>">
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach ($statusOptions as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(studentProgressStatusLabel($s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        </form>

        <?php if (empty($requests)): ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No requests found.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table student-requests-table data-table-responsive">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Document</th>
                            <th>Progress</th>
                            <th>Copies</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
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
            <?= paginationLinks($pag, '?' . http_build_query(array_filter(['status' => $status, 'search' => $search]))) ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
