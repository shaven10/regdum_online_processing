<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
requireRole('cashier');

$period = $_GET['period'] ?? 'daily';
$date = trim($_GET['date'] ?? date('Y-m-d'));
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$method = trim($_GET['method'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$export = $_GET['export'] ?? '';

$filters = [
    'period' => $period,
    'date' => $date,
    'status' => $status,
    'search' => $search,
    'method' => $method,
];

$queryBase = array_filter([
    'period' => $period,
    'date' => $date,
    'status' => $status,
    'search' => $search,
    'method' => $method,
], static fn($v) => $v !== '' && $v !== null);

if ($export === 'excel' || $export === 'csv') {
    $exportData = getPaymentReportData($filters, null, null);
    $filenameBase = 'payment_report_' . ($exportData['period']['period'] ?? 'daily') . '_' . ($exportData['period']['from'] ?? date('Y-m-d'));

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

$report = getPaymentReportData($filters, $page, ITEMS_PER_PAGE);
$summary = $report['summary'];
$periodInfo = $report['period'];
$payments = $report['rows'];
$pag = $report['pagination'];

$listQuery = $queryBase;
$paginationQuery = $queryBase;
$exportQuery = $queryBase;
$printQuery = $queryBase;
$printQuery['print'] = '1';

$pageTitle = 'Payment Reports';
$activeNav = 'reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="payment-report-page">
    <div class="card no-print">
        <div class="card-header">
            <div>
                <h2>Payment Reports</h2>
                <p class="text-muted" style="margin:.35rem 0 0">Detailed cashier collection report with period filters and exports.</p>
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
            <form method="GET" class="filter-bar payment-report-filters">
                <div class="payment-report-period-tabs">
                    <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $key => $label): ?>
                        <label class="payment-report-period-option">
                            <input type="radio" name="period" value="<?= e($key) ?>" <?= $periodInfo['period'] === $key ? 'checked' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <input type="date" name="date" value="<?= e($periodInfo['date']) ?>" title="Anchor date for the selected period">

                <select name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['pending', 'verified', 'rejected', 'refunded'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="method">
                    <option value="">All Methods</option>
                    <?php foreach (paymentMethodOptions() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $method === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="search" placeholder="Search request, student, OR, reference..." value="<?= e($search) ?>">

                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply</button>
                <?php if ($search !== '' || $status !== '' || $method !== '' || $periodInfo['period'] !== 'daily' || $periodInfo['date'] !== date('Y-m-d')): ?>
                    <a href="reports.php" class="btn btn-outline btn-sm">Reset</a>
                <?php endif; ?>
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
                <h2>Detailed Payment Records</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Showing <?= count($payments) ?> of <?= (int) $pag['total'] ?> record<?= (int) $pag['total'] === 1 ? '' : 's' ?>
                    <?= (int) $pag['total_pages'] > 1 ? ' · Page ' . (int) $pag['page'] . ' of ' . (int) $pag['total_pages'] : '' ?>
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
                                <th>Request #</th>
                                <th>Student</th>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Reference / OR</th>
                                <th>Status</th>
                                <th>Payment Date</th>
                                <th>Verified By</th>
                                <th>Submitted</th>
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
                                    <td data-label="Method"><?= e(paymentMethodLabel($p['payment_method'] ?? null)) ?></td>
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

                <?= paginationLinks($pag, '?' . http_build_query($paginationQuery) . '&') ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
