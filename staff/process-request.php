<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/request-items.php';
requireRole('staff');

$user = currentUser();
$listUrl = APP_URL . '/staff/requests.php';
$processUrl = APP_URL . '/staff/process-request.php';
$activeNav = 'requests';
$processorLabel = 'Registrar Staff';

$itemId = (int) ($_GET['item_id'] ?? 0);
$requestId = (int) ($_GET['id'] ?? 0);
if ($itemId <= 0 && $requestId > 0) {
    ensureRequestItemsSchema();
    $assignedItems = array_values(array_filter(
        getStaffAssignedItems((int) $user['id']),
        static fn(array $row): bool => (int) ($row['request_id'] ?? 0) === $requestId
    ));
    if ($assignedItems !== []) {
        redirect($processUrl . '?item_id=' . (int) $assignedItems[0]['id']);
    }
}

require_once __DIR__ . '/../includes/process-assigned-document.php';
