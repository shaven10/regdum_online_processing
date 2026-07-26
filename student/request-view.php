<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/clearance.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/request-items.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/ui.php';
requireRole('student');
ensureRequestItemsSchema();
$user = currentUser();

$db = getDB();
$requestId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT r.*, dt.name as document_name, dt.processing_days, u.first_name, u.last_name, u.email, u.student_id FROM requests r LEFT JOIN document_types dt ON r.document_type_id = dt.id JOIN users u ON r.user_id = u.id WHERE r.id = ? AND r.user_id = ?');
$stmt->execute([$requestId, $user['id']]);
$request = $stmt->fetch();

if (!$request) {
    setFlash('error', 'Request not found.');
    redirect(APP_URL . '/student/requests.php');
}

$requestItems = getRequestItems($requestId);
$documentsSummary = formatRequestItemsSummary($requestItems);
if (in_array($request['status'] ?? '', ['processing', 'ready_for_pickup', 'payment_verified'], true)) {
    syncRequestAssignmentSummary($requestId);
    $stmt->execute([$requestId, $user['id']]);
    $request = $stmt->fetch() ?: $request;
}
if (in_array($request['status'] ?? '', ['processing', 'ready_for_pickup', 'shipped', 'completed'], true)) {
    $simpleCode = ensureSimpleVerificationCode($requestId);
    if ($simpleCode) {
        $request['verification_code'] = $simpleCode;
    }
}
$pickupErrors = [];
$pickupFormData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $postAction = $_POST['action'] ?? '';
    $shouldRedirect = true;

    if ($postAction === 'set_pickup_option') {
        $result = processStudentPickupOption($requestId, $user['id'], $_POST, $_FILES);
        if ($result['success']) {
            setFlash('success', $result['message'], [
                'title' => 'Pickup Option Saved',
                'next_step' => 'Keep your claim stub ready for your scheduled on-site pickup.',
            ]);
        } else {
            setFlash('error', $result['message'], [
                'title' => 'Pickup Option Incomplete',
                'next_step' => 'Select a pickup option and upload the required representative documents if applicable.',
            ]);
            $pickupErrors = $result['errors'];
            $pickupFormData = $_POST;
            $shouldRedirect = false;
        }
    } elseif ($postAction === 'confirm_pickup') {
        if (empty($_POST['pickup_confirm'])) {
            setFlash('error', 'Please confirm that you received your document before completing this transaction.', [
                'title' => 'Confirmation Required',
                'next_step' => 'Check the confirmation box after you have physically received your document.',
            ]);
        } elseif (processStudentPickupConfirmation($requestId, $user['id'])) {
            setFlash('success', 'Pickup confirmed. Your document request is now complete.', [
                'title' => 'Transaction Completed',
                'context' => [
                    'Request' => $request['request_number'],
                    'Document' => $request['document_name'],
                    'Completed' => date('M d, Y h:i A'),
                ],
                'next_step' => 'You may leave feedback about your experience or download your document if available.',
                'action_url' => APP_URL . '/student/feedback.php?request_id=' . $requestId,
                'action_label' => 'Leave feedback',
            ]);
        } else {
            setFlash('error', 'Unable to confirm pickup. Your request must be ready for on-site pickup.', [
                'title' => 'Pickup Not Available',
                'next_step' => 'Wait until the Registrar marks your document as ready for pickup.',
            ]);
        }
    } elseif ($postAction === 'resubmit' && $request['status'] === 'rejected') {
        if (processStudentRejectedResubmit($requestId, $user['id'], $_FILES)) {
            setFlash('success', 'Documents uploaded. Your request has been resubmitted for registrar review.', [
                'title' => 'Request Resubmitted',
                'context' => [
                    'Request' => $request['request_number'],
                    'Document' => $request['document_name'],
                    'Status' => 'Under review',
                ],
                'next_step' => 'The Registrar will review your corrected documents and update the request status.',
            ]);
        } else {
            setFlash('error', 'Please upload at least one corrected document before resubmitting.', [
                'title' => 'Upload Required',
                'next_step' => 'Attach corrected files in the resubmit section, then try again.',
            ]);
        }
    } elseif ($postAction !== 'confirm_pickup' && processStudentRequirementUploads($requestId, $user['id'], $_FILES)) {
        if ($request['status'] !== 'requirements_submitted') {
            setFlash('success', 'Requirements saved. Upload all required attachments to submit for re-evaluation.', [
                'title' => 'Requirements Saved',
                'context' => [
                    'Request' => $request['request_number'],
                    'Progress' => studentProgressPercent($request['status'], $requestId) . '% complete',
                ],
                'next_step' => 'Complete every assigned requirement, then submit for registrar re-evaluation.',
            ]);
        } else {
            setFlash('success', 'Requirements submitted for registrar re-evaluation.', [
                'title' => 'Requirements Submitted',
                'context' => [
                    'Request' => $request['request_number'],
                    'Document' => $request['document_name'],
                ],
                'next_step' => 'Wait for the Registrar to re-evaluate your submission before payment is allowed.',
            ]);
        }
    } elseif ($postAction !== 'confirm_pickup' && $postAction !== 'resubmit') {
        setFlash('error', 'Unable to submit requirements. Please check your uploads.', [
            'title' => 'Submission Failed',
            'next_step' => 'Verify each required file is attached and within the allowed format.',
        ]);
    }

    if ($shouldRedirect) {
        redirect(APP_URL . '/student/request-view.php?id=' . $requestId);
    }
}



