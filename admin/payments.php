<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
requireRole('admin');

$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $paymentId = (int) ($_POST['payment_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['notes'] ?? '');

    if ($paymentId && processPaymentAction($paymentId, $action, $user['id'], $user['role_name'], $notes)) {
        setFlash('success', 'Payment ' . ($action === 'verify' ? 'verified' : 'rejected') . '.');
    } elseif ($action === 'reject') {
        setFlash('error', 'Please provide feedback explaining why the payment was rejected.');
    } else {
        setFlash('error', 'Unable to process payment.');
    }
    redirect(APP_URL . '/admin/payments.php');
}

$payments = getPaymentsList();

$pageTitle = 'Payments';
$activeNav = 'payments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>All Payments</h2></div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            Payment verification is primarily handled by the Cashier role. Admins may override when needed.
        </div>
        <table class="data-table">
            <thead><tr><th>Request #</th><th>Student</th><th>Method</th><th>Amount</th><th>Reference</th><th>Status</th><th>Feedback</th><th>Verified By</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= e($p['request_number']) ?></td>
                    <td><?= e($p['first_name'] . ' ' . $p['last_name']) ?></td>
                    <td><?= e(paymentMethodLabel($p['payment_method'])) ?></td>
                    <td><?= formatMoney((float)$p['amount']) ?></td>
                    <td><?= e($p['reference_number'] ?? '—') ?></td>
                    <td><?= statusBadge($p['status']) ?></td>
                    <td><?= ($p['status'] === 'rejected' && !empty($p['notes'])) ? e($p['notes']) : '—' ?></td>
                    <td><?= $p['verifier_first'] ? e($p['verifier_first'] . ' ' . $p['verifier_last']) : '—' ?></td>
                    <td><?= formatDate($p['created_at']) ?></td>
                    <td>
                        <?php if ($p['status'] === 'pending'): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                <button type="submit" name="action" value="verify" class="btn btn-sm btn-primary">Verify</button>
                            </form>
                            <details class="payment-reject-panel" style="display:inline-block">
                                <summary class="btn btn-sm btn-danger">Reject</summary>
                                <form method="POST" class="payment-reject-form">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                    <textarea name="notes" rows="2" required placeholder="Rejection feedback..."></textarea>
                                    <button type="submit" name="action" value="reject" class="btn btn-sm btn-danger">Confirm</button>
                                </form>
                            </details>
                        <?php endif; ?>
                        <?php if ($p['receipt_path']): ?>
                            <a href="<?= UPLOAD_URL ?>/<?= e($p['receipt_path']) ?>" target="_blank" class="btn btn-sm btn-outline">Receipt</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
