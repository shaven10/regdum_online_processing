<?php

/**
 * Shared document-assignment processing UI for registrar staff, cashier, and guidance.
 *
 * Expected before include:
 * - $user (current user)
 * - $listUrl (redirect/list page)
 * - $activeNav
 * - $processorLabel (e.g. "Cashier", "Guidance Office")
 */

require_once __DIR__ . '/request-items.php';

ensureRequestItemsSchema();

$itemId = (int) ($_GET['item_id'] ?? 0);
if ($itemId <= 0) {
    setFlash('info', 'Select a document assignment to process.');
    redirect($listUrl);
}

$item = getRequestItem($itemId);
if (!$item || (int) ($item['assigned_to'] ?? 0) !== (int) $user['id']) {
    setFlash('error', 'Assignment not found or not assigned to you.');
    redirect($listUrl);
}

if (!empty($allowedDocumentCodes) && is_array($allowedDocumentCodes)) {
    $allowed = array_map(static fn($code): string => strtoupper(trim((string) $code)), $allowedDocumentCodes);
    $itemCode = strtoupper(trim((string) ($item['document_code'] ?? '')));
    if (!in_array($itemCode, $allowed, true)) {
        setFlash('error', 'This document type is outside your office scope.');
        redirect($listUrl);
    }
}

$requestId = (int) $item['request_id'];
$context = loadAssignmentRequestContext($requestId);
$requestHeader = $context['request'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'ready') {
        if (updateRequestItemStatus($itemId, 'ready_for_pickup')) {
            setFlash('success', 'Document marked ready for pickup.', [
                'title' => 'Ready for Pickup',
                'context' => [
                    'Request' => $item['request_number'],
                    'Document' => $item['document_name'],
                ],
            ]);
        }
    } elseif ($action === 'complete') {
        if (updateRequestItemStatus($itemId, 'completed')) {
            setFlash('success', 'Document released to student.', [
                'title' => 'Document Completed',
                'context' => [
                    'Request' => $item['request_number'],
                    'Document' => $item['document_name'],
                ],
            ]);
        }
    } elseif ($action === 'notify') {
        $message = trim($_POST['message'] ?? '');
        if ($message !== '') {
            sendNotification(
                (int) $requestHeader['user_id'],
                'Message from ' . ($processorLabel ?? 'Processing Office'),
                $message,
                'info',
                APP_URL . '/student/request-view.php?id=' . $requestId
            );
            setFlash('success', 'Notification sent to student.');
        }
    }

    redirect($processUrl . '?item_id=' . $itemId);
}

$pageTitle = 'Process ' . $item['request_number'] . ' — ' . $item['document_name'];
require_once __DIR__ . '/header.php';
?>

<div class="grid-2 assignment-process-layout">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Request Details</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    <?= e($item['request_number']) ?> · <?= e($item['document_name']) ?>
                </p>
            </div>
            <?= requestItemStatusBadge($item['item_status']) ?>
        </div>
        <div class="card-body">
            <?= renderAssignmentRequestDetailsHtml($context, $item) ?>
        </div>
    </div>

    <div class="card assignment-actions-card">
        <div class="card-header"><h2>Processing Actions</h2></div>
        <div class="card-body">
            <div class="action-buttons">
                <?php if ($item['item_status'] === 'processing'): ?>
                    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="ready"><button class="btn btn-primary">Mark Ready for Pickup</button></form>
                <?php elseif ($item['item_status'] === 'ready_for_pickup'): ?>
                    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="complete"><button class="btn btn-primary">Mark Completed</button></form>
                <?php elseif ($item['item_status'] === 'completed'): ?>
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
                <a href="<?= e($listUrl) ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Assignments</a>
            </p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
