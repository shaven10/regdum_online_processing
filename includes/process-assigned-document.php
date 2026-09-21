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

$allowedDocumentCodes = $allowedDocumentCodes ?? null;
if (!empty($allowedDocumentCodes) && is_array($allowedDocumentCodes)) {
    $allowed = array_map(static fn($code): string => strtoupper(trim((string) $code)), $allowedDocumentCodes);
    $itemCode = strtoupper(trim((string) ($item['document_code'] ?? '')));
    if (!in_array($itemCode, $allowed, true)) {
        setFlash('error', 'This document type is outside your office scope.');
        redirect($listUrl);
    }
}

$requestId = (int) $item['request_id'];
$staffId = (int) $user['id'];
$assignedItems = getProcessorAssignedItemsForRequest($requestId, $staffId, is_array($allowedDocumentCodes) ? $allowedDocumentCodes : null);
if ($assignedItems === []) {
    $assignedItems = [$item];
}

$context = loadAssignmentRequestContext($requestId);
$context['items'] = $assignedItems;
$requestHeader = $context['request'];

$flashAssignedUpdate = static function (array $result, string $statusLabel) use ($item): void {
    if (($result['updated'] ?? 0) > 0) {
        setFlash('success', $result['updated'] . ' document item' . ((int) $result['updated'] === 1 ? '' : 's')
            . ' updated to ' . $statusLabel . '.', [
            'title' => 'Assigned Documents Updated',
            'context' => array_filter([
                'Request' => (string) ($item['request_number'] ?? ''),
                'Updated' => (string) $result['updated'],
                'Skipped' => ((int) ($result['skipped'] ?? 0) > 0) ? (string) $result['skipped'] : null,
            ]),
            'details' => array_slice($result['failed'] ?? [], 0, 8),
        ]);
        return;
    }

    if (($result['skipped'] ?? 0) > 0 && empty($result['failed'])) {
        setFlash('info', 'Your assigned documents are already at that status or cannot move to ' . $statusLabel . ' yet.', [
            'title' => 'No Changes Needed',
            'context' => ['Request' => (string) ($item['request_number'] ?? '')],
        ]);
        return;
    }

    setFlash('error', implode(' ', $result['failed'] ?? ['Unable to update assigned documents.']), [
        'title' => 'Update Failed',
    ]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = (string) ($_POST['action'] ?? '');
    $postedItemId = (int) ($_POST['item_id'] ?? 0);

    $findAssignedItem = static function (int $targetItemId) use ($assignedItems): ?array {
        foreach ($assignedItems as $assignedItem) {
            if ((int) ($assignedItem['id'] ?? 0) === $targetItemId) {
                return $assignedItem;
            }
        }

        return null;
    };

    if ($action === 'ready' || $action === 'complete') {
        $targetItemId = $postedItemId > 0 ? $postedItemId : $itemId;
        $targetItem = $findAssignedItem($targetItemId);
        $newStatus = $action === 'ready' ? 'ready_for_pickup' : 'completed';
        $statusLabel = $action === 'ready' ? 'Ready for Pickup' : 'Completed';

        if (!$targetItem) {
            setFlash('error', 'That document is not assigned to you.');
        } elseif (updateRequestItemStatus($targetItemId, $newStatus)) {
            setFlash('success', $action === 'ready' ? 'Document marked ready for pickup.' : 'Document released to student.', [
                'title' => $statusLabel,
                'context' => [
                    'Request' => (string) ($item['request_number'] ?? ''),
                    'Document' => (string) ($targetItem['document_name'] ?? $item['document_name'] ?? 'Document'),
                ],
            ]);
        } else {
            setFlash('error', 'Unable to update that document status.');
        }
    } elseif ($action === 'ready_all' || $action === 'complete_all') {
        $newStatus = $action === 'ready_all' ? 'ready_for_pickup' : 'completed';
        $statusLabel = $action === 'ready_all' ? 'Ready for Pickup' : 'Completed';
        $result = batchUpdateAssignedRequestItemStatuses(
            [$requestId],
            $newStatus,
            $staffId,
            is_array($allowedDocumentCodes) ? $allowedDocumentCodes : null
        );
        $flashAssignedUpdate($result, $statusLabel);
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

$assignedStatuses = array_values(array_unique(array_filter(array_map(
    static fn(array $assignedItem): string => trim((string) ($assignedItem['item_status'] ?? '')),
    $assignedItems
))));
$headerStatus = count($assignedStatuses) > 1 ? 'mixed' : (string) ($item['item_status'] ?? '');
$headerStatusDetail = count($assignedStatuses) > 1
    ? implode(' · ', array_map('requestItemStatusLabel', $assignedStatuses))
    : '';
$canReadyAny = in_array('processing', $assignedStatuses, true);
$canCompleteAny = in_array('ready_for_pickup', $assignedStatuses, true);
$assignedCount = count($assignedItems);
$headerSubtitle = $item['request_number'] . ' · '
    . ($assignedCount > 1
        ? $assignedCount . ' assigned documents'
        : (string) ($item['document_name'] ?? 'Document'));

$pageTitle = 'Process ' . $item['request_number']
    . ($assignedCount > 1 ? ' — Assigned Documents' : ' — ' . $item['document_name']);
require_once __DIR__ . '/header.php';
?>

<div class="grid-2 assignment-process-layout">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Request Details</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    <?= e($headerSubtitle) ?>
                </p>
            </div>
            <div class="assignment-process-header-status">
                <?= requestItemStatusBadge($headerStatus) ?>
                <?php if ($headerStatusDetail !== ''): ?>
                    <small class="text-muted"><?= e($headerStatusDetail) ?></small>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <?= renderAssignmentRequestDetailsHtml($context, $item) ?>
        </div>
    </div>

    <div class="card assignment-actions-card">
        <div class="card-header"><h2>Processing Actions</h2></div>
        <div class="card-body">
            <?php if ($assignedCount > 1): ?>
                <p class="text-muted" style="margin-top:0">
                    Update each document assigned to you, or apply one status to all of them.
                </p>
                <div class="assignment-doc-action-list">
                    <?php foreach ($assignedItems as $assignedItem): ?>
                        <?php
                        $assignedItemId = (int) ($assignedItem['id'] ?? 0);
                        $assignedStatus = (string) ($assignedItem['item_status'] ?? '');
                        $isCurrent = $assignedItemId === $itemId;
                        ?>
                        <div class="assignment-doc-action-row<?= $isCurrent ? ' is-current' : '' ?>">
                            <div class="assignment-doc-action-meta">
                                <strong><?= e((string) ($assignedItem['document_name'] ?? 'Document')) ?></strong>
                                <span class="assignment-doc-action-meta-line">
                                    <?= requestItemStatusBadge($assignedStatus) ?>
                                    <small class="text-muted">
                                        <?= (int) ($assignedItem['copies'] ?? 1) ?> cop<?= (int) ($assignedItem['copies'] ?? 1) === 1 ? 'y' : 'ies' ?>
                                        <?php if ($isCurrent): ?> · currently open<?php endif; ?>
                                    </small>
                                </span>
                            </div>
                            <div class="assignment-doc-action-buttons">
                                <?php if ($assignedStatus === 'processing'): ?>
                                    <form method="POST">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="ready">
                                        <input type="hidden" name="item_id" value="<?= $assignedItemId ?>">
                                        <button class="btn btn-primary btn-sm">Mark Ready for Pickup</button>
                                    </form>
                                <?php elseif ($assignedStatus === 'ready_for_pickup'): ?>
                                    <form method="POST">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="complete">
                                        <input type="hidden" name="item_id" value="<?= $assignedItemId ?>">
                                        <button class="btn btn-primary btn-sm">Mark Completed</button>
                                    </form>
                                <?php elseif ($assignedStatus === 'completed'): ?>
                                    <small class="text-muted">Released</small>
                                <?php else: ?>
                                    <small class="text-muted">Awaiting assignment</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($canReadyAny || $canCompleteAny): ?>
                    <div class="assignment-bulk-actions">
                        <?php if ($canReadyAny): ?>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="ready_all">
                                <button class="btn btn-primary">Mark All Ready for Pickup</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canCompleteAny): ?>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="complete_all">
                                <button class="btn <?= $canReadyAny ? 'btn-outline' : 'btn-primary' ?>">Mark All Completed</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
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
            <?php endif; ?>

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
