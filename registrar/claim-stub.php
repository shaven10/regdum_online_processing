<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/claim-stub.php';
requireLogin();

$requestId = (int) ($_GET['id'] ?? 0);
$autoPrint = isset($_GET['print']);
$autoDownload = $_GET['download'] ?? '';
$data = fetchClaimStubData($requestId);

if (!$data) {
    setFlash('error', 'Request not found.');
    redirect(dashboardUrl());
}

$user = currentUser();
if (!canViewClaimStub($user, $data['request'])) {
    setFlash('error', 'You are not allowed to view this claim stub.');
    redirect(dashboardUrl());
}

if (!in_array($data['request']['status'], ['processing', 'ready_for_pickup', 'shipped', 'completed'], true)) {
    setFlash('warning', 'Claim stub is available after the request enters processing.');
    redirect(APP_URL . '/registrar/verify-request.php?id=' . $requestId);
}

renderClaimStubDocument($data, $autoPrint, $autoDownload);
exit;
