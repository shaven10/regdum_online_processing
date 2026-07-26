<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/dashboard.php';
requireRole('admin');

$user = currentUser();
$stats = getDashboardStats();
$workflow = adminWorkflowStats();
$statusCounts = getStatusCounts();
$db = getDB();

$recentRequests = $db->query('SELECT r.*, dt.name as document_name, u.first_name, u.last_name FROM requests r JOIN document_types dt ON r.document_type_id = dt.id JOIN users u ON r.user_id = u.id ORDER BY r.created_at DESC LIMIT 10')->fetchAll();

$pageTitle = 'Admin Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';

renderDashboardWelcome($user, 'System overview across all requests, payments, and workflow stages.');
renderDashboardActions([
    ['url' => 'requests.php', 'label' => 'All Requests', 'icon' => 'fa-list', 'class' => 'btn-primary'],
    ['url' => 'users.php', 'label' => 'User Management', 'icon' => 'fa-users'],
    ['url' => 'document-types.php', 'label' => 'Document Types', 'icon' => 'fa-file-alt'],
    ['url' => 'reports.php', 'label' => 'Reports', 'icon' => 'fa-chart-bar'],
]);
?>

<div class="stats-grid">
    <?= statCardLink('requests.php', 'blue', 'fa-file-alt', (string)$stats['total_requests'], 'Total Requests') ?>
    <?= statCardLink('requests.php?status=awaiting_requirements', 'orange', 'fa-list-check', (string)$workflow['awaiting_requirements'], 'Awaiting Requirements') ?>
    <?= statCardLink('requests.php?status=requirements_verified', 'purple', 'fa-credit-card', (string)$workflow['awaiting_payment'], 'Awaiting Payment') ?>
    <?= statCardLink('requests.php?status=processing', 'teal', 'fa-cog', (string)$workflow['processing'], 'In Processing') ?>
    <?= statCardLink('requests.php?status=completed', 'green', 'fa-check-circle', (string)$stats['completed'], 'Completed') ?>
    <?= statCardLink('students.php', 'gold', 'fa-users', (string)$stats['students'], 'Students') ?>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>Requests by Status</h2></div>
        <div class="card-body">
            <?php foreach (['submitted','awaiting_requirements','requirements_submitted','requirements_verified','payment_verified','processing','ready_for_pickup','shipped','completed','rejected'] as $s): ?>
                <?php $count = $statusCounts[$s] ?? 0; ?>
                <a href="requests.php?status=<?= urlencode($s) ?>" class="status-bar-item clickable">
                    <span><?= e(studentProgressStatusLabel($s)) ?></span>
                    <div class="status-bar"><div class="status-bar-fill" style="width:<?= $stats['total_requests'] ? (($count / $stats['total_requests']) * 100) : 0 ?>%"></div></div>
                    <strong><?= $count ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Workflow Snapshot</h2></div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item"><label>Re-evaluation Queue</label><span><?= $workflow['re_evaluation'] ?> requests</span></div>
                <div class="detail-item"><label>Today's Requests</label><span><?= $stats['today'] ?></span></div>
                <div class="detail-item"><label>This Month</label><span><?= $stats['month'] ?> requests</span></div>
                <div class="detail-item"><label>Total Revenue</label><span><?= formatMoney($stats['revenue']) ?></span></div>
                <div class="detail-item"><label>Month Revenue</label><span><?= formatMoney($stats['month_revenue']) ?></span></div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Recent Requests</h2>
        <a href="requests.php" class="btn btn-outline btn-sm">View All</a>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="data-table data-table-responsive">
                <thead><tr><th>Request #</th><th>Student</th><th>Document</th><th>Stage</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($recentRequests as $req): ?>
                    <tr>
                        <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                        <td data-label="Student"><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                        <td data-label="Document"><?= e($req['document_name']) ?></td>
                        <td data-label="Stage">
                            <?= statusBadge($req['status']) ?>
                            <br><small class="text-muted"><?= e(workflowPhaseLabel($req['status'])) ?></small>
                        </td>
                        <td data-label="Date"><?= formatDate($req['created_at']) ?></td>
                        <td data-label="Action"><a href="request-manage.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline">Manage</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
