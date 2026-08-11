<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/request-items.php';
requireRole('registrar');

$user = currentUser();
ensureComplianceSchema();
ensureRequestStatuses();
ensureRequestItemsSchema();

$stats = getComplianceStats();
$assignmentStats = staffDashboardStats((int) $user['id']);
$myAssignments = getStaffAssignedItems((int) $user['id']);
$processingAssignments = array_values(array_filter(
    $myAssignments,
    static fn(array $item): bool => ($item['item_status'] ?? '') === 'processing'
));
$readyAssignments = array_values(array_filter(
    $myAssignments,
    static fn(array $item): bool => ($item['item_status'] ?? '') === 'ready_for_pickup'
));
$pendingRequests = getRequestsForCompliance('pending');
$awaitingStudent = getRequestsForCompliance('awaiting_student');
$reEvaluation = getRequestsForCompliance('re_evaluation');
$assignmentQueue = getRequestsAwaitingStaffAssignment();
$completedTransactions = getRequestsForCompliance('completed');

$pageTitle = 'Registrar Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';

renderDashboardWelcome($user, 'Review requests, verify requirements, assign documents to staff, and process documents assigned to you.');
renderDashboardActions([
    ['url' => 'new-onsite-request.php', 'label' => 'Onsite Request', 'icon' => 'fa-store', 'class' => 'btn-primary'],
    ['url' => 'reports.php', 'label' => 'All Requests', 'icon' => 'fa-chart-bar'],
    ['url' => 'documents.php', 'label' => 'My Assignments', 'icon' => 'fa-tasks'],
    ['url' => 'compliance.php', 'label' => 'Request Review', 'icon' => 'fa-clipboard-check'],
    ['url' => 'assignments.php', 'label' => 'Staff Assignment', 'icon' => 'fa-user-tag'],
    ['url' => 'compliance.php?filter=awaiting_student', 'label' => 'Awaiting Student', 'icon' => 'fa-list-check'],
    ['url' => 'compliance.php?filter=re_evaluation', 'label' => 'Re-evaluation', 'icon' => 'fa-search'],
    ['url' => 'attachments.php', 'label' => 'Attachments', 'icon' => 'fa-paperclip'],
    ['url' => 'compliance.php?filter=completed', 'label' => 'Completed', 'icon' => 'fa-check-circle'],
]);
?>

<div class="stats-grid">
    <?= statCardLink('compliance.php', 'orange', 'fa-clipboard-check', (string)($stats['review'] ?? 0), 'Review Queue') ?>
    <?= statCardLink('compliance.php?filter=pending', 'blue', 'fa-inbox', (string)$stats['pending'], 'New Requests') ?>
    <?= statCardLink('compliance.php?filter=needs_revision', 'gold', 'fa-exclamation-triangle', (string)$stats['needs_revision'], 'Needs Revision') ?>
    <?= statCardLink('compliance.php?filter=awaiting_student', 'purple', 'fa-list-check', (string)$stats['awaiting_student'], 'Awaiting Student') ?>
    <?= statCardLink('compliance.php?filter=re_evaluation', 'teal', 'fa-search', (string)$stats['re_evaluation'], 'Re-evaluation') ?>
    <?= statCardLink('compliance.php?filter=verified', 'green', 'fa-credit-card', (string)$stats['compliant'], 'Approved for Payment') ?>
    <?= statCardLink('assignments.php', 'purple', 'fa-user-tag', (string)($stats['assignment_pending'] ?? 0), 'Staff Assignment') ?>
    <?= statCardLink('documents.php', 'orange', 'fa-tasks', (string) ($assignmentStats['assigned'] ?? 0), 'My Assignments') ?>
    <?= statCardLink('documents.php?status=processing', 'blue', 'fa-cog', (string) ($assignmentStats['processing'] ?? 0), 'My Processing') ?>
    <?= statCardLink('documents.php?status=ready_for_pickup', 'green', 'fa-box-open', (string) ($assignmentStats['ready'] ?? 0), 'Ready for Pickup') ?>
    <?= statCardLink('compliance.php?filter=release_ready', 'blue', 'fa-file-export', (string)$stats['release_ready'], 'Document Release') ?>
    <?= statCardLink('compliance.php?filter=completed', 'teal', 'fa-check-circle', (string)$stats['completed'], 'Completed Transactions') ?>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-tasks"></i> My Assignments</h2>
            <a href="documents.php" class="btn btn-primary btn-sm">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($myAssignments)): ?>
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
                            <?php foreach (array_slice($myAssignments, 0, 8) as $item): ?>
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

    <div class="card">
        <div class="card-header">
            <h2>New Requests — Set Requirements</h2>
            <a href="compliance.php?filter=pending" class="btn btn-primary btn-sm">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($pendingRequests)): ?>
                <div class="empty-state"><i class="fas fa-check"></i><p>No new requests awaiting review.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead><tr><th>Request #</th><th>Student</th><th>Document</th><th>Submitted</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($pendingRequests, 0, 8) as $req): ?>
                            <tr>
                                <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                                <td data-label="Student"><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                                <td data-label="Document"><?= e($req['document_name']) ?></td>
                                <td data-label="Submitted"><?= formatDate($req['created_at']) ?></td>
                                <td data-label="Action"><a href="verify-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">Review</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Staff Assignment Queue</h2>
        <a href="assignments.php" class="btn btn-primary btn-sm">View All</a>
    </div>
    <div class="card-body">
        <?php if (empty($assignmentQueue)): ?>
            <div class="empty-state"><i class="fas fa-user-check"></i><p>No documents waiting for staff assignment.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table data-table-responsive">
                    <thead><tr><th>Request #</th><th>Student</th><th>Documents</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($assignmentQueue, 0, 8) as $req): ?>
                        <tr>
                            <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                            <td data-label="Student"><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                            <td data-label="Documents">
                                <?= e($req['document_name'] ?? '—') ?>
                                <?php if ((int) ($req['pending_assignment_count'] ?? 0) > 0): ?>
                                    <br><small class="text-muted"><?= (int) $req['pending_assignment_count'] ?> pending</small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Action">
                                <a href="assignments.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-primary">Assign Staff</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($reEvaluation)): ?>
