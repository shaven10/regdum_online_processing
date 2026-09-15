<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clearance.php';
require_once __DIR__ . '/../includes/onsite-request.php';
require_once __DIR__ . '/../includes/programs.php';
requireClearanceAccess();

$user = currentUser();
$department = getUserClearanceDepartment($user);
$deptId = $department['id'] ?? (int) ($_GET['department_id'] ?? 0);
$status = $_GET['status'] ?? 'pending';
$search = trim($_GET['search'] ?? '');

if (!$deptId) {
    setFlash('error', 'No clearance department assigned.');
    redirect(dashboardUrl());
}

$programScope = getClearanceOfficerProgramScope($user);
$isProgramChair = isProgramChairDepartment($department);
$queueProgramId = null;
$assignedProgram = null;
if ($isProgramChair) {
    $queueProgramId = ($programScope !== null && $programScope > 0) ? $programScope : 0;
    if ($queueProgramId > 0) {
        $assignedProgram = getAcademicProgramById($queueProgramId);
    }
}

$requests = getClearanceRequestsForDepartment((int) $deptId, $status, $search, $queueProgramId);
$sortColumns = [
    'request_number' => ['type' => 'string'],
    'name' => [
        'type' => 'string',
        'get' => static fn(array $r): string => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
    ],
    'course' => ['type' => 'string'],
    'request_channel' => ['type' => 'string'],
    'document_name' => ['type' => 'string'],
    'status' => ['type' => 'string'],
    'request_date' => ['type' => 'date', 'default_dir' => 'desc'],
];
$sortState = resolveRecordsSort($sortColumns, 'request_date', 'desc');
$requests = sortRecordList($requests, $sortState);
$listFilters = array_merge([
    'status' => $status !== 'pending' ? $status : '',
    'search' => $search,
], recordsSortFilterParams($sortState));
$pagedClearance = paginateRecordList($requests, $listFilters, 'clearanceRequestsFilterForm', 'request', 'requests');
$requests = $pagedClearance['items'];
$sortQuery = $listFilters;
if ($pagedClearance['per_page'] !== ITEMS_PER_PAGE) {
    $sortQuery['per_page'] = $pagedClearance['per_page'];
}

$pageTitle = 'Clearance Requests';
$activeNav = $status === 'pending' ? 'pending' : 'requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2><?= e($department['name']) ?> — Clearance Queue</h2>
            <p class="text-muted" style="margin:.35rem 0 0">
                <?php if ($isProgramChair && $assignedProgram): ?>
                    Showing requests for <strong><?= e($assignedProgram['name']) ?></strong> (<?= e($assignedProgram['code']) ?>).
                <?php elseif ($isProgramChair): ?>
                    No course/program is assigned to this Program Chair account yet.
                <?php else: ?>
                    Open a request to review requestor details and sign clearance.
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar" id="clearanceRequestsFilterForm">
            <?= recordsSortFormFields($sortState) ?>
            <input type="text" name="search" placeholder="Search request #, name, student ID, course..." value="<?= e($search) ?>">
            <select name="status">
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="cleared" <?= $status === 'cleared' ? 'selected' : '' ?>>Cleared</option>
                <option value="on_hold" <?= $status === 'on_hold' ? 'selected' : '' ?>>On Hold</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <?php if ($search !== '' || $status !== 'pending'): ?>
                <a href="requests.php" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>
        <?= $pagedClearance['meta_html'] ?>

        <?php if (empty($requests)): ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No clearance records found.</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table data-table-responsive">
                    <thead>
                        <tr>
                            <?= renderRecordsSortHeader('Request #', 'request_number', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Requestor', 'name', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Course', 'course', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Channel', 'request_channel', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Document', 'document_name', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Clearance', 'status', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Date', 'request_date', $sortState, $sortQuery) ?>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $item): ?>
                            <?php $isOnsite = isOnsiteRequestChannel($item['request_channel'] ?? null); ?>
                            <tr>
                                <td data-label="Request #"><strong><?= e($item['request_number']) ?></strong></td>
                                <td data-label="Requestor">
                                    <?= e($item['first_name'] . ' ' . $item['last_name']) ?>
                                    <br><small class="text-muted"><?= e($item['student_id'] ?? '—') ?></small>
                                    <?php if (!empty($item['enrollment_status'])): ?>
                                        <br><small class="text-muted"><?= e(enrollmentStatusLabel($item['enrollment_status'])) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Course">
                                    <?= e($item['course'] ?? '—') ?>
                                    <?php if (!empty($item['year_level'])): ?>
                                        <br><small class="text-muted"><?= e($item['year_level']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Channel">
                                    <?= $isOnsite
                                        ? '<span class="badge badge-processing">Onsite</span>'
                                        : '<span class="badge badge-review">Online</span>' ?>
                                </td>
                                <td data-label="Document"><?= e($item['document_name']) ?></td>
                                <td data-label="Clearance"><?= clearanceStatusBadge($item['status']) ?></td>
                                <td data-label="Date"><?= formatDate($item['request_date']) ?></td>
                                <td data-label="Action">
                                    <a href="sign.php?request_id=<?= (int) $item['request_id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-stamp"></i> <?= ($item['status'] ?? '') === 'pending' ? 'Sign' : 'Review' ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $pagedClearance['html'] ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
