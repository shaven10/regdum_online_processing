<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/claim-stub.php';
requireRole('cashier');

$user = currentUser();
$autoPrint = isset($_GET['print']);
$autoDownload = $_GET['download'] ?? '';
$backUrl = APP_URL . '/cashier/payments.php';
$batchIds = normalizeClaimStubRequestIds($_GET['ids'] ?? '');
$singleId = (int) ($_GET['id'] ?? 0);
if ($singleId > 0 && !in_array($singleId, $batchIds, true)) {
    $batchIds[] = $singleId;
}

if ($batchIds === []) {
    setFlash('error', 'No claim slip was selected.');
    redirect($backUrl);
}

$slips = fetchClaimStubsForRequests($batchIds, $user);
if ($slips === []) {
    setFlash('error', 'No printable claim slip was found. Verify the payment first.');
    redirect($backUrl);
}

$options = [
    'back_url' => $backUrl,
    'back_label' => 'Back to Payments',
    'related_ids' => array_map(static fn(array $slip): int => (int) ($slip['request']['id'] ?? 0), $slips),
];

$layout = (string) ($_GET['layout'] ?? '');
if (count($slips) > 1 && $layout !== 'separate') {
    renderClaimStubCombinedDocument($slips, $autoPrint, $options);
    exit;
}

if (count($slips) > 1) {
    renderClaimStubBatchDocument($slips, $autoPrint, $options);
    exit;
}

renderClaimStubDocument($slips[0], $autoPrint, $autoDownload, $options);
exit;
