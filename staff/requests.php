<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/request-items.php';
requireRole('staff');

$user = currentUser();
ensureRequestItemsSchema();

$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

$items = getStaffAssignedItems((int) $user['id'], $status);

if ($search !== '') {
    $items = array_values(array_filter($items, static function (array $row) use ($search): bool {
        $haystack = strtolower($row['request_number'] . ' ' . $row['document_name'] . ' ' . $row['first_name'] . ' ' . $row['last_name'] . ' ' . ($row['student_id'] ?? ''));
        return str_contains($haystack, strtolower($search));
    }));
}

$pageTitle = 'Process Requests';
$activeNav = 'requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2>My Document Assignments</h2>
            <p class="text-muted" style="margin:.35rem 0 0">Documents assigned to you by the Registrar for processing and release.</p>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search request #, document, or student..." value="<?= e($search) ?>">
            <select name="status">
                <option value="">Active Assignments</option>
                <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>Processing</option>
                <option value="ready_for_pickup" <?= $status === 'ready_for_pickup' ? 'selected' : '' ?>>Ready for Pickup</option>
                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        </form>

        <?php if (empty($items)): ?>
            <div class="empty-state"><i class="fas fa-inbox"></i><p>No document assignments found.</p></div>
        <?php else: ?>
            <table class="data-table data-table-responsive">
                <thead>
                    <tr>
                        <th>Request #</th>
                        <th>Document</th>
                        <th>Student</th>
                        <th>Copies</th>
                        <th>Item Status</th>
                        <th>Batch Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td data-label="Request #"><strong><?= e($item['request_number']) ?></strong></td>
                        <td data-label="Document"><?= e($item['document_name']) ?></td>
                        <td data-label="Student"><?= e($item['first_name'] . ' ' . $item['last_name']) ?><br><small class="text-muted"><?= e($item['student_id'] ?? '') ?></small></td>
                        <td data-label="Copies"><?= (int) $item['copies'] ?></td>
                        <td data-label="Item Status"><?= requestItemStatusBadge($item['item_status']) ?></td>
                        <td data-label="Batch Status"><?= statusBadge($item['request_status']) ?></td>
                        <td data-label="Action"><a href="process-request.php?item_id=<?= (int) $item['id'] ?>" class="btn btn-sm btn-primary">Process</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
