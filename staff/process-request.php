<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/request-items.php';
requireRole('staff');

$user = currentUser();
ensureRequestItemsSchema();

$itemId = (int) ($_GET['item_id'] ?? 0);
$requestId = (int) ($_GET['id'] ?? 0);

if ($itemId) {
    $item = getRequestItem($itemId);
    if (!$item || (int) $item['assigned_to'] !== (int) $user['id']) {
        setFlash('error', 'Assignment not found or not assigned to you.');
        redirect(APP_URL . '/staff/requests.php');
    }
    $request = $item;
    $requestId = (int) $item['request_id'];
} else {
    $assignedItems = getStaffAssignedItems((int) $user['id']);
    if ($requestId) {
        $assignedItems = array_values(array_filter($assignedItems, static fn($row) => (int) $row['request_id'] === $requestId));
    }
    if (count($assignedItems) === 1) {
        redirect(APP_URL . '/staff/process-request.php?item_id=' . (int) $assignedItems[0]['id']);
    }
    setFlash('info', 'Select a document assignment to process.');
    redirect(APP_URL . '/staff/requests.php');
}

$context = loadAssignmentRequestContext($requestId);
$requestHeader = $context['request'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'ready') {
        if (updateRequestItemStatus($itemId, 'ready_for_pickup')) {
            setFlash('success', 'Document marked ready for pickup.', [
                'title' => 'Ready for Pickup',
                'context' => [
                    'Request' => $request['request_number'],
                    'Document' => $request['document_name'],
                ],
            ]);
        }
    } elseif ($action === 'complete') {
        if (updateRequestItemStatus($itemId, 'completed')) {
            setFlash('success', 'Document released to student.', [
                'title' => 'Document Completed',
                'context' => [
                    'Request' => $request['request_number'],
                    'Document' => $request['document_name'],
                ],
            ]);
        }
    } elseif ($action === 'notify') {
        $message = trim($_POST['message'] ?? '');
        if ($message) {
            sendNotification((int) $requestHeader['user_id'], 'Message from Registrar Staff', $message, 'info', APP_URL . '/student/request-view.php?id=' . $requestId);
            setFlash('success', 'Notification sent to student.');
        }
    }

    redirect(APP_URL . '/staff/process-request.php?item_id=' . $itemId);
}

$pageTitle = 'Process ' . $request['request_number'] . ' — ' . $request['document_name'];
$activeNav = 'requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="grid-2 assignment-process-layout">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Request Details</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    <?= e($request['request_number']) ?> · <?= e($request['document_name']) ?>
                </p>
            </div>
            <?= requestItemStatusBadge($request['item_status']) ?>
        </div>
        <div class="card-body">
            <?= renderAssignmentRequestDetailsHtml($context, $request) ?>
        </div>
    </div>

    <div class="card assignment-actions-card">
        <div class="card-header"><h2>Processing Actions</h2></div>
        <div class="card-body">
            <div class="action-buttons">
                <?php if ($request['item_status'] === 'processing'): ?>
                    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="ready"><button class="btn btn-primary">Mark Ready for Pickup</button></form>
                <?php elseif ($request['item_status'] === 'ready_for_pickup'): ?>
                    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="complete"><button class="btn btn-primary">Mark Completed</button></form>
                <?php elseif ($request['item_status'] === 'completed'): ?>
                    <p class="text-muted">This document has been released.</p>
                <?php else: ?>
                    <p class="text-muted">Awaiting registrar assignment.</p>
                <?php endif; ?>
            </div>

            <hr>
            <form method="POST" class="action-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="notify">
                <div class="form-group">
                    <label>Message to Student</label>
                    <textarea name="message" rows="3" placeholder="Optional update about this document..."></textarea>
                </div>
                <button type="submit" class="btn btn-outline">Send Notification</button>
            </form>

            <p style="margin-top:1rem">
                <a href="<?= APP_URL ?>/staff/requests.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Assignments</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
