<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/request-items.php';
require_once __DIR__ . '/../includes/assignment-offices.php';
require_once __DIR__ . '/../includes/ui.php';
requireRole('registrar');

$user = currentUser();
ensureComplianceSchema();
ensureRequestItemsSchema();
ensureRequestStatuses();
ensureDocumentAssignmentOfficeSchema();

$db = getDB();
$search = trim($_GET['search'] ?? '');
$requestId = (int) ($_GET['id'] ?? 0);
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

    $awaitingAssignment = $request['status'] === 'payment_verified'
        || ($request['status'] === 'processing' && requestHasPendingAssignmentItems($requestId));

    if (!$awaitingAssignment) {
        setFlash('error', 'This request is not awaiting staff assignment.');
        redirect(APP_URL . '/registrar/assignments.php');
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
        $itemAssignments = $_POST['item_assignments'] ?? [];
        $extra = ['item_assignments' => $itemAssignments];

        if (empty(array_filter($itemAssignments, static fn($row) => !empty($row['assigned_to'])))) {
            $extra = [
                'assigned_to' => (int) ($_POST['assigned_to'] ?? 0),
                'release_date' => $_POST['release_date'] ?? null,
                'release_time' => $_POST['release_time'] ?? null,
            ];
        }

        $ok = processComplianceAction($postRequestId, [], 'assign_processing', $user['id'], '', $extra);
        if ($ok) {
            $reqNumber = $request['request_number'] ?? ('#' . $postRequestId);
            setFlash('success', 'Documents assigned to staff. Processing has started.', [
                'title' => 'Staff Assignment Complete',
                'context' => ['Request' => $reqNumber],
                'next_step' => 'Staff can now process the assigned documents. Print the claim stub for the student.',
                'action_url' => APP_URL . '/registrar/claim-stub.php?id=' . $postRequestId . '&print=1',
                'action_label' => 'Print Claim Stub',
            ]);
            redirect(APP_URL . '/registrar/assignments.php');
        }

        setFlash('error', 'Select staff and release schedule for each pending document.');
        redirect(APP_URL . '/registrar/assignments.php?id=' . $postRequestId);
    }
}

$assignmentRequests = getRequestsAwaitingStaffAssignment($search);
$processors = getAssignableProcessors();
$pendingCount = count($assignmentRequests);

