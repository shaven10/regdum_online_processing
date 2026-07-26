<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/dashboard.php';
requireRole('student');

$user = currentUser();
ensureDeliveryMethods();
ensureStudentEmploymentFields();
ensureAcademicProgramsSchema();
ensureEnrollmentStatuses();
ensureCampusesSchema();
ensureStudentAcademicTermFields();
ensureStudentValidIdField();

$db = getDB();
$userId = $user['id'];
$profileCompletion = getStudentProfileCompletion($userId);
$stats = studentDashboardStats($userId);

$stmt = $db->prepare('SELECT r.*, dt.name as document_name FROM requests r JOIN document_types dt ON r.document_type_id = dt.id WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 5');
$stmt->execute([$userId]);
$recentRequests = $stmt->fetchAll();

$actionRequired = $db->prepare("SELECT r.*, dt.name as document_name FROM requests r JOIN document_types dt ON r.document_type_id = dt.id WHERE r.user_id = ? AND r.status IN ('awaiting_requirements','needs_revision','requirements_verified') ORDER BY r.updated_at DESC LIMIT 5");
$actionRequired->execute([$userId]);
$actionItems = $actionRequired->fetchAll();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';

renderDashboardWelcome($user, 'Track your document requests and progress through each workflow step.');
echo renderStudentRegistrationStatus($profileCompletion, 'card');
renderDashboardActions([
    $profileCompletion['complete']
        ? ['url' => 'new-request.php', 'label' => 'New Request', 'icon' => 'fa-plus', 'class' => 'btn-primary']
        : ['url' => 'profile.php', 'label' => 'Complete Profile', 'icon' => 'fa-user-edit', 'class' => 'btn-primary'],
    ['url' => 'requests.php', 'label' => 'My Requests', 'icon' => 'fa-list'],
    ['url' => APP_URL . '/notifications.php', 'label' => 'Notifications', 'icon' => 'fa-bell'],
]);
?>

<div class="stats-grid">
    <?= statCardLink('requests.php', 'blue', 'fa-file-alt', (string)$stats['total'], 'Total Requests') ?>
    <?= statCardLink('requests.php', 'orange', 'fa-clock', (string)$stats['active'], 'Active Requests') ?>
    <?= statCardLink('requests.php?status=awaiting_requirements', 'purple', 'fa-list-check', (string)$stats['needs_action'], 'Needs Your Action') ?>
    <?= statCardLink('requests.php?status=completed', 'green', 'fa-check-circle', (string)$stats['completed'], 'Completed') ?>
</div>

<?php if (!empty($actionItems)): ?>
<div class="card">
    <div class="card-header">
        <h2>Action Required</h2>
        <a href="requests.php" class="btn btn-outline btn-sm">View All</a>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="data-table student-requests-table data-table-responsive">
                <thead>
                    <tr>
                        <th>Request #</th>
                        <th>Document</th>
                        <th>Progress</th>
                        <th>Next Step</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($actionItems as $req): ?>
                    <tr>
                        <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                        <td data-label="Document"><?= e($req['document_name']) ?></td>
                        <td data-label="Progress"><?= renderStudentProgressMini($req['status'], (int) $req['id']) ?></td>
                        <td data-label="Next Step"><small><?= e(studentProgressStatusLabel($req['status'])) ?></small></td>
                        <td data-label="Action">
                            <?php if ($req['status'] === 'requirements_verified'): ?>
                                <a href="payment.php?request_id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">Pay Now</a>
                            <?php else: ?>
                                <a href="request-view.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">Complete</a>
                            <?php endif; ?>
                        </td>
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
        <h2>Recent Requests</h2>
        <a href="<?= $profileCompletion['complete'] ? 'new-request.php' : 'profile.php' ?>" class="btn btn-primary btn-sm"><i class="fas fa-<?= $profileCompletion['complete'] ? 'plus' : 'user-edit' ?>"></i> <?= $profileCompletion['complete'] ? 'New Request' : 'Complete Profile' ?></a>
    </div>
    <div class="card-body">
        <?php if (empty($recentRequests)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No requests yet. Start by creating a new credential request.</p>
                <a href="<?= $profileCompletion['complete'] ? 'new-request.php' : 'profile.php' ?>" class="btn btn-primary"><?= $profileCompletion['complete'] ? 'Create Request' : 'Complete Profile First' ?></a>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table student-requests-table data-table-responsive">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Document</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentRequests as $req): ?>
                        <tr>
                            <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                            <td data-label="Document"><?= e($req['document_name']) ?></td>
                            <td data-label="Progress"><?= renderStudentProgressMini($req['status'], (int) $req['id']) ?></td>
                            <td data-label="Status"><?= statusBadge($req['status']) ?></td>
                            <td data-label="Date"><?= formatDate($req['created_at']) ?></td>
                            <td data-label="Action">
                                <a href="request-view.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline">View Progress</a>
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
