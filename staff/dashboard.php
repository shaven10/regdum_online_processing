<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/request-items.php';
requireRole('staff');

$user = currentUser();
ensureRequestItemsSchema();

$stats = staffDashboardStats((int) $user['id']);
$myItems = getStaffAssignedItems((int) $user['id']);
$processingItems = array_values(array_filter(
    $myItems,
    static fn(array $item): bool => ($item['item_status'] ?? '') === 'processing'
));
$readyItems = array_values(array_filter(
    $myItems,
    static fn(array $item): bool => ($item['item_status'] ?? '') === 'ready_for_pickup'
));
$dueSoonItems = array_values(array_filter($processingItems, static function (array $item): bool {
    if (empty($item['release_date'])) {
        return false;
    }
    $release = strtotime((string) $item['release_date']);
    if ($release === false) {
        return false;
    }
    $today = strtotime(date('Y-m-d'));
    $limit = strtotime('+3 days', $today);
    return $release >= $today && $release <= $limit;
}));

$pageTitle = 'Staff Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';

renderDashboardWelcome($user, 'Process documents assigned to you and prepare them for on-site release.');
renderDashboardActions([
    ['url' => 'requests.php', 'label' => 'My Assignments', 'icon' => 'fa-tasks', 'class' => 'btn-primary'],
    ['url' => 'requests.php?status=processing', 'label' => 'Processing', 'icon' => 'fa-cog'],
    ['url' => 'requests.php?status=ready_for_pickup', 'label' => 'Ready for Pickup', 'icon' => 'fa-box-open'],
    ['url' => 'documents.php', 'label' => 'Documents', 'icon' => 'fa-print'],
]);
?>

<div class="stats-grid">
    <?= statCardLink('requests.php', 'orange', 'fa-tasks', (string) $stats['assigned'], 'Active Assignments') ?>
    <?= statCardLink('requests.php?status=processing', 'blue', 'fa-cog', (string) $stats['processing'], 'Processing') ?>
    <?= statCardLink('requests.php?status=ready_for_pickup', 'green', 'fa-box-open', (string) $stats['ready'], 'Ready for Pickup') ?>
    <?= statCardLink('requests.php?status=completed', 'teal', 'fa-check', (string) $stats['completed'], 'Completed') ?>
    <?= statCardLink('requests.php?status=processing', 'gold', 'fa-calendar-day', (string) ($stats['due_soon'] ?? 0), 'Due in 3 Days') ?>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h2>My Document Assignments</h2>
            <a href="requests.php" class="btn btn-outline btn-sm">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($myItems)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>No documents assigned to you yet.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Document</th>
                                <th>Student</th>
                                <th>Release Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($myItems, 0, 8) as $item): ?>
                            <tr>
                                <td data-label="Request #"><strong><?= e($item['request_number']) ?></strong></td>
                                <td data-label="Document"><?= e($item['document_name']) ?></td>
                                <td data-label="Student">
                                    <?= e($item['first_name'] . ' ' . $item['last_name']) ?>
                                    <br><small class="text-muted"><?= e($item['student_id'] ?? '') ?></small>
                                </td>
                                <td data-label="Release Date">
                                    <?php if (!empty($item['release_date'])): ?>
                                        <?= formatDate($item['release_date']) ?>
                                        <?php if (!empty($item['release_time'])): ?>
                                            <br><small class="text-muted"><?= date('g:i A', strtotime((string) $item['release_time'])) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status"><?= requestItemStatusBadge($item['item_status']) ?></td>
                                <td data-label="Action">
                                    <a href="process-request.php?item_id=<?= (int) $item['id'] ?>" class="btn btn-sm btn-primary">Process</a>
                                </td>
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
            <h2>Due Soon / Ready for Pickup</h2>
            <a href="requests.php?status=ready_for_pickup" class="btn btn-outline btn-sm">Ready List</a>
        </div>
        <div class="card-body">
            <?php
            $sideItems = array_slice(array_merge($dueSoonItems, $readyItems), 0, 8);
            ?>
            <?php if (empty($sideItems)): ?>
                <div class="empty-state"><i class="fas fa-check"></i><p>No upcoming release deadlines or ready documents.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Document</th>
                                <th>Release</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sideItems as $item): ?>
                            <tr>
                                <td data-label="Request #"><strong><?= e($item['request_number']) ?></strong></td>
                                <td data-label="Document"><?= e($item['document_name']) ?></td>
                                <td data-label="Release">
                                    <?php if (!empty($item['release_date'])): ?>
                                        <?= formatDate($item['release_date']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status"><?= requestItemStatusBadge($item['item_status']) ?></td>
                                <td data-label="Action">
                                    <a href="process-request.php?item_id=<?= (int) $item['id'] ?>" class="btn btn-sm btn-primary">Open</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