$docs = $db->prepare('SELECT * FROM request_documents WHERE request_id = ?');

$docs->execute([$requestId]);

$documents = $docs->fetchAll();



$history = $db->prepare('SELECT h.*, u.first_name, u.last_name FROM request_status_history h LEFT JOIN users u ON h.changed_by = u.id WHERE h.request_id = ? ORDER BY h.created_at ASC');

$history->execute([$requestId]);

$statusHistory = $history->fetchAll();



$payment = $db->prepare('SELECT * FROM payments WHERE request_id = ? ORDER BY created_at DESC LIMIT 1');

$payment->execute([$requestId]);

$paymentData = $payment->fetch();



$assignedRequirements = getAssignedRequirements($requestId);
if (hasAssignedRequirement($requestId, 'online_clearance')) {
    initRequestClearance($requestId);
    syncAssignedClearanceRequirement($requestId);
}
maybeAdvanceToRequirementsSubmitted($requestId);

$stmt = $db->prepare('SELECT r.*, dt.name as document_name, dt.processing_days, u.first_name, u.last_name, u.email, u.student_id FROM requests r LEFT JOIN document_types dt ON r.document_type_id = dt.id JOIN users u ON r.user_id = u.id WHERE r.id = ? AND r.user_id = ?');
$stmt->execute([$requestId, $user['id']]);
$request = $stmt->fetch();
$assignedRequirements = getAssignedRequirements($requestId);
$complianceSummary = getComplianceSummary($requestId);

$estimatedRelease = null;
if (isOnSitePickupMethod($request['delivery_method'])) {
    $estimatedRelease = buildReleaseScheduleForRequest(
        $requestId,
        (int) ($request['processing_days'] ?? 3),
        $request['release_date'] ?? null,
        $request['release_time'] ?? null
    );
}



$pageTitle = 'Request ' . $request['request_number'];

$activeNav = 'requests';

require_once __DIR__ . '/../includes/header.php';

?>



