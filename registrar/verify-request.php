<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/request-items.php';
require_once __DIR__ . '/../includes/clearance.php';
require_once __DIR__ . '/../includes/assignment-offices.php';
requireRole('registrar');

$user = currentUser();

ensureComplianceSchema();
ensureRequestItemsSchema();
ensureRequestStatuses();
ensureRequestCopyTypeSchema();

$db = getDB();
$requestId = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT r.*, dt.name as document_name, dt.code as document_code, dt.requires_upload, dt.processing_days,
    u.first_name, u.last_name, u.email, u.student_id, u.phone,
    sp.course, sp.year_level, sp.section, sp.enrollment_status, sp.graduation_date,
    s.first_name as staff_first, s.last_name as staff_last
    FROM requests r
    LEFT JOIN document_types dt ON r.document_type_id = dt.id
    JOIN users u ON r.user_id = u.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    LEFT JOIN users s ON r.assigned_to = s.id
    WHERE r.id = ?');
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    setFlash('error', 'Request not found.');
    redirect(APP_URL . '/registrar/compliance.php');
}

$requestItems = getRequestItems($requestId);
$primaryDocTypeId = (int) ($request['document_type_id'] ?? ($requestItems[0]['document_type_id'] ?? 0));
$copyRequestType = normalizeRequirementCopyType($request['copy_request_type'] ?? 'first_request');
$requirementsRequired = requestRequiresRequirementsForCopyType($requestId, $copyRequestType);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {

    $action = $_POST['action'] ?? '';

    $remarks = trim($_POST['remarks'] ?? '');

    $checks = $_POST['checks'] ?? [];



    if ($action === 'confirm_request') {
        $completeRequirements = !empty($_POST['complete_requirements']);

        if ($completeRequirements) {
            $ok = processComplianceAction($requestId, [], 'confirm_request', $user['id'], $remarks, [
                'complete_requirements' => true,
            ]);
            $instructionCount = 0;
            if ($ok && !empty($_FILES['instruction_attachments'])) {
                $instructionCount = saveRegistrarInstructionAttachments($requestId, $_FILES['instruction_attachments']);
            }
            $successMessage = 'Request confirmed. Student requirements are already complete and may proceed to payment.';
            if ($ok && $instructionCount > 0) {
                $successMessage .= ' ' . $instructionCount . ' instruction attachment'
                    . ($instructionCount === 1 ? '' : 's') . ' sent.';
            }
            setFlash($ok ? 'success' : 'error', $ok
                ? $successMessage
                : 'Unable to confirm this request.', $ok ? [
                'title' => 'Requirements Complete',
                'context' => [
                    'Request' => $request['request_number'],
                    'Student' => $request['first_name'] . ' ' . $request['last_name'],
                    'Document' => $request['document_name'],
                ],
                'next_step' => 'The student may proceed directly to payment.',
                'action_url' => APP_URL . '/registrar/compliance.php?filter=verified',
                'action_label' => 'View awaiting payment',
            ] : [
                'title' => 'Confirmation Failed',
            ]);
        } else {
        $selectedCodes = array_values(array_filter(array_map(
            static fn($code): string => normalizeRequirementCode((string) $code),
            (array) ($_POST['req_codes'] ?? [])
        )));
        $selectedSubcodes = [];
        foreach ((array) ($_POST['req_subcodes'] ?? []) as $parentCode => $subcodes) {
            $parentKey = normalizeRequirementCode((string) $parentCode);
            if ($parentKey === '') {
                continue;
            }
            $selectedSubcodes[$parentKey] = array_values(array_filter(array_map(
                static fn($code): string => normalizeRequirementCode((string) $code),
                (array) $subcodes
            )));
            if (!in_array($parentKey, $selectedCodes, true) && $selectedSubcodes[$parentKey] !== []) {
                $selectedCodes[] = $parentKey;
            }
        }
        $requirements = buildRequirementsFromCodes($selectedCodes, $selectedSubcodes);

        $ok = processComplianceAction($requestId, [], 'confirm_request', $user['id'], $remarks, [
            'requirements' => $requirements,
        ]);
        $instructionCount = 0;
        if ($ok && !empty($_FILES['instruction_attachments'])) {
            $instructionCount = saveRegistrarInstructionAttachments($requestId, $_FILES['instruction_attachments']);
        }
        $successMessage = $requirementsRequired
            ? 'Request confirmed. Requirements sent to the student.'
            : 'Request confirmed. Student may proceed directly to payment.';
        if ($ok && $instructionCount > 0) {
            $successMessage .= ' ' . $instructionCount . ' instruction attachment'
                . ($instructionCount === 1 ? '' : 's') . ' sent.';
        }
        setFlash($ok ? 'success' : 'error', $ok
            ? $successMessage
            : ($requirementsRequired
                ? 'Select at least one requirement (and subcategory documents where shown).'
                : 'Unable to confirm this request.'), $ok ? [
                'title' => $requirementsRequired ? 'Requirements Assigned' : 'Ready for Payment',
                'context' => [
                    'Request' => $request['request_number'],
                    'Student' => $request['first_name'] . ' ' . $request['last_name'],
                    'Document' => $request['document_name'],
                ],
                'next_step' => 'The student must complete the assigned requirements and clearance items.',
                'action_url' => APP_URL . '/registrar/compliance.php?filter=awaiting_student',
                'action_label' => 'Track student progress',
            ] : [
                'title' => 'Assignment Incomplete',
            ]);
        }

    } elseif ($action === 'approve_for_payment') {

        $ok = processComplianceAction($requestId, $checks, 'approve_for_payment', $user['id'], $remarks);

        if ($ok) {
            setFlash('success', 'Requirements approved. Student may proceed to payment.', [
                'title' => 'Approved for Payment',
                'context' => [
                    'Request' => $request['request_number'],
                    'Student' => $request['first_name'] . ' ' . $request['last_name'],
                    'Document' => $request['document_name'],
                ],
                'next_step' => 'The student will submit payment proof and the Cashier will verify it.',
                'action_url' => APP_URL . '/registrar/compliance.php?filter=verified',
                'action_label' => 'View awaiting payment',
            ]);
        } elseif (hasAssignedRequirement($requestId, 'online_clearance') && !isClearanceComplete($requestId)) {
            setFlash('error', 'Online clearance must be completed by all offices before approval.');
        } elseif (!studentRequirementsComplete($requestId)) {
            setFlash('error', 'The student has not completed all assigned requirements yet.');
        } else {
            setFlash('error', 'Verify all assigned requirements before approval.');
        }

    } elseif ($action === 'needs_revision') {

        $ok = processComplianceAction($requestId, [], 'needs_revision', $user['id'], $remarks);
        $instructionCount = 0;
        if ($ok && !empty($_FILES['instruction_attachments'])) {
            $instructionCount = saveRegistrarInstructionAttachments($requestId, $_FILES['instruction_attachments']);
        }
        $revisionMessage = 'Request sent back to the student for revision.';
        if ($ok && $instructionCount > 0) {
            $revisionMessage .= ' ' . $instructionCount . ' instruction attachment'
                . ($instructionCount === 1 ? '' : 's') . ' sent.';
        }

        setFlash($ok ? 'success' : 'error', $ok
            ? $revisionMessage
            : 'Please provide remarks for revision.');

    } elseif ($action === 'reject') {

        $ok = processComplianceAction($requestId, [], 'reject', $user['id'], $remarks);

        setFlash($ok ? 'success' : 'error', $ok ? 'Request rejected.' : 'Please provide a rejection reason.');

    } elseif ($action === 'assign_processing') {
        $itemAssignments = $_POST['item_assignments'] ?? [];
        $extra = ['item_assignments' => $itemAssignments];

        if (empty(array_filter($itemAssignments, static fn($row) => !empty($row['assigned_to'])))) {
            $extra = [
                'assigned_to' => (int) ($_POST['assigned_to'] ?? 0),
                'release_date' => $_POST['release_date'] ?? null,
                'release_time' => $_POST['release_time'] ?? null,
            ];
        }

        $ok = processComplianceAction($requestId, [], 'assign_processing', $user['id'], $remarks, $extra);

        if ($ok) {
            setFlash('success', 'Request assigned and processing started. Print the claim stub for the student.', [
                'title' => 'Processing Started',
                'context' => [
                    'Request' => $request['request_number'],
                    'Document' => $request['document_name'],
                    'Release' => (isOnSitePickupMethod($request['delivery_method']) && !empty($_POST['release_date']))
                        ? formatDate($_POST['release_date']) : 'As scheduled',
                ],
                'next_step' => 'Staff will prepare the document. The student should keep the claim stub for pickup.',
            ]);
            redirect(APP_URL . '/registrar/claim-stub.php?id=' . $requestId . '&print=1');
        }

        setFlash('error', 'Select assigned personnel' . (isOnSitePickupMethod($request['delivery_method']) || isPickupOptionPending($request['delivery_method'] ?? null) ? ' and on-site release date/time.' : '.'));

    } elseif ($action === 'update_release_schedule') {

        $ok = processComplianceAction($requestId, [], 'update_release_schedule', $user['id'], $remarks, [

            'release_date' => $_POST['release_date'] ?? null,

            'release_time' => $_POST['release_time'] ?? null,

        ]);

        setFlash($ok ? 'success' : 'error', $ok

            ? 'On-site release schedule updated.'

            : 'Unable to update release schedule.');

    }



    redirect(APP_URL . '/registrar/verify-request.php?id=' . $requestId);
}

