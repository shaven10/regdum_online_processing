<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/attachments.php';
requireRole('registrar');

ensureComplianceSchema();

$filter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$requests = getRequestsWithAttachments($filter, $search);

$pageTitle = 'Request Attachments';
$activeNav = 'attachments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Requestor Attachments</h2></div>
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search request #, student..." value="<?= e($search) ?>">
            <select name="status">
                <option value="">All statuses</option>
                <?php foreach (['submitted','awaiting_requirements','requirements_submitted','requirements_verified','payment_verified','processing','completed'] as $s): ?>
                    <option value="<?= $s ?>" <?= $filter === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        </form>

        <?php if (empty($requests)): ?>
            <div class="empty-state"><i class="fas fa-paperclip"></i><p>No requests with attachments found.</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table data-table-responsive attachments-list-table">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Student</th>
                            <th>Document</th>
                            <th>Status</th>
                            <th>Attachments</th>
                            <th>Submitted</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                            <td data-label="Student">
                                <?= e($req['first_name'] . ' ' . $req['last_name']) ?>
                                <br><small class="text-muted"><?= e($req['student_id']) ?></small>
                            </td>
                            <td data-label="Document"><?= e($req['document_name']) ?></td>
                            <td data-label="Status"><?= statusBadge($req['status']) ?></td>
                            <td data-label="Attachments">
                                <span class="badge badge-review"><?= (int)$req['document_count'] ?> file(s)</span>
                                <?php if ((int)$req['receipt_count'] > 0): ?>
                                    <span class="badge badge-payment"><?= (int)$req['receipt_count'] ?> receipt</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Submitted"><?= formatDate($req['created_at']) ?></td>
                            <td data-label="Action">
                                <a href="view-attachments.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-paperclip"></i> View
                                </a>
                                <a href="verify-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline">Review</a>
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
