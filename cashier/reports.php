<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
requireRole('cashier');

$db = getDB();
$export = $_GET['export'] ?? '';

if ($export === 'csv') {
    $rows = [];
    $data = $db->query("SELECT p.id, r.request_number, CONCAT(u.first_name,' ',u.last_name) as student,
        u.student_id, p.payment_method, p.amount, p.reference_number, p.or_number, p.payment_date, p.status,
        p.created_at, p.verified_at, CONCAT(v.first_name,' ',v.last_name) as verified_by
        FROM payments p
        JOIN requests r ON p.request_id = r.id
        JOIN users u ON r.user_id = u.id
        LEFT JOIN users v ON p.verified_by = v.id
        ORDER BY p.created_at DESC")->fetchAll();

    foreach ($data as $d) {
        $rows[] = [
            $d['id'], $d['request_number'], $d['student'], $d['student_id'],
            paymentMethodLabel($d['payment_method']), $d['amount'], $d['reference_number'], $d['or_number'] ?? '', $d['payment_date'] ?? '',
            $d['status'], $d['created_at'], $d['verified_at'], $d['verified_by'] ?? '',
        ];
    }

    exportCSV(
        ['ID', 'Request #', 'Student', 'Student ID', 'Method', 'Amount', 'Reference', 'OR Number', 'Payment Date', 'Status', 'Submitted', 'Verified At', 'Verified By'],
        $rows,
        'payment_report_' . date('Y-m-d') . '.csv'
    );
}

$stats = getPaymentStats();

$byMethod = $db->query("SELECT payment_method, COUNT(*) as count, SUM(amount) as total
    FROM payments WHERE status = 'verified'
    GROUP BY payment_method ORDER BY total DESC")->fetchAll();

$monthly = $db->query("SELECT DATE_FORMAT(verified_at, '%Y-%m') as month, COUNT(*) as count, SUM(amount) as total
    FROM payments WHERE status = 'verified' AND verified_at IS NOT NULL
    GROUP BY month ORDER BY month DESC LIMIT 6")->fetchAll();

$pageTitle = 'Payment Reports';
$activeNav = 'reports';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2>Payment Summary</h2>
        <a href="?export=csv" class="btn btn-outline btn-sm"><i class="fas fa-download"></i> Export CSV</a>
    </div>
    <div class="card-body">
        <div class="stats-grid">
            <?= statCardLink('payments.php?status=verified', 'green', 'fa-check', (string)$stats['verified'], 'Total Verified') ?>
            <?= statCardLink('payments.php?status=pending', 'orange', 'fa-clock', (string)$stats['pending'], 'Pending') ?>
            <?= statCardLink('reports.php', 'gold', 'fa-peso-sign', e(formatMoney($stats['verified_today_amount'])), 'Collected Today') ?>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Verified by Payment Method</h3></div>
        <div class="card-body">
            <table class="data-table">
                <thead><tr><th>Method</th><th>Count</th><th>Total</th></tr></thead>
                <tbody>
                    <?php foreach ($byMethod as $m): ?>
                    <tr>
                        <td><?= e(paymentMethodLabel($m['payment_method'])) ?></td>
                        <td><?= $m['count'] ?></td>
                        <td><?= formatMoney((float)$m['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($byMethod)): ?>
                    <tr><td colspan="3" class="text-muted">No verified payments yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Monthly Verified Payments</h3></div>
        <div class="card-body">
            <table class="data-table">
                <thead><tr><th>Month</th><th>Count</th><th>Total</th></tr></thead>
                <tbody>
                    <?php foreach ($monthly as $m): ?>
                    <tr>
                        <td><?= e($m['month']) ?></td>
                        <td><?= $m['count'] ?></td>
                        <td><?= formatMoney((float)$m['total']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($monthly)): ?>
                    <tr><td colspan="3" class="text-muted">No data available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
