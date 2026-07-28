<?php

function ensurePaymentVerificationSchema(): void {
    $db = getDB();

    $orCol = $db->query("SHOW COLUMNS FROM payments LIKE 'or_number'")->fetch();
    if (!$orCol) {
        $db->exec('ALTER TABLE payments ADD COLUMN or_number VARCHAR(50) NULL AFTER reference_number');
    }

    $dateCol = $db->query("SHOW COLUMNS FROM payments LIKE 'payment_date'")->fetch();
    if (!$dateCol) {
        $db->exec('ALTER TABLE payments ADD COLUMN payment_date DATE NULL AFTER or_number');
    }

    ensurePaymentMethodSchema();
}

function ensurePaymentMethodSchema(): void {
    $db = getDB();
    $column = $db->query("SHOW COLUMNS FROM payments LIKE 'payment_method'")->fetch();
    if ($column && stripos((string) $column['Type'], 'enum') !== false) {
        $db->exec("ALTER TABLE payments MODIFY payment_method VARCHAR(30) NOT NULL");
    }

    migratePaymentMethodsFaqText();
}

function migratePaymentMethodsFaqText(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db = getDB();
    $table = $db->query("SHOW TABLES LIKE 'faqs'")->fetch();
    if (!$table) {
        return;
    }

    $db->prepare("UPDATE faqs
        SET answer = ?
        WHERE question = ?
          AND answer LIKE ?")
       ->execute([
           'We accept bank transfer and on-site payment at the cashier. For on-site payment, the app generates a 6-digit reference code for the cashier to locate your request.',
           'What payment methods are accepted?',
           '%GCash%',
       ]);
}

function paymentMethodOptions(): array {
    return [
        'bank_transfer'  => 'Bank Transfer',
        'onsite_payment' => 'On-Site Payment',
    ];
}

function paymentMethodLabel(?string $method): string {
    $legacyLabels = [
        'gcash' => 'GCash',
    ];

    return paymentMethodOptions()[$method ?? '']
        ?? $legacyLabels[$method ?? '']
        ?? ucwords(str_replace('_', ' ', (string) $method));
}

function isOnsitePaymentMethod(?string $method): bool {
    return ($method ?? '') === 'onsite_payment';
}

function isBankTransferPaymentMethod(?string $method): bool {
    return ($method ?? '') === 'bank_transfer';
}

function ensurePaymentSettingsAvailable(): void {
    if (!function_exists('getAppSetting')) {
        require_once __DIR__ . '/compliance.php';
    }
}

function defaultBankTransferDetails(): array {
    return [
        'bank_name' => '',
        'account_name' => '',
        'account_number' => '',
        'branch' => '',
        'instructions' => '',
    ];
}

function getBankTransferDetails(): array {
    ensurePaymentSettingsAvailable();
    $raw = getAppSetting('bank_transfer_details', '');
    if ($raw === '') {
        return defaultBankTransferDetails();
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return defaultBankTransferDetails();
    }

    return array_merge(defaultBankTransferDetails(), array_intersect_key($decoded, defaultBankTransferDetails()));
}

function bankTransferDetailsConfigured(): bool {
    $details = getBankTransferDetails();

    return trim((string) ($details['bank_name'] ?? '')) !== ''
        && trim((string) ($details['account_name'] ?? '')) !== ''
        && trim((string) ($details['account_number'] ?? '')) !== '';
}

function validateBankTransferDetailsInput(array $input): array {
    $details = [
        'bank_name' => trim((string) ($input['bank_name'] ?? '')),
        'account_name' => trim((string) ($input['account_name'] ?? '')),
        'account_number' => trim((string) ($input['account_number'] ?? '')),
        'branch' => trim((string) ($input['branch'] ?? '')),
        'instructions' => trim((string) ($input['instructions'] ?? '')),
    ];
    $errors = [];

    if ($details['bank_name'] === '') {
        $errors[] = 'Bank name is required.';
    }
    if ($details['account_name'] === '') {
        $errors[] = 'Account name is required.';
    }
    if ($details['account_number'] === '') {
        $errors[] = 'Account number is required.';
    }
    if (strlen($details['bank_name']) > 120) {
        $errors[] = 'Bank name must be 120 characters or less.';
    }
    if (strlen($details['account_name']) > 120) {
        $errors[] = 'Account name must be 120 characters or less.';
    }
    if (strlen($details['account_number']) > 60) {
        $errors[] = 'Account number must be 60 characters or less.';
    }
    if (strlen($details['branch']) > 120) {
        $errors[] = 'Branch must be 120 characters or less.';
    }
    if (strlen($details['instructions']) > 500) {
        $errors[] = 'Instructions must be 500 characters or less.';
    }

    return [$details, $errors];
}

function saveBankTransferDetails(array $details): void {
    ensurePaymentSettingsAvailable();
    setAppSetting(
        'bank_transfer_details',
        json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function renderStudentBankTransferDetailsHtml(): string {
    if (!bankTransferDetailsConfigured()) {
        return '<div class="alert alert-warning bank-transfer-details-alert">'
            . '<i class="fas fa-exclamation-triangle"></i> '
            . 'Bank transfer details are not available yet. Please contact the cashier office before sending payment.'
            . '</div>';
    }

    $details = getBankTransferDetails();
    $html = '<div class="bank-transfer-details">';
    $html .= '<h4><i class="fas fa-university"></i> Bank Transfer Details</h4>';
    $html .= '<dl class="bank-transfer-details-list">';
    $html .= '<div><dt>Bank</dt><dd>' . e($details['bank_name']) . '</dd></div>';
    $html .= '<div><dt>Account Name</dt><dd>' . e($details['account_name']) . '</dd></div>';
    $html .= '<div><dt>Account Number</dt><dd><strong>' . e($details['account_number']) . '</strong></dd></div>';

    if ($details['branch'] !== '') {
        $html .= '<div><dt>Branch</dt><dd>' . e($details['branch']) . '</dd></div>';
    }

    $html .= '</dl>';

    if ($details['instructions'] !== '') {
        $html .= '<p class="bank-transfer-details-note">' . nl2br(e($details['instructions'])) . '</p>';
    }

    $html .= '<p class="text-muted bank-transfer-details-help">Transfer the exact amount shown above, then enter your reference number and upload the receipt.</p>';
    $html .= '</div>';

    return $html;
}

function generateOnsitePaymentReference(): string {
    $db = getDB();

    for ($attempt = 0; $attempt < 25; $attempt++) {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $db->prepare("SELECT id FROM payments
            WHERE payment_method = 'onsite_payment' AND reference_number = ? AND status = 'pending'
            LIMIT 1");
        $stmt->execute([$code]);
        if (!$stmt->fetch()) {
            return $code;
        }
    }

    throw new RuntimeException('Unable to generate onsite payment reference.');
}

function validateStudentPaymentSubmission(string $method, ?string $referenceNumber, ?string $receiptPath): ?string {
    if (!array_key_exists($method, paymentMethodOptions())) {
        return 'Please select a valid payment method.';
    }

    if (isOnsitePaymentMethod($method)) {
        return null;
    }

    if (isBankTransferPaymentMethod($method) && !bankTransferDetailsConfigured()) {
        return 'Bank transfer details are not available yet. Please contact the cashier office or choose on-site payment.';
    }

    if (trim((string) $referenceNumber) === '') {
        return 'Please enter your payment reference or transaction number.';
    }

    if (!$receiptPath) {
        return 'Please upload your payment receipt for verification.';
    }

    return null;
}

function findPaymentByOnsiteReference(string $code): ?array {
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT p.*, r.request_number, r.total_amount as request_amount, r.status as request_status,
            u.first_name, u.last_name, u.student_id, u.email,
            v.first_name as verifier_first, v.last_name as verifier_last
        FROM payments p
        JOIN requests r ON p.request_id = r.id
        JOIN users u ON r.user_id = u.id
        LEFT JOIN users v ON p.verified_by = v.id
        WHERE p.payment_method = ? AND p.reference_number = ? AND p.status = ?
        ORDER BY p.created_at DESC
        LIMIT 1');
    $stmt->execute(['onsite_payment', $code, 'pending']);
    $payment = $stmt->fetch();

    return $payment ?: null;
}

function validatePaymentVerificationFields(?string $orNumber, ?string $paymentDate): ?string {
    $orNumber = trim((string) $orNumber);
    $paymentDate = trim((string) $paymentDate);

    if ($orNumber === '') {
        return 'OR number is required to verify payment.';
    }
    if (strlen($orNumber) > 50) {
        return 'OR number must be 50 characters or less.';
    }
    if ($paymentDate === '') {
        return 'Date of payment is required to verify payment.';
    }
    $timestamp = strtotime($paymentDate);
    if ($timestamp === false) {
        return 'Please enter a valid date of payment.';
    }
    if ($timestamp > strtotime('today 23:59:59')) {
        return 'Date of payment cannot be in the future.';
    }

    return null;
}

/**
 * When online clearance is assigned and incomplete, block cashier payment verification.
 *
 * @return array{required:bool,complete:bool,blocked:bool,cleared:int,total:int,message:?string}
 */
function getPaymentClearanceGate(int $requestId): array {
    require_once __DIR__ . '/compliance.php';
    require_once __DIR__ . '/clearance.php';

    $required = hasAssignedRequirement($requestId, 'online_clearance');
    if (!$required) {
        return [
            'required' => false,
            'complete' => true,
            'blocked' => false,
            'cleared' => 0,
            'total' => 0,
            'message' => null,
        ];
    }

    $progress = getClearanceProgress($requestId);
    $complete = isClearanceComplete($requestId);
    // No clearance office rows means nothing to wait on — do not block payment.
    if ((int) $progress['total'] <= 0) {
        return [
            'required' => false,
            'complete' => true,
            'blocked' => false,
            'cleared' => 0,
            'total' => 0,
            'message' => null,
        ];
    }

    $message = $complete
        ? null
        : 'Online clearance is incomplete (' . (int) $progress['cleared'] . '/' . (int) $progress['total']
            . ' offices cleared). Payment can be verified only after all offices clear.';

    return [
        'required' => true,
        'complete' => $complete,
        'blocked' => !$complete,
        'cleared' => (int) $progress['cleared'],
        'total' => (int) $progress['total'],
        'message' => $message,
    ];
}

function paymentVerificationBlockedByClearance(int $requestId): ?string {
    $gate = getPaymentClearanceGate($requestId);
    return $gate['blocked'] ? $gate['message'] : null;
}

function paymentVerificationItemHasStamp(array $item): bool {
    return !empty($item['requires_documentary_stamp']);
}

function paymentVerificationFeeDetail(array $item, array $authItems = [], bool $includeStamp = true): string {
    if (!function_exists('documentStampFeeAmount')) {
        require_once __DIR__ . '/document-rules.php';
    }

    $base = (float) ($item['base_fee'] ?? 0);
    $copies = max(1, (int) ($item['copies'] ?? 1));
    $feePerSet = !empty($item['fee_per_set']);
    $stampFee = paymentVerificationItemHasStamp($item) ? documentStampFeeAmount() : 0.0;
    $requiresAuth = !empty($item['requires_auth_document_type']);

    if ($requiresAuth && !empty($authItems)) {
        $parts = [];
        foreach ($authItems as $authItem) {
            $sets = max(1, (int) ($authItem['sets'] ?? 1));
            $label = authenticationDocumentTypeLabel($authItem['auth_document_type'] ?? null);
            $parts[] = $label . ': ' . $sets . ' set(s) × ' . formatMoney($base);
        }
        $detail = implode(' · ', $parts);
        if ($includeStamp && $stampFee > 0) {
            $detail .= ' + documentary stamp ' . formatMoney($stampFee);
        }
        return $detail;
    }

    $feeParts = $feePerSet
        ? ['1 set × ' . formatMoney($base)]
        : [$copies . ' × ' . formatMoney($base)];

    if ($includeStamp && $stampFee > 0) {
        $feeParts[] = 'documentary stamp ' . formatMoney($stampFee);
    }

    return implode(' + ', $feeParts);
}

function paymentVerificationBreakdownDetail(array $item, array $authItems = [], bool $includeStamp = true): string {
    if (!function_exists('semesterLabel')) {
        require_once __DIR__ . '/student.php';
    }

    $parts = [];

    if (!empty($item['request_school_year']) || !empty($item['request_semester'])) {
        $term = trim(
            (string) ($item['request_school_year'] ?? '')
            . (!empty($item['request_semester']) ? ' · ' . semesterLabel($item['request_semester']) : '')
        );
        if ($term !== '') {
            $parts[] = $term;
        }
    }

    $parts[] = paymentVerificationFeeDetail($item, $authItems, $includeStamp);
    return implode(' · ', $parts);
}

function loadPaymentVerificationAuthItems(array $requestIds, array $itemIds): array {
    $authByItemId = [];
    if (empty($itemIds) && empty($requestIds)) {
        return $authByItemId;
    }

    $db = getDB();

    if (!empty($itemIds)) {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $stmt = $db->prepare(
            "SELECT request_item_id, auth_document_type, sets
             FROM request_authentication_items
             WHERE request_item_id IN ($placeholders)
             ORDER BY request_item_id, id"
        );
        $stmt->execute($itemIds);
        foreach ($stmt->fetchAll() as $row) {
            $itemId = (int) ($row['request_item_id'] ?? 0);
            if ($itemId > 0) {
                $authByItemId[$itemId][] = $row;
            }
        }
    }

    if (!empty($requestIds)) {
        $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $stmt = $db->prepare(
            "SELECT request_id, auth_document_type, sets
             FROM request_authentication_items
             WHERE request_id IN ($placeholders) AND request_item_id IS NULL
             ORDER BY request_id, id"
        );
        $stmt->execute($requestIds);
        foreach ($stmt->fetchAll() as $row) {
            $requestId = (int) ($row['request_id'] ?? 0);
            if ($requestId > 0) {
                $authByItemId['request:' . $requestId][] = $row;
            }
        }
    }

    return $authByItemId;
}

function buildPaymentVerificationDetailsMap(array $requestIds): array {
    $requestIds = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
    if (empty($requestIds)) {
        return [];
    }

    require_once __DIR__ . '/request-items.php';
    require_once __DIR__ . '/student.php';

    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
    $requestStmt = $db->prepare(
        "SELECT r.id, r.purpose, r.purpose_other, r.copy_request_type, r.delivery_method, r.total_amount, r.request_channel,
                dt.name AS document_name
         FROM requests r
         LEFT JOIN document_types dt ON r.document_type_id = dt.id
         WHERE r.id IN ($placeholders)"
    );
    $requestStmt->execute($requestIds);
    $requests = [];
    foreach ($requestStmt->fetchAll() as $row) {
        $requests[(int) $row['id']] = $row;
    }

    $itemStmt = $db->prepare(
        "SELECT ri.*, dt.name AS document_name, dt.base_fee, dt.fee_per_set,
                dt.requires_documentary_stamp, dt.requires_auth_document_type
         FROM request_items ri
         JOIN document_types dt ON ri.document_type_id = dt.id
         WHERE ri.request_id IN ($placeholders)
         ORDER BY ri.request_id, ri.sort_order, ri.id"
    );
    $itemStmt->execute($requestIds);
    $itemsByRequest = [];
    $itemIds = [];
    foreach ($itemStmt->fetchAll() as $row) {
        $itemsByRequest[(int) $row['request_id']][] = $row;
        $itemIds[] = (int) $row['id'];
    }

    $authByItemId = loadPaymentVerificationAuthItems($requestIds, $itemIds);

    $map = [];
    foreach ($requestIds as $requestId) {
        $request = $requests[$requestId] ?? null;
        if (!$request) {
            continue;
        }

        $items = $itemsByRequest[$requestId] ?? [];
        foreach ($items as $index => $item) {
            $itemId = (int) ($item['id'] ?? 0);
            $items[$index]['auth_items'] = $authByItemId[$itemId] ?? (
                count($items) === 1 ? ($authByItemId['request:' . $requestId] ?? []) : []
            );
        }

        $map[$requestId] = [
            'request' => $request,
            'items' => $items,
        ];
    }

    return $map;
}

function buildPaymentVerificationDetailsForPayments(array $payments): array {
    $contextMap = buildPaymentVerificationDetailsMap(
        array_map(static fn(array $payment): int => (int) $payment['request_id'], $payments)
    );

    $details = [];
    foreach ($payments as $payment) {
        $requestId = (int) $payment['request_id'];
        $context = $contextMap[$requestId] ?? null;
        if (!$context) {
            continue;
        }

        $details[(int) $payment['id']] = renderPaymentVerificationSections(
            $context['request'],
            $context['items'],
            (float) ($payment['amount'] ?? 0)
        );
    }

    return $details;
}

function renderPaymentVerificationSections(array $request, array $items, float $paymentAmount = 0.0): string {
    require_once __DIR__ . '/student.php';
    require_once __DIR__ . '/document-rules.php';

    $purposeText = purposeLabel((string) ($request['purpose'] ?? ''));
    if (!empty($request['purpose_other'])) {
        $purposeText .= ' — ' . $request['purpose_other'];
    }

    $requestTotal = (float) ($request['total_amount'] ?? 0);
    $submittedAmount = $paymentAmount > 0 ? $paymentAmount : $requestTotal;
    $amountMismatch = $paymentAmount > 0 && abs($paymentAmount - $requestTotal) > 0.009;

    ob_start();
    ?>
    <div class="payment-verification-request">
        <section class="payment-verification-summary">
            <h5><i class="fas fa-file-invoice"></i> Documents & Payment Breakdown</h5>
            <div class="detail-grid payment-verification-doc-grid">
                <div class="detail-item full">
                    <label>Purpose</label>
                    <span><?= e($purposeText !== '' ? $purposeText : '—') ?></span>
                </div>
                <div class="detail-item">
                    <label>Request Type</label>
                    <span><?= e(copyRequestTypeLabel($request['copy_request_type'] ?? null)) ?></span>
                </div>
                <?php if (!empty($request['delivery_method'])): ?>
                <div class="detail-item">
                    <label>Delivery</label>
                    <span><?= e(deliveryMethodLabel($request['delivery_method'])) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="payment-breakdown-panel">
                <?php if (!empty($items)): ?>
                    <div class="payment-breakdown-list">
                        <?php foreach ($items as $item): ?>
                        <?php
                            $authItems = $item['auth_items'] ?? [];
                            $hasStamp = paymentVerificationItemHasStamp($item);
                            $stampFee = $hasStamp ? documentStampFeeAmount() : 0.0;
                            $lineTotal = (float) ($item['item_amount'] ?? 0);
                            $documentFee = max(0, $lineTotal - $stampFee);
                        ?>
                        <div class="payment-breakdown-item<?= $hasStamp ? ' has-documentary-stamp' : '' ?>">
                            <div class="payment-breakdown-item-main">
                                <div class="payment-breakdown-item-title">
                                    <strong><?= e($item['document_name'] ?? 'Document') ?></strong>
                                    <?php if ($hasStamp): ?>
                                        <span class="payment-verification-stamp-badge">Documentary stamp</span>
                                    <?php endif; ?>
                                </div>
                                <span class="payment-breakdown-detail"><?= e(paymentVerificationBreakdownDetail($item, $authItems, !$hasStamp)) ?></span>
                            </div>
                            <span class="payment-breakdown-amount"><?= e(formatMoney($hasStamp ? $documentFee : $lineTotal)) ?></span>
                        </div>
                        <?php if ($hasStamp): ?>
                        <div class="payment-breakdown-item payment-breakdown-item-stamp">
                            <div class="payment-breakdown-item-main">
                                <strong>Documentary stamp</strong>
                                <span class="payment-breakdown-detail"><?= e($item['document_name'] ?? 'Document') ?></span>
                            </div>
                            <span class="payment-breakdown-amount"><?= e(formatMoney($stampFee)) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (!empty($request['document_name'])): ?>
                    <p class="payment-breakdown-empty"><?= e($request['document_name']) ?></p>
                <?php else: ?>
                    <p class="payment-breakdown-empty">No document details recorded.</p>
                <?php endif; ?>

                <div class="payment-breakdown-total">
                    <div>
                        <span class="payment-breakdown-total-label">Amount due</span>
                        <strong><?= e(formatMoney($requestTotal)) ?></strong>
                    </div>
                    <?php if ($amountMismatch): ?>
                    <div class="payment-verification-submitted-amount">
                        <span class="payment-breakdown-total-label">Submitted amount</span>
                        <strong class="payment-verification-amount-mismatch"><?= e(formatMoney($submittedAmount)) ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
    <?php
    return (string) ob_get_clean();
}

function getPaymentStats(): array {
    $db = getDB();
    return [
        'pending'   => (int) $db->query("SELECT COUNT(*) FROM payments WHERE status = 'pending'")->fetchColumn(),
        'verified'  => (int) $db->query("SELECT COUNT(*) FROM payments WHERE status = 'verified'")->fetchColumn(),
        'rejected'  => (int) $db->query("SELECT COUNT(*) FROM payments WHERE status = 'rejected'")->fetchColumn(),
        'today'     => (int) $db->query("SELECT COUNT(*) FROM payments WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
        'verified_today' => (int) $db->query("SELECT COUNT(*) FROM payments WHERE status = 'verified' AND DATE(verified_at) = CURDATE()")->fetchColumn(),
        'pending_amount' => (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'pending'")->fetchColumn(),
        'verified_today_amount' => (float) $db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'verified' AND DATE(verified_at) = CURDATE()")->fetchColumn(),
    ];
}

function getPaymentsList(string $status = '', string $search = ''): array {
    $db = getDB();
    $where = ['1=1'];
    $params = [];

    if ($status) {
        $where[] = 'p.status = ?';
        $params[] = $status;
    }
    if ($search) {
        $where[] = '(r.request_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR p.reference_number LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
    }

    $sql = 'SELECT p.*, r.request_number, r.total_amount as request_amount, r.status as request_status,
                   r.request_channel,
                   u.first_name, u.last_name, u.student_id, u.email,
                   v.first_name as verifier_first, v.last_name as verifier_last
            FROM payments p
            JOIN requests r ON p.request_id = r.id
            JOIN users u ON r.user_id = u.id
            LEFT JOIN users v ON p.verified_by = v.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.created_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function processPaymentAction(int $paymentId, string $action, int $verifierId, string $verifierRole, string $notes = '', ?string $orNumber = null, ?string $paymentDate = null): bool {
    if (!in_array($action, ['verify', 'reject'], true)) {
        return false;
    }

    $notes = trim($notes);
    if ($action === 'reject' && $notes === '') {
        return false;
    }

    if ($action === 'verify') {
        $validationError = validatePaymentVerificationFields($orNumber, $paymentDate);
        if ($validationError) {
            return false;
        }
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT p.*, r.request_number, r.user_id, r.request_channel, r.status as request_status
        FROM payments p
        JOIN requests r ON p.request_id = r.id
        WHERE p.id = ?');
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();

    if (!$payment || $payment['status'] !== 'pending') {
        return false;
    }

    if ($action === 'verify') {
        $clearanceBlock = paymentVerificationBlockedByClearance((int) $payment['request_id']);
        if ($clearanceBlock !== null) {
            return false;
        }
    }

    $status = $action === 'verify' ? 'verified' : 'rejected';
    $storedOrNumber = $action === 'verify' ? trim((string) $orNumber) : null;
    $storedPaymentDate = $action === 'verify' ? date('Y-m-d', strtotime((string) $paymentDate)) : null;

    $db->prepare('UPDATE payments SET status = ?, verified_by = ?, verified_at = NOW(), notes = ?, or_number = ?, payment_date = ? WHERE id = ?')
       ->execute([$status, $verifierId, $notes ?: null, $storedOrNumber, $storedPaymentDate, $paymentId]);

    $roleLabel = ucfirst($verifierRole);
    if ($action === 'verify') {
        updateRequestStatus($payment['request_id'], 'payment_verified', "Payment verified by $roleLabel");
        require_once __DIR__ . '/request-items.php';
        prepareRequestItemsAfterPayment((int) $payment['request_id']);
        sendNotification(
            $payment['user_id'],
            'Payment Verified',
            'Your payment for ' . $payment['request_number'] . ' has been verified. The Registrar will assign your request for processing.',
            'success',
            APP_URL . '/student/request-view.php?id=' . $payment['request_id']
        );

        $db = getDB();
        $registrarRoleId = $db->query("SELECT id FROM roles WHERE name = 'registrar'")->fetchColumn();
        if ($registrarRoleId) {
            $registrars = $db->prepare('SELECT id FROM users WHERE role_id = ? AND is_active = 1');
            $registrars->execute([$registrarRoleId]);
            foreach ($registrars->fetchAll() as $reg) {
                sendNotification(
                    (int) $reg['id'],
                    'Payment Verified — Assign Processing',
                    'Payment for ' . $payment['request_number'] . ' is verified. Assign personnel and set release date.',
                    'info',
                    APP_URL . '/registrar/verify-request.php?id=' . $payment['request_id']
                );
            }
        }
    } else {
        sendNotification(
            $payment['user_id'],
            'Payment Rejected',
            'Your payment for ' . $payment['request_number'] . ' was rejected. Feedback: ' . $notes . ' Please review and resubmit.',
            'error',
            APP_URL . '/student/payment.php?request_id=' . $payment['request_id']
        );
    }

    auditLog('payment_' . $action, 'payments', $paymentId);
    return true;
}

function ensureCashierRole(): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM roles WHERE name = 'cashier'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $db->exec("INSERT INTO roles (name, description) VALUES ('cashier', 'Payment Verification Cashier')");
    }
}

function ensureDefaultCashierUser(): void {
    $db = getDB();
    ensureCashierRole();

    $stmt = $db->prepare("SELECT id FROM users WHERE email = 'cashier@regdum.edu.ph'");
    $stmt->execute();
    if ($stmt->fetch()) {
        return;
    }

    $roleId = $db->query("SELECT id FROM roles WHERE name = 'cashier'")->fetchColumn();
    if (!$roleId) {
        return;
    }

    $hash = password_hash('Cashier@123', PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (?, ?, ?, ?, ?, 1, 1)')
       ->execute([$roleId, 'cashier@regdum.edu.ph', $hash, 'Payment', 'Cashier']);
}
