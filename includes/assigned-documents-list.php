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
 */

require_once __DIR__ . '/request-items.php';
require_once __DIR__ . '/student.php';
ensureRequestItemsSchema();

$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$items = getStaffAssignedItems((int) $user['id'], $status);

if (!empty($documentCodeFilter)) {
    $allowedCodes = is_array($documentCodeFilter)
        ? array_map(static fn($code): string => strtoupper(trim((string) $code)), $documentCodeFilter)
        : [strtoupper(trim((string) $documentCodeFilter))];
    $items = array_values(array_filter($items, static function (array $row) use ($allowedCodes): bool {
        return in_array(strtoupper(trim((string) ($row['document_code'] ?? ''))), $allowedCodes, true);
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
], recordsSortFilterParams($sortState)), 'assignedDocumentsFilterForm', 'document', 'documents');
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
            <p class="text-muted" style="margin:.35rem 0 0">Documents assigned to your office for processing.</p>
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
            <table class="data-table data-table-responsive assigned-documents-table">
                <thead>
                    <tr>
                        <?= renderRecordsSortHeader('Request #', 'request_number', $sortState, $sortQuery) ?>
                        <?= renderRecordsSortHeader('Method', 'method', $sortState, $sortQuery) ?>
                        <?= renderRecordsSortHeader('Document/s Requested', 'document_name', $sortState, $sortQuery) ?>
                        <?= renderRecordsSortHeader('Student', 'name', $sortState, $sortQuery) ?>
                        <?= renderRecordsSortHeader('Course / Year', 'course', $sortState, $sortQuery) ?>
                        <?= renderRecordsSortHeader('Enrollment', 'enrollment_status', $sortState, $sortQuery) ?>
                        <?= renderRecordsSortHeader('Copies', 'copies', $sortState, $sortQuery) ?>
                        <?= renderRecordsSortHeader('Item Status', 'item_status', $sortState, $sortQuery) ?>
                        <?= renderRecordsSortHeader('Batch Status', 'request_status', $sortState, $sortQuery) ?>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td data-label="Request #"><strong><?= e($item['request_number']) ?></strong></td>
                        <td data-label="Method"><?= renderAssignedItemMethodHtml($item) ?></td>
                        <td data-label="Document/s Requested" class="assigned-documents-docs">
                            <?= renderAssignedDocumentLabelsHtml($item) ?>
                        </td>
                        <td data-label="Student"><?= renderAssignedStudentNameIdHtml($item) ?></td>
                        <td data-label="Course / Year"><?= renderAssignedStudentCourseYearHtml($item) ?></td>
                        <td data-label="Enrollment"><?= e(enrollmentStatusLabel($item['enrollment_status'] ?? null)) ?></td>
                        <td data-label="Copies"><?= (int) $item['copies'] ?></td>
                        <td data-label="Item Status"><?= requestItemStatusBadge($item['item_status']) ?></td>
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
            <?= $pagedAssignedDocuments['html'] ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
