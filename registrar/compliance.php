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
ensureRequestStatuses();
ensureRequestItemsSchema();
ensureDocumentAssignmentOfficeSchema();

$allowedFilters = [
    'review',
    'pending',
    'needs_revision',
    'awaiting_student',
    're_evaluation',
    'verified',
    'payment_ready',
    'release_ready',
    'completed',
    '',
];

$filter = array_key_exists('filter', $_GET) ? (string) ($_GET['filter'] ?? '') : 'review';
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'review';
}

$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = normalizeRecordsPerPage((int) ($_GET['per_page'] ?? ITEMS_PER_PAGE));
$listQuery = array_filter([
    'filter' => $filter !== 'review' ? $filter : '',
    'search' => $search,
    'per_page' => $perPage !== ITEMS_PER_PAGE ? (string) $perPage : '',
    'page' => $page > 1 ? (string) $page : '',
], static fn($v) => $v !== null && $v !== '');
$listUrl = APP_URL . '/registrar/compliance.php' . ($listQuery ? '?' . http_build_query($listQuery) : '');

$releaseTimeOptions = [
    '09:00:00' => '9:00 AM',
    '10:00:00' => '10:00 AM',
    '11:00:00' => '11:00 AM',
    '13:00:00' => '1:00 PM',
    '14:00:00' => '2:00 PM',
    '15:00:00' => '3:00 PM',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $requestIds = normalizeAdminBatchRequestIds($_POST['request_ids'] ?? []);

    if ($requestIds === []) {
        setFlash('error', 'Select at least one request.', ['title' => 'No Requests Selected']);
        redirect($listUrl);
    }

    if ($action === 'batch_update_status') {
        $newStatus = trim($_POST['status'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $result = batchUpdateRequestStatuses($requestIds, $newStatus, $remarks ?: 'Status updated by registrar (batch)');

        if (($result['updated'] ?? 0) > 0) {
            setFlash('success', $result['updated'] . ' request(s) updated to ' . ucwords(str_replace('_', ' ', $newStatus)) . '.', [
                'title' => 'Batch Status Updated',
                'context' => [
                    'Updated' => (string) $result['updated'],
                    'Unchanged' => (string) ($result['unchanged'] ?? 0),
                ],
            ]);
        } elseif (($result['unchanged'] ?? 0) > 0 && empty($result['failed'])) {
            setFlash('info', 'Selected requests already have the chosen status.', ['title' => 'No Changes Needed']);
        } else {
            setFlash('error', implode(' ', $result['failed'] ?? ['Unable to update selected requests.']), [
                'title' => 'Batch Update Failed',
            ]);
        }
        redirect($listUrl);
    }

    if ($action === 'batch_assign') {
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
            setFlash('error', implode(' ', $result['failed'] ?? ['Unable to assign selected requests. Only payment-verified requests with pending documents can be assigned.']), [
                'title' => 'Batch Assignment Failed',
            ]);
        }
        redirect($listUrl);
    }

    setFlash('error', 'Unknown batch action.', ['title' => 'Action Failed']);
    redirect($listUrl);
}

$stats = getComplianceStats();
$requests = getRequestsForCompliance($filter);

if ($search !== '') {
    $requests = array_values(array_filter($requests, static function (array $r) use ($search): bool {
        $haystack = strtolower(
            ($r['request_number'] ?? '') . ' '
            . ($r['first_name'] ?? '') . ' '
            . ($r['last_name'] ?? '') . ' '
            . ($r['student_id'] ?? '') . ' '
            . ($r['document_name'] ?? '')
        );
        return str_contains($haystack, strtolower($search));
    }));
}

$sortColumns = [
    'request_number' => ['type' => 'string'],
    'student_id' => ['type' => 'string'],
    'name' => [
        'type' => 'string',
        'get' => static fn(array $r): string => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
    ],
    'document_name' => ['type' => 'string'],
    'status' => ['type' => 'string'],
    'requirement_count' => ['type' => 'number', 'default_dir' => 'desc'],
    'created_at' => ['type' => 'date', 'default_dir' => 'desc'],
    'completed_at' => [
        'type' => 'date',
        'default_dir' => 'desc',
        'get' => static fn(array $r): string => (string) ($r['completed_at'] ?? $r['updated_at'] ?? $r['created_at'] ?? ''),
    ],
];
$defaultSort = $filter === 'completed' ? 'completed_at' : 'created_at';
$sortState = resolveRecordsSort($sortColumns, $defaultSort, 'desc');
$requests = sortRecordList($requests, $sortState);
$listFilters = array_merge([
    'filter' => $filter !== 'review' ? $filter : '',
    'search' => $search,
], recordsSortFilterParams($sortState));
$paged = paginateRecordList($requests, $listFilters, 'complianceFilterForm', 'request', 'requests');
$requests = $paged['items'];
$sortQuery = $listFilters;
if ($paged['per_page'] !== ITEMS_PER_PAGE) {
    $sortQuery['per_page'] = $paged['per_page'];
}

$stageCards = [
    [
        'key' => 'review',
        'label' => 'Review Queue',
        'hint' => 'New + Needs Revision',
        'count' => (int) ($stats['review'] ?? 0),
        'color' => 'orange',
        'icon' => 'fa-clipboard-check',
    ],
    [
        'key' => 'pending',
        'label' => 'New Requests',
        'hint' => 'Submitted / Under review',
        'count' => (int) ($stats['pending'] ?? 0),
        'color' => 'blue',
        'icon' => 'fa-inbox',
    ],
    [
        'key' => 'needs_revision',
        'label' => 'Needs Revision',
        'hint' => 'Corrections requested',
        'count' => (int) ($stats['needs_revision'] ?? 0),
        'color' => 'gold',
        'icon' => 'fa-exclamation-triangle',
    ],
    [
        'key' => 'awaiting_student',
        'label' => 'Awaiting Student',
        'hint' => 'Requirements pending',
        'count' => (int) ($stats['awaiting_student'] ?? 0),
        'color' => 'purple',
        'icon' => 'fa-list-check',
    ],
    [
        'key' => 're_evaluation',
        'label' => 'Re-evaluation',
        'hint' => 'Student resubmitted',
        'count' => (int) ($stats['re_evaluation'] ?? 0),
        'color' => 'teal',
        'icon' => 'fa-search',
    ],
    [
        'key' => 'verified',
        'label' => 'Awaiting Payment',
        'hint' => 'Approved requirements',
        'count' => (int) ($stats['compliant'] ?? 0),
        'color' => 'green',
        'icon' => 'fa-credit-card',
    ],
];

$filterLabels = [
    'review' => 'Review Queue (New + Needs Revision)',
    'pending' => 'New Requests',
    'needs_revision' => 'Needs Revision',
    'awaiting_student' => 'Awaiting Student',
    're_evaluation' => 'Re-evaluation',
    'verified' => 'Awaiting Payment',
    'payment_ready' => 'Staff Assignment / Processing',
    'release_ready' => 'Document Release',
    'completed' => 'Completed Transactions',
    '' => 'All Active Requests',
];

$statusOptions = requestStatusOptions();
$processors = getAssignableProcessors();

$pageTitle = 'Request Review';
$activeNav = 'compliance';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="payment-report-page">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Request Review</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Combined compliance queue for new requests, pending review, and needs revision — plus the rest of the workflow.
                </p>
            </div>
        </div>
        <div class="card-body">
            <div class="stats-grid">
                <?php foreach ($stageCards as $card): ?>
                    <?php
                    $cardQuery = ['filter' => $card['key']];
                    if ($search !== '') {
                        $cardQuery['search'] = $search;
                    }
                    ?>
                    <?= statCardLink(
                        'compliance.php?' . http_build_query($cardQuery),
                        $card['color'],
                        $card['icon'],
                        (string) $card['count'],
                        $card['label'] . ($filter === $card['key'] ? ' · Active' : '')
                    ) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2><?= e($filterLabels[$filter] ?? 'Requests') ?></h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    <?= (int) $paged['total'] ?> request<?= (int) $paged['total'] === 1 ? '' : 's' ?>
                    <?php if ((int) $paged['total'] > 0): ?>
                        · showing <?= (int) $paged['pag']['offset'] + 1 ?>–<?= min((int) $paged['pag']['offset'] + (int) $paged['per_page'], (int) $paged['total']) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar" id="complianceFilterForm">
                <?= recordsSortFormFields($sortState) ?>
                <input type="text" name="search" placeholder="Search request #, student, document..." value="<?= e($search) ?>">
                <select name="filter" aria-label="Review stage">
                    <option value="review" <?= $filter === 'review' ? 'selected' : '' ?>>Review Queue (New + Needs Revision)</option>
                    <option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>New Requests only</option>
                    <option value="needs_revision" <?= $filter === 'needs_revision' ? 'selected' : '' ?>>Needs Revision only</option>
                    <option value="awaiting_student" <?= $filter === 'awaiting_student' ? 'selected' : '' ?>>Awaiting Student</option>
                    <option value="re_evaluation" <?= $filter === 're_evaluation' ? 'selected' : '' ?>>Re-evaluation</option>
                    <option value="verified" <?= $filter === 'verified' ? 'selected' : '' ?>>Awaiting Payment</option>
                    <option value="payment_ready" <?= $filter === 'payment_ready' ? 'selected' : '' ?>>Staff Assignment / Processing</option>
                    <option value="release_ready" <?= $filter === 'release_ready' ? 'selected' : '' ?>>Document Release</option>
                    <option value="completed" <?= $filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="" <?= $filter === '' ? 'selected' : '' ?>>All Active</option>
                </select>
                <button type="submit" class="btn btn-outline btn-sm">Filter</button>
                <?php if ($search !== '' || $filter !== 'review'): ?>
                    <a href="compliance.php" class="btn btn-outline btn-sm">Reset</a>
                <?php endif; ?>
            </form>

            <?php if (empty($requests)): ?>
                <div class="empty-state"><i class="fas fa-clipboard-list"></i><p>No requests found for this stage.</p></div>
            <?php else: ?>
                <form method="POST" id="registrarRequestsBatchForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" id="registrarBatchAction" value="">

                    <div class="batch-action-bar" id="registrarBatchActionBar" hidden>
                        <span class="batch-action-count"><strong id="registrarBatchSelectedCount">0</strong> selected</span>
                        <div class="batch-action-buttons">
                            <button type="button" class="btn btn-primary btn-sm" id="openRegistrarBatchAssignModal">
                                <i class="fas fa-user-tag"></i> Assign Staff
                            </button>
                            <button type="button" class="btn btn-outline btn-sm" id="openRegistrarBatchStatusModal">
                                <i class="fas fa-sync-alt"></i> Change Status
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="data-table data-table-responsive">
                            <thead>
                                <tr>
                                    <th class="batch-select-col">
                                        <label class="checkbox-label batch-select-all-label">
                                            <input type="checkbox" id="registrarSelectAllRequests" aria-label="Select all requests on this page">
                                        </label>
                                    </th>
                                    <?= renderRecordsSortHeader('Request #', 'request_number', $sortState, $sortQuery) ?>
                                    <?= renderRecordsSortHeader('Student ID', 'student_id', $sortState, $sortQuery) ?>
                                    <?= renderRecordsSortHeader('Name', 'name', $sortState, $sortQuery) ?>
                                    <?= renderRecordsSortHeader('Document', 'document_name', $sortState, $sortQuery) ?>
                                    <?= renderRecordsSortHeader('Workflow Stage', 'status', $sortState, $sortQuery) ?>
                                    <?= renderRecordsSortHeader('Requirements', 'requirement_count', $sortState, $sortQuery) ?>
                                    <?= renderRecordsSortHeader(
                                        $filter === 'completed' ? 'Completed' : 'Submitted',
                                        $filter === 'completed' ? 'completed_at' : 'created_at',
                                        $sortState,
                                        $sortQuery
                                    ) ?>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($requests as $req): ?>
                                    <tr>
                                        <td class="batch-select-col" data-label="Select">
                                            <label class="checkbox-label">
                                                <input type="checkbox" class="registrar-request-select" name="request_ids[]" value="<?= (int) $req['id'] ?>">
                                            </label>
                                        </td>
                                        <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                                        <td data-label="Student ID"><?= e($req['student_id'] ?? '—') ?></td>
                                        <td data-label="Name"><?= e(trim(($req['first_name'] ?? '') . ' ' . ($req['last_name'] ?? ''))) ?></td>
                                        <td data-label="Document"><?= e($req['document_name'] ?? '—') ?></td>
                                        <td data-label="Workflow Stage">
                                            <?= statusBadge($req['status']) ?>
                                            <br><small class="text-muted"><?= e(workflowPhaseLabel($req['status'])) ?></small>
                                        </td>
                                        <td data-label="Requirements"><?= (int) ($req['requirement_count'] ?? 0) ?></td>
                                        <td data-label="<?= $filter === 'completed' ? 'Completed' : 'Submitted' ?>">
                                            <?= $filter === 'completed'
                                                ? e(formatDateTime($req['completed_at'] ?? $req['updated_at'] ?? null))
                                                : e(formatDate($req['created_at'] ?? null)) ?>
                                        </td>
                                        <td data-label="Action" class="payment-actions-cell">
                                            <?php if ($filter === 'payment_ready' || ($req['status'] ?? '') === 'payment_verified'): ?>
                                                <a href="assignments.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-primary">Assign Staff</a>
                                            <?php else: ?>
                                                <a href="verify-request.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-primary">Open</a>
                                            <?php endif; ?>
                                            <?= renderRegistrarClaimSlipButtonsHtml($req, true) ?>
                                            <a href="view-attachments.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-outline" title="View attachments">
                                                <i class="fas fa-paperclip"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
                <?= $paged['html'] ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($requests)): ?>
