<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clearance.php';
require_once __DIR__ . '/../includes/compliance.php';
requireClearanceAccess();

$user = currentUser();
$db = getDB();
$requestId = (int) ($_GET['request_id'] ?? 0);
$department = getUserClearanceDepartment($user);

if (!$department) {
    setFlash('error', 'No clearance department assigned.');
    redirect(dashboardUrl());
}

$stmt = $db->prepare('SELECT r.*, dt.name as document_name, u.first_name, u.last_name, u.student_id, u.email,
    sp.course, sp.year_level, sp.enrollment_status
    FROM requests r
    JOIN document_types dt ON r.document_type_id = dt.id
    JOIN users u ON r.user_id = u.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE r.id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    setFlash('error', 'Request not found.');
    redirect(APP_URL . '/clearance/dashboard.php');
}

initRequestClearance($requestId);
$clearances = getRequestClearances($requestId);
$myClearance = null;
foreach ($clearances as $c) {
    if ((int) $c['department_id'] === (int) $department['id']) {
        $myClearance = $c;
        break;
    }
}

if (!$myClearance) {
    setFlash('error', 'Clearance record not found for your department.');
    redirect(APP_URL . '/clearance/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if (in_array($action, ['cleared', 'on_hold'], true) && processClearanceAction($requestId, (int) $department['id'], $user['id'], $action, $remarks)) {
        setFlash('success', $action === 'cleared' ? 'Clearance signed successfully.' : 'Clearance placed on hold.');
    } elseif ($action === 'reset' && processClearanceAction($requestId, (int) $department['id'], $user['id'], 'reset', $remarks)) {
        setFlash('success', 'Clearance reset to pending.');
    } else {
        setFlash('error', 'Unable to update clearance.');
    }
    redirect(APP_URL . '/clearance/sign.php?request_id=' . $requestId);
}

$pageTitle = 'Sign Clearance — ' . $request['request_number'];
$activeNav = 'requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>Student Request Details</h2></div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item"><label>Request #</label><span><?= e($request['request_number']) ?></span></div>
                <div class="detail-item"><label>Student</label><span><?= e($request['first_name'] . ' ' . $request['last_name']) ?></span></div>
                <div class="detail-item"><label>Student ID</label><span><?= e($request['student_id']) ?></span></div>
                <div class="detail-item"><label>Course</label><span><?= e($request['course'] ?? '—') ?></span></div>
                <div class="detail-item"><label>Document</label><span><?= e($request['document_name']) ?></span></div>
                <div class="detail-item"><label>Request Status</label><span><?= statusBadge($request['status']) ?></span></div>
            </div>

            <?= renderClearanceGrid($requestId, true) ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Sign as <?= e($department['name']) ?></h2></div>
        <div class="card-body">
            <div class="detail-item" style="margin-bottom:1rem">
                <label>Current Status</label>
                <span><?= clearanceStatusBadge($myClearance['status']) ?></span>
            </div>

            <?php if (!empty($myClearance['remarks'])): ?>
                <div class="alert alert-info"><strong>Remarks:</strong> <?= e($myClearance['remarks']) ?></div>
            <?php endif; ?>

            <form method="POST" class="form-grid">
                <?= csrfField() ?>
                <div class="form-group">
                    <label for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" rows="3" placeholder="Optional notes for the student / registrar"><?= e($myClearance['remarks'] ?? '') ?></textarea>
                </div>
                <div class="form-actions" style="display:flex;gap:.5rem;flex-wrap:wrap">
                    <?php if ($myClearance['status'] !== 'cleared'): ?>
                        <button type="submit" name="action" value="cleared" class="btn btn-success"><i class="fas fa-check"></i> Sign / Clear</button>
                    <?php endif; ?>
                    <?php if ($myClearance['status'] !== 'on_hold'): ?>
                        <button type="submit" name="action" value="on_hold" class="btn btn-warning"><i class="fas fa-pause"></i> Place On Hold</button>
                    <?php endif; ?>
                    <?php if ($myClearance['status'] !== 'pending'): ?>
                        <button type="submit" name="action" value="reset" class="btn btn-outline"><i class="fas fa-undo"></i> Reset to Pending</button>
                    <?php endif; ?>
                    <a href="requests.php?status=pending" class="btn btn-outline">Back to Queue</a>
                </div>
            </form>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
