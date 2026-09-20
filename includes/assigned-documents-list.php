<?php

/**
 * Shared list of documents assigned to the current processor.
 *
 * Expected before include:
 * - $user
 * - $pageTitle
 * - $activeNav
 * - $processBaseUrl (e.g. APP_URL.'/cashier/process-document.php')
 * - $officeLabel
 * - $listPageUrl (optional)
 * - $documentCodeFilter (optional string|list)
 */

require_once __DIR__ . '/request-items.php';
require_once __DIR__ . '/student.php';
ensureRequestItemsSchema();

if (!function_exists('currentScriptPageUrl')) {
    function currentScriptPageUrl(): string {
        $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($scriptPath === '') {
            return rtrim(APP_URL, '/');
        }

        $appUrlPath = parse_url(APP_URL, PHP_URL_PATH);
        $appUrlPath = is_string($appUrlPath) ? rtrim($appUrlPath, '/') : '';

        if ($appUrlPath !== '' && str_starts_with($scriptPath, $appUrlPath)) {
            $scriptPath = substr($scriptPath, strlen($appUrlPath)) ?: '/';
        }

        return rtrim(APP_URL, '/') . $scriptPath;
    }
}

$listPageUrl = $listPageUrl ?? currentScriptPageUrl();
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

$allowedDocumentCodes = null;
if (!empty($documentCodeFilter)) {
    $allowedDocumentCodes = is_array($documentCodeFilter)
        ? array_values(array_filter(array_map(
            static fn($code): string => strtoupper(trim((string) $code)),
            $documentCodeFilter
        )))
        : [strtoupper(trim((string) $documentCodeFilter))];
}

$listRedirectQuery = array_filter([
    'status' => $status !== '' ? $status : null,
    'search' => $search !== '' ? $search : null,
], static fn($value) => $value !== null && $value !== '');
$listRedirectUrl = $listPageUrl . ($listRedirectQuery !== [] ? '?' . http_build_query($listRedirectQuery) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'batch_update_status') {
        $newStatus = trim((string) ($_POST['status'] ?? ''));
        $requestIds = array_map('intval', (array) ($_POST['request_ids'] ?? []));
        $result = batchUpdateAssignedRequestItemStatuses(
            $requestIds,
            $newStatus,
            (int) ($user['id'] ?? 0),
            $allowedDocumentCodes
        );

        $statusLabel = $newStatus === 'ready_for_pickup'
            ? 'Ready for Pickup'
            : ($newStatus === 'completed' ? 'Completed' : ucwords(str_replace('_', ' ', $newStatus)));

        if (($result['updated'] ?? 0) > 0) {
            setFlash('success', $result['updated'] . ' document item' . ((int) $result['updated'] === 1 ? '' : 's')
                . ' updated to ' . $statusLabel . '.', [
                'title' => 'Batch Status Updated',
                'context' => array_filter([
                    'Updated' => (string) $result['updated'],
                    'Skipped' => ((int) ($result['skipped'] ?? 0) > 0) ? (string) $result['skipped'] : null,
                ]),
                'details' => array_slice($result['failed'] ?? [], 0, 8),
            ]);
        } elseif (($result['skipped'] ?? 0) > 0 && empty($result['failed'])) {
            setFlash('info', 'Selected assignments are already at that status or cannot move to ' . $statusLabel . ' yet.', [
                'title' => 'No Changes Needed',
            ]);
        } else {
            setFlash('error', implode(' ', $result['failed'] ?? ['Unable to update selected assignments.']), [
                'title' => 'Batch Update Failed',
            ]);
        }

        redirect($listRedirectUrl);
    }
}

$items = getStaffAssignedItems((int) $user['id'], $status);

if ($allowedDocumentCodes !== null) {
    $items = array_values(array_filter($items, static function (array $row) use ($allowedDocumentCodes): bool {
        return in_array(strtoupper(trim((string) ($row['document_code'] ?? ''))), $allowedDocumentCodes, true);
    }));
}

if ($search !== '') {
    $items = array_values(array_filter($items, static function (array $row) use ($search): bool {
        $haystack = strtolower(
            ($row['request_number'] ?? '') . ' '
            . ($row['document_name'] ?? '') . ' '
            . assignedItemDocumentSummary($row) . ' '
            . assignedItemSchoolYear($row) . ' '
            . assignedItemSemester($row) . ' '
            . ($row['first_name'] ?? '') . ' '
            . ($row['middle_name'] ?? '') . ' '
            . ($row['last_name'] ?? '') . ' '
            . assignedStudentNameLabel($row) . ' '
            . assignedStudentNameIdLabel($row) . ' '
            . ($row['student_id'] ?? '') . ' '
            . assignedStudentCourseLabel($row) . ' '
            . assignedStudentYearLabel($row) . ' '
            . assignedStudentCourseYearLabel($row) . ' '
            . assignedItemMethodLabel($row) . ' '
            . ($row['payment_method_label'] ?? '') . ' '
            . ($row['payment_scope_label'] ?? '') . ' '
            . enrollmentStatusLabel($row['enrollment_status'] ?? null)
        );
        return str_contains($haystack, strtolower($search));
    }));
}