<?php renderAdminFormModalOpen('Request Review', 'Batch Change Status', 'registrarBatchStatusModal'); ?>
<form method="POST" id="registrarBatchStatusForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="batch_update_status">
    <div id="registrarBatchStatusHiddenIds"></div>
    <div class="form-group">
        <label for="registrar_batch_status">New Status *</label>
        <select id="registrar_batch_status" name="status" required>
            <?php foreach ($statusOptions as $s): ?>
                <option value="<?= e($s) ?>"><?= ucwords(str_replace('_', ' ', $s)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="registrar_batch_remarks">Remarks</label>
        <input type="text" id="registrar_batch_remarks" name="remarks" placeholder="Optional note for status history">
    </div>
    <?php renderAdminFormModalFooter('Update Selected', 'fa-sync-alt'); ?>
</form>
<?php renderAdminFormModalClose(); ?>

<?php renderAdminFormModalOpen('Request Review', 'Batch Assign Staff', 'registrarBatchAssignModal'); ?>
<form method="POST" id="registrarBatchAssignForm" class="form-grid">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="batch_assign">
    <div id="registrarBatchAssignHiddenIds"></div>
    <p class="text-muted">
        Assigns pending document items on selected payment-verified requests to one staff member with a shared release schedule.
    </p>
    <div class="form-group">
        <label for="registrar_batch_assigned_to">Assign to *</label>
        <?php if (empty($processors)): ?>
            <select id="registrar_batch_assigned_to" name="assigned_to" required disabled>
                <option value="">No active assignees available</option>
            </select>
        <?php else: ?>
            <?= renderAssigneeSelectHtml('assigned_to', $processors, null, true, 'registrar_batch_assigned_to') ?>
        <?php endif; ?>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label for="registrar_batch_release_date">Release Date *</label>
            <input type="date" id="registrar_batch_release_date" name="release_date" value="<?= e(date('Y-m-d')) ?>" min="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="form-group">
            <label for="registrar_batch_release_time">Release Time *</label>
            <select id="registrar_batch_release_time" name="release_time" required>
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
    const batchBar = document.getElementById('registrarBatchActionBar');
    const countEl = document.getElementById('registrarBatchSelectedCount');
    const selectAll = document.getElementById('registrarSelectAllRequests');
    const rowChecks = () => Array.from(document.querySelectorAll('.registrar-request-select'));
    const statusModal = document.getElementById('registrarBatchStatusModal');
    const assignModal = document.getElementById('registrarBatchAssignModal');
    const statusHiddenIds = document.getElementById('registrarBatchStatusHiddenIds');
    const assignHiddenIds = document.getElementById('registrarBatchAssignHiddenIds');

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

    function fillHiddenIds(container, selected) {
        if (!container) return;
        container.innerHTML = '';
        selected.forEach(function (cb) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'request_ids[]';
            input.value = cb.value;
            container.appendChild(input);
        });
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
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

    [statusModal, assignModal].forEach(function (modal) {
        if (!modal) return;
        modal.querySelectorAll('[data-close-admin-form]').forEach(function (el) {
            el.addEventListener('click', function () { closeModal(modal); });
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (statusModal && statusModal.classList.contains('is-open')) closeModal(statusModal);
        if (assignModal && assignModal.classList.contains('is-open')) closeModal(assignModal);
    });

    document.getElementById('openRegistrarBatchStatusModal')?.addEventListener('click', function () {
        const selected = selectedChecks();
        if (!selected.length) {
            alert('Select at least one request.');
            return;
        }
        fillHiddenIds(statusHiddenIds, selected);
        openModal(statusModal);
        document.getElementById('registrar_batch_status')?.focus();
    });

    document.getElementById('openRegistrarBatchAssignModal')?.addEventListener('click', function () {
        const selected = selectedChecks();
        if (!selected.length) {
            alert('Select at least one request.');
            return;
        }
        fillHiddenIds(assignHiddenIds, selected);
        openModal(assignModal);
        document.getElementById('registrar_batch_assigned_to')?.focus();
    });

    syncBatchBar();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
