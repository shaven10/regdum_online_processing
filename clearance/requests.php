<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clearance.php';
requireClearanceAccess();

$user = currentUser();
$department = getUserClearanceDepartment($user);
$deptId = $department['id'] ?? (int)($_GET['department_id'] ?? 0);
$status = $_GET['status'] ?? 'pending';

if (!$deptId) {
    setFlash('error', 'No clearance department assigned.');
    redirect(dashboardUrl());
}

$requests = getClearanceRequestsForDepartment($deptId, $status);

$pageTitle = 'Clearance Requests';
$activeNav = 'requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2><?= e($department['name']) ?> — Clearance Queue</h2>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <select name="status">
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="cleared" <?= $status === 'cleared' ? 'selected' : '' ?>>Cleared</option>
                <option value="on_hold" <?= $status === 'on_hold' ? 'selected' : '' ?>>On Hold</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        </form>

        <?php if (empty($requests)): ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No clearance records found.</p></div>
        <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Request #</th><th>Student ID</th><th>Name</th><th>Document</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($requests as $item): ?>
                    <tr>
                        <td><strong><?= e($item['request_number']) ?></strong></td>
                        <td><?= e($item['student_id']) ?></td>
                        <td><?= e($item['first_name'] . ' ' . $item['last_name']) ?></td>
                        <td><?= e($item['document_name']) ?></td>
                        <td><?= clearanceStatusBadge($item['status']) ?></td>
                        <td><?= formatDate($item['request_date']) ?></td>
                        <td><a href="sign.php?request_id=<?= $item['request_id'] ?>" class="btn btn-sm btn-primary">Open</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