$items = groupStaffAssignedItemsByRequest($items);

$exportBaseQuery = array_filter([
    'status' => $status !== '' ? $status : null,
    'search' => $search !== '' ? $search : null,
], static fn($value) => $value !== null && $value !== '');

$printUrl = $listPageUrl . '?' . http_build_query($exportBaseQuery + ['print' => '1']);
$pdfUrl = $listPageUrl . '?' . http_build_query($exportBaseQuery + ['print' => '1', 'pdf' => '1']);
$csvUrl = $listPageUrl . '?' . http_build_query($exportBaseQuery + ['export' => 'csv']);

if (($_GET['export'] ?? '') === 'csv') {
    exportAssignedDocumentsCsv($items, 'my_assignments_' . date('Ymd_His') . '.csv');
}

if (($_GET['print'] ?? '') === '1') {
    require_once __DIR__ . '/ui.php';
    require_once __DIR__ . '/assigned-documents-print.php';
    exit;
}

$sortColumns = [
    'request_number' => ['type' => 'string'],
    'method' => [
        'type' => 'string',
        'get' => static fn(array $r): string => (string) ($r['payment_scope_label'] ?? $r['payment_method_label'] ?? ''),
    ],
    'document_name' => [
        'type' => 'string',
        'get' => static function (array $r): string {
            return function_exists('assignedItemDocumentSummary')
                ? assignedItemDocumentSummary($r)
                : (string) ($r['document_name'] ?? '');
        },
    ],
    'name' => [
        'type' => 'string',
        'get' => static fn(array $r): string => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
    ],
    'course' => [
        'type' => 'string',
        'get' => static function (array $r): string {
            return function_exists('assignedStudentCourseYearLabel')
                ? assignedStudentCourseYearLabel($r)
                : trim(($r['course'] ?? '') . ' ' . ($r['year_level'] ?? ''));
        },
    ],
    'enrollment_status' => ['type' => 'string'],
    'copies' => ['type' => 'number', 'default_dir' => 'desc'],
    'item_status' => ['type' => 'string'],
    'request_status' => ['type' => 'string'],
];
$sortState = resolveRecordsSort($sortColumns, 'request_number', 'asc');
$items = sortRecordList($items, $sortState);

$pagedAssignedDocuments = paginateRecordList($items, array_merge([
    'status' => $status,
    'search' => $search,
], recordsSortFilterParams($sortState)), 'assignedDocumentsFilterForm', 'request', 'requests');
$items = $pagedAssignedDocuments['items'];
$sortQuery = array_merge([
    'status' => $status,
    'search' => $search,
], recordsSortFilterParams($sortState));
if ($pagedAssignedDocuments['per_page'] !== ITEMS_PER_PAGE) {
    $sortQuery['per_page'] = $pagedAssignedDocuments['per_page'];
}

