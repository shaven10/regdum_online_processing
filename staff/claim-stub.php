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
    redirect(APP_URL . '/staff/requests.php');
}

$user = currentUser();
if (!canViewClaimStub($user, $data['request'])) {
    setFlash('error', 'You are not allowed to view this claim stub.');
    redirect(APP_URL . '/staff/requests.php');
}

if (!canPrintClaimStub($data)) {
    setFlash('warning', 'The claim stub can be printed after this request is assigned and a release date is set.');
    redirect(APP_URL . '/staff/process-request.php?id=' . $requestId);
}

renderClaimStubDocument($data, $autoPrint, $autoDownload);
exit;
