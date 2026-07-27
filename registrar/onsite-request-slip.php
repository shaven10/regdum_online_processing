<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/onsite-request.php';
requireRole('registrar');

$requestId = (int) ($_GET['id'] ?? 0);
$autoPrint = isset($_GET['print']);
$data = fetchOnsiteRequestSlipData($requestId);

if (!$data) {
    setFlash('error', 'Onsite request not found.');
    redirect(APP_URL . '/registrar/new-onsite-request.php');
}

$user = currentUser();
if (!canViewOnsiteRequestSlip($user, $data['request'])) {
    setFlash('error', 'You are not allowed to view this request slip.');
    redirect(dashboardUrl());
}

if (($data['request']['request_channel'] ?? '') !== 'onsite') {
    setFlash('warning', 'This request was not created as an onsite walk-in.');
    redirect(APP_URL . '/registrar/verify-request.php?id=' . $requestId);
}

if (empty($data['payment_code'])) {
    setFlash('warning', 'No cashier payment code is available for this request yet.');
    redirect(APP_URL . '/registrar/verify-request.php?id=' . $requestId);
}

renderOnsiteRequestSlipDocument($data, $autoPrint);
exit;
