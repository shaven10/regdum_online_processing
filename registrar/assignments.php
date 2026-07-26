<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/request-items.php';
require_once __DIR__ . '/../includes/assignment-offices.php';
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
                No active assignees found. Add registrar staff, cashier, or guidance officer accounts first.
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-user-tag"></i>
                Assign each document to Registrar Staff, Cashier, or Guidance Office. Suggested offices:
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
            <div class="table-wrap">
                <table class="data-table data-table-responsive">
                    <thead>
                        <tr>
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
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
