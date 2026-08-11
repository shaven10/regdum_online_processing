<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/registrar-reports.php';
requireRole('registrar');

$period = $_GET['period'] ?? 'daily';
$date = trim($_GET['date'] ?? date('Y-m-d'));
$channel = trim($_GET['channel'] ?? '');
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$export = $_GET['export'] ?? '';

$filters = [
    'period' => $period,
    'date' => $date,
    'channel' => $channel,
    'status' => $status,
    'search' => $search,
];

$queryBase = array_filter([
    'period' => $period,
    'date' => $date,
    'channel' => $channel,
    'status' => $status,
    'search' => $search,
], static fn($v) => $v !== '' && $v !== null);

if ($export === 'csv') {
    $exportData = getRegistrarRequestReportData($filters, null, null);
    $filenameBase = 'request_report_' . ($exportData['period']['period'] ?? 'daily') . '_' . ($exportData['period']['from'] ?? date('Y-m-d'));
    $rows = [];
    foreach ($exportData['rows'] as $row) {
        $rows[] = mapRegistrarRequestReportExportRow($row);
    }
    exportCSV(registrarRequestReportExportHeaders(), $rows, $filenameBase . '.csv');
}

$report = getRegistrarRequestReportData($filters, $page, ITEMS_PER_PAGE);
$summary = $report['summary'];
$periodInfo = $report['period'];
$requests = $report['rows'];
$pag = $report['pagination'];
$applied = $report['filters'];

$listQuery = $queryBase;
$paginationQuery = $queryBase;
$exportQuery = $queryBase;
$printQuery = $queryBase;
$printQuery['print'] = '1';

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

                <select name="channel" aria-label="Mode of request">
                    <option value="">All Modes</option>
                    <option value="online" <?= $applied['channel'] === 'online' ? 'selected' : '' ?>>Online</option>
                    <option value="onsite" <?= $applied['channel'] === 'onsite' ? 'selected' : '' ?>>Onsite</option>
                </select>

                <select name="status" aria-label="Request status">
                    <option value="">All Statuses</option>
                    <?php foreach (registrarRequestStatusOptions() as $s): ?>
                        <option value="<?= e($s) ?>" <?= $applied['status'] === $s ? 'selected' : '' ?>>
                            <?= e(ucwords(str_replace('_', ' ', $s))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="text" name="search" placeholder="Search request #, requestor, student ID, document..." value="<?= e($applied['search']) ?>">

                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply</button>
                <?php if ($applied['search'] !== '' || $applied['channel'] !== '' || $applied['status'] !== '' || $periodInfo['period'] !== 'daily' || $periodInfo['date'] !== date('Y-m-d')): ?>
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
                </p>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($requests)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>No requests found for this period and filters.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-responsive payment-report-table">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Mode</th>
                                <th>Requestor</th>
                                <th>Documents</th>
                                <th>Status</th>
                                <th>Amount</th>
                                <th>Created</th>
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
                                            <a href="<?= e($req['document_link']) ?>" target="_blank" class="btn btn-outline btn-sm">
                                                <i class="fas <?= $isOnsite ? 'fa-receipt' : 'fa-ticket-alt' ?>"></i>
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

                <?= paginationLinks($pag, '?' . http_build_query($paginationQuery) . '&') ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