$pageTitle = $request ? ('Assign Staff — ' . $request['request_number']) : 'Staff Assignment';
$activeNav = 'assignments';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($request): ?>
<div class="card">
    <div class="card-header">
        <div>
            <a href="assignments.php<?= $search !== '' ? '?search=' . urlencode($search) : '' ?>" class="btn btn-outline btn-sm">
                <i class="fas fa-arrow-left"></i> Back to Assignment Queue
            </a>
            <h2 style="margin-top:.75rem">Assign Staff — <?= e($request['request_number']) ?></h2>
        </div>
        <a href="verify-request.php?id=<?= (int) $request['id'] ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-clipboard-check"></i> Open Full Review
        </a>
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
                Assign each document to a Registrar, Registrar Staff, Cashier, or Guidance Office account. Suggested offices:
                SOA → Cashier, Good Moral → Guidance.
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

                <?php if (count($pendingItems) === 1): ?>
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
                            <?= e($singleItem['document_name']) ?> — Assign to *
                            <span class="badge badge-review">Suggested: <?= e(assignmentOfficeLabel($preferredOffice)) ?></span>
                        </label>
                        <?= renderAssigneeSelectHtml('assigned_to', $processors, $preferredOffice, true, 'assigned_to') ?>
                        <small class="text-muted">You can assign outside the Registrar when needed (Cashier or Guidance).</small>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="release_date">On-Site Release Date *</label>
                            <input type="date" id="release_date" name="release_date"
                                value="<?= e($singleSchedule['release_date'] ?? $singleSchedule['suggested_date'] ?? date('Y-m-d')) ?>"
                                min="<?= date('Y-m-d') ?>" required>
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
                    <div class="request-item-assignment-table-wrap">
                        <table class="data-table request-item-assignment-table data-table-responsive">
                            <thead>
                                <tr>
                                    <th>Document</th>
                                    <th>Assign To *</th>
                                    <th>Release Date *</th>
                                    <th>Release Time *</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requestItems as $requestItem): ?>
                                    <?php
                                    $itemId = (int) $requestItem['id'];
                                    $schedule = $itemSchedules[$itemId] ?? $releaseSchedule;
                                    $isAssigned = ($requestItem['item_status'] ?? '') !== 'pending_assignment';
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
                                            <?php if ($isAssigned): ?>
                                                <br><?= requestItemStatusBadge($requestItem['item_status']) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Assign To">
                                            <?php if ($isAssigned && !empty($requestItem['staff_first'])): ?>
                                                <span><?= e($requestItem['staff_first'] . ' ' . $requestItem['staff_last']) ?></span>
                                            <?php else: ?>
                                                <?= renderAssigneeSelectHtml(
                                                    'item_assignments[' . $itemId . '][assigned_to]',
                                                    $processors,
                                                    $preferredOffice
                                                ) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Release Date">
                                            <?php if ($isAssigned): ?>
                                                <?= !empty($requestItem['release_date']) ? e(formatDate($requestItem['release_date'])) : '—' ?>
                                            <?php else: ?>
                                                <input type="date" name="item_assignments[<?= $itemId ?>][release_date]"
                                                    value="<?= e($schedule['release_date'] ?? $schedule['suggested_date'] ?? date('Y-m-d')) ?>"
                                                    min="<?= date('Y-m-d') ?>" required>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Release Time">
                                            <?php if ($isAssigned): ?>
                                                <?= !empty($requestItem['release_time']) ? e(date('g:i A', strtotime((string) $requestItem['release_time']))) : '—' ?>
                                            <?php else: ?>
                                                <select name="item_assignments[<?= $itemId ?>][release_time]" required>
                                                    <?php foreach ($releaseTimeOptions as $value => $label): ?>
                                                        <option value="<?= $value ?>" <?= (($schedule['release_time'] ?? $schedule['suggested_time'] ?? '') === $value) ? 'selected' : '' ?>>
                                                            <?= e($label) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Assign selected personnel and start document processing?')">
                        <i class="fas fa-user-check"></i> Assign & Start Processing
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
            <h2>Document Assignment to Staff</h2>
            <p class="text-muted" style="margin:.35rem 0 0">Assign paid requests to registrar staff for document processing.</p>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search request #, student..." value="<?= e($search) ?>">
            <button type="submit" class="btn btn-outline btn-sm">Search</button>
            <?php if ($search !== ''): ?>
                <a href="assignments.php" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($assignmentRequests)): ?>
            <div class="empty-state">
                <i class="fas fa-user-check"></i>
                <p>No requests are waiting for staff assignment.</p>
            </div>
        <?php else: ?>
            <form method="POST" id="assignmentBatchForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="batch_assign">

                <div class="batch-action-bar" id="assignmentBatchActionBar" hidden>
                    <span class="batch-action-count"><strong id="assignmentBatchSelectedCount">0</strong> selected</span>
                    <div class="batch-action-buttons">
                        <button type="button" class="btn btn-primary btn-sm" id="openAssignmentBatchAssignModal">
                            <i class="fas fa-user-tag"></i> Assign Selected
                        </button>
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <th class="batch-select-col">
                                    <label class="checkbox-label batch-select-all-label">
                                        <input type="checkbox" id="assignmentSelectAllRequests" aria-label="Select all requests">
                                    </label>
                                </th>
                                <th>Request #</th>
                                <th>Student</th>
                                <th>Documents</th>
                                <th>Pending Items</th>
                                <th>Amount</th>
                                <th>Paid / Updated</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignmentRequests as $req): ?>
                                <tr>
                                    <td class="batch-select-col" data-label="Select">
                                        <label class="checkbox-label">
                                            <input type="checkbox" class="assignment-request-select" name="request_ids[]" value="<?= (int) $req['id'] ?>">
                                        </label>
                                    </td>
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
                                    <td data-label="Pending Items">
                                        <span class="badge badge-review">
                                            <?= max(1, (int) ($req['pending_assignment_count'] ?? 0)) ?> to assign
                                        </span>
                                    </td>
                                    <td data-label="Amount"><?= formatMoney((float) ($req['total_amount'] ?? 0)) ?></td>
                                    <td data-label="Paid / Updated"><?= formatDateTime($req['updated_at'] ?? $req['created_at']) ?></td>
                                    <td data-label="Action" class="action-cell-buttons">
                                        <a href="assignments.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-user-tag"></i> Assign Staff
                                        </a>
                                        <a href="verify-request.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-outline">Review</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>

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
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
