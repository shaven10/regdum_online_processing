<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/onsite-request.php';
requireRole('cashier');

$user = currentUser();
ensureCashierRole();
ensurePaymentVerificationSchema();
ensureOnsiteRequestSchema();

$status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$onsiteCode = trim($_GET['onsite_code'] ?? '');
$redirectUrl = APP_URL . '/cashier/payments.php' . ($status ? '?status=' . urlencode($status) : '');

$onsiteLookupPayment = null;
$onsiteLookupError = null;
if ($onsiteCode !== '') {
    if (!preg_match('/^\d{6}$/', $onsiteCode)) {
        $onsiteLookupError = 'Enter a valid 6-digit onsite payment code.';
    } else {
        $onsiteLookupPayment = findPaymentByOnsiteReference($onsiteCode);
        if (!$onsiteLookupPayment) {
            $onsiteLookupError = 'No pending onsite payment found for that code.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $paymentId = (int) ($_POST['payment_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $orNumber = trim($_POST['or_number'] ?? '');
    $paymentDate = trim($_POST['payment_date'] ?? '');

    if ($paymentId && $action === 'verify') {
        $validationError = validatePaymentVerificationFields($orNumber, $paymentDate);
        if ($validationError) {
            setFlash('error', $validationError, [
                'title' => 'Verification Details Required',
                'next_step' => 'Enter the OR number and date of payment before verifying.',
            ]);
            redirect($redirectUrl . ($search ? '&search=' . urlencode($search) : '') . '#payment-' . $paymentId);
        }

        $payLookup = getDB()->prepare('SELECT request_id FROM payments WHERE id = ?');
        $payLookup->execute([$paymentId]);
        $requestIdForGate = (int) ($payLookup->fetchColumn() ?: 0);
        if ($requestIdForGate > 0) {
            $clearanceBlock = paymentVerificationBlockedByClearance($requestIdForGate);
            if ($clearanceBlock !== null) {
                setFlash('error', $clearanceBlock, [
                    'title' => 'Online Clearance Incomplete',
                    'next_step' => 'Wait until all clearance offices have signed before verifying this payment.',
                ]);
                redirect($redirectUrl . ($search ? '&search=' . urlencode($search) : '') . '#payment-' . $paymentId);
            }
        }
    }

    if ($paymentId && processPaymentAction($paymentId, $action, $user['id'], $user['role_name'], $notes, $orNumber, $paymentDate)) {
        $verified = $action === 'verify';
        setFlash('success', 'Payment ' . ($verified ? 'verified' : 'rejected') . ' successfully.', [
            'title' => $verified ? 'Payment Verified' : 'Payment Rejected',
            'details' => $verified
                ? ['The request can now move to document processing and release.']
                : ['Feedback was sent to the student so they can correct and resubmit payment proof.'],
            'next_step' => $verified
                ? 'The Registrar will assign staff and schedule document release.'
                : 'The student will receive your feedback and may upload a new payment proof.',
            'action_url' => APP_URL . '/cashier/payments.php' . ($verified ? '' : '?status=rejected'),
            'action_label' => 'Back to payments',
        ]);
    } elseif ($action === 'reject') {
        setFlash('error', 'Please provide feedback explaining why the payment was rejected.', [
            'title' => 'Feedback Required',
            'next_step' => 'Add clear notes so the student knows what to fix before resubmitting.',
        ]);
        redirect($redirectUrl . ($search ? '&search=' . urlencode($search) : '') . '#payment-' . $paymentId);
    } else {
        setFlash('error', 'Unable to process payment. It may have already been handled.');
    }
    redirect($redirectUrl . ($search ? '&search=' . urlencode($search) : ''));
}

$payments = getPaymentsList($status, $search);
$stats = getPaymentStats();
$rejectReasons = [
    'Invalid or unreadable receipt uploaded.',
    'Payment amount does not match the request total.',
    'Reference or transaction number is missing or incorrect.',
    'Payment could not be verified in our records.',
    'Student did not complete payment at the cashier.',
];

$pageTitle = 'Verify Payments';
$activeNav = $status === 'pending' ? 'pending' : 'payments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="cashier-payments-page">
<div class="stats-grid cashier-payments-stats">
    <?= statCardLink('payments.php?status=pending', 'orange', 'fa-clock', (string)$stats['pending'], 'Pending') ?>
    <?= statCardLink('payments.php?status=verified', 'green', 'fa-check', (string)$stats['verified'], 'Verified') ?>
    <?= statCardLink('payments.php?status=rejected', 'orange', 'fa-times', (string)$stats['rejected'], 'Rejected') ?>
</div>

<div class="card onsite-payment-lookup-card">
    <div class="card-header">
        <h2><i class="fas fa-barcode"></i> On-Site Payment Lookup</h2>
    </div>
    <div class="card-body">
        <form method="GET" class="onsite-payment-lookup">
            <?php if ($status): ?>
                <input type="hidden" name="status" value="<?= e($status) ?>">
            <?php endif; ?>
            <?php if ($search): ?>
                <input type="hidden" name="search" value="<?= e($search) ?>">
            <?php endif; ?>
            <label for="onsite_code">Enter the student's 6-digit payment code</label>
            <div class="onsite-payment-lookup-row">
                <input type="text"
                    id="onsite_code"
                    name="onsite_code"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    placeholder="000000"
                    value="<?= e($onsiteCode) ?>"
                    autocomplete="one-time-code"
                    aria-describedby="onsiteCodeHelp">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Find Request
                </button>
            </div>
            <small id="onsiteCodeHelp" class="text-muted">Students receive this code when they choose on-site payment.</small>
        </form>

        <?php if ($onsiteLookupError): ?>
            <div class="alert alert-warning onsite-payment-lookup-alert">
                <i class="fas fa-exclamation-triangle"></i> <?= e($onsiteLookupError) ?>
            </div>
        <?php elseif ($onsiteLookupPayment): ?>
            <?php $lookupGate = getPaymentClearanceGate((int) $onsiteLookupPayment['request_id']); ?>
            <div class="alert <?= !empty($lookupGate['blocked']) ? 'alert-warning' : 'alert-success' ?> onsite-payment-lookup-result onsite-payment-lookup-alert">
                <i class="fas <?= !empty($lookupGate['blocked']) ? 'fa-exclamation-triangle' : 'fa-check-circle' ?>"></i>
                Found pending payment for <strong><?= e($onsiteLookupPayment['request_number']) ?></strong>
                — <?= e($onsiteLookupPayment['first_name'] . ' ' . $onsiteLookupPayment['last_name']) ?>
                (<?= formatMoney((float) $onsiteLookupPayment['amount']) ?>).
                <?php if (!empty($lookupGate['blocked'])): ?>
                    Online clearance is incomplete (<?= (int) $lookupGate['cleared'] ?>/<?= (int) $lookupGate['total'] ?>). Verification is blocked until clearance is complete.
                <?php else: ?>
                    The review window will open automatically.
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card cashier-payments-list-card">
    <div class="card-header payment-page-header">
        <div>
            <h2>Payment Verification</h2>
            <?php if ($status === 'pending'): ?>
                <p class="text-muted payment-page-subtitle"><?= count($payments) ?> payment<?= count($payments) === 1 ? '' : 's' ?> awaiting your review</p>
            <?php elseif ($status === 'rejected'): ?>
                <p class="text-muted payment-page-subtitle">Review rejection feedback sent to students</p>
            <?php else: ?>
                <p class="text-muted payment-page-subtitle">Search and verify student payments</p>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar payments-filter-bar">
            <input type="text" name="search" placeholder="Search request #, student, or reference..." value="<?= e($search) ?>" aria-label="Search payments">
            <select name="status" aria-label="Filter by status">
                <option value="">All Statuses</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="verified" <?= $status === 'verified' ? 'selected' : '' ?>>Verified</option>
                <option value="rejected" <?= $status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            </select>
            <div class="payments-filter-actions">
                <button type="submit" class="btn btn-outline btn-sm">Filter</button>
                <?php if ($status || $search): ?>
                    <a href="payments.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (empty($payments)): ?>
            <div class="empty-state"><i class="fas fa-receipt"></i><p>No payments found.</p></div>
        <?php else: ?>
            <div class="table-wrap payments-table-wrap">
                <table class="data-table payments-table data-table-responsive">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Student</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                        <?php
                            $receiptExt = $p['receipt_path'] ? attachmentFileExt($p['receipt_path']) : '';
                            $receiptIsImage = $receiptExt && attachmentIsImage($receiptExt);
                            $clearanceGate = $p['status'] === 'pending'
                                ? getPaymentClearanceGate((int) $p['request_id'])
                                : ['required' => false, 'complete' => true, 'blocked' => false, 'cleared' => 0, 'total' => 0, 'message' => null];
                        ?>
                        <tr id="payment-<?= $p['id'] ?>" class="<?= $p['status'] === 'pending' ? 'payment-row-pending' : ($p['status'] === 'rejected' ? 'payment-row-rejected' : '') ?><?= $onsiteLookupPayment && (int) $onsiteLookupPayment['id'] === (int) $p['id'] ? ' payment-row-highlight' : '' ?><?= !empty($clearanceGate['blocked']) ? ' payment-row-clearance-blocked' : '' ?>">
                            <td data-label="Request #"><strong><?= e($p['request_number']) ?></strong></td>
                            <td data-label="Student">
                                <?= e($p['first_name'] . ' ' . $p['last_name']) ?>
                                <br><small class="text-muted"><?= e($p['student_id'] ?? '') ?></small>
                            </td>
                            <td data-label="Method">
                                <?= e(paymentMethodLabel($p['payment_method'])) ?>
                                <?php if (!empty($clearanceGate['required'])): ?>
                                    <br>
                                    <?php if (!empty($clearanceGate['blocked'])): ?>
                                        <small class="payment-clearance-pill is-pending">
                                            Clearance <?= (int) $clearanceGate['cleared'] ?>/<?= (int) $clearanceGate['total'] ?>
                                        </small>
                                    <?php else: ?>
                                        <small class="payment-clearance-pill is-complete">Clearance complete</small>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td data-label="<?= isOnsitePaymentMethod($p['payment_method']) ? 'Payment Code' : 'Reference' ?>"><?= e($p['reference_number'] ?? '—') ?></td>
                            <td data-label="Status"><?= statusBadge($p['status']) ?></td>
                            <td data-label="Submitted"><?= formatDateTime($p['created_at']) ?></td>
                            <td data-label="Actions" class="payment-actions-cell">
                                <?php if ($p['receipt_path'] && !isOnsitePaymentMethod($p['payment_method'])): ?>
                                    <a href="<?= UPLOAD_URL ?>/<?= e($p['receipt_path']) ?>" target="_blank" class="btn btn-sm btn-outline">
                                        <i class="fas fa-file-invoice"></i> Receipt
                                    </a>
                                <?php endif; ?>
                                <?php if ($p['status'] === 'pending'): ?>
                                    <button type="button"
                                        class="btn btn-sm btn-primary payment-review-btn"
                                        data-payment-id="<?= $p['id'] ?>"
                                        data-request-number="<?= e($p['request_number']) ?>"
                                        data-student-name="<?= e($p['first_name'] . ' ' . $p['last_name']) ?>"
                                        data-student-id="<?= e($p['student_id'] ?? '') ?>"
                                        data-method="<?= e(paymentMethodLabel($p['payment_method'])) ?>"
                                        data-amount="<?= e(formatMoney((float)$p['amount'])) ?>"
                                        data-reference="<?= e($p['reference_number'] ?? '—') ?>"
                                        data-is-onsite="<?= isOnsitePaymentMethod($p['payment_method']) ? '1' : '0' ?>"
                                        data-clearance-required="<?= !empty($clearanceGate['required']) ? '1' : '0' ?>"
                                        data-clearance-blocked="<?= !empty($clearanceGate['blocked']) ? '1' : '0' ?>"
                                        data-clearance-progress="<?= !empty($clearanceGate['required']) ? ((int) $clearanceGate['cleared'] . '/' . (int) $clearanceGate['total']) : '' ?>"
                                        data-clearance-message="<?= e($clearanceGate['message'] ?? '') ?>"
                                        data-submitted="<?= e(formatDateTime($p['created_at'])) ?>"
                                        data-receipt-url="<?= (!isOnsitePaymentMethod($p['payment_method']) && $p['receipt_path']) ? e(UPLOAD_URL . '/' . $p['receipt_path']) : '' ?>"
                                        data-receipt-is-image="<?= (!isOnsitePaymentMethod($p['payment_method']) && $receiptIsImage) ? '1' : '0' ?>">
                                        <i class="fas fa-search"></i> Review
                                    </button>
                                <?php endif; ?>
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

<div class="payment-modal" id="paymentReviewModal" aria-hidden="true">
    <div class="payment-modal-overlay" data-close-payment-modal></div>
    <div class="payment-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="paymentModalTitle">
        <div class="payment-modal-step" data-step="review">
            <div class="payment-modal-header">
                <div>
                    <span class="payment-modal-eyebrow">Payment Review</span>
                    <h3 id="paymentModalTitle">Review Payment</h3>
                </div>
                <button type="button" class="payment-modal-close" data-close-payment-modal aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="payment-modal-body">
                <div class="payment-review-grid" data-payment-review-grid>
                    <div class="payment-review-details">
                        <div class="payment-review-item">
                            <span>Request #</span>
                            <strong data-field="request-number">—</strong>
                        </div>
                        <div class="payment-review-item">
                            <span>Student</span>
                            <strong data-field="student-name">—</strong>
                            <small data-field="student-id" class="text-muted"></small>
                        </div>
                        <div class="payment-review-item">
                            <span>Method</span>
                            <strong data-field="method">—</strong>
                        </div>
                        <div class="payment-review-item">
                            <span>Amount</span>
                            <strong class="payment-review-amount" data-field="amount">—</strong>
                        </div>
                        <div class="payment-review-item">
                            <span data-reference-label>Reference</span>
                            <strong data-field="reference">—</strong>
                        </div>
                        <div class="payment-review-item">
                            <span>Submitted</span>
                            <strong data-field="submitted">—</strong>
                        </div>
                    </div>

                    <div class="payment-receipt-panel" data-receipt-panel>
                        <div class="payment-receipt-panel-header">
                            <h4><i class="fas fa-receipt"></i> Payment Receipt</h4>
                            <a href="#" target="_blank" class="btn btn-sm btn-outline payment-receipt-open" hidden>
                                Open Full Size
                            </a>
                        </div>
                        <div class="payment-receipt-preview" data-receipt-preview>
                            <div class="payment-receipt-empty">
                                <i class="fas fa-file-image"></i>
                                <p>No receipt uploaded</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="payment-clearance-alert" data-clearance-alert hidden>
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Online clearance incomplete</strong>
                        <p data-clearance-alert-message>Payment verification is blocked until all offices clear this request.</p>
                    </div>
                </div>

                <div class="payment-verify-fields" data-verify-panel>
                    <h4><i class="fas fa-file-invoice-dollar"></i> Verification Details</h4>
                    <p class="text-muted payment-verify-fields-note" data-verify-fields-note>
                        Enter the OR number and date of payment before verifying.
                    </p>
                    <div class="payment-verify-fields-grid">
                        <div class="form-group">
                            <label for="paymentOrNumber">OR Number *</label>
                            <input type="text"
                                id="paymentOrNumber"
                                name="or_number"
                                form="paymentVerifyForm"
                                maxlength="50"
                                placeholder="e.g. OR-2026-001234"
                                required>
                        </div>
                        <div class="form-group">
                            <label for="paymentDatePaid">Date of Payment *</label>
                            <input type="date"
                                id="paymentDatePaid"
                                name="payment_date"
                                form="paymentVerifyForm"
                                max="<?= date('Y-m-d') ?>"
                                required>
                        </div>
                    </div>
                </div>

                <div class="payment-reject-panel" data-reject-panel hidden>
                    <div class="payment-reject-panel-header">
                        <h4><i class="fas fa-times-circle"></i> Rejection Feedback</h4>
                        <p class="text-muted">This feedback will be sent to the student so they can correct and resubmit.</p>
                    </div>

                    <form method="POST" id="paymentRejectForm" class="payment-reject-form-modal">
                        <?= csrfField() ?>
                        <input type="hidden" name="payment_id" value="" data-payment-id-input>
                        <input type="hidden" name="action" value="reject">

                        <div class="form-group">
                            <label>Quick reasons</label>
                            <div class="reject-reason-chips">
                                <?php foreach ($rejectReasons as $reason): ?>
                                    <button type="button" class="reject-reason-chip" data-reason="<?= e($reason) ?>">
                                        <?= e($reason) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="paymentRejectNotes">Feedback for student *</label>
                            <textarea id="paymentRejectNotes" name="notes" rows="4" maxlength="500" required
                                placeholder="Explain clearly what the student needs to fix before resubmitting payment..."></textarea>
                            <div class="reject-notes-meta">
                                <small class="text-muted">Be specific so the student knows exactly what to correct.</small>
                                <small class="reject-char-count"><span data-char-count>0</span>/500</small>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="payment-modal-footer">
                <button type="button" class="btn btn-outline" data-close-payment-modal>Cancel</button>
                <button type="button" class="btn btn-outline" data-cancel-reject hidden>
                    <i class="fas fa-arrow-left"></i> Back to Verify
                </button>
                <button type="button" class="btn btn-danger" data-reject-action>
                    <i class="fas fa-times-circle"></i>
                    <span data-reject-action-label>Reject Payment</span>
                </button>
                <form method="POST" id="paymentVerifyForm" class="payment-verify-form" data-verify-form>
                    <?= csrfField() ?>
                    <input type="hidden" name="payment_id" value="" data-payment-id-input>
                    <button type="submit" name="action" value="verify" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i> Verify Payment
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($onsiteLookupPayment): ?>
<script>window.__ONSITE_PAYMENT_LOOKUP__ = <?= (int) $onsiteLookupPayment['id'] ?>;</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