<div class="card">
    <div class="card-header">
        <h2>Re-evaluation Queue</h2>
        <a href="compliance.php?filter=re_evaluation" class="btn btn-outline btn-sm">View All</a>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="data-table data-table-responsive">
                <thead><tr><th>Request #</th><th>Student</th><th>Document</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($reEvaluation, 0, 6) as $req): ?>
                    <tr>
                        <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                        <td data-label="Student"><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                        <td data-label="Document"><?= e($req['document_name']) ?></td>
                        <td data-label="Action"><a href="verify-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">Review</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($awaitingStudent)): ?>
<div class="card">
    <div class="card-header">
        <h2>Awaiting Student Requirements</h2>
        <a href="compliance.php?filter=awaiting_student" class="btn btn-outline btn-sm">View All</a>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="data-table data-table-responsive">
                <thead><tr><th>Request #</th><th>Student</th><th>Document</th><th>Requirements</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($awaitingStudent, 0, 6) as $req): ?>
                    <tr>
                        <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                        <td data-label="Student"><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                        <td data-label="Document"><?= e($req['document_name']) ?></td>
                        <td data-label="Requirements"><?= (int) ($req['requirement_count'] ?? 0) ?> assigned</td>
                        <td data-label="Action"><a href="verify-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-check-circle"></i> Completed Transactions</h2>
        <div class="card-header-actions">
            <?php if ((int) ($stats['completed_today'] ?? 0) > 0): ?>
                <span class="badge badge-completed"><?= (int) $stats['completed_today'] ?> today</span>
            <?php endif; ?>
            <a href="compliance.php?filter=completed" class="btn btn-outline btn-sm">View All</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($completedTransactions)): ?>
            <div class="empty-state"><i class="fas fa-clipboard-check"></i><p>No completed transactions yet.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table data-table-responsive">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Student</th>
                            <th>Document</th>
                            <th>Amount</th>
                            <th>Completed</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($completedTransactions, 0, 10) as $req): ?>
                        <tr>
                            <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                            <td data-label="Student">
                                <?= e($req['first_name'] . ' ' . $req['last_name']) ?>
                                <br><small class="text-muted"><?= e($req['student_id'] ?? '') ?></small>
                            </td>
                            <td data-label="Document"><?= e($req['document_name']) ?></td>
                            <td data-label="Amount"><?= formatMoney((float) ($req['total_amount'] ?? 0)) ?></td>
                            <td data-label="Completed"><?= formatDateTime($req['completed_at'] ?? $req['updated_at']) ?></td>
                            <td data-label="Action">
                                <a href="verify-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline">View</a>
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
