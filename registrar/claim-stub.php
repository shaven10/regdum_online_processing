<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/claim-stub.php';
requireLogin();

$user = currentUser();
if (!hasRole('registrar', 'admin')) {
    setFlash('error', 'You are not allowed to view this claim slip.');
    redirect(dashboardUrl());
}

$autoPrint = isset($_GET['print']);
$autoDownload = $_GET['download'] ?? '';
$batchIds = normalizeClaimStubRequestIds($_GET['ids'] ?? '');
$singleId = (int) ($_GET['id'] ?? 0);
if ($singleId > 0 && !in_array($singleId, $batchIds, true)) {
    $batchIds[] = $singleId;
}

$fallbackId = (int) ($batchIds[0] ?? 0);
$backUrl = $fallbackId > 0
    ? APP_URL . '/registrar/verify-request.php?id=' . $fallbackId
    : APP_URL . '/registrar/reports.php';

if ($batchIds === []) {
    setFlash('error', 'No claim slip was selected.');
    redirect($backUrl);
}

$slips = fetchClaimStubsForRequests($batchIds, $user);
if ($slips === []) {
    setFlash('warning', 'Claim slip is available after the request is assigned and a release date is set.');
    redirect($backUrl);
}

$relatedIds = claimStubRelatedRequestIds($slips[0]['request'] ?? []);
if ($relatedIds === []) {
    $relatedIds = array_map(static fn(array $slip): int => (int) ($slip['request']['id'] ?? 0), $slips);
}

$options = [
    'back_url' => $backUrl,
    'back_label' => 'Back to Request',
    'audience' => 'registrar',
    'related_ids' => $relatedIds,
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