<div class="request-detail">

    <div class="card">

        <div class="card-header">

            <h2><?= e($request['request_number']) ?></h2>

            <?= statusBadge($request['status']) ?>

        </div>

        <div class="card-body">

            <?php if ($request['status'] === 'needs_revision'): ?>

                <div class="alert alert-warning">

                    <i class="fas fa-exclamation-triangle"></i>

                    Your request requires revision. <?= e($complianceSummary['remarks'] ?? 'Please review the registrar remarks and update your submission.') ?>

                </div>
                <?= renderRegistrarInstructionAttachmentsHtml($requestId) ?>

            <?php elseif ($request['status'] === 'rejected'): ?>

                <div class="alert alert-error">
                    <i class="fas fa-times-circle"></i>
                    <strong>Request Rejected:</strong> <?= e($request['rejection_reason'] ?? 'No reason provided.') ?>
                </div>
                <div class="alert alert-info">
                    <i class="fas fa-upload"></i>
                    You may upload corrected documents below and resubmit this request for review.
                </div>

                <?= renderStudentProgressPanel($request['status'], $requestId, $request['delivery_method'] ?? null) ?>
                <div class="status-detail-wrap"><?= renderRequestStatusButton($request, $requestId) ?></div>

            <?php else: ?>

                <?= renderStudentProgressPanel($request['status'], $requestId, $request['delivery_method'] ?? null) ?>
                <div class="status-detail-wrap"><?= renderRequestStatusButton($request, $requestId) ?></div>

            <?php endif; ?>



            <h3 class="request-info-heading"><i class="fas fa-file-alt"></i> Request Information</h3>
            <div class="detail-grid">
                <div class="detail-item"><label>Request #</label><span><?= e($request['request_number']) ?></span></div>
                <div class="detail-item"><label>Submitted</label><span><?= formatDateTime($request['created_at']) ?></span></div>
                <div class="detail-item full">
                    <label>Documents (<?= count($requestItems) ?>)</label>
                    <span><?= e($documentsSummary) ?></span>
                </div>
                <div class="detail-item"><label>Purpose</label><span><?= purposeLabel($request['purpose']) ?><?= $request['purpose_other'] ? ' — ' . e($request['purpose_other']) : '' ?></span></div>
                <div class="detail-item"><label>Total Amount</label><span><?= formatMoney((float) $request['total_amount']) ?></span></div>
                <?php if ($request['release_date']): ?>
                    <div class="detail-item"><label>On-Site Release</label><span><?= formatDate($request['release_date']) ?> at <?= date('g:i A', strtotime($request['release_time'])) ?></span></div>
                <?php elseif ($estimatedRelease && in_array($request['status'], ['payment_verified', 'processing'], true)): ?>
                    <div class="detail-item full">
                        <label>Estimated On-Site Release</label>
                        <span>
                            <?= formatDate($estimatedRelease['suggested_date']) ?> at <?= date('g:i A', strtotime($estimatedRelease['suggested_time'])) ?>
                            <small class="text-muted">(based on <?= (int) $estimatedRelease['processing_days'] ?> working days, excluding weekends)</small>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if ($request['verification_code'] && in_array($request['status'], ['processing', 'ready_for_pickup', 'shipped', 'completed'], true)): ?>
                    <div class="detail-item"><label>Verification Code</label><span><code><?= e(formatVerificationCode($request['verification_code'])) ?></code></span></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($requestItems)): ?>
            <div class="request-items-panel">
                <h3><i class="fas fa-layer-group"></i> Requested Documents</h3>
                <?php foreach ($requestItems as $requestItem): ?>
                    <?= renderRequestItemDetailsHtml($requestItem) ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>


            <?php if ($request['status'] === 'submitted' || $request['status'] === 'under_review'): ?>

                <div class="alert alert-info"><i class="fas fa-hourglass-half"></i> Your request is awaiting registrar review. Requirements will be assigned once confirmed.</div>

            <?php endif; ?>



            <?php if ($request['status'] === 'requirements_verified'): ?>

                <a href="payment.php?request_id=<?= $requestId ?>" class="btn btn-primary"><i class="fas fa-credit-card"></i> Proceed to Payment</a>

            <?php elseif ($request['status'] === 'awaiting_requirements' || $request['status'] === 'needs_revision'): ?>

                <div class="alert alert-info"><i class="fas fa-list-check"></i> Complete the requirements below and submit for registrar re-evaluation.</div>

            <?php elseif ($request['status'] === 'requirements_submitted'): ?>

                <div class="alert alert-info"><i class="fas fa-search"></i> Requirements submitted. Awaiting registrar re-evaluation before payment.</div>

            <?php elseif ($request['status'] === 'payment_verified'): ?>

                <div class="alert alert-info"><i class="fas fa-hourglass-half"></i> Payment verified. Your request is awaiting assignment by the Registrar's Office.</div>

            <?php elseif ($request['status'] === 'processing'): ?>

                <?php if (canStudentSelectPickupOption($request)): ?>
                    <div class="alert alert-warning"><i class="fas fa-building"></i> Your request has been assigned for processing. Select your on-site pickup option below.</div>
                <?php else: ?>
                    <div class="alert alert-info"><i class="fas fa-cog"></i> Your document is being processed by the Registrar's Office.</div>
                <?php endif; ?>

            <?php endif; ?>



            <?php if (in_array($request['status'], ['processing', 'ready_for_pickup', 'shipped', 'completed'], true)): ?>

                <a href="claim-stub.php?id=<?= $requestId ?>" target="_blank" class="btn btn-primary"><i class="fas fa-receipt"></i> Claim Stub</a>
                <a href="claim-stub.php?id=<?= $requestId ?>&download=pdf" target="_blank" class="btn btn-outline"><i class="fas fa-file-pdf"></i> PDF</a>
                <a href="claim-stub.php?id=<?= $requestId ?>&download=png" target="_blank" class="btn btn-outline"><i class="fas fa-image"></i> Image</a>

            <?php endif; ?>



            <?php if ($request['status'] === 'ready_for_pickup' && $request['pickup_date']): ?>

                <div class="alert alert-info"><i class="fas fa-calendar"></i> Scheduled pickup: <?= formatDate($request['pickup_date']) ?><?= $request['pickup_time'] ? ' at ' . date('g:i A', strtotime($request['pickup_time'])) : '' ?>.</div>

            <?php endif; ?>



            <?php if ($request['status'] === 'completed'): ?>

                <a href="feedback.php?request_id=<?= $requestId ?>" class="btn btn-outline"><i class="fas fa-star"></i> Leave Feedback</a>

                <?php if ($request['pdf_path']): ?>

                    <a href="<?= UPLOAD_URL ?>/<?= e($request['pdf_path']) ?>" class="btn btn-outline" target="_blank"><i class="fas fa-download"></i> Download Document</a>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>



    <?php if (canStudentSelectPickupOption($request)): ?>
        <?= renderStudentPickupOptionForm($request, $pickupErrors, $pickupFormData) ?>
    <?php endif; ?>



    <?php if ($request['status'] === 'ready_for_pickup' && isPickupOptionPending($request['delivery_method'] ?? null)): ?>
        <div class="card">
            <div class="card-body">
                <div class="alert alert-warning">
                    <i class="fas fa-building"></i>
                    Your document is ready, but you still need to select an on-site pickup option above before completing the transaction.
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($request['status'] === 'ready_for_pickup' && isOnSitePickupMethod($request['delivery_method'])): ?>

    <div class="card pickup-complete-card" id="pickup-complete">
        <div class="card-header">
            <h2><i class="fas fa-hand-holding"></i> Complete Pickup Transaction</h2>
        </div>
        <div class="card-body">
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php if ($request['delivery_method'] === 'authorized_representative'): ?>
                    Your document is ready for on-site pickup by your authorized representative. Present the claim stub and verification code at the Registrar's Office.
                <?php else: ?>
                    Your document is ready for on-site pickup. Present your claim stub and verification code at the Registrar's Office.
                <?php endif; ?>
            </div>

            <div class="detail-grid pickup-complete-grid">
                <div class="detail-item">
                    <label>Verification Code</label>
                    <span><code><?= e(formatVerificationCode($request['verification_code'])) ?></code></span>
                </div>
                <?php if ($request['release_date']): ?>
                    <div class="detail-item">
                        <label>Scheduled Release</label>
                        <span><?= formatDate($request['release_date']) ?> at <?= $request['release_time'] ? date('g:i A', strtotime($request['release_time'])) : '—' ?></span>
                    </div>
                <?php elseif ($estimatedRelease): ?>
                    <div class="detail-item">
                        <label>Estimated Release</label>
                        <span><?= formatDate($estimatedRelease['suggested_date']) ?> at <?= date('g:i A', strtotime($estimatedRelease['suggested_time'])) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <form method="POST" class="pickup-complete-form" onsubmit="return document.getElementById('pickup_confirm').checked || (alert('Please confirm that you received your document.'), false)">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="confirm_pickup">
                <label class="checkbox-label pickup-confirm-label">
                    <input type="checkbox" id="pickup_confirm" name="pickup_confirm" value="1" required>
                    <?php if ($request['delivery_method'] === 'authorized_representative'): ?>
                        I confirm that my authorized representative has received my requested document(s) from the Registrar's Office.
                    <?php else: ?>
                        I confirm that I have received my requested document(s) from the Registrar's Office.
                    <?php endif; ?>
                </label>
                <div class="pickup-complete-actions">
                    <a href="claim-stub.php?id=<?= $requestId ?>&print=1" target="_blank" class="btn btn-outline">
                        <i class="fas fa-print"></i> Print Claim Stub
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-check-double"></i> Complete Transaction
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>



    <?php if ($request['status'] === 'rejected'): ?>

    <div class="card" id="resubmit-documents">

        <div class="card-header"><h3>Re-upload Documents</h3></div>

        <div class="card-body">

            <?= renderRegistrarNotesHtml($complianceSummary, $requestId) ?>

            <form method="POST" enctype="multipart/form-data" class="form-grid">

                <?= csrfField() ?>

                <input type="hidden" name="action" value="resubmit">

                <?php if (!empty($assignedRequirements)): ?>
                    <?php foreach ($assignedRequirements as $req): ?>
                        <div class="form-group">
                            <label><strong><?= e($req['requirement_name']) ?></strong></label>
                            <?php if ($req['description']): ?><p class="text-muted"><?= e($req['description']) ?></p><?php endif; ?>
                            <?php if (($req['requirement_code'] ?? '') === 'online_clearance'): ?>
                                <?= renderClearanceGrid($requestId, true) ?>
                                <p class="text-muted">Complete online clearance if not yet finished.</p>
                            <?php elseif ($req['requires_upload']): ?>
                                <?php if ($req['file_name']): ?>
                                    <p><i class="fas fa-file"></i> Previous: <a href="<?= UPLOAD_URL ?>/<?= e($req['file_name']) ?>" target="_blank"><?= e($req['original_name']) ?></a></p>
                                <?php endif; ?>
                                <input type="file" name="requirement_<?= $req['id'] ?>" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                <small class="text-muted">Upload a corrected file to replace the previous submission.</small>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php if (!empty($documents)): ?>
                        <div class="form-group">
                            <label>Previously Uploaded</label>
                            <ul class="doc-list">
                                <?php foreach ($documents as $doc): ?>
                                    <li><i class="fas fa-file"></i> <a href="<?= UPLOAD_URL ?>/<?= e($doc['file_name']) ?>" target="_blank"><?= e($doc['original_name']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="resubmit_documents">Upload Corrected Documents *</label>
                        <input type="file" id="resubmit_documents" name="documents[]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" multiple required>
                        <small class="text-muted">Select one or more corrected files to resubmit.</small>
                    </div>
                <?php endif; ?>

                <?php if (!empty($assignedRequirements)): ?>
                    <div class="form-group">
                        <label for="resubmit_supporting">Additional Supporting Documents</label>
                        <input type="file" id="resubmit_supporting" name="documents[]" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" multiple>
                        <small class="text-muted">Optional — upload any extra supporting files.</small>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary" onclick="return confirm('Resubmit this request with the uploaded documents?')">
                    <i class="fas fa-redo"></i> Resubmit Request
                </button>

                <?php if (!empty($assignedRequirements) && studentRequirementsComplete($requestId)): ?>
                    <p class="text-muted">All requirement attachments are already on file. You may resubmit without uploading new files.</p>
                <?php endif; ?>

            </form>

        </div>

    </div>

    <?php elseif (!empty($assignedRequirements) && in_array($request['status'], ['awaiting_requirements', 'needs_revision'], true)): ?>

    <div class="card" id="assigned-requirements">

        <div class="card-header"><h3>Assigned Requirements</h3></div>

        <div class="card-body">

            <?= renderRegistrarNotesHtml($complianceSummary, $requestId) ?>

            <form method="POST" enctype="multipart/form-data" class="form-grid">

                <?= csrfField() ?>

                <?php foreach (groupAssignedRequirements($assignedRequirements) as $group): ?>
                    <?php
                    $hasSubcats = count($group['items']) > 1
                        || !empty($group['items'][0]['subcategory_code']);
                    ?>
                    <?php if ($hasSubcats): ?>
                        <div class="requirement-group">
                            <h4 class="requirement-group-title"><i class="fas fa-folder-open"></i> <?= e($group['name']) ?></h4>
                            <p class="text-muted">Upload each required document below.</p>
                    <?php endif; ?>
                    <?php foreach ($group['items'] as $req): ?>
                        <div class="form-group<?= $hasSubcats ? ' requirement-subitem' : '' ?>">
                            <label><strong><?= e($req['requirement_name']) ?></strong></label>
                            <?php if ($req['description']): ?><p class="text-muted"><?= e($req['description']) ?></p><?php endif; ?>
                            <?php if (($req['requirement_code'] ?? '') === 'online_clearance'): ?>
                                <?= renderClearanceGrid($requestId, true) ?>
                            <?php elseif ($req['requires_upload']): ?>
                                <?php if ($req['file_name']): ?>
                                    <p><i class="fas fa-check-circle"></i> Uploaded: <a href="<?= UPLOAD_URL ?>/<?= e($req['file_name']) ?>" target="_blank"><?= e($req['original_name']) ?></a></p>
                                <?php endif; ?>
                                <input type="file" name="requirement_<?= $req['id'] ?>" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($hasSubcats): ?></div><?php endif; ?>
                <?php endforeach; ?>

                <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Submit Requirements</button>

            </form>

        </div>

    </div>

    <?php elseif (!empty($assignedRequirements)): ?>

    <div class="card">

        <div class="card-header"><h3>Requirements Checklist</h3></div>

        <div class="card-body">

            <?= renderRegistrarNotesHtml($complianceSummary, $requestId) ?>

            <ul class="doc-list">

                <?php foreach (groupAssignedRequirements($assignedRequirements) as $group): ?>
                    <?php
                    $hasSubcats = count($group['items']) > 1 || !empty($group['items'][0]['subcategory_code']);
                    ?>
                    <?php if ($hasSubcats): ?>
                        <li class="requirement-group-list-item">
                            <strong><?= e($group['name']) ?></strong>
                            <ul class="doc-list requirement-sublist">
                                <?php foreach ($group['items'] as $req): ?>
                                    <li>
                                        <?= e($req['requirement_name']) ?>
                                        <?php if ($req['is_met']): ?>
                                            <span class="badge badge-completed">Verified</span>
                                        <?php elseif ($req['file_name']): ?>
                                            <span class="badge badge-review">Submitted</span>
                                        <?php else: ?>
                                            <span class="badge badge-submitted">Pending</span>
                                        <?php endif; ?>
                                        <?php if ($req['file_name']): ?>
                                            — <a href="<?= UPLOAD_URL ?>/<?= e($req['file_name']) ?>" target="_blank"><?= e($req['original_name']) ?></a>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php else: ?>
                        <?php $req = $group['items'][0]; ?>
                        <li>
                            <strong><?= e($req['requirement_name']) ?></strong>
                            <?php if (($req['requirement_code'] ?? '') === 'online_clearance'): ?>
                                <?= isClearanceComplete($requestId) ? '<span class="badge badge-completed">Complete</span>' : '<span class="badge badge-submitted">In progress</span>' ?>
                            <?php elseif ($req['is_met']): ?>
                                <span class="badge badge-completed">Verified</span>
                            <?php elseif ($req['file_name']): ?>
                                <span class="badge badge-review">Submitted</span>
                            <?php endif; ?>
                            <?php if ($req['file_name']): ?>
                                — <a href="<?= UPLOAD_URL ?>/<?= e($req['file_name']) ?>" target="_blank"><?= e($req['original_name']) ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
            <?php if (hasAssignedRequirement($requestId, 'online_clearance')): ?>
                <?= renderClearanceGrid($requestId, true) ?>
            <?php endif; ?>

        </div>

    </div>

    <?php endif; ?>



    <?php if ($paymentData): ?>

    <div class="card">

        <div class="card-header"><h3>Payment Information</h3></div>

        <div class="card-body">

            <div class="detail-grid">

                <div class="detail-item"><label>Method</label><span><?= e(paymentMethodLabel($paymentData['payment_method'])) ?></span></div>

                <div class="detail-item"><label>Amount</label><span><?= formatMoney((float)$paymentData['amount']) ?></span></div>

                <div class="detail-item">
                    <label><?= isOnsitePaymentMethod($paymentData['payment_method']) ? 'Payment Code' : 'Reference' ?></label>
                    <span><?= e($paymentData['reference_number'] ?? '—') ?></span>
                </div>

                <?php if (isOnsitePaymentMethod($paymentData['payment_method']) && $paymentData['status'] === 'pending'): ?>
                <div class="detail-item full">
                    <div class="alert alert-info onsite-payment-code-alert">
                        <i class="fas fa-store"></i>
                        Present this code at the cashier:
                        <span class="onsite-payment-code"><?= e($paymentData['reference_number']) ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($paymentData['status'] === 'verified'): ?>
                <div class="detail-item"><label>OR Number</label><span><?= e($paymentData['or_number'] ?? '—') ?></span></div>
                <div class="detail-item"><label>Date of Payment</label><span><?= !empty($paymentData['payment_date']) ? formatDate($paymentData['payment_date']) : '—' ?></span></div>
                <?php endif; ?>

                <div class="detail-item"><label>Status</label><span><?= statusBadge($paymentData['status']) ?></span></div>

            </div>

            <?php if ($paymentData['status'] === 'rejected' && !empty($paymentData['notes'])): ?>
                <div class="alert alert-warning" style="margin-top:1rem">
                    <i class="fas fa-comment-dots"></i>
                    <strong>Cashier Feedback:</strong> <?= e($paymentData['notes']) ?>
                </div>
                <?php if ($request['status'] === 'requirements_verified'): ?>
                    <a href="payment.php?request_id=<?= $requestId ?>" class="btn btn-primary" style="margin-top:.75rem">
                        <i class="fas fa-redo"></i> Resubmit Payment
                    </a>
                <?php endif; ?>
            <?php endif; ?>

        </div>

    </div>

    <?php endif; ?>



    <?php if (!empty($documents)): ?>

    <div class="card">

        <div class="card-header"><h3>Uploaded Documents</h3></div>

        <div class="card-body">

            <ul class="doc-list">

                <?php foreach ($documents as $doc): ?>

                    <li><i class="fas fa-file"></i> <a href="<?= UPLOAD_URL ?>/<?= e($doc['file_name']) ?>" target="_blank"><?= e($doc['original_name']) ?></a></li>

                <?php endforeach; ?>

            </ul>

        </div>

    </div>

    <?php endif; ?>



    <div class="card">

        <div class="card-header"><h3>Status History</h3></div>

        <div class="card-body">

            <div class="timeline">

                <?php foreach ($statusHistory as $h): ?>

                    <div class="timeline-item">

                        <div class="timeline-dot"></div>

                        <div class="timeline-content">

                            <strong><?= ucwords(str_replace('_',' ',$h['new_status'])) ?></strong>

                            <span class="text-muted"><?= formatDateTime($h['created_at']) ?></span>

                            <?php if ($h['remarks']): ?><p><?= e($h['remarks']) ?></p><?php endif; ?>

                            <?php if ($h['first_name']): ?><small>by <?= e($h['first_name'] . ' ' . $h['last_name']) ?></small><?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

    </div>

</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>

