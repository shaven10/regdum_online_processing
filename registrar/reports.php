<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/registrar-reports.php';
requireRole('registrar');

$period = $_GET['period'] ?? 'monthly';
$date = trim($_GET['date'] ?? appToday());
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$channel = trim($_GET['channel'] ?? '');
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = normalizeRecordsPerPage((int) ($_GET['per_page'] ?? ITEMS_PER_PAGE));
$export = $_GET['export'] ?? '';

$filters = [
    'period' => $period,
    'date' => $date,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'channel' => $channel,
    'status' => $status,
    'search' => $search,
];

$queryBase = array_filter([
    'period' => $period,
    'date' => $date,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'channel' => $channel,
    'status' => $status,
    'search' => $search,
    'per_page' => $perPage !== ITEMS_PER_PAGE ? $perPage : '',
], static fn($v) => $v !== '' && $v !== null);

$sortColumns = [
    'request_number' => ['type' => 'string', 'sql' => 'r.request_number'],
    'channel' => ['type' => 'string', 'sql' => 'r.request_channel'],
    'name' => ['type' => 'string', 'sql' => 'u.last_name, u.first_name'],
    'document_summary' => ['type' => 'string', 'sql' => 'dt.name'],
    'status' => ['type' => 'string', 'sql' => 'r.status'],
    'total_amount' => ['type' => 'number', 'sql' => 'r.total_amount', 'default_dir' => 'desc'],
    'created_at' => ['type' => 'date', 'sql' => 'r.created_at', 'default_dir' => 'desc'],
];
$sortState = resolveRecordsSort($sortColumns, 'created_at', 'desc');
$queryBase = array_merge($queryBase, recordsSortFilterParams($sortState));
$sortQuery = $queryBase;

if ($export === 'csv') {
    $exportData = getRegistrarRequestReportData($filters, null, null);
    $filenameBase = 'request_report_' . ($exportData['period']['period'] ?? 'monthly') . '_' . ($exportData['period']['from'] ?? appToday());
    $rows = [];
    foreach ($exportData['rows'] as $row) {
        $rows[] = mapRegistrarRequestReportExportRow($row);
    }
    exportCSV(registrarRequestReportExportHeaders(), $rows, $filenameBase . '.csv');
}

$report = getRegistrarRequestReportData($filters, $page, $perPage);
$summary = $report['summary'];
$periodInfo = $report['period'];
$requests = $report['rows'];
$pag = $report['pagination'];
$applied = $report['filters'];

$queryBase['date_from'] = $periodInfo['from'];
$queryBase['date_to'] = $periodInfo['to'];
$queryBase = array_filter($queryBase, static fn($v) => $v !== '' && $v !== null);

$listQuery = $queryBase;
$paginationQuery = $queryBase;
$exportQuery = $queryBase;
$sortQuery = $queryBase;
$printQuery = $queryBase;
$printQuery['print'] = '1';

$isDefaultRange = $periodInfo['period'] === 'monthly'
    && $periodInfo['from'] === date('Y-m-01')
    && $periodInfo['to'] === date('Y-m-t')
    && $applied['search'] === ''
    && $applied['channel'] === ''
    && $applied['status'] === '';

