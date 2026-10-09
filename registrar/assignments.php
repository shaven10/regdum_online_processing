<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/request-items.php';
require_once __DIR__ . '/../includes/assignment-offices.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/claim-stub.php';
requireRole('registrar');

$user = currentUser();
ensureComplianceSchema();
ensureRequestItemsSchema();
ensureRequestStatuses();
ensureDocumentAssignmentOfficeSchema();

$db = getDB();
$search = trim($_GET['search'] ?? '');
$requestId = (int) ($_GET['id'] ?? 0);
$assignmentView = ($_GET['view'] ?? '') === 'reassign' ? 'reassign' : 'queue';
$releaseTimeOptions = [
    '09:00:00' => '9:00 AM',
    '10:00:00' => '10:00 AM',
    '11:00:00' => '11:00 AM',
    '13:00:00' => '1:00 PM',
    '14:00:00' => '2:00 PM',
    '15:00:00' => '3:00 PM',
];

$request = null;
$requestItems = [];
$itemSchedules = [];
$releaseSchedule = null;

if ($requestId > 0) {
    $stmt = $db->prepare('SELECT r.*, dt.name as document_name, dt.processing_days,
        u.first_name, u.last_name, u.student_id, u.email
        FROM requests r
        LEFT JOIN document_types dt ON r.document_type_id = dt.id
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch() ?: null;

    if (!$request) {
        setFlash('error', 'Request not found.');
        redirect(APP_URL . '/registrar/assignments.php');
    }

    if (!requestDocumentAssignmentIsOpen($request['status'] ?? null)) {
        setFlash('error', 'Documents can be reassigned only while the request is still being processed. Ready for pickup and completed requests stay with their current staff.');
        redirect(APP_URL . '/registrar/assignments.php' . ($assignmentView === 'reassign' ? '?view=reassign' : ''));
    }

    $requestItems = getRequestItems($requestId);
    if ($requestItems === []) {
        setFlash('error', 'No document items found for this request.');
        redirect(APP_URL . '/registrar/assignments.php');
    }

    $releaseSchedule = buildReleaseScheduleForRequest(
        $requestId,
        (int) ($request['processing_days'] ?? 3),
        $request['release_date'] ?? null,
        $request['release_time'] ?? null
    );

    foreach ($requestItems as $requestItem) {
        $itemSchedules[(int) $requestItem['id']] = buildReleaseScheduleForRequestItem(
            (int) $requestItem['id'],
            $requestItem['release_date'] ?? null,
            $requestItem['release_time'] ?? null
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $postRequestId = (int) ($_POST['request_id'] ?? 0);

    if ($action === 'batch_assign') {
        $listUrl = APP_URL . '/registrar/assignments.php' . ($search !== '' ? '?search=' . urlencode($search) : '');
        $requestIds = normalizeAdminBatchRequestIds($_POST['request_ids'] ?? []);
        $result = batchAssignRequestsProcessing(
            $requestIds,
            (int) ($_POST['assigned_to'] ?? 0),
            (string) ($_POST['release_date'] ?? ''),
            (string) ($_POST['release_time'] ?? ''),
            (int) ($user['id'] ?? 0)
        );

        if (($result['assigned_requests'] ?? 0) > 0) {
            setFlash('success', $result['assigned_requests'] . ' request(s) assigned (' . (int) $result['assigned_items'] . ' document item' . ((int) $result['assigned_items'] === 1 ? '' : 's') . ').', [
                'title' => 'Batch Assignment Complete',
                'context' => array_filter([
                    'Assigned requests' => (string) $result['assigned_requests'],
                    'Assigned items' => (string) $result['assigned_items'],
                    'Skipped' => ((int) ($result['skipped'] ?? 0) > 0) ? (string) $result['skipped'] : null,
                ]),
                'details' => array_slice($result['failed'] ?? [], 0, 8),
            ]);
        } else {
            setFlash('error', implode(' ', $result['failed'] ?? ['Unable to assign selected requests.']), [
                'title' => 'Batch Assignment Failed',
            ]);
        }
        redirect($listUrl);
    }

    if ($action === 'assign_processing' && $postRequestId > 0) {
        $itemAssignments = is_array($_POST['item_assignments'] ?? null) ? $_POST['item_assignments'] : [];
        $hasItemAssignees = !empty(array_filter(
            $itemAssignments,
            static fn($row): bool => is_array($row) && !empty($row['assigned_to'])
        ));
        if ($hasItemAssignees) {
            $extra = [
                'item_assignments' => $itemAssignments,
                'release_date' => $_POST['release_date'] ?? null,
                'release_time' => $_POST['release_time'] ?? null,
            ];
        } else {
            $extra = [
                'assigned_to' => (int) ($_POST['assigned_to'] ?? 0),
                'release_date' => $_POST['release_date'] ?? null,
                'release_time' => $_POST['release_time'] ?? null,
            ];
        }

        $ok = processComplianceAction($postRequestId, [], 'assign_processing', $user['id'], '', $extra);
        if ($ok) {
            $reqNumber = $request['request_number'] ?? ('#' . $postRequestId);
            $isReassignment = ($request['status'] ?? '') === 'processing';
            setFlash('success', $isReassignment
                ? 'Documents were reassigned. Staff still processing this request will see the updated assignment.'
                : 'Documents assigned to staff. Processing has started.', [
                'title' => $isReassignment ? 'Documents Reassigned' : 'Staff Assignment Complete',
                'context' => ['Request' => $reqNumber],
                'next_step' => $isReassignment
                    ? 'The new assignee can continue processing. Ready for pickup and completed requests are not changed.'
                    : 'Staff can now process the assigned documents. Print the claim stub for the student.',
                'action_url' => APP_URL . '/registrar/claim-stub.php?id=' . $postRequestId . '&print=1',
                'action_label' => 'Print Claim Stub',
            ]);
            redirect(APP_URL . '/registrar/assignments.php' . ($isReassignment ? '?view=reassign' : ''));
        }

        setFlash('error', count($requestItems) > 1
            ? 'Select staff for each pending document and one release date for this request.'
            : 'Select staff and a release schedule.');
        redirect(APP_URL . '/registrar/assignments.php?id=' . $postRequestId);
    }
}

$assignmentRequests = $assignmentView === 'reassign'
    ? getRequestsEligibleForDocumentReassignment($search)
    : getRequestsAwaitingStaffAssignment($search);
$processors = getAssignableProcessors();
$pendingCount = $assignmentView === 'reassign'
    ? countRequestsAwaitingStaffAssignment()
    : count($assignmentRequests);
$sortColumns = [
    'request_number' => ['type' => 'string'],
    'name' => [
        'type' => 'string',
        'get' => static fn(array $r): string => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
    ],
    'document_name' => ['type' => 'string'],
    'pending_assignment_count' => ['type' => 'number', 'default_dir' => 'desc'],
    'total_amount' => ['type' => 'number', 'default_dir' => 'desc'],
    'updated_at' => [
        'type' => 'date',
        'default_dir' => 'desc',
        'get' => static fn(array $r): string => (string) ($r['updated_at'] ?? $r['created_at'] ?? ''),
    ],
];
$sortState = resolveRecordsSort($sortColumns, 'updated_at', 'desc');
$assignmentRequests = sortRecordList($assignmentRequests, $sortState);
$listFilters = array_merge(['search' => $search], recordsSortFilterParams($sortState));
if ($assignmentView === 'reassign') {
    $listFilters['view'] = 'reassign';
}
$pagedAssignments = paginateRecordList(
    $assignmentRequests,
    $listFilters,
    'assignmentFilterForm',
    'request',
    'requests'
);
$assignmentRequests = $pagedAssignments['items'];
$sortQuery = $listFilters;
if ($pagedAssignments['per_page'] !== ITEMS_PER_PAGE) {
    $sortQuery['per_page'] = $pagedAssignments['per_page'];
}

$isReassignment = $request && ($request['status'] ?? '') === 'processing';
$pageTitle = $request
    ? (($isReassignment ? 'Reassign Staff — ' : 'Assign Staff — ') . $request['request_number'])
    : ($assignmentView === 'reassign' ? 'Reassign Documents' : 'Staff Assignment');
$activeNav = 'assignments';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($request): ?>
<div class="card">
    <div class="card-header">
        <div>
            <a href="assignments.php?<?= http_build_query(array_filter(['view' => $isReassignment ? 'reassign' : null, 'search' => $search !== '' ? $search : null])) ?>" class="btn btn-outline btn-sm">
                <i class="fas fa-arrow-left"></i> <?= $isReassignment ? 'Back to Reassignment' : 'Back to Assignment Queue' ?>
            </a>
            <h2 style="margin-top:.75rem"><?= $isReassignment ? 'Reassign Staff' : 'Assign Staff' ?> — <?= e($request['request_number']) ?></h2>
        </div>
        <div class="card-header-actions">
            <?= renderRegistrarClaimSlipButtonsHtml($request, true) ?>
            <a href="verify-request.php?id=<?= (int) $request['id'] ?>" class="btn btn-outline btn-sm">
                <i class="fas fa-clipboard-check"></i> Open Full Review
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="detail-grid" style="margin-bottom:1.25rem">
            <div class="detail-item"><label>Student</label><span><?= e($request['first_name'] . ' ' . $request['last_name']) ?></span></div>
            <div class="detail-item"><label>Student ID</label><span><?= e($request['student_id'] ?? '—') ?></span></div>
            <div class="detail-item full"><label>Documents</label><span><?= e(formatRequestItemsSummary($requestItems)) ?></span></div>
            <div class="detail-item"><label>Amount</label><span><?= formatMoney((float) $request['total_amount']) ?></span></div>
            <div class="detail-item"><label>Status</label><span><?= statusBadge($request['status']) ?></span></div>
        </div>

        <?php if (empty($processors)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                No active assignees found. Add registrar, registrar staff, cashier, or guidance officer accounts first.
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-user-tag"></i>
                <?php if ($isReassignment): ?>
                    Change the staff on documents that are still being processed. Documents already ready for pickup or completed stay with their current staff.
                <?php else: ?>
                    Assign each document to a Registrar, Registrar Staff, Cashier, or Guidance Office account. Suggested offices:
                    SOA → Cashier, Good Moral → Guidance.
                <?php endif; ?>
                <?php if (count($requestItems) > 1): ?>
                    Documents in this request share one release date.
                <?php endif; ?>
            </div>

            <form method="POST" class="form-grid">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign_processing">
                <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">

                <?php
                $pendingItems = array_values(array_filter(
                    $requestItems,
                    static fn(array $item): bool => ($item['item_status'] ?? '') === 'pending_assignment'
                ));
                if ($pendingItems === []) {
                    $pendingItems = $requestItems;
                }
                ?>

                <?php if (count($requestItems) <= 1): ?>
                    <?php
                    $singleItem = $pendingItems[0];
                    $singleSchedule = $itemSchedules[(int) $singleItem['id']] ?? $releaseSchedule;
                    $preferredOffice = getDocumentAssignmentOffice(
                        (int) ($singleItem['document_type_id'] ?? 0),
                        $singleItem['document_code'] ?? null
                    );
                    ?>
                    <div class="form-group">
                        <label for="assigned_to">
                            <?= e($singleItem['document_name']) ?> — <?= $isReassignment ? 'Reassign to' : 'Assign to' ?> *
                            <span class="badge badge-review">Suggested: <?= e(assignmentOfficeLabel($preferredOffice)) ?></span>
                        </label>
                        <?php if (!requestItemCanBeReassigned($singleItem)): ?>
                            <p><?= e(trim(($singleItem['staff_first'] ?? '') . ' ' . ($singleItem['staff_last'] ?? ''))) ?> <?= requestItemStatusBadge($singleItem['item_status'] ?? '') ?></p>
                        <?php else: ?>
                            <?= renderAssigneeSelectHtml(
                                'assigned_to',
                                $processors,
                                $preferredOffice,
                                true,
                                'assigned_to',
                                (int) ($singleItem['assigned_to'] ?? 0) ?: null
                            ) ?>
                            <small class="text-muted">You can assign outside the Registrar when needed (Cashier or Guidance).</small>
                        <?php endif; ?>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="release_date">On-Site Release Date *</label>
                            <?php $singleReleaseDate = (string) ($singleSchedule['release_date'] ?? $singleSchedule['suggested_date'] ?? date('Y-m-d')); ?>
                            <input type="date" id="release_date" name="release_date"
                                value="<?= e($singleReleaseDate) ?>"
                                min="<?= e($singleReleaseDate !== '' && $singleReleaseDate < date('Y-m-d') ? $singleReleaseDate : date('Y-m-d')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="release_time">Release Time *</label>
                            <select id="release_time" name="release_time" required>
                                <?php foreach ($releaseTimeOptions as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= (($singleSchedule['release_time'] ?? $singleSchedule['suggested_time'] ?? '') === $value) ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php else: ?>
                    <?php
                    $groupSchedule = buildSharedReleaseScheduleForRequest(
                        (int) $request['id'],
                        $requestItems,
                        $request['release_date'] ?? null,
                        $request['release_time'] ?? null
                    );
                    ?>
                    <div class="request-item-assignment-table-wrap">
                        <table class="data-table request-item-assignment-table data-table-responsive">
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Assign To *</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requestItems as $requestItem): ?>
                                    <?php
                                    $itemId = (int) $requestItem['id'];
                                    $itemLocked = !requestItemCanBeReassigned($requestItem);
                                    $preferredOffice = getDocumentAssignmentOffice(
                                        (int) ($requestItem['document_type_id'] ?? 0),
                                        $requestItem['document_code'] ?? null
                                    );
                                    ?>
                                    <tr>
                                        <td data-label="Document">
                                            <strong><?= e($requestItem['document_name']) ?></strong>
                                            <br><small class="text-muted"><?= (int) $requestItem['copies'] ?> cop<?= (int) $requestItem['copies'] === 1 ? 'y' : 'ies' ?> · <?= formatMoney((float) $requestItem['item_amount']) ?></small>
                                            <br><span class="badge badge-review">Suggested: <?= e(assignmentOfficeLabel($preferredOffice)) ?></span>
                                            <?php if ($itemLocked || !empty($requestItem['assigned_to'])): ?>
                                                <br><?= requestItemStatusBadge($requestItem['item_status']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Assign To">
                                            <?php if ($itemLocked): ?>
                                                <span><?= e(trim(($requestItem['staff_first'] ?? '') . ' ' . ($requestItem['staff_last'] ?? '')) ?: '—') ?></span>
                                                <br><small class="text-muted">Cannot reassign</small>
                                            <?php else: ?>
                                                <?= renderAssigneeSelectHtml(
                                                    'item_assignments[' . $itemId . '][assigned_to]',
                                                    $processors,
                                                    $preferredOffice,
                                                    true,
                                                    '',
                                                    (int) ($requestItem['assigned_to'] ?? 0) ?: null
                                                ) ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted">One release date for this set of <?= count($requestItems) ?> documents. Saving it updates every document still open in this request. Suggested from <?= (int) $groupSchedule['processing_days'] ?> working day(s), excluding weekends.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="release_date">On-Site Release Date *</label>
                            <?php $groupReleaseDate = (string) ($groupSchedule['release_date'] ?? $groupSchedule['suggested_date'] ?? date('Y-m-d')); ?>
                            <input type="date" id="release_date" name="release_date"
                                value="<?= e($groupReleaseDate) ?>"
                                min="<?= e($groupReleaseDate !== '' && $groupReleaseDate < date('Y-m-d') ? $groupReleaseDate : date('Y-m-d')) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="release_time">Release Time *</label>
                            <select id="release_time" name="release_time" required>
                                <?php foreach ($releaseTimeOptions as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= (($groupSchedule['release_time'] ?? $groupSchedule['suggested_time'] ?? '') === $value) ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" onclick="return confirm(<?= $isReassignment ? "'Save the new staff assignment for documents still being processed?'" : "'Assign selected personnel and start document processing?'" ?>)">
                        <i class="fas fa-user-check"></i> <?= $isReassignment ? 'Save Reassignment' : 'Assign & Start Processing' ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>

<?php $assignmentStats = getComplianceStats(); ?>
<div class="stats-grid">
    <?= statCardLink('assignments.php', 'purple', 'fa-user-tag', (string) $pendingCount, 'Awaiting Assignment') ?>
    <?= statCardLink('compliance.php?filter=payment_ready', 'green', 'fa-credit-card', (string) $assignmentStats['payment_ready'], 'Payment Verified') ?>
    <?= statCardLink('compliance.php?filter=release_ready', 'blue', 'fa-cog', (string) $assignmentStats['release_ready'], 'In Processing / Release') ?>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2><?= $assignmentView === 'reassign' ? 'Reassign Documents' : 'Document Assignment to Staff' ?></h2>
            <p class="text-muted" style="margin:.35rem 0 0">
                <?= $assignmentView === 'reassign'
                    ? 'Change staff on requests that are still being processed. Ready for pickup and completed requests are not listed.'
                    : 'Assign paid requests to registrar staff for document processing.' ?>
            </p>
        </div>
        <div class="card-header-actions">
            <a href="assignments.php" class="btn btn-sm <?= $assignmentView === 'queue' ? 'btn-primary' : 'btn-outline' ?>">Awaiting Assignment</a>
            <a href="assignments.php?view=reassign" class="btn btn-sm <?= $assignmentView === 'reassign' ? 'btn-primary' : 'btn-outline' ?>">Reassign</a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar" id="assignmentFilterForm">
            <?php if ($assignmentView === 'reassign'): ?>
                <input type="hidden" name="view" value="reassign">
            <?php endif; ?>
            <?= recordsSortFormFields($sortState) ?>
            <input type="text" name="search" placeholder="Search request #, student..." value="<?= e($search) ?>">
            <button type="submit" class="btn btn-outline btn-sm">Search</button>
            <?php if ($search !== ''): ?>
                <a href="assignments.php<?= $assignmentView === 'reassign' ? '?view=reassign' : '' ?>" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>
        <?= $pagedAssignments['meta_html'] ?>

        <?php if (empty($assignmentRequests)): ?>
            <div class="empty-state">
                <i class="fas fa-user-check"></i>
                <p><?= $assignmentView === 'reassign'
                    ? 'No processing requests can be reassigned right now.'
                    : 'No requests are waiting for staff assignment.' ?></p>
            </div>
        <?php else: ?>
            <form method="POST" id="assignmentBatchForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="batch_assign">

                <?php if ($assignmentView !== 'reassign'): ?>
                <div class="batch-action-bar" id="assignmentBatchActionBar" hidden>
                    <span class="batch-action-count"><strong id="assignmentBatchSelectedCount">0</strong> selected</span>
                    <div class="batch-action-buttons">
                        <button type="button" class="btn btn-primary btn-sm" id="openAssignmentBatchAssignModal">
                            <i class="fas fa-user-tag"></i> Assign Selected
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <?php if ($assignmentView !== 'reassign'): ?>
                                <th class="batch-select-col">
                                    <label class="checkbox-label batch-select-all-label">
                                        <input type="checkbox" id="assignmentSelectAllRequests" aria-label="Select all requests">
                                    </label>
                                </th>
                                <?php endif; ?>
                                <?= renderRecordsSortHeader('Request #', 'request_number', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Student', 'name', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Documents', 'document_name', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader($assignmentView === 'reassign' ? 'Open Documents' : 'Pending Items', 'pending_assignment_count', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Amount', 'total_amount', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Paid / Updated', 'updated_at', $sortState, $sortQuery) ?>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignmentRequests as $req): ?>
                                <tr>
                                    <?php if ($assignmentView !== 'reassign'): ?>
                                    <td class="batch-select-col" data-label="Select">
                                        <label class="checkbox-label">
                                            <input type="checkbox" class="assignment-request-select" name="request_ids[]" value="<?= (int) $req['id'] ?>">
                                        </label>
                                    </td>
                                    <?php endif; ?>
                                    <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                                    <td data-label="Student">
                                        <?= e($req['first_name'] . ' ' . $req['last_name']) ?>
                                        <br><small class="text-muted"><?= e($req['student_id'] ?? '') ?></small>
                                    </td>
                                    <td data-label="Documents">
                                        <?= e($req['document_name'] ?? '—') ?>
                                        <?php if ((int) ($req['document_count'] ?? 0) > 1): ?>
                                            <br><small class="text-muted"><?= (int) $req['document_count'] ?> documents</small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?= $assignmentView === 'reassign' ? 'Open Documents' : 'Pending Items' ?>">
                                        <span class="badge badge-review">
                                            <?= max(1, (int) ($req['pending_assignment_count'] ?? 0)) ?>
                                            <?= $assignmentView === 'reassign' ? 'open' : 'to assign' ?>
                                        </span>
                                        <?php if ($assignmentView === 'reassign' && !empty($req['assignee_names'])): ?>
                                            <br><small class="text-muted"><?= e($req['assignee_names']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Amount"><?= formatMoney((float) ($req['total_amount'] ?? 0)) ?></td>
                                    <td data-label="Paid / Updated"><?= formatDateTime($req['updated_at'] ?? $req['created_at']) ?></td>
                                    <td data-label="Action" class="action-cell-buttons">
                                        <a href="assignments.php?id=<?= (int) $req['id'] ?><?= $assignmentView === 'reassign' ? '&view=reassign' : '' ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-user-tag"></i> <?= $assignmentView === 'reassign' ? 'Reassign' : 'Assign Staff' ?>
                                        </a>
                                        <a href="verify-request.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-outline">Review</a>
                                        <?= renderRegistrarClaimSlipButtonsHtml($req, true) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?= $pagedAssignments['html'] ?>

            <?php if ($assignmentView !== 'reassign'): ?>
            <?php renderAdminFormModalOpen('Staff Assignment', 'Batch Assign Staff', 'assignmentBatchAssignModal'); ?>
            <form method="POST" id="assignmentBatchAssignForm" class="form-grid">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="batch_assign">
                <div id="assignmentBatchAssignHiddenIds"></div>
                <p class="text-muted">Assign all pending documents on the selected requests to one staff member.</p>
                <div class="form-group">
                    <label for="assignment_batch_assigned_to">Assign to *</label>
                    <?php if (empty($processors)): ?>
                        <select id="assignment_batch_assigned_to" name="assigned_to" required disabled>
                            <option value="">No active assignees available</option>
                        </select>
                    <?php else: ?>
                        <?= renderAssigneeSelectHtml('assigned_to', $processors, null, true, 'assignment_batch_assigned_to') ?>
                    <?php endif; ?>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="assignment_batch_release_date">Release Date *</label>
                        <input type="date" id="assignment_batch_release_date" name="release_date" value="<?= e(date('Y-m-d')) ?>" min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="assignment_batch_release_time">Release Time *</label>
                        <select id="assignment_batch_release_time" name="release_time" required>
                            <?php foreach ($releaseTimeOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $value === '09:00:00' ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php renderAdminFormModalFooter('Assign Selected', 'fa-user-tag'); ?>
            </form>
            <?php renderAdminFormModalClose(); ?>

            <script>
            (function () {
                const batchBar = document.getElementById('assignmentBatchActionBar');
                const countEl = document.getElementById('assignmentBatchSelectedCount');
                const selectAll = document.getElementById('assignmentSelectAllRequests');
                const rowChecks = () => Array.from(document.querySelectorAll('.assignment-request-select'));
                const modal = document.getElementById('assignmentBatchAssignModal');
                const hiddenIds = document.getElementById('assignmentBatchAssignHiddenIds');

                function selectedChecks() {
                    return rowChecks().filter(function (cb) { return cb.checked; });
                }

                function syncBatchBar() {
                    const selected = selectedChecks();
                    const count = selected.length;
                    if (countEl) countEl.textContent = String(count);
                    if (batchBar) batchBar.hidden = count === 0;
                    if (selectAll) {
                        const all = rowChecks();
                        selectAll.checked = all.length > 0 && count === all.length;
                        selectAll.indeterminate = count > 0 && count < all.length;
                    }
                }

                if (selectAll) {
                    selectAll.addEventListener('change', function () {
                        rowChecks().forEach(function (cb) { cb.checked = selectAll.checked; });
                        syncBatchBar();
                    });
                }
                rowChecks().forEach(function (cb) {
                    cb.addEventListener('change', syncBatchBar);
                });

                modal?.querySelectorAll('[data-close-admin-form]').forEach(function (el) {
                    el.addEventListener('click', function () {
                        modal.classList.remove('is-open');
                        modal.setAttribute('aria-hidden', 'true');
                        document.body.style.overflow = '';
                    });
                });

                document.getElementById('openAssignmentBatchAssignModal')?.addEventListener('click', function () {
                    const selected = selectedChecks();
                    if (!selected.length) {
                        alert('Select at least one request.');
                        return;
                    }
                    if (!hiddenIds || !modal) return;
                    hiddenIds.innerHTML = '';
                    selected.forEach(function (cb) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'request_ids[]';
                        input.value = cb.value;
                        hiddenIds.appendChild(input);
                    });
                    modal.classList.add('is-open');
                    modal.setAttribute('aria-hidden', 'false');
                    document.body.style.overflow = 'hidden';
                    document.getElementById('assignment_batch_assigned_to')?.focus();
                });

                syncBatchBar();
            })();
            </script>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
