<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/payments.php';
requireRole('registrar');

$db = getDB();
$requestId = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT r.*, dt.name as document_name,
    u.first_name, u.last_name, u.email, u.student_id
    FROM requests r
    JOIN document_types dt ON r.document_type_id = dt.id
    JOIN users u ON r.user_id = u.id
    WHERE r.id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    setFlash('error', 'Request not found.');
    redirect(APP_URL . '/registrar/attachments.php');
}

$groups = getRequestAttachmentsGrouped($requestId);
$assignedRequirements = getAssignedRequirements($requestId);

$pageTitle = 'Attachments — ' . $request['request_number'];
$activeNav = 'attachments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="attachment-view-header">
    <div>
        <a href="attachments.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Attachments</a>
        <a href="verify-request.php?id=<?= $requestId ?>" class="btn btn-primary btn-sm"><i class="fas fa-clipboard-check"></i> Review Request</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><?= e($request['request_number']) ?> — Requestor Attachments</h2>
        <?= statusBadge($request['status']) ?>
    </div>
    <div class="card-body">
        <div class="detail-grid attachment-request-summary">
            <div class="detail-item"><label>Student</label><span><?= e($request['first_name'] . ' ' . $request['last_name']) ?></span></div>
            <div class="detail-item"><label>Student ID</label><span><?= e($request['student_id']) ?></span></div>
            <div class="detail-item"><label>Document</label><span><?= e($request['document_name']) ?></span></div>
            <div class="detail-item"><label>Email</label><span><?= e($request['email']) ?></span></div>
            <div class="detail-item"><label>Total Files</label><span><?= (int) $groups['total'] ?></span></div>
            <div class="detail-item"><label>Submitted</label><span><?= formatDateTime($request['created_at']) ?></span></div>
        </div>

        <?php if ($groups['total'] === 0): ?>
            <div class="empty-state" style="margin-top:1.5rem">
                <i class="fas fa-folder-open"></i>
                <p>No attachments uploaded yet for this request.</p>
            </div>
        <?php else: ?>
            <?= renderAttachmentSection('Initial Submission', $groups['initial'], 'Initial upload') ?>
            <?= renderAttachmentSection('Requirement Attachments', $groups['requirements']) ?>
            <?= renderAttachmentSection('Registrar Instruction Attachments', $groups['instructions'] ?? [], 'Instruction file') ?>

            <?php if (!empty($groups['payments'])): ?>
                <div class="attachment-section">
                    <h3>Payment Receipts</h3>
                    <div class="attachment-grid">
                        <?php foreach ($groups['payments'] as $payment): ?>
                            <?php
                            $receiptFile = [
                                'receipt_path' => $payment['receipt_path'],
                                'original_name' => basename($payment['receipt_path']),
                                'created_at' => $payment['created_at'],
                            ];
                            $label = 'Payment — ' . paymentMethodLabel($payment['payment_method']) . ' (' . formatMoney((float)$payment['amount']) . ')';
                            ?>
                            <?= renderAttachmentPreview($receiptFile, $label) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($assignedRequirements)): ?>
                <div class="attachment-section attachment-checklist-summary">
                    <h3>Requirements Checklist</h3>
                    <ul class="doc-list">
                        <?php foreach ($assignedRequirements as $req): ?>
                            <li>
                                <strong><?= e($req['requirement_name']) ?></strong>
                                <?php if (($req['requirement_code'] ?? '') === 'online_clearance'): ?>
                                    <span class="badge badge-submitted">Online clearance</span>
                                <?php elseif ($req['requires_upload']): ?>
                                    <?= $req['document_id'] ? '<span class="badge badge-completed">Uploaded</span>' : '<span class="badge badge-review">Missing</span>' ?>
                                    <?php if ($req['file_name']): ?>
                                        — <a href="<?= e(attachmentUrl($req['file_name'])) ?>" target="_blank"><?= e($req['original_name']) ?></a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge badge-submitted">No upload required</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