$itemSchedules = [];
foreach ($requestItems as $requestItem) {
    $itemSchedules[(int) $requestItem['id']] = buildReleaseScheduleForRequestItem(
        (int) $requestItem['id'],
        $requestItem['release_date'] ?? null,
        $requestItem['release_time'] ?? null
    );
}

initRequestCompliance($requestId, $primaryDocTypeId);

$assignedRequirements = getAssignedRequirements($requestId);
$summary = getComplianceSummary($requestId);
$requirementChecklist = registrarRequirementChecklist();
$assignedCodes = assignedRequirementCodes($requestId);
$assignedSubcodes = assignedRequirementSubcodes($requestId);
$defaultCodes = getRegistrarSuggestedRequirementCodes($requestId, $copyRequestType);

if (hasAssignedRequirement($requestId, 'online_clearance')) {
    initRequestClearance($requestId);
    syncAssignedClearanceRequirement($requestId);
}
maybeAdvanceToRequirementsSubmitted($requestId);
$stmt->execute([$requestId]);
$request = $stmt->fetch();
$assignedRequirements = getAssignedRequirements($requestId);
ensureDocumentAssignmentOfficeSchema();
$staffUsers = getAssignableProcessors();

$releaseSchedule = buildReleaseScheduleForRequest(
    $requestId,
    (int) ($request['processing_days'] ?? 3),
    $request['release_date'] ?? null,
    $request['release_time'] ?? null
);
$releaseTimeOptions = [
    '09:00:00' => '9:00 AM',
    '10:00:00' => '10:00 AM',
    '11:00:00' => '11:00 AM',
    '13:00:00' => '1:00 PM',
    '14:00:00' => '2:00 PM',
    '15:00:00' => '3:00 PM',
];

