<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/attachments.php';
requireRole('registrar');

ensureComplianceSchema();

$filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$requests = getRequestsWithAttachments($filter, $search);
$sortColumns = [
    'request_number' => ['type' => 'string'],
    'name' => [
        'type' => 'string',
        'get' => static fn(array $r): string => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
    ],
    'document_name' => ['type' => 'string'],
    'status' => ['type' => 'string'],
    'attachments' => [
        'type' => 'number',
        'default_dir' => 'desc',
        'get' => static fn(array $r): int => (int) ($r['document_count'] ?? 0) + (int) ($r['receipt_count'] ?? 0),
    ],
    'created_at' => ['type' => 'date', 'default_dir' => 'desc'],
];
$sortState = resolveRecordsSort($sortColumns, 'created_at', 'desc');
$requests = sortRecordList($requests, $sortState);
$listFilters = array_merge([
    'status' => $filter,
    'search' => $search,
], recordsSortFilterParams($sortState));
$pagedAttachments = paginateRecordList($requests, $listFilters, 'attachmentsFilterForm', 'request', 'requests');
$requests = $pagedAttachments['items'];
$sortQuery = $listFilters;
if ($pagedAttachments['per_page'] !== ITEMS_PER_PAGE) {
    $sortQuery['per_page'] = $pagedAttachments['per_page'];
}

$pageTitle = 'Request Attachments';
$activeNav = 'attachments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Requestor Attachments</h2></div>
    <div class="card-body">
        <form method="GET" class="filter-bar" id="attachmentsFilterForm">
            <?= recordsSortFormFields($sortState) ?>
            <input type="text" name="search" placeholder="Search request #, student..." value="<?= e($search) ?>">
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach (['submitted','awaiting_requirements','requirements_submitted','requirements_verified','payment_verified','processing','completed'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filter === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        </form>
        <?= $pagedAttachments['meta_html'] ?>

        <?php if (empty($requests)): ?>
            <div class="empty-state"><i class="fas fa-paperclip"></i><p>No requests with attachments found.</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table data-table-responsive attachments-list-table">
                    <thead>
                        <tr>
                            <?= renderRecordsSortHeader('Request #', 'request_number', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Student', 'name', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Document', 'document_name', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Status', 'status', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Attachments', 'attachments', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Submitted', 'created_at', $sortState, $sortQuery) ?>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                            <td data-label="Student">
                                <?= e($req['first_name'] . ' ' . $req['last_name']) ?>
                                <br><small class="text-muted"><?= e($req['student_id']) ?></small>
                            </td>
                            <td data-label="Document"><?= e($req['document_name']) ?></td>
                            <td data-label="Status"><?= statusBadge($req['status']) ?></td>
                            <td data-label="Attachments">
                                <span class="badge badge-review"><?= (int)$req['document_count'] ?> file(s)</span>
                                <?php if ((int)$req['receipt_count'] > 0): ?>
                                    <span class="badge badge-payment"><?= (int)$req['receipt_count'] ?> receipt</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Submitted"><?= formatDate($req['created_at']) ?></td>
                            <td data-label="Action">
                                <a href="view-attachments.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-paperclip"></i> View
                                </a>
                                <a href="verify-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline">Review</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $pagedAttachments['html'] ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
