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

$requestId = (int) $item['request_id'];
$db = getDB();

$headerStmt = $db->prepare('SELECT r.*, u.first_name, u.last_name, u.email, u.student_id, u.phone,
    sp.course, sp.year_level, sp.enrollment_status
    FROM requests r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE r.id = ?');
$headerStmt->execute([$requestId]);
$requestHeader = $headerStmt->fetch();

$docs = $db->prepare('SELECT * FROM request_documents WHERE request_id = ? ORDER BY uploaded_at ASC');
$docs->execute([$requestId]);
$documents = $docs->fetchAll();

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

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h2><?= e($item['document_name']) ?></h2>
            <?= requestItemStatusBadge($item['item_status']) ?>
        </div>
        <div class="card-body">
            <div class="detail-grid">
                <div class="detail-item"><label>Request #</label><span><?= e($item['request_number']) ?></span></div>
                <div class="detail-item"><label>Student</label><span><?= e($requestHeader['first_name'] . ' ' . $requestHeader['last_name']) ?></span></div>
                <div class="detail-item"><label>Student ID</label><span><?= e($requestHeader['student_id']) ?></span></div>
                <div class="detail-item"><label>Course</label><span><?= e($requestHeader['course'] ?? '—') ?> (<?= e($requestHeader['year_level'] ?? '') ?>)</span></div>
                <div class="detail-item"><label>Purpose</label><span><?= purposeLabel($requestHeader['purpose']) ?></span></div>
                <div class="detail-item"><label>Copies</label><span><?= (int) $item['copies'] ?></span></div>
                <div class="detail-item"><label>Batch Status</label><span><?= statusBadge($requestHeader['status']) ?></span></div>
                <?php if (!empty($item['release_date'])): ?>
                    <div class="detail-item"><label>Release Date</label><span><?= formatDate($item['release_date']) ?> at <?= date('g:i A', strtotime((string) $item['release_time'])) ?></span></div>
                <?php endif; ?>
            </div>

            <?= renderRequestItemDetailsHtml($item) ?>

            <?php if (!empty($documents)): ?>
                <h4>Uploaded Documents</h4>
                <ul class="doc-list">
                    <?php foreach ($documents as $doc): ?>
                        <li><a href="<?= UPLOAD_URL ?>/<?= e($doc['file_name']) ?>" target="_blank"><?= e($doc['original_name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
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