$docs = $db->prepare('SELECT * FROM request_documents WHERE request_id = ?');
$docs->execute([$requestId]);
$documents = $docs->fetchAll();
$attachmentGroups = getRequestAttachmentsGrouped($requestId);

$payment = $db->prepare('SELECT * FROM payments WHERE request_id = ? ORDER BY created_at DESC LIMIT 1');
$payment->execute([$requestId]);
$paymentData = $payment->fetch();

$pageTitle = 'Review ' . $request['request_number'];
$activeNav = 'compliance';

$phase = match (true) {
    in_array($request['status'], ['submitted', 'under_review'], true) => 1,
    in_array($request['status'], ['awaiting_requirements', 'needs_revision'], true)
        && !studentRequirementsComplete($requestId) => 2,
    $request['status'] === 'requirements_submitted' => 3,
    in_array($request['status'], ['awaiting_requirements', 'needs_revision'], true)
        && studentRequirementsComplete($requestId) => 3,
    $request['status'] === 'requirements_verified' => 4,
    $request['status'] === 'payment_verified' => 5,
    $request['status'] === 'processing' && requestHasPendingAssignmentItems($requestId) => 5,
    default => 6,
};

require_once __DIR__ . '/../includes/header.php';
?>



<div class="workflow-banner">

    <span class="badge badge-review"><?= e(workflowPhaseLabel($request['status'])) ?></span>

</div>



