<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/dashboard.php';
requireRole('cashier');

$user = currentUser();
ensureCashierRole();

$stats = getPaymentStats();
$db = getDB();

$pendingPayments = getPaymentsList('pending');
$rejectedPayments = getPaymentsList('rejected');
$recentVerified = $db->query("SELECT p.*, r.request_number, u.first_name, u.last_name
    FROM payments p
    JOIN requests r ON p.request_id = r.id
    JOIN users u ON r.user_id = u.id
    WHERE p.status = 'verified' AND DATE(p.verified_at) = CURDATE()
    ORDER BY p.verified_at DESC LIMIT 5")->fetchAll();

$pageTitle = 'Cashier Dashboard';
$activeNav = 'dashboard';

require_once __DIR__ . '/../includes/request-items.php';
ensureRequestItemsSchema();
$assignedDocuments = getStaffAssignedItems((int) $user['id']);

require_once __DIR__ . '/../includes/header.php';
renderDashboardWelcome($user, 'Verify student payments and process documents assigned to Cashier (e.g. SOA).');
renderDashboardActions([
    ['url' => 'payments.php?status=pending', 'label' => 'Pending Payments', 'icon' => 'fa-clock', 'class' => 'btn-primary'],
    ['url' => 'documents.php', 'label' => 'Assigned Documents', 'icon' => 'fa-file-invoice'],
    ['url' => 'payments.php?status=verified', 'label' => 'Verified', 'icon' => 'fa-check-circle'],
    ['url' => 'payments.php?status=rejected', 'label' => 'Rejected', 'icon' => 'fa-times-circle'],
    ['url' => 'reports.php', 'label' => 'Reports', 'icon' => 'fa-receipt'],
]);
?>

<div class="stats-grid">
    <?= statCardLink('payments.php?status=pending', 'orange', 'fa-clock', (string)$stats['pending'], 'Pending Verification') ?>
    <?= statCardLink('documents.php', 'purple', 'fa-file-invoice', (string) count($assignedDocuments), 'Assigned Documents') ?>
    <?= statCardLink('payments.php?status=verified', 'green', 'fa-check-circle', (string)$stats['verified_today'], 'Verified Today') ?>
    <?= statCardLink('payments.php?status=rejected', 'gold', 'fa-comment-dots', (string)$stats['rejected'], 'Rejected') ?>
    <?= statCardLink('payments.php?status=pending', 'blue', 'fa-peso-sign', e(formatMoney($stats['pending_amount'])), 'Pending Amount') ?>
    <?= statCardLink('reports.php', 'teal', 'fa-money-bill-wave', e(formatMoney($stats['verified_today_amount'])), 'Collected Today') ?>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h2>Pending Payments</h2>
            <a href="payments.php?status=pending" class="btn btn-primary btn-sm">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($pendingPayments)): ?>
                <div class="empty-state"><i class="fas fa-check"></i><p>No pending payments to verify.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead><tr><th>Request #</th><th>Student</th><th>Amount</th><th>Method</th><th>Submitted</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($pendingPayments, 0, 5) as $p): ?>
                            <tr>
                                <td data-label="Request #"><strong><?= e($p['request_number']) ?></strong></td>
                                <td data-label="Student"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                                <td data-label="Amount"><strong><?= formatMoney((float)$p['amount']) ?></strong></td>
                                <td data-label="Method"><?= e(paymentMethodLabel($p['payment_method'])) ?></td>
                                <td data-label="Submitted"><?= formatDateTime($p['created_at']) ?></td>
                                <td data-label="Action"><a href="payments.php?status=pending#payment-<?= $p['id'] ?>" class="btn btn-sm btn-primary">Review</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>Verified Today</h2>
            <a href="payments.php?status=verified" class="btn btn-outline btn-sm">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentVerified)): ?>
                <div class="empty-state"><i class="fas fa-receipt"></i><p>No payments verified yet today.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead><tr><th>Request #</th><th>Student</th><th>Amount</th><th>Verified</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentVerified as $p): ?>
                            <tr>
                                <td data-label="Request #"><?= e($p['request_number']) ?></td>
                                <td data-label="Student"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                                <td data-label="Amount"><?= formatMoney((float)$p['amount']) ?></td>
                                <td data-label="Verified"><?= formatDateTime($p['verified_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($rejectedPayments)): ?>
<div class="card">
    <div class="card-header">
        <h2>Recently Rejected Payments</h2>
        <a href="payments.php?status=rejected" class="btn btn-outline btn-sm">View All</a>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="data-table data-table-responsive">
                <thead><tr><th>Request #</th><th>Student</th><th>Amount</th><th>Feedback</th><th>Date</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($rejectedPayments, 0, 5) as $p): ?>
                    <tr>
                        <td data-label="Request #"><strong><?= e($p['request_number']) ?></strong></td>
                        <td data-label="Student"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                        <td data-label="Amount"><?= formatMoney((float)$p['amount']) ?></td>
                        <td data-label="Feedback"><small><?= e($p['notes'] ?? '—') ?></small></td>
                        <td data-label="Date"><?= formatDateTime($p['verified_at'] ?? $p['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
