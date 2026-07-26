<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/student.php';
requireRole('student');
$user = currentUser();

ensureDeliveryMethods();
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
    $postedTerms = $_POST['document_term'] ?? [];
    $postedAuthItems = $_POST['document_auth_items'] ?? [];
    $docTypesById = [];
    foreach ($docTypes as $docTypeRow) {
        $docTypesById[(int) $docTypeRow['id']] = $docTypeRow;
    }
    $data = [
        'document_type_ids' => array_values(array_unique(array_filter(array_map('intval', $_POST['document_type_ids'] ?? [])))),
        'purpose'          => $_POST['purpose'] ?? '',
        'purpose_other'    => trim($_POST['purpose_other'] ?? ''),
        'notes'            => trim($_POST['notes'] ?? ''),
    ];

    $validDocTypeIds = validateActiveDocumentTypeIdsForEnrollment($data['document_type_ids'], $enrollmentStatus);
    if (empty($validDocTypeIds)) {
        $errors['document_type_ids'] = empty($data['document_type_ids'])
            ? 'Please select at least one document to request.'
            : 'One or more selected documents are not available for your enrollment status.';
    } elseif (count($validDocTypeIds) !== count($data['document_type_ids'])) {
        $errors['document_type_ids'] = 'One or more selected documents are not available for your enrollment status.';
    }

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

        $copies = (int) ($postedCopies[$documentTypeId] ?? 1);
        $copyError = validateStudentDocumentRequest($documentTypeId, $enrollmentStatus, $copies);
        if ($copyError) {
            $errors['document_type_ids'] = $copyError;
            break;
        }

        if ($docType && documentTypeRequiresTermInfo($docType)) {
            $term = $postedTerms[$documentTypeId] ?? [];
            $termError = validateRequestTermFields($term['school_year'] ?? '', $term['semester'] ?? '');
            if ($termError) {
                $errors['document_term_' . $documentTypeId] = $termError;
            }
        }

    }

    if (!$data['purpose']) {
        $errors['purpose'] = 'Please select a purpose.';
    } elseif (!isValidActiveRequestPurposeCode($data['purpose'])) {
        $errors['purpose'] = 'Please select a valid purpose.';
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
            } else {
                $copies = max(1, min($maxCopies, (int) ($postedCopies[$documentTypeId] ?? 1)));
            }

            $itemAmount = calculateRequestFee($documentTypeId, $copies, $authItems ?: null);
            $requestSchoolYear = null;
            $requestSemester = null;

            if ($docType && documentTypeRequiresTermInfo($docType)) {
                $term = $postedTerms[$documentTypeId] ?? [];
                $requestSchoolYear = trim((string) ($term['school_year'] ?? '')) ?: null;
                $requestSemester = trim((string) ($term['semester'] ?? '')) ?: null;
            }

            $itemDrafts[] = [
                'document_type_id' => $documentTypeId,
                'copies' => $copies,
                'item_amount' => $itemAmount,
                'request_school_year' => $requestSchoolYear,
                'request_semester' => $requestSemester,
                'request_soa_assessment_scope' => null,
                'request_soa_remarks' => null,
                'auth_items' => $authItems,
            ];
            $batchTotal += $itemAmount;
        }

        $primaryDocumentTypeId = $itemDrafts[0]['document_type_id'];
        $stmt = $db->prepare('INSERT INTO requests (
            request_number, user_id, document_type_id, purpose, purpose_other, copies, delivery_method,
            pickup_date, pickup_time, representative_name, representative_relationship, representative_phone,
            representative_id_number, total_amount, verification_code, notes
        ) VALUES (?, ?, ?, ?, ?, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?, ?, ?)');
        $stmt->execute([
            $requestNumber,
            $user['id'],
            $primaryDocumentTypeId,
            $data['purpose'],
            $data['purpose_other'] ?: null,
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

            if (isAutoApplyRequirementsEnabled() && applyRequirementDefaultsToRequest($requestId, (int) $draft['document_type_id'], $index === 0, $itemId)) {
                $autoAppliedCount++;
            }
        }

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

        if ($autoAppliedCount === $documentCount) {
            setFlash('success', 'Request submitted successfully! Required documents have been assigned — please complete them in your request details.');
        } elseif ($autoAppliedCount > 0) {
            setFlash('success', 'Request submitted with ' . $documentCount . ' documents. Some requirements have been assigned — complete them in your request details.');
        } else {
            setFlash('success', 'Request submitted successfully with ' . $documentCount . ' document' . ($documentCount === 1 ? '' : 's') . '! The Registrar will review and assign requirements.');
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
$postedTerms = $_POST['document_term'] ?? [];
$postedAuthItems = $_POST['document_auth_items'] ?? [];
$defaultSchoolYear = trim((string) ($studentProfile['current_academic_year'] ?? ''));
$defaultSemester = trim((string) ($studentProfile['current_semester'] ?? ''));
$schoolYearChoices = schoolYearOptions();
$semesterChoices = semesterOptions();
$authDocumentTypeChoices = authenticationDocumentTypeOptions();
$purposeSuggestions = [];
$purposeHints = [];
foreach (purposeOptions() as $purposeKey) {
    $purposeSuggestions[$purposeKey] = getSuggestedDocumentIdsForPurpose($purposeKey, $docTypes);
    $purposeHints[$purposeKey] = purposeSuggestionHint($purposeKey);
}
$selectedPurpose = (string) ($_POST['purpose'] ?? '');

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Credential Request Form</h2></div>
    <div class="card-body">
        <?= renderStudentProfileIncompleteAlert($profileCompletion) ?>

        <?php if (!$profileCompletion['complete']): ?>
            <div class="empty-state">
                <i class="fas fa-user-check"></i>
                <p>Document requests are available only when your account profile is fully completed.</p>
                <a href="profile.php" class="btn btn-primary">Go to Profile</a>
            </div>
        <?php else: ?>
        <div class="alert alert-info enrollment-status-note">
            <i class="fas fa-id-badge"></i>
            Showing credentials available for <strong><?= e(enrollmentStatusLabel($enrollmentStatus)) ?></strong> students.
            <?php if (empty($docTypes)): ?>
                No documents are currently enabled for your enrollment status. Contact the Registrar's Office for assistance.
            <?php endif; ?>
        </div>
        <?php if (empty($docTypes)): ?>
            <div class="empty-state">
                <i class="fas fa-file-circle-xmark"></i>
                <p>No credentials are available for your current enrollment status.</p>
                <a href="profile.php" class="btn btn-outline">Review Profile</a>
            </div>
        <?php else: ?>
        <form method="POST" class="form-grid" id="requestForm">
            <?= csrfField() ?>

            <div class="form-section">
                <h3><i class="fas fa-bullseye"></i> Purpose of Request</h3>
                <p class="text-muted document-checklist-note">Select your purpose first. We will suggest documents commonly needed for that purpose.</p>
                <div class="form-row">
                    <div class="form-group">
                        <label for="purpose">Purpose *</label>
                        <select id="purpose" name="purpose" required>
                            <option value="">— Select Purpose —</option>
                            <?php foreach (purposeOptions() as $p): ?>
                                <option value="<?= $p ?>" <?= $selectedPurpose === $p ? 'selected' : '' ?>><?= purposeLabel($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['purpose'])): ?><span class="field-error"><?= e($errors['purpose']) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="form-group" id="purposeOtherGroup" style="display:none">
                    <label for="purpose_other">Specify Purpose</label>
                    <input type="text" id="purpose_other" name="purpose_other" value="<?= e($_POST['purpose_other'] ?? '') ?>">
                </div>
                <div class="purpose-suggestion-panel" id="purposeSuggestionPanel" hidden>
                    <div class="purpose-suggestion-header">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Suggested Documents</strong>
                    </div>
                    <p class="purpose-suggestion-hint" id="purposeSuggestionHint"></p>
                    <ul class="purpose-suggestion-list" id="purposeSuggestionList"></ul>
                    <button type="button" class="btn btn-outline btn-sm" id="applyPurposeSuggestions">
                        <i class="fas fa-check-double"></i> Re-apply suggested documents
                    </button>
                </div>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    On-site pickup options will be available after the Registrar assigns your request to staff.
                </div>
            </div>

            <div class="form-section">
                <h3><i class="fas fa-file"></i> Documents to Request</h3>
                <p class="text-muted document-checklist-note">Document selections update automatically when you change the purpose. Click a document row to expand details and copy options.</p>
                <div class="document-checklist">
                    <?php foreach ($docTypes as $dt): ?>
                        <?php
                        $maxCopies = max(1, (int) ($dt['max_copies'] ?? 10));
                        $docCopyCount = max(1, min($maxCopies, (int) ($postedCopies[$dt['id']] ?? 1)));
                        $requiresTermInfo = documentTypeRequiresTermInfo($dt);
                        $requiresAuthDocumentType = documentTypeRequiresAuthDocumentType($dt);
                        $feePerSet = documentTypeUsesFeePerSet($dt);
                        $termSchoolYear = trim((string) ($postedTerms[$dt['id']]['school_year'] ?? $defaultSchoolYear));
                        $termSemester = trim((string) ($postedTerms[$dt['id']]['semester'] ?? $defaultSemester));
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
                                        <i class="fas fa-clock"></i> <?= (int) $dt['processing_days'] ?> day<?= (int) $dt['processing_days'] === 1 ? '' : 's' ?>
                                    </span>
                                </button>
                                <span class="document-checklist-chevron" aria-hidden="true">
                                    <i class="fas fa-chevron-down"></i>
                                </span>
                            </div>
                            <div class="document-checklist-item-details" id="doc_details_<?= (int) $dt['id'] ?>">
                                <?php if (!empty($dt['description'])): ?>
                                    <span class="document-checklist-desc"><?= e($dt['description']) ?></span>
                                <?php endif; ?>
                                <span class="document-checklist-meta">
                                    <i class="fas fa-clock"></i> <?= (int) $dt['processing_days'] ?> day<?= (int) $dt['processing_days'] === 1 ? '' : 's' ?> processing
                                    · <?= e(documentTypeFeeMetaText($dt)) ?>
                                    <?php if (!empty($dt['requires_documentary_stamp'])): ?>
                                        · Documentary stamp <?= formatMoney(documentStampFeeAmount()) ?>
                                    <?php endif; ?>
                                </span>
                                <?php if (!$requiresAuthDocumentType): ?>
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
                                <?php else: ?>
                                <input type="hidden" name="document_copies[<?= (int) $dt['id'] ?>]" value="1">
                                <?php endif; ?>
                                <?php if ($requiresTermInfo): ?>
                                <div class="document-checklist-term" data-extra-fields <?= $isSelected ? '' : 'hidden' ?>>
                                    <div class="document-checklist-term-fields">
                                        <div class="form-group">
                                            <label for="term_sy_<?= (int) $dt['id'] ?>">School Year *</label>
                                            <select id="term_sy_<?= (int) $dt['id'] ?>"
                                                name="document_term[<?= (int) $dt['id'] ?>][school_year]"
                                                <?= $isSelected ? 'required' : '' ?>
                                                onclick="event.stopPropagation()">
                                                <option value="">— Select School Year —</option>
                                                <?php foreach ($schoolYearChoices as $value => $label): ?>
                                                    <option value="<?= e($value) ?>" <?= $termSchoolYear === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="term_sem_<?= (int) $dt['id'] ?>">Semester *</label>
                                            <select id="term_sem_<?= (int) $dt['id'] ?>"
                                                name="document_term[<?= (int) $dt['id'] ?>][semester]"
                                                <?= $isSelected ? 'required' : '' ?>
                                                onclick="event.stopPropagation()">
                                                <option value="">— Select Semester —</option>
                                                <?php foreach ($semesterChoices as $value => $label): ?>
                                                    <option value="<?= e($value) ?>" <?= $termSemester === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php if ($termError): ?><span class="field-error"><?= e($termError) ?></span><?php endif; ?>
                                    <small class="text-muted">Indicate the school year and semester covered by this request.</small>
                                </div>
                                <?php endif; ?>
                                <?php if ($requiresAuthDocumentType): ?>
                                <div class="document-checklist-auth-type" data-extra-fields <?= $isSelected ? '' : 'hidden' ?>>
                                    <p class="auth-doc-options-title">Documents to Authenticate *</p>
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
                                    <small class="text-muted">Select one or more documents and indicate how many sets to authenticate for each.</small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($errors['document_type_ids'])): ?><span class="field-error"><?= e($errors['document_type_ids']) ?></span><?php endif; ?>
            </div>

            <div class="form-section">
                <h3><i class="fas fa-sticky-note"></i> Additional Notes</h3>
                <div class="form-group">
                    <textarea id="notes" name="notes" rows="3" placeholder="Any special instructions..."><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-section payment-breakdown-section">
                <h3><i class="fas fa-receipt"></i> Payment Breakdown</h3>
                <div class="fee-summary payment-breakdown-panel">
                    <p class="payment-breakdown-empty" id="paymentBreakdownEmpty">Select one or more documents to view the estimated fees.</p>
                    <div class="payment-breakdown-list" id="paymentBreakdownList" hidden></div>
                    <div class="payment-breakdown-total">
                        <div>
                            <span class="payment-breakdown-total-label">Estimated Total</span>
                            <small class="text-muted" id="selectedDocCount">No documents selected</small>
                        </div>
                        <strong id="totalFee">₱ 0.00</strong>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-paper-plane"></i> Submit Request</button>
        </form>
        <?php endif; ?>
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
                if (!show) {
                    select.selectedIndex = 0;
                }
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

    checked.forEach(function (checkbox) {
        const item = checkbox.closest('.document-checklist-item');
        const name = checkbox.dataset.docName || 'Document';
        const requiresAuth = checkbox.dataset.requiresAuthType === '1';
        const copiesInput = item ? item.querySelector('.document-checklist-copies input') : null;
        const maxCopies = parseInt(checkbox.dataset.maxCopies, 10) || 10;
        let copies = parseInt(copiesInput?.value, 10) || 1;
        copies = Math.max(1, Math.min(maxCopies, copies));
        if (copiesInput && !requiresAuth) {
            copiesInput.value = copies;
        }
        const base = parseFloat(checkbox.dataset.base) || 0;
        const stampFee = checkbox.dataset.stamp === '1' ? (parseFloat(checkbox.dataset.stampFee) || 30) : 0;
        const feePerSet = checkbox.dataset.feePerSet === '1';
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

        html += '<div class="payment-breakdown-item">' +
            '<div class="payment-breakdown-item-main">' +
                '<strong>' + escapeHtml(name) + '</strong>' +
                '<span class="payment-breakdown-detail">' + detailText + '</span>' +
            '</div>' +
            '<span class="payment-breakdown-amount">' + formatPeso(lineTotal) + '</span>' +
        '</div>';
    });

    if (checked.length) {
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
        const count = checked.length;
        countEl.textContent = count === 0
            ? 'No documents selected'
            : count + ' document request' + (count === 1 ? '' : 's');
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
updateFee();
toggleDocumentExtraFields();
togglePurposeOtherField();
updatePurposeSuggestions(false);
initDocumentChecklistToggles();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