<div class="grid-2">

    <div class="card">

        <div class="card-header">

            <h2>Request & Requester Details</h2>

            <?= statusBadge($request['status']) ?>

        </div>

        <div class="card-body">

            <div class="detail-grid">

                <div class="detail-item"><label>Request #</label><span><?= e($request['request_number']) ?></span></div>

                <?php if (($request['request_channel'] ?? 'online') === 'onsite'): ?>
                <div class="detail-item"><label>Channel</label><span><span class="badge badge-processing">Onsite Walk-in</span></span></div>
                <?php endif; ?>

                <div class="detail-item full">
                    <label>Documents (<?= count($requestItems) ?>)</label>
                    <div class="request-items-summary-list">
                        <?php foreach ($requestItems as $requestItem): ?>
                            <div class="request-item-summary-row">
                                <strong><?= e($requestItem['document_name']) ?></strong>
                                <span><?= (int) $requestItem['copies'] ?> cop<?= (int) $requestItem['copies'] === 1 ? 'y' : 'ies' ?></span>
                                <span><?= formatMoney((float) $requestItem['item_amount']) ?></span>
                                <?= requestItemStatusBadge($requestItem['item_status']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="detail-item"><label>Purpose</label><span><?= purposeLabel($request['purpose']) ?></span></div>
                <div class="detail-item"><label>Request Type</label><span><?= e(copyRequestTypeLabel($request['copy_request_type'] ?? null)) ?><?= ($request['copy_request_type'] ?? '') === 'second_copy' ? ' <span class="badge badge-processing">Affidavit may be required</span>' : '' ?></span></div>

                <div class="detail-item"><label>Amount</label><span><?= formatMoney((float)$request['total_amount']) ?></span></div>

                <div class="detail-item"><label>Delivery</label><span><?= e(deliveryMethodLabel($request['delivery_method'])) ?></span></div>

                <?php if ($request['delivery_method'] === 'authorized_representative'): ?>
                    <?= renderRepresentativePickupDetailsHtml($requestId, $request) ?>
                <?php endif; ?>

                <div class="detail-item"><label>Student</label><span><?= e($request['first_name'] . ' ' . $request['last_name']) ?></span></div>

                <div class="detail-item"><label>Student ID</label><span><?= e($request['student_id']) ?></span></div>

                <div class="detail-item"><label>Email</label><span><?= e($request['email']) ?></span></div>

                <div class="detail-item"><label>Phone</label><span><?= e($request['phone'] ?? '—') ?></span></div>

                <div class="detail-item"><label>Course</label><span><?= e($request['course'] ?? '—') ?></span></div>

                <div class="detail-item"><label>Year Level</label><span><?= e($request['year_level'] ?? '—') ?></span></div>

            </div>



            <?php if ($attachmentGroups['total'] > 0): ?>
                <div class="attachment-summary-bar">
                    <div>
                        <h4><i class="fas fa-paperclip"></i> Requestor Attachments (<?= (int) $attachmentGroups['total'] ?>)</h4>
                        <p class="text-muted">Initial: <?= count($attachmentGroups['initial']) ?> · Requirements: <?= count($attachmentGroups['requirements']) ?> · Representative: <?= count($attachmentGroups['representative']) ?> · Receipts: <?= count($attachmentGroups['payments']) ?></p>
                    </div>
                    <a href="view-attachments.php?id=<?= $requestId ?>" class="btn btn-outline btn-sm">
                        <i class="fas fa-eye"></i> View All Attachments
                    </a>
                </div>
                <ul class="doc-list compact-doc-list">
                    <?php foreach (array_slice(array_merge($attachmentGroups['initial'], $attachmentGroups['requirements']), 0, 4) as $doc): ?>
                        <li>
                            <i class="fas <?= e(attachmentIcon(attachmentFileExt($doc['original_name'] ?? $doc['file_name']))) ?>"></i>
                            <a href="<?= e(attachmentUrl($doc['file_name'])) ?>" target="_blank"><?= e($doc['original_name']) ?></a>
                            <?php if (!empty($doc['requirement_name'])): ?>
                                <small class="text-muted">— <?= e($doc['requirement_name']) ?></small>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif (!empty($documents)): ?>
                <h4 style="margin-top:1rem">Initial Submissions</h4>
                <ul class="doc-list">
                    <?php foreach ($documents as $doc): ?>
                        <li>
                            <i class="fas fa-file"></i>
                            <a href="<?= e(attachmentUrl($doc['file_name'])) ?>" target="_blank"><?= e($doc['original_name']) ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>



            <?php if ($paymentData): ?>

                <h4 style="margin-top:1rem">Payment</h4>

                <p><?= statusBadge($paymentData['status']) ?> — <?= formatMoney((float)$paymentData['amount']) ?></p>
                <?php if (($paymentData['payment_method'] ?? '') === 'onsite_payment' && !empty($paymentData['reference_number'])): ?>
                    <p class="onsite-payment-code-alert alert alert-info">
                        Cashier payment code:
                        <span class="onsite-payment-code"><?= e($paymentData['reference_number']) ?></span>
                    </p>
                    <?php if (($request['request_channel'] ?? '') === 'onsite'): ?>
                        <a href="onsite-request-slip.php?id=<?= (int) $requestId ?>&print=1" target="_blank" class="btn btn-outline btn-sm">
                            <i class="fas fa-print"></i> Print Request Slip
                        </a>
                    <?php endif; ?>
                <?php endif; ?>

            <?php endif; ?>



            <?php if (!empty($requestItems)): ?>
                <h4 style="margin-top:1rem">Document Details</h4>
                <?php foreach ($requestItems as $requestItem): ?>
                    <?= renderRequestItemDetailsHtml($requestItem) ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($request['assigned_to'] || array_filter($requestItems, static fn($item) => !empty($item['assigned_to']))): ?>

                <h4 style="margin-top:1rem">Staff Assignments</h4>
                <?php foreach ($requestItems as $requestItem): ?>
                    <?php if (!empty($requestItem['assigned_to'])): ?>
                        <p>
                            <strong><?= e($requestItem['document_name']) ?>:</strong>
                            <?= e(($requestItem['staff_first'] ?? '') . ' ' . ($requestItem['staff_last'] ?? '')) ?>
                            <?php if (!empty($requestItem['release_date'])): ?>
                                — <?= formatDate($requestItem['release_date']) ?> at <?= date('g:i A', strtotime((string) $requestItem['release_time'])) ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                <?php endforeach; ?>

            <?php endif; ?>



            <?php if (($summary && $summary['remarks']) || !empty(getRegistrarInstructionAttachments($requestId))): ?>

                <h4 style="margin-top:1rem">Registrar Notes</h4>

                <?php if ($summary && $summary['remarks']): ?>
                    <p class="text-muted"><?= e($summary['remarks']) ?></p>
                <?php endif; ?>
                <?= renderRegistrarInstructionAttachmentsHtml($requestId) ?>

            <?php endif; ?>

        </div>

    </div>



    <div class="card">

        <div class="card-header"><h2>Registrar Actions</h2></div>

        <div class="card-body">



            <?php if ($phase === 1): ?>

                <div class="alert alert-info">

                    <i class="fas fa-info-circle"></i>

                    <strong>Step 2:</strong>
                    <?php if ($requirementsRequired): ?>
                        Review this request and set the necessary requirements and attachments to confirm it.
                    <?php else: ?>
                        This credential is configured with no requirements. Confirm to send the student directly to payment.
                    <?php endif; ?>

                </div>



                <form method="POST" enctype="multipart/form-data" class="form-grid" id="requirementsForm">

                    <?= csrfField() ?>

                    <input type="hidden" name="action" value="confirm_request">

                    <?php if ($requirementsRequired): ?>

                    <label class="complete-requirements-option" for="completeRequirementsCheck">
                        <input
                            type="checkbox"
                            id="completeRequirementsCheck"
                            name="complete_requirements"
                            value="1"
                        >
                        <div>
                            <strong>Complete requirements</strong>
                            <p class="text-muted">Check this if the student already completed all requirements on file. This clears the pre-selected checklist from admin settings and sends the student directly to payment.</p>
                        </div>
                    </label>

                    <div class="compliance-checklist registrar-checklist" id="registrarRequirementChecklist">
                        <?php foreach ($requirementChecklist as $code => $item): ?>
                            <?php
                            $subcategories = getRequirementSubcategories($code, true);
                            $hasSubcategories = !empty($subcategories);
                            $checked = in_array($code, $assignedCodes, true)
                                || (empty($assignedCodes) && in_array($code, $defaultCodes, true));
                            $assignedSubsForParent = $assignedSubcodes[$code] ?? [];
                            ?>
                            <div class="compliance-item<?= $hasSubcategories ? ' compliance-item-with-subs' : '' ?>">
                                <label class="compliance-item-main">
                                    <input
                                        type="checkbox"
                                        name="req_codes[]"
                                        value="<?= e($code) ?>"
                                        class="req-parent-check"
                                        data-req-parent="<?= e($code) ?>"
                                        data-default-checked="<?= $checked ? '1' : '0' ?>"
                                        <?= $checked ? 'checked' : '' ?>
                                    >
                                    <div>
                                        <strong><?= e($item['name']) ?></strong>
                                        <?php if ($hasSubcategories): ?>
                                            <span class="badge badge-review"><?= count($subcategories) ?> subcategory document<?= count($subcategories) === 1 ? '' : 's' ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($item['optional'])): ?><span class="badge badge-review">Optional — 2nd request</span><?php endif; ?>
                                        <?php if (!$item['requires_upload']): ?><span class="badge badge-submitted">Online clearance</span><?php endif; ?>
                                        <p><?= e($item['description']) ?></p>
                                    </div>
                                </label>
                                <?php if ($hasSubcategories): ?>
                                    <div
                                        class="requirement-subchecklist"
                                        data-subchecklist="<?= e($code) ?>"
                                        <?= $checked ? '' : 'hidden' ?>
                                    >
                                        <p class="requirement-subchecklist-hint">Select the subcategory documents to require:</p>
                                        <?php foreach ($subcategories as $sub): ?>
                                            <?php
                                            $subChecked = in_array((string) $sub['code'], $assignedSubsForParent, true)
                                                || ($checked && empty($assignedSubsForParent));
                                            ?>
                                            <label class="compliance-item requirement-subcheck">
                                                <input
                                                    type="checkbox"
                                                    name="req_subcodes[<?= e($code) ?>][]"
                                                    value="<?= e($sub['code']) ?>"
                                                    class="req-sub-check"
                                                    data-req-parent="<?= e($code) ?>"
                                                    data-default-checked="<?= $subChecked ? '1' : '0' ?>"
                                                    <?= $subChecked ? 'checked' : '' ?>
                                                >
                                                <div>
                                                    <strong><?= e($sub['name']) ?></strong>
                                                    <?php if (!empty($sub['description'])): ?>
                                                        <p><?= e($sub['description']) ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php else: ?>

                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        No clearance, uploads, or other requirements are configured for this credential type.
                    </div>

                    <?php endif; ?>

                    <div class="form-group">
                        <label for="remarks">Instructions to Requester</label>
                        <textarea id="remarks" name="remarks" rows="3" placeholder="Additional notes for the student..."><?= e($summary['remarks'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="instruction_attachments">Instruction Attachments <span class="text-muted">(optional)</span></label>
                        <input
                            type="file"
                            id="instruction_attachments"
                            name="instruction_attachments[]"
                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                            multiple
                        >
                        <small class="text-muted">
                            Attach sample forms, guides, or other files for the requestor (PDF, JPG, PNG, DOC/DOCX, max 5MB each).
                        </small>
                        <?= renderRegistrarInstructionAttachmentsHtml($requestId) ?>
                    </div>

                    <div class="action-buttons">

                        <button type="submit" class="btn btn-primary" id="confirmRequirementsBtn" data-default-confirm="<?= $requirementsRequired ? 'Confirm request and send requirements to the student?' : 'Confirm request and send the student to payment?' ?>" data-complete-confirm="Confirm request and mark requirements as already complete? The student will proceed directly to payment.">
                            <i class="fas fa-check"></i>
                            <span id="confirmRequirementsBtnLabel"><?= $requirementsRequired ? 'Confirm Request & Set Requirements' : 'Confirm Request & Send to Payment' ?></span>
                        </button>
                    </div>
                </form>

                <?php if ($requirementsRequired): ?>
                <script>
                (function () {
                    const root = document.getElementById('registrarRequirementChecklist');
                    const completeCheck = document.getElementById('completeRequirementsCheck');
                    const confirmBtn = document.getElementById('confirmRequirementsBtn');
                    const confirmBtnLabel = document.getElementById('confirmRequirementsBtnLabel');
                    if (!root) return;

                    function panelFor(parentCode) {
                        return root.querySelector('[data-subchecklist="' + parentCode + '"]');
                    }

                    function parentInput(parentCode) {
                        return root.querySelector('.req-parent-check[data-req-parent="' + parentCode + '"]');
                    }

                    function syncParent(parentCode) {
                        const parent = parentInput(parentCode);
                        const panel = panelFor(parentCode);
                        if (!parent || !panel) return;

                        panel.hidden = !parent.checked;
                        const subs = panel.querySelectorAll('.req-sub-check');
                        if (!parent.checked) {
                            subs.forEach(function (sub) { sub.checked = false; });
                            return;
                        }

                        const anyChecked = Array.prototype.some.call(subs, function (sub) { return sub.checked; });
                        if (!anyChecked) {
                            subs.forEach(function (sub) { sub.checked = true; });
                        }
                    }

                    function clearAllRequirements() {
                        root.querySelectorAll('.req-parent-check, .req-sub-check').forEach(function (input) {
                            input.checked = false;
                        });
                        root.querySelectorAll('[data-subchecklist]').forEach(function (panel) {
                            panel.hidden = true;
                        });
                    }

                    function restoreDefaultRequirements() {
                        root.querySelectorAll('.req-sub-check').forEach(function (sub) {
                            sub.checked = sub.getAttribute('data-default-checked') === '1';
                        });
                        root.querySelectorAll('.req-parent-check').forEach(function (parent) {
                            parent.checked = parent.getAttribute('data-default-checked') === '1';
                            syncParent(parent.getAttribute('data-req-parent'));
                        });
                    }

                    function setChecklistDisabled(disabled) {
                        root.classList.toggle('is-disabled', disabled);
                        root.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
                            input.disabled = disabled;
                        });
                    }

                    function syncCompleteRequirementsState() {
                        const complete = !!(completeCheck && completeCheck.checked);
                        setChecklistDisabled(complete);
                        if (complete) {
                            clearAllRequirements();
                            if (confirmBtnLabel) {
                                confirmBtnLabel.textContent = 'Confirm Request & Send to Payment';
                            }
                        } else {
                            restoreDefaultRequirements();
                            if (confirmBtnLabel) {
                                confirmBtnLabel.textContent = 'Confirm Request & Set Requirements';
                            }
                        }
                    }

                    if (completeCheck) {
                        completeCheck.addEventListener('change', syncCompleteRequirementsState);
                    }

                    root.querySelectorAll('.req-parent-check').forEach(function (parent) {
                        parent.addEventListener('change', function () {
                            syncParent(parent.getAttribute('data-req-parent'));
                        });
                    });

                    root.querySelectorAll('.req-sub-check').forEach(function (sub) {
                        sub.addEventListener('change', function () {
                            const parentCode = sub.getAttribute('data-req-parent');
                            const parent = parentInput(parentCode);
                            const panel = panelFor(parentCode);
                            if (!parent || !panel) return;

                            const subs = panel.querySelectorAll('.req-sub-check');
                            const anyChecked = Array.prototype.some.call(subs, function (item) { return item.checked; });
                            if (anyChecked) {
                                parent.checked = true;
                                panel.hidden = false;
                            } else {
                                parent.checked = false;
                                panel.hidden = true;
                            }
                        });
                    });

                    const form = document.getElementById('requirementsForm');
                    if (form) {
                        form.addEventListener('submit', function (event) {
                            const complete = !!(completeCheck && completeCheck.checked);
                            const confirmMessage = complete
                                ? (confirmBtn ? confirmBtn.getAttribute('data-complete-confirm') : '')
                                : (confirmBtn ? confirmBtn.getAttribute('data-default-confirm') : '');
                            if (confirmMessage && !window.confirm(confirmMessage)) {
                                event.preventDefault();
                                return;
                            }

                            if (complete) {
                                return;
                            }

                            const openParents = root.querySelectorAll('.req-parent-check:checked');
                            for (let i = 0; i < openParents.length; i++) {
                                const parentCode = openParents[i].getAttribute('data-req-parent');
                                const panel = panelFor(parentCode);
                                if (!panel) continue;
                                const checkedSubs = panel.querySelectorAll('.req-sub-check:checked');
                                if (checkedSubs.length === 0) {
                                    event.preventDefault();
                                    var titleEl = openParents[i].closest('.compliance-item');
                                    titleEl = titleEl ? titleEl.querySelector('strong') : null;
                                    alert('Select at least one subcategory document under "' + (titleEl ? titleEl.textContent : parentCode) + '".');
                                    return;
                                }
                            }
                        });
                    }
                })();
                </script>
                <?php endif; ?>

                <form method="POST" style="margin-top:1rem">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reject">
                    <div class="form-group">
                        <label for="reject_remarks">Rejection Reason</label>
                        <textarea id="reject_remarks" name="remarks" rows="2" required placeholder="Reason for rejecting this request..."></textarea>
                    </div>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Reject this request?')">
                        <i class="fas fa-times"></i> Reject Request
                    </button>
                </form>



            <?php elseif ($phase === 2): ?>

                <div class="alert alert-warning">

                    <i class="fas fa-hourglass-half"></i>

                    Waiting for the student to complete the assigned requirements.

                </div>

                <?php if (!empty($assignedRequirements)): ?>
                    <ul class="doc-list">
                        <?php foreach (groupAssignedRequirements($assignedRequirements) as $group): ?>
                            <?php
                            $hasSubcats = count($group['items']) > 1 || !empty($group['items'][0]['subcategory_code']);
                            ?>
                            <?php if ($hasSubcats): ?>
                                <li>
                                    <strong><?= e($group['name']) ?></strong>
                                    <ul class="doc-list requirement-sublist">
                                        <?php foreach ($group['items'] as $req): ?>
                                            <li>
                                                <?= e($req['requirement_name']) ?>
                                                — <?= $req['document_id']
                                                    ? '<span class="badge badge-completed">Uploaded</span>'
                                                    : '<span class="badge badge-submitted">Pending upload</span>' ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </li>
                            <?php else: ?>
                                <?php $req = $group['items'][0]; ?>
                                <li>
                                    <strong><?= e($req['requirement_name']) ?></strong>
                                    <?php if (($req['requirement_code'] ?? '') === 'online_clearance'): ?>
                                        — <?= isClearanceComplete($requestId) ? '<span class="badge badge-completed">Complete</span>' : '<span class="badge badge-submitted">In progress</span>' ?>
                                    <?php elseif ($req['requires_upload']): ?>
                                        — <?= $req['document_id'] ? '<span class="badge badge-completed">Uploaded</span>' : '<span class="badge badge-submitted">Pending upload</span>' ?>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (hasAssignedRequirement($requestId, 'online_clearance')): ?>
                        <?= renderClearanceGrid($requestId, true) ?>
                    <?php endif; ?>
                <?php endif; ?>



            <?php elseif ($phase === 3 && in_array($request['status'], ['requirements_submitted', 'awaiting_requirements', 'needs_revision'], true)): ?>

                <div class="alert alert-info">

                    <i class="fas fa-search"></i>

                    <strong>Step 3:</strong> Re-evaluate the completed requirements. Payment is allowed only after approval.

                </div>



                <form method="POST" enctype="multipart/form-data" class="form-grid">

                    <?= csrfField() ?>



                    <?php if (hasAssignedRequirement($requestId, 'online_clearance')): ?>
                        <?= renderClearanceGrid($requestId) ?>
                    <?php endif; ?>

                    <div class="compliance-checklist">
                        <?php foreach (groupAssignedRequirements($assignedRequirements) as $group): ?>
                            <?php
                            $hasSubcats = count($group['items']) > 1 || !empty($group['items'][0]['subcategory_code']);
                            ?>
                            <?php if ($hasSubcats): ?>
                                <div class="requirement-group">
                                    <h4 class="requirement-group-title"><i class="fas fa-folder-open"></i> <?= e($group['name']) ?></h4>
                            <?php endif; ?>
                            <?php foreach ($group['items'] as $req): ?>
                                <?php $isClearance = ($req['requirement_code'] ?? '') === 'online_clearance'; ?>
                                <label class="compliance-item<?= $hasSubcats ? ' requirement-subitem' : '' ?>">
                                    <input type="checkbox" name="checks[<?= $req['id'] ?>]" value="1"
                                        <?= ($isClearance && isClearanceComplete($requestId)) || $req['is_met'] || ($req['requires_upload'] && $req['document_id']) ? 'checked' : '' ?>
                                        <?= $isClearance ? 'disabled' : '' ?>>
                                    <?php if ($isClearance): ?>
                                        <input type="hidden" name="checks[<?= $req['id'] ?>]" value="<?= isClearanceComplete($requestId) ? '1' : '0' ?>">
                                    <?php endif; ?>
                                    <div>
                                        <strong><?= e($req['requirement_name']) ?></strong>
                                        <?php if ($req['description']): ?><p><?= e($req['description']) ?></p><?php endif; ?>
                                        <?php if ($isClearance): ?>
                                            <p><?= isClearanceComplete($requestId) ? '<span class="badge badge-completed">All offices cleared</span>' : '<span class="badge badge-submitted">Clearance incomplete</span>' ?></p>
                                        <?php elseif ($req['requires_upload'] && $req['file_name']): ?>
                                            <p>
                                                <a href="<?= e(attachmentUrl($req['file_name'])) ?>" target="_blank"><i class="fas fa-file"></i> <?= e($req['original_name']) ?></a>
                                                <a href="view-attachments.php?id=<?= $requestId ?>" class="btn btn-sm btn-outline" style="margin-left:.5rem">View</a>
                                            </p>
                                        <?php elseif ($req['requires_upload']): ?>
                                            <p class="text-muted">No file uploaded</p>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                            <?php if ($hasSubcats): ?></div><?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if (hasAssignedRequirement($requestId, 'online_clearance') && !isClearanceComplete($requestId)): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> Online clearance must be completed by all offices before approval.
                        </div>
                    <?php endif; ?>



                    <div class="form-group">
                        <label for="remarks">Remarks / Instructions to Requester</label>
                        <textarea id="remarks" name="remarks" rows="3"><?= e($summary['remarks'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="instruction_attachments_review">Instruction Attachments <span class="text-muted">(optional)</span></label>
                        <input
                            type="file"
                            id="instruction_attachments_review"
                            name="instruction_attachments[]"
                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                            multiple
                        >
                        <small class="text-muted">
                            Attach additional instruction files when sending the request back for revision.
                        </small>
                        <?= renderRegistrarInstructionAttachmentsHtml($requestId) ?>
                    </div>

                    <div class="action-buttons">

                        <button type="submit" name="action" value="approve_for_payment" class="btn btn-primary"
                            <?= hasAssignedRequirement($requestId, 'online_clearance') && !isClearanceComplete($requestId) ? 'disabled' : '' ?>
                            onclick="return confirm('Approve requirements and allow payment?')">

                            <i class="fas fa-check-circle"></i> Approve for Payment

                        </button>

                        <button type="submit" name="action" value="needs_revision" class="btn btn-outline"

                            onclick="return document.getElementById('remarks').value.trim() !== '' || (alert('Please provide revision remarks.'), false)">

                            <i class="fas fa-redo"></i> Needs Revision

                        </button>

                        <button type="submit" name="action" value="reject" class="btn btn-danger"

                            onclick="return document.getElementById('remarks').value.trim() !== '' || (alert('Please provide rejection reason.'), false)">

                            <i class="fas fa-times"></i> Reject

                        </button>

                    </div>

                </form>



            <?php elseif ($request['status'] === 'requirements_verified'): ?>

                <div class="alert alert-info">

                    <i class="fas fa-credit-card"></i>

                    <strong>Step 4:</strong> Requirements approved. Awaiting student payment and cashier verification.

                </div>



            <?php elseif ($phase === 5): ?>

                <div class="alert alert-info">

                    <i class="fas fa-user-check"></i>

                    <strong>Step 5:</strong> Assign processing personnel per document. You can assign to a Registrar, Registrar Staff, Cashier, or Guidance Office account (e.g. SOA → Cashier, Good Moral → Guidance).

                </div>

                <form method="POST" class="form-grid">

                    <?= csrfField() ?>

                    <input type="hidden" name="action" value="assign_processing">

                    <?php if (count($requestItems) === 1): ?>
                        <?php
                        $singleItem = $requestItems[0];
                        $singleSchedule = $itemSchedules[(int) $singleItem['id']] ?? $releaseSchedule;
                        $preferredOffice = getDocumentAssignmentOffice(
                            (int) ($singleItem['document_type_id'] ?? 0),
                            $singleItem['document_code'] ?? null
                        );
                        ?>
                        <input type="hidden" name="item_assignments[<?= (int) $singleItem['id'] ?>][assigned_to]" value="" disabled>
                        <div class="form-group">
                            <label for="assigned_to">
                                <?= e($singleItem['document_name']) ?> — Assign to *
                                <span class="badge badge-review">Suggested: <?= e(assignmentOfficeLabel($preferredOffice)) ?></span>
                            </label>
                            <?= renderAssigneeSelectHtml('assigned_to', $staffUsers, $preferredOffice, true, 'assigned_to') ?>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="release_date">On-Site Release Date *</label>
                                <input type="date" id="release_date" name="release_date" value="<?= e($singleSchedule['release_date']) ?>" min="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="release_time">Release Time *</label>
                                <select id="release_time" name="release_time" required>
                                    <?php foreach ($releaseTimeOptions as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= ($singleSchedule['release_time'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="request-item-assignment-table-wrap">
                            <table class="data-table request-item-assignment-table">
                                <thead>
                                    <tr>
                                        <th>Document</th>
                                        <th>Assign To *</th>
                                        <th>Release Date *</th>
                                        <th>Release Time *</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requestItems as $requestItem): ?>
                                        <?php
                                        $itemId = (int) $requestItem['id'];
                                        $schedule = $itemSchedules[$itemId] ?? $releaseSchedule;
                                        $isAssigned = ($requestItem['item_status'] ?? '') !== 'pending_assignment';
                                        $preferredOffice = getDocumentAssignmentOffice(
                                            (int) ($requestItem['document_type_id'] ?? 0),
                                            $requestItem['document_code'] ?? null
                                        );
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= e($requestItem['document_name']) ?></strong>
                                                <br><small class="text-muted"><?= (int) $requestItem['copies'] ?> cop<?= (int) $requestItem['copies'] === 1 ? 'y' : 'ies' ?> · <?= formatMoney((float) $requestItem['item_amount']) ?></small>
                                                <br><span class="badge badge-review">Suggested: <?= e(assignmentOfficeLabel($preferredOffice)) ?></span>
                                                <?php if ($isAssigned): ?>
                                                    <br><?= requestItemStatusBadge($requestItem['item_status']) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isAssigned && !empty($requestItem['staff_first'])): ?>
                                                    <span><?= e($requestItem['staff_first'] . ' ' . $requestItem['staff_last']) ?></span>
                                                <?php else: ?>
                                                    <?= renderAssigneeSelectHtml(
                                                        'item_assignments[' . $itemId . '][assigned_to]',
                                                        $staffUsers,
                                                        $preferredOffice
                                                    ) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isAssigned): ?>
                                                    <?= !empty($requestItem['release_date']) ? e(formatDate($requestItem['release_date'])) : '—' ?>
                                                <?php else: ?>
                                                    <input type="date" name="item_assignments[<?= $itemId ?>][release_date]" value="<?= e($schedule['release_date']) ?>" min="<?= date('Y-m-d') ?>" required>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isAssigned): ?>
                                                    <?= !empty($requestItem['release_time']) ? e(date('g:i A', strtotime((string) $requestItem['release_time']))) : '—' ?>
                                                <?php else: ?>
                                                    <select name="item_assignments[<?= $itemId ?>][release_time]" required>
                                                        <?php foreach ($releaseTimeOptions as $value => $label): ?>
                                                            <option value="<?= $value ?>" <?= ($schedule['release_time'] ?? '') === $value ? 'selected' : '' ?>><?= $label ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">

                        <label for="remarks">Processing Notes</label>

                        <textarea id="remarks" name="remarks" rows="2" placeholder="Optional notes for staff..."></textarea>

                    </div>



                    <button type="submit" class="btn btn-primary">

                        <i class="fas fa-play"></i> Assign & Start Processing

                    </button>

                </form>

            <?php elseif ($phase === 6 && (isOnSitePickupMethod($request['delivery_method']) || isPickupOptionPending($request['delivery_method'] ?? null)) && in_array($request['status'], ['processing', 'ready_for_pickup'], true)): ?>

                <div class="alert alert-info">
                    <i class="fas fa-calendar-alt"></i>
                    <strong>Step 6:</strong> Document release. Update the on-site release schedule if needed before the student collects the document.
                </div>

                <form method="POST" class="form-grid release-schedule-panel">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_release_schedule">

                    <div class="alert alert-info release-schedule-hint">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>System suggested date:</strong>
                            <?= formatDate($releaseSchedule['suggested_date']) ?>
                            (<?= (int) $releaseSchedule['processing_days'] ?> working day(s) for <?= e($request['document_name']) ?>, excluding weekends)
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_release_date">On-Site Release Date *</label>
                            <input type="date" id="edit_release_date" name="release_date"
                                value="<?= e($request['release_date'] ?? $releaseSchedule['suggested_date']) ?>"
                                data-suggested-date="<?= e($releaseSchedule['suggested_date']) ?>"
                                data-suggested-time="<?= e($releaseSchedule['suggested_time']) ?>"
                                min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_release_time">Release Time *</label>
                            <select id="edit_release_time" name="release_time" required>
                                <?php
                                $currentTime = $request['release_time'] ?? $releaseSchedule['release_time'];
                                foreach ($releaseTimeOptions as $value => $label):
                                ?>
                                    <option value="<?= $value ?>" <?= $currentTime === $value ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <button type="button" class="btn btn-outline" id="resetReleaseScheduleEdit">
                            <i class="fas fa-undo"></i> Reset to suggested date
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Release Schedule
                        </button>
                    </div>
                </form>

            <?php else: ?>

                <?php if (in_array($request['status'], ['processing', 'ready_for_pickup', 'shipped', 'completed'], true)): ?>

                    <div class="alert alert-success">
                        <i class="fas fa-print"></i>
                        This request is in processing. Print the claim stub for the student to present when claiming the document.
                    </div>
                    <a href="claim-stub.php?id=<?= $requestId ?>&print=1" target="_blank" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Claim Stub
                    </a>
                    <a href="claim-stub.php?id=<?= $requestId ?>" target="_blank" class="btn btn-outline">
                        <i class="fas fa-eye"></i> Preview Claim Stub
                    </a>

                <?php else: ?>

                    <p class="text-muted">No registrar action required at this stage.</p>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>

</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>

