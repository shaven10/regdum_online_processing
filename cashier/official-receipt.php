<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/official-receipt.php';
requireRole('cashier', 'admin', 'registrar');

$user = currentUser();
$autoPrint = isset($_GET['print']);
$backUrl = APP_URL . '/cashier/payments.php';

$ids = normalizeOfficialReceiptPaymentIds($_GET['ids'] ?? '');
$singleId = (int) ($_GET['id'] ?? 0);
if ($singleId > 0 && !in_array($singleId, $ids, true)) {
    $ids[] = $singleId;
}

if ($ids === []) {
    setFlash('error', 'No official receipt was selected.');
    redirect($backUrl);
}

$receipt = fetchOfficialReceiptData($ids, $user);
if (!$receipt) {
    setFlash('error', 'Official receipt is available only for verified payments.');
    redirect($backUrl);
}

if (!canViewOfficialReceipt($user, $receipt)) {
    setFlash('error', 'You are not allowed to view this official receipt.');
    redirect(dashboardUrl());
}

renderOfficialReceiptDocument($receipt, $autoPrint, $backUrl, getOfficialReceiptPrintMode((int) ($user['id'] ?? 0)));
exit;
