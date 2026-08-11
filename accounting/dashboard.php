<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting.php';
require_once __DIR__ . '/../includes/dashboard.php';
requireRole('accounting');

ensureAccountingModule();

$user = currentUser();
$pageTitle = 'Accounting Dashboard';
$activeNav = 'dashboard';

require_once __DIR__ . '/../includes/request-items.php';
ensureRequestItemsSchema();

$assignedDocuments = filterAccountingSoaAssignments(getStaffAssignedItems((int) $user['id']));
$processing = array_values(array_filter($assignedDocuments, static fn(array $i): bool => ($i['item_status'] ?? '') === 'processing'));
$ready = array_values(array_filter($assignedDocuments, static fn(array $i): bool => ($i['item_status'] ?? '') === 'ready_for_pickup'));
$completed = filterAccountingSoaAssignments(getStaffAssignedItems((int) $user['id'], 'completed'));

require_once __DIR__ . '/../includes/header.php';
renderDashboardWelcome($user, 'Process Statement of Account (SOA) document requests assigned to Accounting.');
renderDashboardActions([
    ['url' => 'documents.php?status=processing', 'label' => 'Processing SOA', 'icon' => 'fa-file-invoice-dollar', 'class' => 'btn-primary'],
    ['url' => 'documents.php?status=ready_for_pickup', 'label' => 'Ready for Pickup', 'icon' => 'fa-box-open'],
    ['url' => 'documents.php?status=completed', 'label' => 'Completed', 'icon' => 'fa-check-circle'],
    ['url' => 'documents.php', 'label' => 'All Assignments', 'icon' => 'fa-list'],
]);
?>

<div class="stats-grid">
    <?= statCardLink('documents.php?status=processing', 'orange', 'fa-spinner', (string) count($processing), 'Processing') ?>
    <?= statCardLink('documents.php?status=ready_for_pickup', 'blue', 'fa-box-open', (string) count($ready), 'Ready for Pickup') ?>
    <?= statCardLink('documents.php', 'purple', 'fa-file-invoice-dollar', (string) count($assignedDocuments), 'Active SOA Assignments') ?>
    <?= statCardLink('documents.php?status=completed', 'green', 'fa-check', (string) count($completed), 'Completed SOA') ?>
</div>

<div class="card">
    <div class="card-header">
        <h2>Active SOA Assignments</h2>
        <a href="documents.php" class="btn btn-primary btn-sm">View All</a>
    </div>
    <div class="card-body">
        <?php if (empty($assignedDocuments)): ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No Statement of Account documents assigned to you yet.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table data-table-responsive">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Student</th>
                            <th>Copies</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($assignedDocuments, 0, 8) as $item): ?>
                        <tr>
                            <td data-label="Request #"><strong><?= e($item['request_number']) ?></strong></td>
                            <td data-label="Student"><?= e($item['first_name'] . ' ' . $item['last_name']) ?><br><small class="text-muted"><?= e($item['student_id'] ?? '') ?></small></td>
                            <td data-label="Copies"><?= (int) $item['copies'] ?></td>
                            <td data-label="Status"><?= requestItemStatusBadge($item['item_status']) ?></td>
                            <td data-label="Action">
                                <a href="process-document.php?item_id=<?= (int) $item['id'] ?>" class="btn btn-sm btn-primary">Process</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
