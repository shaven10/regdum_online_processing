<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/onsite-request.php';
requireRole('registrar');

$user = currentUser();
$autoPrint = isset($_GET['print']);
$batchIds = normalizeOnsiteRequestIdList($_GET['ids'] ?? '');

if ($batchIds !== []) {
    $slips = fetchOnsiteRequestSlipBatch($batchIds, $user);
    $slips = array_values(array_filter($slips, static function (array $slip): bool {
        return !empty($slip['payment_code']);
    }));

    if ($slips === []) {
        setFlash('error', 'No printable onsite request slips were found for that batch.');
        redirect(APP_URL . '/registrar/onsite-requests.php');
    }

    $layout = (string) ($_GET['layout'] ?? 'separate');
    if ($layout === 'combined') {
        renderOnsiteRequestCombinedSlipDocument($slips, $autoPrint);
        exit;
    }

    renderOnsiteRequestSlipBatchDocument($slips, $autoPrint);
    exit;
}

$requestId = (int) ($_GET['id'] ?? 0);
$data = fetchOnsiteRequestSlipData($requestId);

if (!$data) {
    setFlash('error', 'Onsite request not found.');
    redirect(APP_URL . '/registrar/new-onsite-request.php');
}

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
