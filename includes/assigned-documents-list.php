<?php

/**
 * Shared list of documents assigned to the current processor.
 *
 * Expected before include:
 * - $user
 * - $pageTitle
 * - $activeNav
 * - $processBaseUrl (e.g. APP_URL.'/cashier/process-document.php')
 * - $officeLabel
 */

require_once __DIR__ . '/request-items.php';
ensureRequestItemsSchema();

$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$items = getStaffAssignedItems((int) $user['id'], $status);

if (!empty($documentCodeFilter)) {
    $allowedCodes = is_array($documentCodeFilter)
        ? array_map(static fn($code): string => strtoupper(trim((string) $code)), $documentCodeFilter)
        : [strtoupper(trim((string) $documentCodeFilter))];
    $items = array_values(array_filter($items, static function (array $row) use ($allowedCodes): bool {
        return in_array(strtoupper(trim((string) ($row['document_code'] ?? ''))), $allowedCodes, true);
    }));
}

if ($search !== '') {
    $items = array_values(array_filter($items, static function (array $row) use ($search): bool {
        $haystack = strtolower($row['request_number'] . ' ' . $row['document_name'] . ' ' . $row['first_name'] . ' ' . $row['last_name'] . ' ' . ($row['student_id'] ?? ''));
        return str_contains($haystack, strtolower($search));
    }));
}

require_once __DIR__ . '/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2><?= e($officeLabel ?? 'Document Assignments') ?></h2>
            <p class="text-muted" style="margin:.35rem 0 0">Documents assigned to your office for processing.</p>
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
                        <td data-label="Action" class="payment-actions-cell">
                            <a href="<?= e($processBaseUrl) ?>?item_id=<?= (int) $item['id'] ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-eye"></i> View / Process
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
