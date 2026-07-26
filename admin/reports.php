<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$db = getDB();
$reportType = $_GET['type'] ?? 'daily';
$export = $_GET['export'] ?? '';

// Handle export
if ($export === 'csv') {
    $headers = ['Request #', 'Student', 'Document', 'Amount', 'Status', 'Date'];
    $rows = [];
    $data = $db->query('SELECT r.request_number, CONCAT(u.first_name," ",u.last_name) as student, dt.name as document, r.total_amount, r.status, r.created_at FROM requests r JOIN users u ON r.user_id = u.id JOIN document_types dt ON r.document_type_id = dt.id ORDER BY r.created_at DESC')->fetchAll();
    foreach ($data as $d) {
        $rows[] = [$d['request_number'], $d['student'], $d['document'], $d['total_amount'], $d['status'], $d['created_at']];
    }
    exportCSV($headers, $rows, 'requests_report_' . date('Y-m-d') . '.csv');
}

$stats = getDashboardStats();

// Document type breakdown
$docBreakdown = $db->query('SELECT dt.name, COUNT(r.id) as count, SUM(r.total_amount) as revenue FROM document_types dt LEFT JOIN requests r ON dt.id = r.document_type_id GROUP BY dt.id ORDER BY count DESC')->fetchAll();

// Processing time analysis
$processingTime = $db->query("SELECT dt.name, AVG(DATEDIFF(r.completed_at, r.created_at)) as avg_days FROM requests r JOIN document_types dt ON r.document_type_id = dt.id WHERE r.completed_at IS NOT NULL GROUP BY dt.id")->fetchAll();

// Monthly stats for the year
$monthlyStats = $db->query('SELECT MONTH(created_at) as month, COUNT(*) as count, SUM(total_amount) as revenue FROM requests WHERE YEAR(created_at) = YEAR(CURDATE()) GROUP BY MONTH(created_at) ORDER BY month')->fetchAll();

$pageTitle = 'Reports & Analytics';
$activeNav = 'reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2>Reports & Analytics</h2>
        <a href="?export=csv" class="btn btn-outline btn-sm"><i class="fas fa-download"></i> Export CSV</a>
    </div>
    <div class="card-body">
        <div class="stats-grid">
            <?= statCardLink('../admin/requests.php', 'blue', 'fa-file-alt', (string)$stats['total_requests'], 'Total Requests') ?>
            <?= statCardLink('../admin/payments.php', 'green', 'fa-peso-sign', e(formatMoney($stats['revenue'])), 'Total Revenue') ?>
            <?= statCardLink('../admin/requests.php', 'orange', 'fa-calendar-day', (string)$stats['today'], 'Today') ?>
            <?= statCardLink('reports.php', 'purple', 'fa-calendar', (string)$stats['month'], 'This Month') ?>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Most Requested Documents</h3></div>
        <div class="card-body">
            <table class="data-table">
                <thead><tr><th>Document</th><th>Requests</th><th>Revenue</th></tr></thead>
                <tbody>
                    <?php foreach ($docBreakdown as $d): ?>
                    <tr>
                        <td><?= e($d['name']) ?></td>
                        <td><?= $d['count'] ?></td>
                        <td><?= formatMoney((float)($d['revenue'] ?? 0)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Processing Time Analysis</h3></div>
        <div class="card-body">
            <table class="data-table">
                <thead><tr><th>Document</th><th>Avg. Days</th></tr></thead>
                <tbody>
                    <?php foreach ($processingTime as $pt): ?>
                    <tr>
                        <td><?= e($pt['name']) ?></td>
                        <td><?= round((float)$pt['avg_days'], 1) ?> days</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($processingTime)): ?>
                    <tr><td colspan="2" class="text-muted">No completed requests yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Monthly Statistics (<?= date('Y') ?>)</h3></div>
    <div class="card-body">
        <table class="data-table">
            <thead><tr><th>Month</th><th>Requests</th><th>Revenue</th></tr></thead>
            <tbody>
                <?php
                $months = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                foreach ($monthlyStats as $ms): ?>
                <tr>
                    <td><?= $months[$ms['month']] ?></td>
                    <td><?= $ms['count'] ?></td>
                    <td><?= formatMoney((float)($ms['revenue'] ?? 0)) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