$pageTitle = 'All Requests Report';
$activeNav = 'reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="payment-report-page">
    <div class="card no-print">
        <div class="card-header">
            <div>
                <h2>All Requests Report</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    View online and onsite credential requests with period filters, search, and printable records.
                </p>
            </div>
            <div class="payment-report-actions">
                <a href="report-print.php?<?= e(http_build_query($printQuery)) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="fas fa-print"></i> Print
                </a>
                <a href="report-print.php?<?= e(http_build_query(array_merge($printQuery, ['pdf' => '1']))) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
                <a href="?<?= e(http_build_query(array_merge($exportQuery, ['export' => 'csv']))) ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar payment-report-filters" id="registrarReportFilterForm">
                <?= recordsSortFormFields($sortState) ?>
                <input type="hidden" name="period" id="reportPeriod" value="<?= e($periodInfo['period']) ?>">

                <div class="payment-report-period-tabs" role="group" aria-label="Quick date range">
                    <?php foreach (['daily' => 'Today', 'weekly' => 'This week', 'monthly' => 'This month'] as $key => $label): ?>
                        <label class="payment-report-period-option">
                            <input type="radio"
                                name="period_preset"
                                value="<?= e($key) ?>"
                                <?= $periodInfo['period'] === $key && $dateFrom === '' && $dateTo === '' ? 'checked' : '' ?>
                                data-report-period="<?= e($key) ?>">
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <label class="payment-report-filter-field">
                    <span>Date from</span>
                    <input type="date" name="date_from" id="reportDateFrom" value="<?= e($periodInfo['from']) ?>" max="<?= e(appToday()) ?>">
                </label>

                <label class="payment-report-filter-field">
                    <span>Date to</span>
                    <input type="date" name="date_to" id="reportDateTo" value="<?= e($periodInfo['to']) ?>" max="<?= e(appToday()) ?>">
                </label>

                <label class="payment-report-filter-field">
                    <span>Mode</span>
                    <select name="channel" aria-label="Mode of request">
                        <option value="">All Modes</option>
                        <option value="online" <?= $applied['channel'] === 'online' ? 'selected' : '' ?>>Online</option>
                        <option value="onsite" <?= $applied['channel'] === 'onsite' ? 'selected' : '' ?>>Onsite</option>
                    </select>
                </label>

                <label class="payment-report-filter-field">
                    <span>Status</span>
                    <select name="status" aria-label="Request status">
                        <option value="">All Statuses</option>
                        <?php foreach (registrarRequestStatusOptions() as $s): ?>
                            <option value="<?= e($s) ?>" <?= $applied['status'] === $s ? 'selected' : '' ?>>
                                <?= e(ucwords(str_replace('_', ' ', $s))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="payment-report-filter-field payment-report-filter-search">
                    <span>Search</span>
                    <input type="text" name="search" placeholder="Request #, requestor, student ID, document..." value="<?= e($applied['search']) ?>">
                </label>

                <div class="payment-report-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply</button>
                    <?php if (!$isDefaultRange || $perPage !== ITEMS_PER_PAGE): ?>
                        <a href="reports.php" class="btn btn-outline btn-sm">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Summary — <?= e($periodInfo['label']) ?></h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    <?= e(ucfirst($periodInfo['period'])) ?> report
                    · <?= e($periodInfo['from']) ?> to <?= e($periodInfo['to']) ?>
                </p>
            </div>
        </div>
        <div class="card-body">
            <div class="stats-grid">
                <?= statCardLink('reports.php?' . http_build_query($listQuery), 'blue', 'fa-file-alt', (string) (int) ($summary['total_count'] ?? 0), 'Total Requests') ?>
                <?= statCardLink('reports.php?' . http_build_query(array_merge($listQuery, ['channel' => 'online'])), 'teal', 'fa-globe', (string) (int) ($summary['online_count'] ?? 0), 'Online') ?>
                <?= statCardLink('reports.php?' . http_build_query(array_merge($listQuery, ['channel' => 'onsite'])), 'purple', 'fa-store', (string) (int) ($summary['onsite_count'] ?? 0), 'Onsite') ?>
                <?= statCardLink('reports.php?' . http_build_query(array_merge($listQuery, ['status' => 'completed'])), 'green', 'fa-check-circle', (string) (int) ($summary['completed_count'] ?? 0), 'Completed') ?>
            </div>
            <div class="payment-report-summary-notes text-muted">
                Pending review: <?= (int) ($summary['pending_review_count'] ?? 0) ?>
                · In processing / release: <?= (int) ($summary['processing_count'] ?? 0) ?>
                · Rejected: <?= (int) ($summary['rejected_count'] ?? 0) ?>
                · Amount total: <?= e(formatMoney((float) ($summary['total_amount'] ?? 0))) ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Request Records</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Showing <?= count($requests) ?> of <?= (int) $pag['total'] ?> record<?= (int) $pag['total'] === 1 ? '' : 's' ?>
                    <?= (int) $pag['total_pages'] > 1 ? ' · Page ' . (int) $pag['page'] . ' of ' . (int) $pag['total_pages'] : '' ?>
                    · <?= e($periodInfo['from']) ?> to <?= e($periodInfo['to']) ?>
                </p>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($requests)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No requests found for <?= e($periodInfo['from']) ?> to <?= e($periodInfo['to']) ?>.</p>
                    <p class="text-muted">Try <strong>This month</strong>, widen the date range, or clear filters.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-responsive payment-report-table">
                        <thead>
                            <tr>
                                <?= renderRecordsSortHeader('Request #', 'request_number', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Mode', 'channel', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Requestor', 'name', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Documents', 'document_summary', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Status', 'status', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Amount', 'total_amount', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Created', 'created_at', $sortState, $sortQuery) ?>
                                <th>Slip / Stub</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): ?>
                                <?php
                                $requestor = trim(($req['first_name'] ?? '') . ' ' . ($req['last_name'] ?? ''));
                                $isOnsite = isOnsiteRequestChannel($req['request_channel'] ?? null);
                                ?>
                                <tr>
                                    <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                                    <td data-label="Mode">
                                        <span class="badge <?= $isOnsite ? 'badge-processing' : 'badge-review' ?>">
                                            <?= e($req['channel_label']) ?>
                                        </span>
                                    </td>
                                    <td data-label="Requestor">
                                        <?= e($requestor) ?>
                                        <br><small class="text-muted"><?= e($req['student_id'] ?? '—') ?></small>
                                    </td>
                                    <td data-label="Documents"><?= e($req['document_summary'] ?? '—') ?></td>
                                    <td data-label="Status"><?= statusBadge((string) ($req['status'] ?? '')) ?></td>
                                    <td data-label="Amount"><strong><?= e(formatMoney((float) ($req['total_amount'] ?? 0))) ?></strong></td>
                                    <td data-label="Created"><?= e(formatDateTime($req['created_at'] ?? null)) ?></td>
                                    <td data-label="Slip / Stub">
                                        <?php if (!empty($req['document_link'])): ?>
                                            <a href="<?= e($req['document_link']) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm action-print-btn" title="<?= e($req['document_link_label'] === 'Claim' ? 'Print claim slip' : 'Print onsite request slip') ?>">
                                                <i class="fas <?= isClaimStubPrintableStatus((string) ($req['status'] ?? '')) ? 'fa-ticket-alt' : 'fa-print' ?>"></i>
                                                <?= e($req['document_link_label']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Actions" class="payment-actions-cell">
                                        <a href="<?= APP_URL ?>/registrar/verify-request.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-primary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?= renderRecordsPaginationBar($pag, '?' . http_build_query($paginationQuery) . '&', $perPage, 'registrarReportFilterForm') ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('registrarReportFilterForm');
    const periodInput = document.getElementById('reportPeriod');
    const fromInput = document.getElementById('reportDateFrom');
    const toInput = document.getElementById('reportDateTo');
    if (!form || !fromInput || !toInput) {
        return;
    }

    function isoDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function applyPreset(period) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        let from = new Date(today);
        let to = new Date(today);

        if (period === 'weekly') {
            const weekday = today.getDay() === 0 ? 6 : today.getDay() - 1;
            from.setDate(today.getDate() - weekday);
            to = new Date(from);
            to.setDate(from.getDate() + 6);
        } else if (period === 'monthly') {
            from = new Date(today.getFullYear(), today.getMonth(), 1);
            to = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        }

        fromInput.value = isoDate(from);
        toInput.value = isoDate(to);
        if (periodInput) {
            periodInput.value = period;
        }
        form.submit();
    }

    form.querySelectorAll('[data-report-period]').forEach(function (input) {
        input.addEventListener('change', function () {
            applyPreset(input.getAttribute('data-report-period') || 'monthly');
        });
    });

    [fromInput, toInput].forEach(function (input) {
        input.addEventListener('change', function () {
            if (periodInput) {
                periodInput.value = 'custom';
            }
            form.querySelectorAll('[data-report-period]').forEach(function (radio) {
                radio.checked = false;
            });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
