<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/request-items.php';
requireRole('student');
ensurePaymentMethodSchema();
ensureRequestItemsSchema();

$db = getDB();
$user = currentUser();
$requestId = (int)($_GET['request_id'] ?? 0);

$stmt = $db->prepare('SELECT r.* FROM requests r WHERE r.id = ? AND r.user_id = ?');
$stmt->execute([$requestId, $user['id']]);
$request = $stmt->fetch();
$requestItems = $request ? getRequestItems($requestId) : [];

if (!$request) {
    setFlash('error', 'Request not found.');
    redirect(APP_URL . '/student/requests.php');
}

if (!in_array($request['status'], ['requirements_verified', 'payment_verified'], true)) {
    setFlash('warning', 'Payment is available after the Registrar verifies your request requirements.');
    redirect(APP_URL . '/student/request-view.php?id=' . $requestId);
}

$lastPayment = $db->prepare('SELECT * FROM payments WHERE request_id = ? ORDER BY created_at DESC LIMIT 1');
$lastPayment->execute([$requestId]);
$previousPayment = $lastPayment->fetch();
$hasPendingPayment = $previousPayment && $previousPayment['status'] === 'pending';

if ($hasPendingPayment) {
    setFlash('info', 'You already have a payment pending verification.');
    redirect(APP_URL . '/student/request-view.php?id=' . $requestId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $method = $_POST['payment_method'] ?? '';
    $reference = trim($_POST['reference_number'] ?? '');

    $receiptPath = null;
    if (!empty($_FILES['receipt']['name'])) {
        $receiptPath = uploadFile($_FILES['receipt'], 'receipts');
    }

    $validationError = validateStudentPaymentSubmission($method, $reference, $receiptPath);
    if ($validationError) {
        setFlash('error', $validationError);
    } else {
        if (isOnsitePaymentMethod($method)) {
            $reference = generateOnsitePaymentReference();
        }

        $db->prepare('INSERT INTO payments (request_id, amount, payment_method, reference_number, receipt_path) VALUES (?, ?, ?, ?, ?)')
           ->execute([$requestId, $request['total_amount'], $method, $reference ?: null, $receiptPath]);

        sendNotification($user['id'], 'Payment Submitted', 'Your payment for ' . $request['request_number'] . ' is pending verification.', 'info');
        $studentName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        notifyCashiersNewPayment($requestId, $request['request_number'], $studentName !== '' ? $studentName : 'A student');

        if (isOnsitePaymentMethod($method)) {
            sendNotification(
                $user['id'],
                'On-Site Payment Code',
                'Your onsite payment code for ' . $request['request_number'] . ' is ' . $reference . '. Present this at the cashier.',
                'info',
                APP_URL . '/student/request-view.php?id=' . $requestId
            );
            auditLog('payment_submitted', 'payments', $requestId);
            setFlash('success', 'On-site payment registered. Present your 6-digit code at the cashier.', [
                'title' => 'On-Site Payment Code',
                'context' => [
                    'Payment Code' => $reference,
                    'Amount' => formatMoney((float) $request['total_amount']),
                ],
                'next_step' => 'Go to the cashier and give them your payment code so they can locate this request.',
            ]);
        } else {
            auditLog('payment_submitted', 'payments', $requestId);
            setFlash('success', 'Payment submitted! Awaiting verification.');
        }

        redirect(APP_URL . '/student/request-view.php?id=' . $requestId);
    }
}

$pageTitle = 'Payment';
$activeNav = 'requests';
$bankTransferAvailable = bankTransferDetailsConfigured();
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Payment for <?= e($request['request_number']) ?></h2></div>
    <div class="card-body">
        <div class="payment-summary">
            <div class="detail-item"><label>Request #</label><span><?= e($request['request_number']) ?></span></div>
            <div class="detail-item full"><label>Documents</label><span><?= e(formatRequestItemsSummary($requestItems)) ?></span></div>
            <div class="detail-item"><label>Total Amount</label><span class="amount-large"><?= formatMoney((float)$request['total_amount']) ?></span></div>
        </div>

        <?php if ($previousPayment && $previousPayment['status'] === 'rejected' && !empty($previousPayment['notes'])): ?>
            <div class="alert alert-warning">
                <i class="fas fa-comment-dots"></i>
                <strong>Previous payment rejected.</strong> Cashier feedback: <?= e($previousPayment['notes']) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="form-grid" id="studentPaymentForm">
            <?= csrfField() ?>

            <div class="form-group">
                <label>Payment Method *</label>
                <div class="payment-methods">
                    <?php foreach (paymentMethodOptions() as $val => $label): ?>
                        <label class="payment-option">
                            <input type="radio" name="payment_method" value="<?= e($val) ?>" required>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="alert alert-info" id="onsitePaymentInfo" hidden>
                <i class="fas fa-store"></i>
                After you submit, the app will generate a <strong>6-digit payment code</strong>. Present that code at the cashier so they can locate your request and accept payment on-site. No receipt upload is needed.
            </div>

            <div id="bankTransferFields" hidden>
                <?= renderStudentBankTransferDetailsHtml() ?>

                <div class="form-group">
                    <label for="reference_number">Reference / Transaction Number *</label>
                    <input type="text" id="reference_number" name="reference_number" placeholder="Enter payment reference number">
                </div>

                <div class="form-group">
                    <label for="receipt">Upload Payment Receipt *</label>
                    <input type="file" id="receipt" name="receipt" accept=".pdf,.jpg,.jpeg,.png">
                    <small class="text-muted">Required for bank transfer</small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg" id="submitPaymentBtn" disabled>
                <i class="fas fa-check"></i> Submit Payment
            </button>
        </form>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('studentPaymentForm');
    if (!form) return;

    const methodInputs = form.querySelectorAll('input[name="payment_method"]');
    const bankTransferFields = document.getElementById('bankTransferFields');
    const onsiteInfo = document.getElementById('onsitePaymentInfo');
    const referenceInput = document.getElementById('reference_number');
    const receiptInput = document.getElementById('receipt');
    const submitBtn = document.getElementById('submitPaymentBtn');
    const bankTransferAvailable = <?= $bankTransferAvailable ? 'true' : 'false' ?>;

    function selectedMethod() {
        const checked = form.querySelector('input[name="payment_method"]:checked');
        return checked ? checked.value : '';
    }

    function syncPaymentFields() {
        const method = selectedMethod();
        const isOnsite = method === 'onsite_payment';
        const isBankTransfer = method === 'bank_transfer';

        onsiteInfo.hidden = !isOnsite;
        if (bankTransferFields) {
            bankTransferFields.hidden = !isBankTransfer;
        }

        if (referenceInput) {
            referenceInput.required = !isOnsite;
            if (isOnsite) referenceInput.value = '';
        }
        if (receiptInput) {
            receiptInput.required = !isOnsite;
            if (isOnsite) receiptInput.value = '';
        }

        submitBtn.disabled = method === '' || (isBankTransfer && !bankTransferAvailable);
        submitBtn.innerHTML = isOnsite
            ? '<i class="fas fa-barcode"></i> Generate Payment Code'
            : '<i class="fas fa-check"></i> Submit Payment';
    }

    methodInputs.forEach(function (input) {
        input.addEventListener('change', syncPaymentFields);
    });

    syncPaymentFields();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
