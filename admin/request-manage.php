<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/payments.php';
requireRole('admin');

ensureRequestStatuses();

$db = getDB();
$requestId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT r.*, dt.name as document_name, u.first_name, u.last_name, u.email, u.student_id, u.phone FROM requests r JOIN document_types dt ON r.document_type_id = dt.id JOIN users u ON r.user_id = u.id WHERE r.id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    setFlash('error', 'Request not found.');
    redirect(APP_URL . '/admin/requests.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status') {
        $newStatus = trim($_POST['status'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $result = adminUpdateRequestStatus($requestId, $newStatus, $remarks ?: null);

        if ($result['ok']) {
            setFlash('success', $result['message'], [
                'title' => 'Status Updated',
                'context' => [
                    'Request' => $request['request_number'],
                    'New status' => ucwords(str_replace('_', ' ', $newStatus)),
                ],
                'next_step' => 'The student has been notified of this status change.',
            ]);
        } else {
            setFlash('error', $result['error'] ?? 'Unable to update status.', [
                'title' => 'Update Failed',
            ]);
        }
    } elseif ($action === 'assign') {
        $assignedTo = (int)($_POST['assigned_to'] ?? 0);
        $db->prepare('UPDATE requests SET assigned_to = ? WHERE id = ?')->execute([$assignedTo ?: null, $requestId]);
        setFlash('success', 'Request assigned.', [
            'title' => 'Assignment Saved',
            'context' => ['Request' => $request['request_number']],
        ]);
    } elseif ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if ($reason === '') {
            setFlash('error', 'Please provide a rejection reason.', ['title' => 'Rejection Incomplete']);
        } else {
            $db->prepare('UPDATE requests SET rejection_reason = ? WHERE id = ?')->execute([$reason, $requestId]);
            updateRequestStatus($requestId, 'rejected', $reason);
            setFlash('success', 'Request rejected.', [
                'title' => 'Request Rejected',
                'context' => ['Request' => $request['request_number']],
                'next_step' => 'The student can review the reason and resubmit if allowed.',
            ]);
        }
    } elseif ($action === 'release') {
        $tracking = trim($_POST['courier_tracking'] ?? '');
        $releaseStatus = $_POST['release_status'] ?? 'completed';
        if (!in_array($releaseStatus, ['shipped', 'completed'], true)) {
            $releaseStatus = 'completed';
        }
        $db->prepare('UPDATE requests SET courier_tracking = ?, completed_at = IF(? = \'completed\', NOW(), completed_at) WHERE id = ?')
           ->execute([$tracking ?: null, $releaseStatus, $requestId]);
        updateRequestStatus($requestId, $releaseStatus, 'Document released by administrator');
        setFlash('success', 'Release recorded.', [
            'title' => 'Release Recorded',
            'context' => ['Request' => $request['request_number']],
        ]);
    }

    redirect(APP_URL . '/admin/request-manage.php?id=' . $requestId);
}

$stmt->execute([$requestId]);
$request = $stmt->fetch();

$staffList = $db->query("SELECT u.id, u.first_name, u.last_name FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name IN ('staff','registrar') AND u.is_active = 1")->fetchAll();
$statusOptions = requestStatusOptions();

$payment = $db->prepare('SELECT * FROM payments WHERE request_id = ? ORDER BY created_at DESC LIMIT 1');
$payment->execute([$requestId]);
$paymentData = $payment->fetch();

$pageTitle = 'Manage ' . $request['request_number'];
$activeNav = 'requests';

