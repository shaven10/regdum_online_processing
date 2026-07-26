<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/student.php';
requireRole('student');
$user = currentUser();

ensureDeliveryMethods();
ensureRequestCopyTypeSchema();
ensureStudentEmploymentFields();
ensureAcademicProgramsSchema();
ensureEnrollmentStatuses();
ensureCampusesSchema();
ensureStudentAcademicTermFields();
ensureStudentValidIdField();
ensureDocumentEnrollmentRulesSchema();
ensureDocumentTypeFeeSchema();
ensureRequestTermInfoSchema();
ensureRequestAuthenticationTypeSchema();
ensureStatementOfAccountSchema();
ensureRequestPurposesSchema();
ensureComplianceSchema();
require_once __DIR__ . '/../includes/clearance.php';
require_once __DIR__ . '/../includes/request-items.php';
ensureClearanceSchema();
ensureRequestItemsSchema();

$db = getDB();
$profileCompletion = getStudentProfileCompletion($user['id']);
$studentProfile = getStudentProfile($user['id']);
$enrollmentStatus = getStudentEnrollmentStatus($user['id']);
$docTypes = getAvailableDocumentTypesForEnrollment($enrollmentStatus);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    if (!$profileCompletion['complete']) {
        setFlash('error', 'Complete your profile before submitting a document request.', [
            'title' => 'Profile Incomplete',
            'details' => array_values($profileCompletion['missing']),
            'action_url' => APP_URL . '/student/profile.php',
            'action_label' => 'Complete profile',
        ]);
        redirect(APP_URL . '/student/new-request.php');
    }

    $postedCopies = $_POST['document_copies'] ?? [];
    $postedTermLines = $_POST['document_term_lines'] ?? [];
    $postedAuthItems = $_POST['document_auth_items'] ?? [];
    $docTypesById = [];
    foreach ($docTypes as $docTypeRow) {
        $docTypesById[(int) $docTypeRow['id']] = $docTypeRow;
    }
    $data = [
        'document_type_ids'  => array_values(array_unique(array_filter(array_map('intval', $_POST['document_type_ids'] ?? [])))),
        'purpose'            => $_POST['purpose'] ?? '',
        'purpose_other'      => trim($_POST['purpose_other'] ?? ''),
        'copy_request_type'  => $_POST['copy_request_type'] ?? '',
        'notes'              => trim($_POST['notes'] ?? ''),
    ];

    $normalizeTermLines = static function ($rawLines): array {
        $lines = [];
        foreach ((array) $rawLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $schoolYear = trim((string) ($line['school_year'] ?? ''));
            $semester = trim((string) ($line['semester'] ?? ''));
            $copies = max(1, (int) ($line['copies'] ?? 1));
            if ($schoolYear === '' && $semester === '') {
                continue;
            }
            $lines[] = [
                'school_year' => $schoolYear,
                'semester' => $semester,
                'copies' => $copies,
            ];
        }
        return array_values($lines);
    };

    $validDocTypeIds = validateActiveDocumentTypeIdsForEnrollment($data['document_type_ids'], $enrollmentStatus);
    if (empty($validDocTypeIds)) {
        $errors['document_type_ids'] = empty($data['document_type_ids'])
            ? 'Please select at least one document to request.'
            : 'One or more selected documents are not available for your enrollment status.';
    } elseif (count($validDocTypeIds) !== count($data['document_type_ids'])) {
        $errors['document_type_ids'] = 'One or more selected documents are not available for your enrollment status.';
    }

    $validatedTermLinesByDoc = [];

    foreach ($validDocTypeIds as $documentTypeId) {
        $docType = $docTypesById[$documentTypeId] ?? null;

        if ($docType && documentTypeRequiresAuthDocumentType($docType)) {
            $authItems = normalizeAuthenticationItems($postedAuthItems[$documentTypeId] ?? []);
            $authError = validateAuthenticationItems($authItems);
            if ($authError) {
                $errors['document_auth_type_' . $documentTypeId] = $authError;
            }
            continue;
        }

        if ($docType && documentTypeRequiresTermInfo($docType)) {
            $termLines = $normalizeTermLines($postedTermLines[$documentTypeId] ?? []);
            if ($termLines === []) {
                $errors['document_term_' . $documentTypeId] = 'Add at least one school year and semester for this document.';
                continue;
            }

            $seenTerms = [];
            foreach ($termLines as $lineIndex => $termLine) {
                $termError = validateRequestTermFields($termLine['school_year'], $termLine['semester']);
                if ($termError) {
                    $errors['document_term_' . $documentTypeId] = $termError;
                    break;
                }

                $copyError = validateStudentDocumentRequest($documentTypeId, $enrollmentStatus, (int) $termLine['copies']);
                if ($copyError) {
                    $errors['document_type_ids'] = $copyError;
                    break 2;
                }

                $termKey = $termLine['school_year'] . '|' . $termLine['semester'];
                if (isset($seenTerms[$termKey])) {
                    $errors['document_term_' . $documentTypeId] = 'Each school year and semester combination can only be added once for this document.';
                    break;
                }
                $seenTerms[$termKey] = true;
                $validatedTermLinesByDoc[$documentTypeId][] = $termLine;
            }
            continue;
        }

        $copies = (int) ($postedCopies[$documentTypeId] ?? 1);
        $copyError = validateStudentDocumentRequest($documentTypeId, $enrollmentStatus, $copies);
        if ($copyError) {
            $errors['document_type_ids'] = $copyError;
            break;
        }
    }

    if (!$data['purpose']) {
        $errors['purpose'] = 'Please select a purpose.';
    } elseif (!isValidActiveRequestPurposeCode($data['purpose'], $enrollmentStatus)) {
        $errors['purpose'] = 'Please select a valid purpose for your enrollment status.';
    }

    if (!isValidCopyRequestType($data['copy_request_type'])) {
        $errors['copy_request_type'] = 'Please select whether this is a first request or a second copy.';
    }

    if (empty($errors)) {
        try {
        $requestNumber = generateRequestNumber();
        $batchTotal = 0.0;
        $itemDrafts = [];

        foreach ($validDocTypeIds as $documentTypeId) {
            $docType = $docTypesById[$documentTypeId] ?? null;
            $authItems = [];
            $maxCopies = getMaxCopiesForDocument($documentTypeId, $enrollmentStatus);

            if ($docType && documentTypeRequiresAuthDocumentType($docType)) {
                $authItems = normalizeAuthenticationItems($postedAuthItems[$documentTypeId] ?? []);
                $copies = max(1, totalAuthenticationSets($authItems));
                $itemAmount = calculateRequestFee($documentTypeId, $copies, $authItems ?: null);
                $itemDrafts[] = [
                    'document_type_id' => $documentTypeId,
                    'copies' => $copies,
                    'item_amount' => $itemAmount,
                    'request_school_year' => null,
                    'request_semester' => null,
                    'request_soa_assessment_scope' => null,
                    'request_soa_remarks' => null,
                    'auth_items' => $authItems,
                ];
                $batchTotal += $itemAmount;
                continue;
            }

            if ($docType && documentTypeRequiresTermInfo($docType)) {
                foreach ($validatedTermLinesByDoc[$documentTypeId] ?? [] as $termLine) {
                    $copies = max(1, min($maxCopies, (int) $termLine['copies']));
                    $itemAmount = calculateRequestFee($documentTypeId, $copies, null);
                    $itemDrafts[] = [
                        'document_type_id' => $documentTypeId,
                        'copies' => $copies,
                        'item_amount' => $itemAmount,
                        'request_school_year' => $termLine['school_year'],
                        'request_semester' => $termLine['semester'],
                        'request_soa_assessment_scope' => null,
                        'request_soa_remarks' => null,
                        'auth_items' => [],
                    ];
                    $batchTotal += $itemAmount;
                }
                continue;
            }

            $copies = max(1, min($maxCopies, (int) ($postedCopies[$documentTypeId] ?? 1)));
            $itemAmount = calculateRequestFee($documentTypeId, $copies, null);
            $itemDrafts[] = [
                'document_type_id' => $documentTypeId,
                'copies' => $copies,
                'item_amount' => $itemAmount,
                'request_school_year' => null,
                'request_semester' => null,
                'request_soa_assessment_scope' => null,
                'request_soa_remarks' => null,
                'auth_items' => [],
            ];
            $batchTotal += $itemAmount;
        }

        $primaryDocumentTypeId = $itemDrafts[0]['document_type_id'];
        $stmt = $db->prepare('INSERT INTO requests (
            request_number, user_id, document_type_id, purpose, purpose_other, copy_request_type, copies, delivery_method,
            pickup_date, pickup_time, representative_name, representative_relationship, representative_phone,
            representative_id_number, total_amount, verification_code, notes
        ) VALUES (?, ?, ?, ?, ?, ?, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?, ?, ?)');
        $stmt->execute([
            $requestNumber,
            $user['id'],
            $primaryDocumentTypeId,
            $data['purpose'],
            $data['purpose_other'] ?: null,
            $data['copy_request_type'],
            $batchTotal,
            generateVerificationCode(),
            $data['notes'] ?: null,
        ]);
        $requestId = (int) $db->lastInsertId();

        $autoAppliedCount = 0;
        foreach ($itemDrafts as $index => $draft) {
            $itemId = createRequestItem(
                $requestId,
                (int) $draft['document_type_id'],
                (int) $draft['copies'],
                (float) $draft['item_amount'],
                $index + 1,
                $draft['request_school_year'],
                $draft['request_semester'],
                $draft['request_soa_assessment_scope'],
                $draft['request_soa_remarks']
            );

            if (!empty($draft['auth_items'])) {
                saveRequestAuthenticationItems($requestId, $draft['auth_items'], $itemId);
            }

            initRequestCompliance($requestId, (int) $draft['document_type_id']);
        }

        $autoApplyResult = maybeAutoApplyOnRequestSubmission(
            $requestId,
            array_column($itemDrafts, 'document_type_id'),
            (string) $data['copy_request_type']
        );

        refreshRequestTotalAmount($requestId);

        $db->prepare('INSERT INTO request_status_history (request_id, new_status, changed_by, remarks) VALUES (?, ?, ?, ?)')
           ->execute([$requestId, 'submitted', $user['id'], 'Batch request submitted by student']);

        auditLog('create_request', 'requests', $requestId);

        $documentCount = count($itemDrafts);
        $studentName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        notifyRegistrarsNewRequest($requestId, $requestNumber, $studentName !== '' ? $studentName : 'A student', $documentCount);

        sendNotification(
            $user['id'],
            'Request Submitted',
            'Your request ' . $requestNumber . ' (' . $documentCount . ' document' . ($documentCount === 1 ? '' : 's') . ') has been submitted.',
            'success',
            APP_URL . '/student/request-view.php?id=' . $requestId
        );

        if ($autoApplyResult === 'payment') {
            setFlash('success', 'Request submitted successfully! No additional requirements are needed — you may proceed to payment.');
        } elseif ($autoApplyResult === 'affidavit') {
            setFlash('success', 'Request submitted successfully! Please upload the affidavit for your second copy request.');
        } else {
            setFlash('success', 'Request submitted successfully with ' . $documentCount . ' document' . ($documentCount === 1 ? '' : 's') . '! The Registrar will review and confirm the required requirements.');
        }

        redirect(APP_URL . '/student/request-view.php?id=' . $requestId);
        } catch (Throwable $e) {
            error_log('New request failed: ' . $e->getMessage());
            setFlash('error', 'Unable to submit your request. Please try again or contact the Registrar office.', [
                'title' => 'Submission Failed',
                'next_step' => 'If this keeps happening, ask an administrator to run install.php?step=upgrade.',
            ]);
            redirect(APP_URL . '/student/new-request.php');
        }
    }
}

