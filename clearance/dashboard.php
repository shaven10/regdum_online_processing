<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clearance.php';
require_once __DIR__ . '/../includes/dashboard.php';
requireClearanceAccess();

$user = currentUser();
$department = getUserClearanceDepartment($user);
if (!$department && !hasRole('admin')) {
    setFlash('error', 'No clearance department assigned to your account.');
    redirect(dashboardUrl());
}

$deptId = $department['id'] ?? (int)($_GET['department_id'] ?? 0);
$pending = $deptId ? getClearanceRequestsForDepartment($deptId, 'pending') : [];
$onHold = $deptId ? getClearanceRequestsForDepartment($deptId, 'on_hold') : [];
$clearanceStats = $deptId ? clearanceDashboardStats($deptId) : ['pending' => 0, 'on_hold' => 0, 'cleared_today' => 0];

$pageTitle = 'Clearance Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($department): ?>
<?php
require_once __DIR__ . '/../includes/request-items.php';
ensureRequestItemsSchema();
$assignedDocuments = getStaffAssignedItems((int) $user['id']);

renderDashboardWelcome($user, 'Sign student clearances for ' . $department['name'] . ' and process assigned documents (e.g. Good Moral).');
renderDashboardActions([
    ['url' => 'requests.php?status=pending', 'label' => 'Pending Clearances', 'icon' => 'fa-clock', 'class' => 'btn-primary'],
    ['url' => 'documents.php', 'label' => 'Assigned Documents', 'icon' => 'fa-file-signature'],
    ['url' => 'requests.php?status=on_hold', 'label' => 'On Hold', 'icon' => 'fa-pause-circle'],
    ['url' => 'requests.php?status=cleared', 'label' => 'Cleared', 'icon' => 'fa-check-circle'],
]);
?>

<div class="stats-grid">
    <?= statCardLink('requests.php?status=pending', 'orange', $department['icon'], (string)$clearanceStats['pending'], 'Pending Clearance') ?>
    <?= statCardLink('documents.php', 'purple', 'fa-file-signature', (string) count($assignedDocuments), 'Assigned Documents') ?>
    <?= statCardLink('requests.php?status=on_hold', 'gold', 'fa-pause-circle', (string)$clearanceStats['on_hold'], 'On Hold') ?>
    <?= statCardLink('requests.php?status=cleared', 'green', 'fa-check', (string)$clearanceStats['cleared_today'], 'Cleared Today') ?>
    <?= statCardLink('requests.php', 'blue', 'fa-building', e($department['name']), 'Your Office') ?>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h2>Pending — <?= e($department['name']) ?></h2>
            <a href="requests.php?status=pending" class="btn btn-primary btn-sm">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($pending)): ?>
                <div class="empty-state"><i class="fas fa-check-circle"></i><p>No pending clearances for your office.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead><tr><th>Request #</th><th>Student</th><th>Document</th><th>Submitted</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($pending, 0, 8) as $item): ?>
                            <tr>
                                <td data-label="Request #"><strong><?= e($item['request_number']) ?></strong></td>
                                <td data-label="Student"><?= e($item['first_name'] . ' ' . $item['last_name']) ?> <small class="text-muted"><?= e($item['student_id']) ?></small></td>
                                <td data-label="Document"><?= e($item['document_name']) ?></td>
                                <td data-label="Submitted"><?= formatDate($item['request_date']) ?></td>
                                <td data-label="Action"><a href="sign.php?request_id=<?= $item['request_id'] ?>" class="btn btn-sm btn-primary">Sign</a></td>
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
            <h2>On Hold</h2>
            <a href="requests.php?status=on_hold" class="btn btn-outline btn-sm">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($onHold)): ?>
                <p class="text-muted">No clearances on hold.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead><tr><th>Request #</th><th>Student</th><th>Document</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($onHold, 0, 6) as $item): ?>
                            <tr>
                                <td data-label="Request #"><?= e($item['request_number']) ?></td>
                                <td data-label="Student"><?= e($item['first_name'] . ' ' . $item['last_name']) ?></td>
                                <td data-label="Document"><?= e($item['document_name']) ?></td>
                                <td data-label="Action"><a href="sign.php?request_id=<?= $item['request_id'] ?>" class="btn btn-sm btn-outline">Review</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php else: ?>
<div class="alert alert-info">Admin view — select a department from Clearance Requests.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