require_once __DIR__ . '/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2><?= e($officeLabel ?? 'Document Assignments') ?></h2>
            <p class="text-muted" style="margin:.35rem 0 0">Requests assigned to your office for processing (grouped by request).</p>
        </div>
        <?php if ($items !== []): ?>
            <div class="card-header-actions payment-report-actions grades-eval-export-actions">
                <span class="grades-eval-export-label">Print / Export</span>
                <a href="<?= e($printUrl) ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fas fa-print"></i> Print</a>
                <a href="<?= e($pdfUrl) ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fas fa-file-pdf"></i> Export PDF</a>
                <a href="<?= e($csvUrl) ?>" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> Export CSV</a>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar" id="assignedDocumentsFilterForm">
            <?= recordsSortFormFields($sortState) ?>
            <input type="text" name="search" placeholder="Search request #, method, document, school year, semester, student..." value="<?= e($search) ?>">
            <select name="status">
                <option value="">Active Assignments</option>
                <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>Processing</option>
                <option value="ready_for_pickup" <?= $status === 'ready_for_pickup' ? 'selected' : '' ?>>Ready for Pickup</option>
                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        </form>
        <?= $pagedAssignedDocuments['meta_html'] ?>

        <?php if (empty($items)): ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No document assignments found.</p></div>
        <?php else: ?>
            <div class="batch-action-bar" id="assignedBatchActionBar" hidden>
                <span class="batch-action-count"><strong id="assignedBatchSelectedCount">0</strong> selected</span>
                <div class="batch-action-buttons">
                    <button type="button" class="btn btn-primary btn-sm" id="openAssignedBatchStatusModal">
                        <i class="fas fa-sync-alt"></i> Update Status
                    </button>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table data-table-responsive assigned-documents-table">
                    <thead>
                        <tr>
                            <th class="batch-select-col">
                                <label class="checkbox-label batch-select-all-label">
                                    <input type="checkbox" id="assignedSelectAllRequests" aria-label="Select all assignments">
                                </label>
                            </th>
                            <?= renderRecordsSortHeader('Request #', 'request_number', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Method', 'method', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Document/s Requested', 'document_name', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Student', 'name', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Course / Year', 'course', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Enrollment', 'enrollment_status', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Copies', 'copies', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Doc Status', 'item_status', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Batch Status', 'request_status', $sortState, $sortQuery) ?>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $rowRequestId = (int) ($item['request_id'] ?? 0);
                            $rowStatus = (string) ($item['item_status'] ?? '');
                            $canBatchSelect = $rowRequestId > 0 && in_array($rowStatus, ['processing', 'ready_for_pickup', 'mixed'], true);
                            ?>
                            <tr>
                                <td class="batch-select-col" data-label="Select">
                                    <?php if ($canBatchSelect): ?>
                                        <label class="checkbox-label">
                                            <input type="checkbox"
                                                class="assigned-request-select"
                                                value="<?= $rowRequestId ?>"
                                                data-doc-status="<?= e($rowStatus) ?>"
                                                aria-label="Select <?= e((string) ($item['request_number'] ?? 'request')) ?>">
                                        </label>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Request #"><strong><?= e($item['request_number']) ?></strong></td>
                                <td data-label="Method"><?= renderAssignedItemMethodHtml($item) ?></td>
                                <td data-label="Document/s Requested" class="assigned-documents-docs">
                                    <?= renderAssignedDocumentLabelsHtml($item) ?>
                                </td>
                                <td data-label="Student"><?= renderAssignedStudentNameIdHtml($item) ?></td>
                                <td data-label="Course / Year"><?= renderAssignedStudentCourseYearHtml($item) ?></td>
                                <td data-label="Enrollment"><?= e(enrollmentStatusLabel($item['enrollment_status'] ?? null)) ?></td>
                                <td data-label="Copies"><?= (int) $item['copies'] ?></td>
                                <td data-label="Doc Status">
                                    <?= requestItemStatusBadge($item['item_status']) ?>
                                    <?php if (($item['item_status'] ?? '') === 'mixed' && !empty($item['item_status_detail'])): ?>
                                        <br><small class="text-muted"><?= e((string) $item['item_status_detail']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Batch Status"><?= statusBadge($item['request_status']) ?></td>
                                <td data-label="Action" class="payment-actions-cell">
                                    <a href="<?= e($processBaseUrl) ?>?item_id=<?= (int) $item['id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View / Process
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $pagedAssignedDocuments['html'] ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($items)): ?>
<?php renderAdminFormModalOpen($officeLabel ?? 'My Assignments', 'Batch Update Status', 'assignedBatchStatusModal'); ?>
<form method="POST" id="assignedBatchStatusForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="batch_update_status">
    <div id="assignedBatchStatusHiddenIds"></div>
    <p class="text-muted">
        Updates your assigned documents on the selected requests.
        Only <strong>Processing → Ready for Pickup</strong> and <strong>Ready for Pickup → Completed</strong> are allowed.
    </p>
    <div class="form-group">
        <label for="assigned_batch_status">New Status *</label>
        <select id="assigned_batch_status" name="status" required>
            <option value="ready_for_pickup">Ready for Pickup</option>
            <option value="completed">Completed</option>
        </select>
    </div>
    <?php renderAdminFormModalFooter('Update Selected', 'fa-sync-alt'); ?>
</form>
<?php renderAdminFormModalClose(); ?>

<script>
(function () {
    const batchBar = document.getElementById('assignedBatchActionBar');
    const countEl = document.getElementById('assignedBatchSelectedCount');
    const selectAll = document.getElementById('assignedSelectAllRequests');
    const statusModal = document.getElementById('assignedBatchStatusModal');
    const statusHiddenIds = document.getElementById('assignedBatchStatusHiddenIds');
    const openBtn = document.getElementById('openAssignedBatchStatusModal');

    function rowChecks() {
        return Array.from(document.querySelectorAll('.assigned-request-select'));
    }

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

    if (statusModal) {
        statusModal.querySelectorAll('[data-close-admin-form]').forEach(function (el) {
            el.addEventListener('click', function () { closeModal(statusModal); });
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && statusModal && statusModal.classList.contains('is-open')) {
            closeModal(statusModal);
        }
    });

    openBtn?.addEventListener('click', function () {
        const selected = selectedChecks();
        if (!selected.length) {
            alert('Select at least one assignment.');
            return;
        }
        fillHiddenIds(statusHiddenIds, selected);
        openModal(statusModal);
        document.getElementById('assigned_batch_status')?.focus();
    });

    syncBatchBar();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
