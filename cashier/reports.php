<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
requireRole('cashier');

$period = $_GET['period'] ?? 'daily';
$date = trim($_GET['date'] ?? date('Y-m-d'));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$method = trim($_GET['method'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = normalizePaymentReportPerPage((int) ($_GET['per_page'] ?? ITEMS_PER_PAGE));
$export = $_GET['export'] ?? '';

$filters = [
    'period' => $period,
    'date' => $date,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'status' => $status,
    'search' => $search,
    'method' => $method,
];

$queryBase = array_filter([
    'period' => $period,
    'date' => $date,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'status' => $status,
    'search' => $search,
    'method' => $method,
    'per_page' => $perPage !== ITEMS_PER_PAGE ? $perPage : '',
], static fn($v) => $v !== '' && $v !== null);

$sortColumns = [
    'request_number' => ['type' => 'string', 'sql' => 'r.request_number'],
    'name' => ['type' => 'string', 'sql' => 'u.last_name, u.first_name'],
    'document_name' => ['type' => 'string', 'sql' => 'dt.name'],
    'payment_method' => ['type' => 'string', 'sql' => 'p.payment_method'],
    'amount' => ['type' => 'number', 'sql' => 'p.amount', 'default_dir' => 'desc'],
    'reference_number' => ['type' => 'string', 'sql' => 'p.reference_number'],
    'status' => ['type' => 'string', 'sql' => 'p.status'],
    'payment_date' => ['type' => 'date', 'sql' => 'p.payment_date', 'default_dir' => 'desc'],
    'created_at' => ['type' => 'date', 'sql' => 'p.created_at', 'default_dir' => 'desc'],
];
$sortState = resolveRecordsSort($sortColumns, 'created_at', 'desc');
$queryBase = array_merge($queryBase, recordsSortFilterParams($sortState));
$sortQuery = $queryBase;

if ($export === 'excel' || $export === 'csv') {
    $exportData = getPaymentReportData($filters, null, null);
    $filenameBase = 'cashier_transactions_'
        . ($exportData['period']['from'] ?? date('Y-m-d'))
        . '_to_'
        . ($exportData['period']['to'] ?? date('Y-m-d'));

    if ($export === 'excel') {
        exportPaymentReportExcel(
            $exportData['rows'],
            $exportData['summary'],
            $exportData['period'],
            $exportData['filters'],
            $filenameBase . '.xls'
        );
    }

    $rows = [];
    foreach ($exportData['rows'] as $row) {
        $rows[] = mapPaymentReportExportRow($row);
    }
    exportCSV(paymentReportExportHeaders(), $rows, $filenameBase . '.csv');
}

$report = getPaymentReportData($filters, $page, $perPage);
$summary = $report['summary'];
$periodInfo = $report['period'];
$payments = $report['rows'];
$pag = $report['pagination'];

$queryBase['date_from'] = $periodInfo['from'];
$queryBase['date_to'] = $periodInfo['to'];
$queryBase = array_filter($queryBase, static fn($v) => $v !== '' && $v !== null);

$listQuery = $queryBase;
$paginationQuery = $queryBase;
$exportQuery = $queryBase;
$sortQuery = $queryBase;
$printQuery = $queryBase;
$printQuery['print'] = '1';

$pageTitle = 'Transaction Reports';
$activeNav = 'reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="payment-report-page">
    <div class="card no-print">
        <div class="card-header">
            <div>
                <h2>Transaction Reports</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Filter cashier transactions by status and date, then print or export the matching records.
                </p>
            </div>
            <div class="payment-report-actions">
                <a href="report-print.php?<?= e(http_build_query($printQuery)) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="fas fa-print"></i> Print
                </a>
                <a href="report-print.php?<?= e(http_build_query(array_merge($printQuery, ['pdf' => '1']))) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
                <a href="?<?= e(http_build_query(array_merge($exportQuery, ['export' => 'excel']))) ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a href="?<?= e(http_build_query(array_merge($exportQuery, ['export' => 'csv']))) ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar payment-report-filters" id="cashierReportFilterForm">
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
                    <span>Status</span>
                    <select name="status" aria-label="Filter by status">
                        <option value="">All statuses</option>
                        <?php foreach (['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="payment-report-filter-field">
                    <span>Date from</span>
                    <input type="date" name="date_from" id="reportDateFrom" value="<?= e($periodInfo['from']) ?>" max="<?= e(date('Y-m-d')) ?>">
                </label>

                <label class="payment-report-filter-field">
                    <span>Date to</span>
                    <input type="date" name="date_to" id="reportDateTo" value="<?= e($periodInfo['to']) ?>" max="<?= e(date('Y-m-d')) ?>">
                </label>

                <label class="payment-report-filter-field">
                    <span>Method</span>
                    <select name="method" aria-label="Filter by method">
                        <option value="">All methods</option>
                        <?php foreach (paymentMethodOptions() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $method === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="payment-report-filter-field payment-report-filter-search">
                    <span>Search</span>
                    <input type="text" name="search" placeholder="Request #, student, document, OR, reference..." value="<?= e($search) ?>">
                </label>

                <div class="payment-report-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply</button>
                    <?php if ($search !== '' || $status !== '' || $method !== '' || $periodInfo['from'] !== date('Y-m-d') || $periodInfo['to'] !== date('Y-m-d') || $perPage !== ITEMS_PER_PAGE): ?>
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
                <?= statCardLink('reports.php?' . http_build_query($listQuery), 'blue', 'fa-receipt', (string) (int) $summary['total_count'], 'Total Payments') ?>
                <?= statCardLink('reports.php?' . http_build_query(array_merge($listQuery, ['status' => 'verified'])), 'green', 'fa-check-circle', e(formatMoney((float) $summary['verified_amount'])), 'Verified Amount') ?>
                <?= statCardLink('reports.php?' . http_build_query(array_merge($listQuery, ['status' => 'pending'])), 'orange', 'fa-clock', (string) (int) $summary['pending_count'], 'Pending') ?>
                <?= statCardLink('reports.php?' . http_build_query(array_merge($listQuery, ['status' => 'rejected'])), 'red', 'fa-times-circle', (string) (int) $summary['rejected_count'], 'Rejected') ?>
            </div>

            <div class="payment-report-summary-notes text-muted">
                Verified count: <?= (int) $summary['verified_count'] ?>
                · Pending amount: <?= e(formatMoney((float) $summary['pending_amount'])) ?>
                · Rejected amount: <?= e(formatMoney((float) $summary['rejected_amount'])) ?>
                · Period total: <?= e(formatMoney((float) $summary['total_amount'])) ?>
            </div>

            <?php if (!empty($summary['by_method'])): ?>
                <div class="payment-report-method-summary">
                    <h3>Verified Collections by Method</h3>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Method</th>
                                    <th>Count</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($summary['by_method'] as $m): ?>
                                    <tr>
                                        <td><?= e(paymentMethodLabel($m['payment_method'] ?? null)) ?></td>
                                        <td><?= (int) ($m['count'] ?? 0) ?></td>
                                        <td><?= e(formatMoney((float) ($m['total'] ?? 0))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Transaction Records</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Showing <?= count($payments) ?> of <?= (int) $pag['total'] ?> record<?= (int) $pag['total'] === 1 ? '' : 's' ?>
                    <?= (int) $pag['total_pages'] > 1 ? ' · Page ' . (int) $pag['page'] . ' of ' . (int) $pag['total_pages'] : '' ?>
                    · <?= e($periodInfo['from']) ?> to <?= e($periodInfo['to']) ?>
                </p>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($payments)): ?>
                <div class="empty-state"><i class="fas fa-receipt"></i><p>No payment records found for this period.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-responsive payment-report-table">
                        <thead>
                            <tr>
                                <?= renderRecordsSortHeader('Request #', 'request_number', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Student', 'name', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Document/s Requested', 'document_name', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Method', 'payment_method', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Amount', 'amount', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Reference / OR', 'reference_number', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Status', 'status', $sortState, $sortQuery) ?>
                                <?= renderRecordsSortHeader('Payment Date', 'payment_date', $sortState, $sortQuery) ?>
                                <th>Verified By</th>
                                <?= renderRecordsSortHeader('Submitted', 'created_at', $sortState, $sortQuery) ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <?php
                                $studentName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                                $verifier = trim(($p['verifier_first'] ?? '') . ' ' . ($p['verifier_last'] ?? ''));
                                ?>
                                <tr>
                                    <td data-label="Request #">
                                        <strong><?= e($p['request_number']) ?></strong>
                                        <?php if (($p['request_channel'] ?? '') === 'onsite'): ?>
                                            <br><span class="badge badge-processing">Onsite</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Student">
                                        <?= e($studentName) ?>
                                        <br><small class="text-muted"><?= e($p['student_id'] ?? '—') ?></small>
                                    </td>
                                    <td data-label="Document/s Requested" class="payment-report-documents">
                                        <?php if (empty($p['document_labels'])): ?>
                                            —
                                        <?php else: ?>
                                            <?php foreach ($p['document_labels'] as $documentLabel): ?>
                                                <div><?= e($documentLabel) ?></div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Method"><?= e(paymentMethodScopeLabel($p)) ?></td>
                                    <td data-label="Amount"><strong><?= e(formatMoney((float) $p['amount'])) ?></strong></td>
                                    <td data-label="Reference / OR">
                                        <?= e($p['reference_number'] ?? '—') ?>
                                        <?php if (!empty($p['or_number'])): ?>
                                            <br><small class="text-muted">OR: <?= e($p['or_number']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status"><?= statusBadge($p['status']) ?></td>
                                    <td data-label="Payment Date"><?= !empty($p['payment_date']) ? e(formatDate($p['payment_date'])) : '—' ?></td>
                                    <td data-label="Verified By"><?= $verifier !== '' ? e($verifier) : '—' ?></td>
                                    <td data-label="Submitted"><?= e(formatDateTime($p['created_at'] ?? null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?= renderRecordsPaginationBar($pag, '?' . http_build_query($paginationQuery) . '&', $perPage, 'cashierReportFilterForm') ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('cashierReportFilterForm');
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
            applyPreset(input.getAttribute('data-report-period') || 'daily');
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