$pageTitle = 'New Request';
$activeNav = 'new-request';
$selectedDocIds = array_map('intval', $_POST['document_type_ids'] ?? []);
$postedCopies = array_map('intval', $_POST['document_copies'] ?? []);
$postedTermLinesByDoc = $_POST['document_term_lines'] ?? [];
$postedAuthItems = $_POST['document_auth_items'] ?? [];
$defaultSchoolYear = trim((string) ($studentProfile['current_academic_year'] ?? ''));
$defaultSemester = trim((string) ($studentProfile['current_semester'] ?? ''));
$schoolYearChoices = schoolYearOptions();
$semesterChoices = semesterOptions();
$authDocumentTypeChoices = authenticationDocumentTypeOptions();
$purposeOptions = purposeOptions($enrollmentStatus);
$purposeSuggestions = [];
$purposeHints = [];
foreach ($purposeOptions as $purposeKey) {
    $purposeSuggestions[$purposeKey] = getSuggestedDocumentIdsForPurpose($purposeKey, $docTypes, $enrollmentStatus);
    $purposeHints[$purposeKey] = purposeSuggestionHint($purposeKey, $enrollmentStatus);
}
$selectedPurpose = (string) ($_POST['purpose'] ?? '');
$selectedCopyType = (string) ($_POST['copy_request_type'] ?? 'first_request');
if (!isValidCopyRequestType($selectedCopyType)) {
    $selectedCopyType = 'first_request';
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card request-form-card">
    <div class="card-header">
        <div>
            <h2>New Credential Request</h2>
            <p class="text-muted request-form-subtitle">Available for <?= e(enrollmentStatusLabel($enrollmentStatus)) ?> students</p>
        </div>
    </div>
    <div class="card-body">
        <?= renderStudentProfileIncompleteAlert($profileCompletion) ?>

        <?php if (!$profileCompletion['complete']): ?>
            <div class="empty-state">
                <i class="fas fa-user-check"></i>
                <p>Complete your profile before submitting a document request.</p>
                <a href="profile.php" class="btn btn-primary">Go to Profile</a>
            </div>
        <?php elseif (empty($docTypes)): ?>
            <div class="empty-state">
                <i class="fas fa-file-circle-xmark"></i>
                <p>No credentials are available for your enrollment status.</p>
                <a href="profile.php" class="btn btn-outline">Review Profile</a>
            </div>
        <?php else: ?>
        <form method="POST" class="form-grid request-form-simple" id="requestForm">
            <?= csrfField() ?>

            <section class="form-section request-form-step">
                <h3><span class="request-step-num">1</span> Purpose &amp; type</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="purpose">Purpose *</label>
                        <select id="purpose" name="purpose" required>
                            <option value="">— Select purpose —</option>
                            <?php foreach ($purposeOptions as $p): ?>
                                <option value="<?= $p ?>" <?= $selectedPurpose === $p ? 'selected' : '' ?>><?= purposeLabel($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['purpose'])): ?><span class="field-error"><?= e($errors['purpose']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="copy_request_type">Request Type *</label>
                        <select id="copy_request_type" name="copy_request_type" required>
                            <?php foreach (copyRequestTypeOptions() as $copyValue => $copyLabel): ?>
                                <option value="<?= e($copyValue) ?>" <?= $selectedCopyType === $copyValue ? 'selected' : '' ?>><?= e($copyLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['copy_request_type'])): ?>
                            <span class="field-error"><?= e($errors['copy_request_type']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-group" id="purposeOtherGroup" style="display:none">
                    <label for="purpose_other">Specify purpose</label>
                    <input type="text" id="purpose_other" name="purpose_other" value="<?= e($_POST['purpose_other'] ?? '') ?>" placeholder="Describe your purpose">
                </div>
                <div class="purpose-suggestion-panel" id="purposeSuggestionPanel" hidden>
                    <div class="purpose-suggestion-header">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Suggested for this purpose</strong>
                        <button type="button" class="btn btn-outline btn-sm" id="applyPurposeSuggestions">Apply</button>
                    </div>
                    <p class="purpose-suggestion-hint" id="purposeSuggestionHint"></p>
                    <ul class="purpose-suggestion-list" id="purposeSuggestionList"></ul>
                </div>
            </section>

            <section class="form-section request-form-step">
                <h3><span class="request-step-num">2</span> Select documents</h3>
                <div class="document-checklist">
                    <?php foreach ($docTypes as $dt): ?>
                        <?php
                        $maxCopies = max(1, (int) ($dt['max_copies'] ?? 10));
                        $docCopyCount = max(1, min($maxCopies, (int) ($postedCopies[$dt['id']] ?? 1)));
                        $requiresTermInfo = documentTypeRequiresTermInfo($dt);
                        $requiresAuthDocumentType = documentTypeRequiresAuthDocumentType($dt);
                        $feePerSet = documentTypeUsesFeePerSet($dt);
                        $rawTermLines = $postedTermLinesByDoc[$dt['id']] ?? null;
                        $termLines = [];
                        if (is_array($rawTermLines)) {
                            foreach ($rawTermLines as $line) {
                                if (!is_array($line)) {
                                    continue;
                                }
                                $termLines[] = [
                                    'school_year' => trim((string) ($line['school_year'] ?? $defaultSchoolYear)),
                                    'semester' => trim((string) ($line['semester'] ?? $defaultSemester)),
                                    'copies' => max(1, min($maxCopies, (int) ($line['copies'] ?? $docCopyCount))),
                                ];
                            }
                        }
                        if ($termLines === []) {
                            $termLines[] = [
                                'school_year' => $defaultSchoolYear,
                                'semester' => $defaultSemester,
                                'copies' => $docCopyCount,
                            ];
                        }
                        $termError = $errors['document_term_' . $dt['id']] ?? '';
                        $postedAuthForDoc = $postedAuthItems[$dt['id']] ?? [];
                        $authTypeError = $errors['document_auth_type_' . $dt['id']] ?? '';
                        $isSelected = in_array((int) $dt['id'], $selectedDocIds, true);
                        $itemStateClass = $isSelected ? 'is-expanded' : 'is-collapsed';
                        ?>
                        <div class="document-checklist-item <?= $itemStateClass ?>" data-doc-id="<?= (int) $dt['id'] ?>">
                            <div class="document-checklist-item-main">
                                <input type="checkbox"
                                    class="document-checklist-checkbox"
                                    name="document_type_ids[]"
                                    value="<?= (int) $dt['id'] ?>"
                                    data-base="<?= e((string) $dt['base_fee']) ?>"
                                    data-stamp="<?= !empty($dt['requires_documentary_stamp']) ? '1' : '0' ?>"
                                    data-stamp-fee="<?= e((string) documentStampFeeAmount()) ?>"
                                    data-doc-name="<?= e($dt['name']) ?>"
                                    data-doc-code="<?= e($dt['code']) ?>"
                                    data-max-copies="<?= $maxCopies ?>"
                                    data-requires-term="<?= $requiresTermInfo ? '1' : '0' ?>"
                                    data-requires-auth-type="<?= $requiresAuthDocumentType ? '1' : '0' ?>"
                                    data-fee-per-set="<?= $feePerSet ? '1' : '0' ?>"
                                    data-qty-label="<?= e(documentTypeQuantityLabel($dt)) ?>"
                                    id="doc_type_<?= (int) $dt['id'] ?>"
                                    aria-label="Select <?= e($dt['name']) ?>"
                                    <?= $isSelected ? 'checked' : '' ?>
                                    onchange="handleDocumentCheckboxChange(this);">
                                <button type="button"
                                    class="document-checklist-summary"
                                    aria-expanded="<?= $isSelected ? 'true' : 'false' ?>"
                                    aria-controls="doc_details_<?= (int) $dt['id'] ?>"
                                    data-document-checklist-toggle>
                                    <span class="document-checklist-header">
                                        <strong><?= e($dt['name']) ?></strong>
                                        <span class="document-checklist-fee"><?= formatDocumentTypeUnitFee($dt) ?></span>
                                    </span>
                                    <span class="document-checklist-summary-meta">
                                        <?= (int) $dt['processing_days'] ?> day<?= (int) $dt['processing_days'] === 1 ? '' : 's' ?>
                                        <?php if ($requiresTermInfo): ?>
                                            · multiple school years allowed
                                        <?php endif; ?>
                                    </span>
                                </button>
                                <span class="document-checklist-chevron" aria-hidden="true">
                                    <i class="fas fa-chevron-down"></i>
                                </span>
                            </div>
                            <div class="document-checklist-item-details" id="doc_details_<?= (int) $dt['id'] ?>">
                                <?php if (!$requiresAuthDocumentType && !$requiresTermInfo): ?>
                                <div class="document-checklist-copies">
                                    <label for="copies_<?= (int) $dt['id'] ?>"><?= e(documentTypeQuantityLabel($dt)) ?></label>
                                    <input type="number"
                                        id="copies_<?= (int) $dt['id'] ?>"
                                        name="document_copies[<?= (int) $dt['id'] ?>]"
                                        min="1"
                                        max="<?= $maxCopies ?>"
                                        value="<?= $docCopyCount ?>"
                                        onchange="updateFee()"
                                        onclick="event.stopPropagation()">
                                    <small class="text-muted">Max <?= $maxCopies ?></small>
                                </div>
                                <?php elseif ($requiresAuthDocumentType): ?>
                                <input type="hidden" name="document_copies[<?= (int) $dt['id'] ?>]" value="1">
                                <?php endif; ?>
                                <?php if ($requiresTermInfo): ?>
                                <div class="document-checklist-term" data-extra-fields data-term-block <?= $isSelected ? '' : 'hidden' ?>>
                                    <p class="document-term-help">Request this document for one or more school years / semesters on the same request.</p>
                                    <div class="document-term-lines" data-term-lines data-doc-id="<?= (int) $dt['id'] ?>">
                                        <?php foreach ($termLines as $lineIndex => $termLine): ?>
                                            <div class="document-term-line" data-term-line>
                                                <div class="document-term-line-header">
                                                    <strong>Term <?= (int) $lineIndex + 1 ?></strong>
                                                    <button type="button" class="btn btn-outline btn-sm" data-remove-term-line <?= count($termLines) > 1 ? '' : 'hidden' ?>>Remove</button>
                                                </div>
                                                <div class="document-checklist-term-fields">
                                                    <div class="form-group">
                                                        <label>School Year *</label>
                                                        <select name="document_term_lines[<?= (int) $dt['id'] ?>][<?= (int) $lineIndex ?>][school_year]"
                                                            <?= $isSelected ? 'required' : '' ?>
                                                            onclick="event.stopPropagation()">
                                                            <option value="">— Select —</option>
                                                            <?php foreach ($schoolYearChoices as $value => $label): ?>
                                                                <option value="<?= e($value) ?>" <?= $termLine['school_year'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="form-group">
                                                        <label>Semester *</label>
                                                        <select name="document_term_lines[<?= (int) $dt['id'] ?>][<?= (int) $lineIndex ?>][semester]"
                                                            <?= $isSelected ? 'required' : '' ?>
                                                            onclick="event.stopPropagation()">
                                                            <option value="">— Select —</option>
                                                            <?php foreach ($semesterChoices as $value => $label): ?>
                                                                <option value="<?= e($value) ?>" <?= $termLine['semester'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="form-group">
                                                        <label><?= e(documentTypeQuantityLabel($dt)) ?></label>
                                                        <input type="number"
                                                            name="document_term_lines[<?= (int) $dt['id'] ?>][<?= (int) $lineIndex ?>][copies]"
                                                            min="1"
                                                            max="<?= $maxCopies ?>"
                                                            value="<?= (int) $termLine['copies'] ?>"
                                                            data-term-copies
                                                            onchange="updateFee()"
                                                            onclick="event.stopPropagation()">
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="btn btn-outline btn-sm document-term-add-btn" data-add-term-line>
                                        <i class="fas fa-plus"></i> Add another school year / semester
                                    </button>
                                    <?php if ($termError): ?><span class="field-error"><?= e($termError) ?></span><?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($requiresAuthDocumentType): ?>
                                <div class="document-checklist-auth-type" data-extra-fields <?= $isSelected ? '' : 'hidden' ?>>
                                    <p class="auth-doc-options-title">Documents to authenticate *</p>
                                    <div class="auth-doc-options">
                                        <?php foreach ($authDocumentTypeChoices as $authValue => $authLabel): ?>
                                            <?php
                                            $postedAuthSets = max(0, (int) ($postedAuthForDoc[$authValue] ?? 0));
                                            $authOptionSelected = $postedAuthSets > 0;
                                            ?>
                                            <div class="auth-doc-option">
                                                <label class="auth-doc-option-label">
                                                    <input type="checkbox"
                                                        class="auth-doc-select"
                                                        data-auth-label="<?= e($authLabel) ?>"
                                                        <?= $authOptionSelected ? 'checked' : '' ?>
                                                        onclick="event.stopPropagation()">
                                                    <span><?= e($authLabel) ?></span>
                                                </label>
                                                <div class="auth-doc-sets-wrap">
                                                    <label for="auth_sets_<?= (int) $dt['id'] ?>_<?= e($authValue) ?>">Sets</label>
                                                    <input type="number"
                                                        id="auth_sets_<?= (int) $dt['id'] ?>_<?= e($authValue) ?>"
                                                        class="auth-doc-sets"
                                                        name="document_auth_items[<?= (int) $dt['id'] ?>][<?= e($authValue) ?>]"
                                                        min="1"
                                                        max="99"
                                                        value="<?= $authOptionSelected ? max(1, $postedAuthSets) : 1 ?>"
                                                        <?= $authOptionSelected ? '' : 'disabled' ?>
                                                        onclick="event.stopPropagation()">
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($authTypeError): ?><span class="field-error"><?= e($authTypeError) ?></span><?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($errors['document_type_ids'])): ?><span class="field-error"><?= e($errors['document_type_ids']) ?></span><?php endif; ?>
            </section>

            <section class="form-section request-form-step">
                <h3><span class="request-step-num">3</span> Notes <span class="request-optional-tag">optional</span></h3>
                <div class="form-group">
                    <textarea id="notes" name="notes" rows="2" placeholder="Any special instructions for the Registrar..."><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
            </section>

            <section class="request-form-footer">
                <div class="fee-summary payment-breakdown-panel">
                    <p class="payment-breakdown-empty" id="paymentBreakdownEmpty">Select documents to estimate fees.</p>
                    <div class="payment-breakdown-list" id="paymentBreakdownList" hidden></div>
                    <div class="payment-breakdown-total">
                        <div>
                            <span class="payment-breakdown-total-label">Estimated total</span>
                            <small class="text-muted" id="selectedDocCount">No documents selected</small>
                        </div>
                        <strong id="totalFee">₱ 0.00</strong>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg request-submit-btn">
                    <i class="fas fa-paper-plane"></i> Submit Request
                </button>
            </section>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
const purposeSuggestions = <?= json_encode($purposeSuggestions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const purposeHints = <?= json_encode($purposeHints, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function formatPeso(amount) {
    return '₱ ' + amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function buildBreakdownDetail(copies, base, stampFee, feePerSet, authItems) {
    if (Array.isArray(authItems) && authItems.length) {
        return authItems.map(function (item) {
            return item.label + ': ' + item.sets + ' set(s) × ' + formatPeso(base);
        }).join(' · ');
    }

    const parts = feePerSet
        ? ['1 set × ' + formatPeso(base)]
        : [copies + ' × ' + formatPeso(base)];
    if (stampFee > 0) {
        parts.push('documentary stamp ' + formatPeso(stampFee));
    }
    return parts.join(' + ');
}

function collectAuthItems(item) {
    const authItems = [];
    if (!item) {
        return authItems;
    }

    item.querySelectorAll('.auth-doc-option').forEach(function (row) {
        const select = row.querySelector('.auth-doc-select');
        const setsInput = row.querySelector('.auth-doc-sets');
        if (!select || !select.checked || !setsInput) {
            return;
        }
        let sets = parseInt(setsInput.value, 10) || 1;
        sets = Math.max(1, Math.min(99, sets));
        setsInput.value = sets;
        authItems.push({
            label: select.dataset.authLabel || 'Document',
            sets: sets
        });
    });

    return authItems;
}

function syncAuthDocOption(selectEl) {
    const row = selectEl.closest('.auth-doc-option');
    const setsInput = row ? row.querySelector('.auth-doc-sets') : null;
    if (!setsInput) {
        return;
    }
    if (selectEl.checked) {
        setsInput.disabled = false;
        if ((parseInt(setsInput.value, 10) || 0) < 1) {
            setsInput.value = 1;
        }
    } else {
        setsInput.disabled = true;
        setsInput.value = 1;
    }
    updateFee();
}

function setDocumentChecklistItemExpanded(item, expanded) {
    if (!item) {
        return;
    }
    item.classList.toggle('is-expanded', expanded);
    item.classList.toggle('is-collapsed', !expanded);
    const summary = item.querySelector('.document-checklist-summary');
    if (summary) {
        summary.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }
}

function toggleDocumentChecklistItem(item) {
    if (!item) {
        return;
    }
    setDocumentChecklistItemExpanded(item, !item.classList.contains('is-expanded'));
}

function syncDocumentChecklistCollapse() {
    document.querySelectorAll('.document-checklist-item').forEach(function (item) {
        const checkbox = item.querySelector('.document-checklist-checkbox');
        setDocumentChecklistItemExpanded(item, !!(checkbox && checkbox.checked));
    });
}

function handleDocumentCheckboxChange(checkbox) {
    const item = checkbox.closest('.document-checklist-item');
    setDocumentChecklistItemExpanded(item, checkbox.checked);
    updateFee();
    toggleDocumentExtraFields();
}

function initDocumentChecklistToggles() {
    document.querySelectorAll('.document-checklist-item').forEach(function (item) {
        const summary = item.querySelector('[data-document-checklist-toggle]');
        const chevron = item.querySelector('.document-checklist-chevron');

        if (summary) {
            summary.addEventListener('click', function () {
                toggleDocumentChecklistItem(item);
            });
        }

        if (chevron) {
            chevron.addEventListener('click', function () {
                toggleDocumentChecklistItem(item);
            });
        }
    });
}

function reindexTermLines(container) {
    if (!container) return;
    const docId = container.getAttribute('data-doc-id');
    const lines = container.querySelectorAll('[data-term-line]');
    lines.forEach(function (line, index) {
        const header = line.querySelector('.document-term-line-header strong');
        if (header) header.textContent = 'Term ' + (index + 1);

        const removeBtn = line.querySelector('[data-remove-term-line]');
        if (removeBtn) removeBtn.hidden = lines.length <= 1;

        line.querySelectorAll('select, input').forEach(function (field) {
            const name = field.getAttribute('name') || '';
            if (!name) return;
            field.name = name.replace(
                /document_term_lines\[\d+\]\[\d+\]/,
                'document_term_lines[' + docId + '][' + index + ']'
            );
        });
    });
}

function addTermLine(container) {
    if (!container) return;
    const first = container.querySelector('[data-term-line]');
    if (!first) return;

    const clone = first.cloneNode(true);
    clone.querySelectorAll('select').forEach(function (select) {
        select.selectedIndex = 0;
        select.required = true;
    });
    clone.querySelectorAll('input[data-term-copies]').forEach(function (input) {
        input.value = 1;
    });
    clone.querySelectorAll('.field-error').forEach(function (el) {
        el.remove();
    });
    clone.querySelectorAll('[data-bound]').forEach(function (el) {
        delete el.dataset.bound;
    });

    container.appendChild(clone);
    reindexTermLines(container);
    bindTermLineControls(clone);
    updateFee();
}

function bindTermLineControls(scope) {
    const root = scope || document;
    root.querySelectorAll('[data-add-term-line]').forEach(function (button) {
        if (button.dataset.bound === '1') return;
        button.dataset.bound = '1';
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            const block = button.closest('[data-term-block]');
            const container = block ? block.querySelector('[data-term-lines]') : null;
            addTermLine(container);
        });
    });

    root.querySelectorAll('[data-remove-term-line]').forEach(function (button) {
        if (button.dataset.bound === '1') return;
        button.dataset.bound = '1';
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            const line = button.closest('[data-term-line]');
            const container = button.closest('[data-term-lines]');
            if (!line || !container) return;
            if (container.querySelectorAll('[data-term-line]').length <= 1) return;
            line.remove();
            reindexTermLines(container);
            updateFee();
        });
    });

    root.querySelectorAll('[data-term-copies]').forEach(function (input) {
        if (input.dataset.bound === '1') return;
        input.dataset.bound = '1';
        input.addEventListener('change', updateFee);
        input.addEventListener('input', updateFee);
    });
}

function resetTermLines(item) {
    const container = item ? item.querySelector('[data-term-lines]') : null;
    if (!container) return;
    const lines = container.querySelectorAll('[data-term-line]');
    lines.forEach(function (line, index) {
        if (index === 0) {
            line.querySelectorAll('select').forEach(function (select) {
                select.selectedIndex = 0;
            });
            const copies = line.querySelector('[data-term-copies]');
            if (copies) copies.value = 1;
            return;
        }
        line.remove();
    });
    reindexTermLines(container);
}

function toggleDocumentExtraFields() {
    document.querySelectorAll('.document-checklist-checkbox').forEach(function (checkbox) {
        const item = checkbox.closest('.document-checklist-item');
        if (!item) {
            return;
        }
        const show = checkbox.checked;
        item.querySelectorAll('[data-extra-fields]').forEach(function (block) {
            block.hidden = !show;
            block.querySelectorAll('select').forEach(function (select) {
                select.required = show;
            });
            block.querySelectorAll('textarea').forEach(function (textarea) {
                if (!show) {
                    textarea.value = '';
                }
            });
        });

        if (!show) {
            item.querySelectorAll('.auth-doc-select').forEach(function (authCheckbox) {
                authCheckbox.checked = false;
                syncAuthDocOption(authCheckbox);
            });
            if (checkbox.dataset.requiresTerm === '1') {
                resetTermLines(item);
            }
        }
    });
}

function applyPurposeDocumentSelection() {
    const purposeSelect = document.getElementById('purpose');
    const purpose = purposeSelect ? purposeSelect.value : '';
    const suggestedIds = purposeSuggestions[purpose] || [];
    const suggestedLookup = {};

    suggestedIds.forEach(function (docId) {
        suggestedLookup[String(docId)] = true;
    });

    document.querySelectorAll('.document-checklist-checkbox').forEach(function (checkbox) {
        checkbox.checked = purpose !== '' && !!suggestedLookup[checkbox.value];
    });

    toggleDocumentExtraFields();
    updateFee();
    syncDocumentChecklistCollapse();
}

function togglePurposeOtherField() {
    const purposeSelect = document.getElementById('purpose');
    const otherGroup = document.getElementById('purposeOtherGroup');
    if (!purposeSelect || !otherGroup) {
        return;
    }
    otherGroup.style.display = purposeSelect.value === 'other' ? 'block' : 'none';
}

function clearPurposeSuggestionHighlights() {
    document.querySelectorAll('.document-checklist-item-suggested').forEach(function (item) {
        item.classList.remove('document-checklist-item-suggested');
    });
}

function updatePurposeSuggestions(syncSelection) {
    const purposeSelect = document.getElementById('purpose');
    const panel = document.getElementById('purposeSuggestionPanel');
    const hintEl = document.getElementById('purposeSuggestionHint');
    const listEl = document.getElementById('purposeSuggestionList');
    const applyButton = document.getElementById('applyPurposeSuggestions');
    const purpose = purposeSelect ? purposeSelect.value : '';

    clearPurposeSuggestionHighlights();

    if (!panel || !hintEl || !listEl) {
        return;
    }

    if (!purpose) {
        panel.hidden = true;
        listEl.innerHTML = '';
        hintEl.textContent = '';
        if (syncSelection) {
            applyPurposeDocumentSelection();
        }
        return;
    }

    const suggestedIds = purposeSuggestions[purpose] || [];
    const hint = purposeHints[purpose] || '';
    hintEl.textContent = hint;
    listEl.innerHTML = '';

    suggestedIds.forEach(function (docId) {
        const checkbox = document.getElementById('doc_type_' + docId);
        if (!checkbox) {
            return;
        }

        const listItem = document.createElement('li');
        listItem.textContent = checkbox.dataset.docName || 'Document';
        listEl.appendChild(listItem);

        const item = checkbox.closest('.document-checklist-item');
        if (item) {
            item.classList.add('document-checklist-item-suggested');
        }
    });

    panel.hidden = false;
    if (applyButton) {
        applyButton.hidden = suggestedIds.length === 0;
    }

    if (syncSelection) {
        applyPurposeDocumentSelection();
    }
}

function applyPurposeSuggestions(scrollToDocs) {
    applyPurposeDocumentSelection();
    updatePurposeSuggestions(false);

    if (scrollToDocs) {
        document.querySelector('.document-checklist')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function updateFee() {
    const checked = document.querySelectorAll('input[name="document_type_ids[]"]:checked');
    const listEl = document.getElementById('paymentBreakdownList');
    const emptyEl = document.getElementById('paymentBreakdownEmpty');
    let total = 0;
    let html = '';
    let requestLineCount = 0;

    checked.forEach(function (checkbox) {
        const item = checkbox.closest('.document-checklist-item');
        const name = checkbox.dataset.docName || 'Document';
        const requiresAuth = checkbox.dataset.requiresAuthType === '1';
        const requiresTerm = checkbox.dataset.requiresTerm === '1';
        const copiesInput = item ? item.querySelector('.document-checklist-copies input') : null;
        const maxCopies = parseInt(checkbox.dataset.maxCopies, 10) || 10;
        const base = parseFloat(checkbox.dataset.base) || 0;
        const stampFee = checkbox.dataset.stamp === '1' ? (parseFloat(checkbox.dataset.stampFee) || 30) : 0;
        const feePerSet = checkbox.dataset.feePerSet === '1';

        if (requiresTerm) {
            const termLines = item ? item.querySelectorAll('[data-term-line]') : [];
            termLines.forEach(function (termLine, index) {
                const termCopiesInput = termLine.querySelector('[data-term-copies]');
                let copies = parseInt(termCopiesInput ? termCopiesInput.value : '1', 10) || 1;
                copies = Math.max(1, Math.min(maxCopies, copies));
                if (termCopiesInput) termCopiesInput.value = copies;

                const sySelect = termLine.querySelector('select[name*="[school_year]"]');
                const semSelect = termLine.querySelector('select[name*="[semester]"]');
                const syText = sySelect && sySelect.selectedOptions[0] ? sySelect.selectedOptions[0].textContent : '';
                const semText = semSelect && semSelect.selectedOptions[0] ? semSelect.selectedOptions[0].textContent : '';
                const termLabel = (sySelect && sySelect.value && semSelect && semSelect.value)
                    ? (syText + ' · ' + semText)
                    : ('Term ' + (index + 1));

                const lineTotal = feePerSet ? base + stampFee : (base * copies) + stampFee;
                total += lineTotal;
                requestLineCount += 1;

                html += '<div class="payment-breakdown-item">' +
                    '<div class="payment-breakdown-item-main">' +
                        '<strong>' + escapeHtml(name) + '</strong>' +
                        '<span class="payment-breakdown-detail">' + escapeHtml(termLabel) + ' · ' +
                            buildBreakdownDetail(copies, base, stampFee, feePerSet, null) + '</span>' +
                    '</div>' +
                    '<span class="payment-breakdown-amount">' + formatPeso(lineTotal) + '</span>' +
                '</div>';
            });
            return;
        }

        let copies = parseInt(copiesInput?.value, 10) || 1;
        copies = Math.max(1, Math.min(maxCopies, copies));
        if (copiesInput && !requiresAuth) {
            copiesInput.value = copies;
        }

        let lineTotal = 0;
        let detailText = '';

        if (requiresAuth) {
            const authItems = collectAuthItems(item);
            authItems.forEach(function (authItem) {
                lineTotal += base * authItem.sets;
            });
            detailText = buildBreakdownDetail(copies, base, 0, feePerSet, authItems);
            if (!authItems.length) {
                detailText = 'Select documents to authenticate';
            }
            lineTotal += stampFee;
            if (stampFee > 0 && authItems.length) {
                detailText += ' + documentary stamp ' + formatPeso(stampFee);
            }
        } else {
            lineTotal = feePerSet ? base + stampFee : (base * copies) + stampFee;
            detailText = buildBreakdownDetail(copies, base, stampFee, feePerSet, null);
        }

        total += lineTotal;
        requestLineCount += 1;

        html += '<div class="payment-breakdown-item">' +
            '<div class="payment-breakdown-item-main">' +
                '<strong>' + escapeHtml(name) + '</strong>' +
                '<span class="payment-breakdown-detail">' + detailText + '</span>' +
            '</div>' +
            '<span class="payment-breakdown-amount">' + formatPeso(lineTotal) + '</span>' +
        '</div>';
    });

    if (requestLineCount) {
        emptyEl.hidden = true;
        listEl.hidden = false;
        listEl.innerHTML = html;
    } else {
        emptyEl.hidden = false;
        listEl.hidden = true;
        listEl.innerHTML = '';
    }

    document.getElementById('totalFee').textContent = formatPeso(total);

    const countEl = document.getElementById('selectedDocCount');
    if (countEl) {
        countEl.textContent = requestLineCount === 0
            ? 'No documents selected'
            : requestLineCount + ' document request' + (requestLineCount === 1 ? '' : 's');
    }
}
document.querySelectorAll('.document-checklist-copies input').forEach(function (input) {
    input.addEventListener('change', updateFee);
    input.addEventListener('input', updateFee);
});
document.querySelectorAll('.auth-doc-select').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
        syncAuthDocOption(checkbox);
    });
});
document.querySelectorAll('.auth-doc-sets').forEach(function (input) {
    input.addEventListener('change', updateFee);
    input.addEventListener('input', updateFee);
});
document.getElementById('purpose')?.addEventListener('change', function () {
    togglePurposeOtherField();
    updatePurposeSuggestions(true);
});
document.getElementById('applyPurposeSuggestions')?.addEventListener('click', function () {
    applyPurposeSuggestions(true);
});
document.getElementById('requestForm')?.addEventListener('submit', function (event) {
    const checked = document.querySelectorAll('input[name="document_type_ids[]"]:checked');
    if (!checked.length) {
        event.preventDefault();
        alert('Please select at least one document to request.');
    }
});
bindTermLineControls(document);
document.querySelectorAll('[data-term-lines]').forEach(function (container) {
    reindexTermLines(container);
});
updateFee();
toggleDocumentExtraFields();
togglePurposeOtherField();
updatePurposeSuggestions(false);
initDocumentChecklistToggles();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