require_once __DIR__ . '/../includes/header.php';
?>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>Request Details</h2> <?= statusBadge($request['status']) ?></div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item"><label>Student</label><span><?= e($request['first_name'] . ' ' . $request['last_name']) ?> (<?= e($request['student_id'] ?? '—') ?>)</span></div>
                <div class="detail-item"><label>Email</label><span><?= e($request['email']) ?></span></div>
                <div class="detail-item"><label>Document</label><span><?= e($request['document_name']) ?></span></div>
                <div class="detail-item"><label>Purpose</label><span><?= purposeLabel($request['purpose'] ?? '') ?></span></div>
                <?= renderRequestTermInfoHtml($request) ?>
                <?= renderRequestSoaInfoHtml($request) ?>
                <div class="detail-item"><label>Request Type</label><span><?= e(copyRequestTypeLabel($request['copy_request_type'] ?? null)) ?></span></div>
                <div class="detail-item"><label>Copies</label><span><?= (int) $request['copies'] ?></span></div>
                <div class="detail-item"><label>Amount</label><span><?= formatMoney((float)$request['total_amount']) ?></span></div>
                <div class="detail-item"><label>Delivery</label><span><?= e(deliveryMethodLabel($request['delivery_method'])) ?></span></div>
                <?php if ($request['delivery_method'] === 'authorized_representative'): ?>
                    <?= renderRepresentativePickupDetailsHtml($requestId, $request) ?>
                <?php endif; ?>
                <div class="detail-item"><label>Verification Code</label><span><code><?= e(formatVerificationCode($request['verification_code'])) ?></code></span></div>
                <?php if (!empty($request['release_date'])): ?>
                    <div class="detail-item"><label>Release Schedule</label><span><?= formatDate($request['release_date']) ?> <?= !empty($request['release_time']) ? date('g:i A', strtotime($request['release_time'])) : '' ?></span></div>
                <?php endif; ?>
                <?php if (!empty($request['completed_at'])): ?>
                    <div class="detail-item"><label>Completed</label><span><?= formatDateTime($request['completed_at']) ?></span></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Actions</h2></div>
        <div class="card-body">
            <form method="POST" class="action-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_status">
                <div class="form-group">
                    <label for="admin_status">Update Status</label>
                    <select id="admin_status" name="status" required>
                        <?php foreach ($statusOptions as $s): ?>
                            <option value="<?= e($s) ?>" <?= $request['status'] === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Current: <strong><?= ucwords(str_replace('_', ' ', $request['status'])) ?></strong></small>
                </div>
                <div class="form-group">
                    <label for="admin_remarks">Remarks</label>
                    <input type="text" id="admin_remarks" name="remarks" placeholder="Optional note for status history">
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-sync-alt"></i> Update Status</button>
            </form>

            <hr>

            <form method="POST" class="action-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign">
                <div class="form-group">
                    <label>Assign to Staff</label>
                    <select name="assigned_to">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($staffList as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (int)($request['assigned_to'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['first_name'] . ' ' . $s['last_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-outline btn-sm">Assign</button>
            </form>

            <hr>

            <form method="POST" class="action-form" onsubmit="return confirm('Reject this request?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject">
                <div class="form-group">
                    <label>Rejection Reason</label>
                    <input type="text" name="rejection_reason" required>
                </div>
                <button type="submit" class="btn btn-danger btn-sm">Reject Request</button>
            </form>

            <?php if ($request['delivery_method'] === 'courier' && in_array($request['status'], ['processing', 'ready_for_pickup', 'shipped'], true)): ?>
                <hr>
                <form method="POST" class="action-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="release">
                    <div class="form-group">
                        <label>Courier Tracking</label>
                        <input type="text" name="courier_tracking" value="<?= e($request['courier_tracking'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Release Status</label>
                        <select name="release_status">
                            <option value="shipped">Shipped</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-outline btn-sm">Record Release</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($paymentData): ?>
<div class="card">
    <div class="card-header"><h3>Payment</h3> <?= statusBadge($paymentData['status']) ?></div>
    <div class="card-body">
        <div class="detail-grid">
            <div class="detail-item"><label>Method</label><span><?= e(paymentMethodLabel($paymentData['payment_method'])) ?></span></div>
            <div class="detail-item"><label>Amount</label><span><?= formatMoney((float)$paymentData['amount']) ?></span></div>
            <div class="detail-item"><label>Reference</label><span><?= e($paymentData['reference_number'] ?? '—') ?></span></div>
        </div>
        <?php if (!empty($paymentData['receipt_path'])): ?>
            <a href="<?= UPLOAD_URL ?>/<?= e($paymentData['receipt_path']) ?>" target="_blank" class="btn btn-outline btn-sm">View Receipt</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
