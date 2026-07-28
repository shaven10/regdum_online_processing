<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/onsite-request.php';
require_once __DIR__ . '/../includes/purpose-suggestions.php';
require_once __DIR__ . '/../includes/campuses.php';
requireRole('registrar');

$user = currentUser();
ensureOnsiteRequestSchema();
ensureDeliveryMethods();
ensureRequestCopyTypeSchema();
ensureStudentEmploymentFields();
ensureAcademicProgramsSchema();
ensureEnrollmentStatuses();
ensureCampusesSchema();
ensureStudentAcademicTermFields();
ensureDocumentEnrollmentRulesSchema();
ensureDocumentTypeFeeSchema();
ensureRequestTermInfoSchema();
ensureRequestAuthenticationTypeSchema();
ensureStatementOfAccountSchema();
ensureRequestPurposesSchema();
ensureComplianceSchema();
ensureRequestItemsSchema();
ensurePaymentMethodSchema();

$db = getDB();
$errors = [];
$createdResult = null;

$search = trim((string) ($_GET['search'] ?? ''));
$selectedUserId = (int) ($_GET['student_user_id'] ?? $_POST['student_user_id'] ?? 0);
$selectedStudent = $selectedUserId > 0 ? findStudentUserById($selectedUserId) : null;

$enrollmentStatus = trim((string) ($_POST['enrollment_status'] ?? $_GET['enrollment_status'] ?? ''));
if ($enrollmentStatus === '' && $selectedStudent) {
    $enrollmentStatus = (string) ($selectedStudent['enrollment_status'] ?? 'enrolled');
}
if (!array_key_exists($enrollmentStatus, enrollmentStatusOptions())) {
    $enrollmentStatus = 'enrolled';
}

$docTypes = getAvailableDocumentTypesForEnrollment($enrollmentStatus);
$searchResults = searchStudentsForOnsiteRequest($search);
$isFilteredStudentSearch = $search !== '';
$expandAllOnsiteSections = !empty($errors);
$onsiteSectionExpanded = static function (string $section) use ($expandAllOnsiteSections, $selectedStudent): bool {
    if ($expandAllOnsiteSections) {
        return true;
    }

    return match ($section) {
        'students' => !$selectedStudent,
        'requestor', 'documents' => true,
        default => false,
    };
};

$selectedCourseId = (int) ($selectedStudent['course_id'] ?? 0);
$programs = getAcademicProgramsForStudent($selectedCourseId);
$selectedCampusId = (int) ($selectedStudent['origin_campus_id'] ?? 0);
$campuses = getCampusesForStudent($selectedCampusId);
$isGraduatedStatus = isGraduatedEnrollment($enrollmentStatus);
$isInactiveStatus = isInactiveEnrollment($enrollmentStatus);
$isEnrolledStatus = isEnrolledEnrollment($enrollmentStatus);

$requestorDefaults = [
    'student_user_id' => $selectedUserId,
    'student_id' => $selectedStudent['student_id'] ?? '',
    'first_name' => $selectedStudent['first_name'] ?? '',
    'last_name' => $selectedStudent['last_name'] ?? '',
    'middle_name' => $selectedStudent['middle_name'] ?? '',
    'email' => $selectedStudent['email'] ?? '',
    'phone' => $selectedStudent['phone'] ?? '',
    'enrollment_status' => $enrollmentStatus,
    'course_id' => $selectedCourseId,
    'year_level' => $selectedStudent['year_level'] ?? '',
    'year_graduated' => (int) ($selectedStudent['year_graduated'] ?? 0),
    'origin_campus_id' => $selectedCampusId,
    'last_school_year' => $selectedStudent['last_school_year'] ?? '',
    'last_semester' => $selectedStudent['current_semester'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $postedCopies = $_POST['document_copies'] ?? [];
    $postedTermLines = $_POST['document_term_lines'] ?? [];
    $postedAuthItems = $_POST['document_auth_items'] ?? [];
    $docTypesById = [];
    foreach ($docTypes as $docTypeRow) {
        $docTypesById[(int) $docTypeRow['id']] = $docTypeRow;
    }

    $requestorInput = [
        'user_id' => (int) ($_POST['student_user_id'] ?? 0),
        'student_id' => trim((string) ($_POST['student_id'] ?? '')),
        'first_name' => trim((string) ($_POST['first_name'] ?? '')),
        'last_name' => trim((string) ($_POST['last_name'] ?? '')),
        'middle_name' => trim((string) ($_POST['middle_name'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'phone' => trim((string) ($_POST['phone'] ?? '')),
        'enrollment_status' => trim((string) ($_POST['enrollment_status'] ?? 'enrolled')),
        'course_id' => (int) ($_POST['course_id'] ?? 0),
        'year_level' => trim((string) ($_POST['year_level'] ?? '')),
        'year_graduated' => (int) ($_POST['year_graduated'] ?? 0),
        'origin_campus_id' => (int) ($_POST['origin_campus_id'] ?? 0),
        'last_school_year' => trim((string) ($_POST['last_school_year'] ?? '')),
        'last_semester' => trim((string) ($_POST['last_semester'] ?? '')),
    ];
    $requestorDefaults = array_merge($requestorDefaults, $requestorInput);
    $requestorDefaults['student_user_id'] = $requestorInput['user_id'];
    $programs = getAcademicProgramsForStudent((int) $requestorInput['course_id']);
    $campuses = getCampusesForStudent((int) $requestorInput['origin_campus_id']);
    $enrollmentStatus = array_key_exists($requestorInput['enrollment_status'], enrollmentStatusOptions())
        ? $requestorInput['enrollment_status']
        : 'enrolled';
    $isGraduatedStatus = isGraduatedEnrollment($enrollmentStatus);
    $isInactiveStatus = isInactiveEnrollment($enrollmentStatus);
    $isEnrolledStatus = isEnrolledEnrollment($enrollmentStatus);
    $docTypes = getAvailableDocumentTypesForEnrollment($enrollmentStatus);
    $docTypesById = [];
    foreach ($docTypes as $docTypeRow) {
        $docTypesById[(int) $docTypeRow['id']] = $docTypeRow;
    }

    $data = [
        'document_type_ids' => array_values(array_unique(array_filter(array_map('intval', $_POST['document_type_ids'] ?? [])))),
        'purpose' => $_POST['purpose'] ?? '',
        'purpose_other' => trim($_POST['purpose_other'] ?? ''),
        'copy_request_type' => $_POST['copy_request_type'] ?? '',
        'notes' => trim($_POST['notes'] ?? ''),
        'require_online_clearance' => !empty($_POST['require_online_clearance']),
    ];

    if ($requestorInput['first_name'] === '') {
        $errors['first_name'] = 'First name is required.';
    }
    if ($requestorInput['last_name'] === '') {
        $errors['last_name'] = 'Last name is required.';
    }
    if ($requestorInput['email'] !== '' && !filter_var($requestorInput['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($requestorInput['course_id'] > 0 && !resolveAcademicProgramFromPost((int) $requestorInput['course_id'])) {
        $errors['course_id'] = 'Please select a valid course/program.';
    }
    if ($isGraduatedStatus) {
        $requestorInput['year_level'] = '';
        $requestorInput['last_school_year'] = '';
        $requestorInput['last_semester'] = '';
        if ($requestorInput['year_graduated'] <= 0 || !array_key_exists((string) $requestorInput['year_graduated'], yearGraduatedOptions())) {
            $errors['year_graduated'] = 'Please select a valid year of graduation.';
        }
        if ($requestorInput['origin_campus_id'] <= 0 || !getCampusById((int) $requestorInput['origin_campus_id'])) {
            $errors['origin_campus_id'] = 'Please select a campus.';
        }
    } elseif ($isInactiveStatus) {
        $requestorInput['year_level'] = '';
        $requestorInput['year_graduated'] = 0;
        if ($requestorInput['last_school_year'] === '' || !array_key_exists($requestorInput['last_school_year'], schoolYearOptions())) {
            $errors['last_school_year'] = 'Please select the last school year attended.';
        }
        if ($requestorInput['last_semester'] === '' || !array_key_exists($requestorInput['last_semester'], semesterOptions())) {
            $errors['last_semester'] = 'Please select the semester attended.';
        }
        if ($requestorInput['origin_campus_id'] <= 0 || !getCampusById((int) $requestorInput['origin_campus_id'])) {
            $errors['origin_campus_id'] = 'Please select a campus.';
        }
    } else {
        $requestorInput['year_graduated'] = 0;
        $requestorInput['origin_campus_id'] = 0;
        $requestorInput['last_school_year'] = '';
        $requestorInput['last_semester'] = '';
        if ($requestorInput['year_level'] !== '' && !array_key_exists($requestorInput['year_level'], yearLevelOptions())) {
            $errors['year_level'] = 'Please select a valid year level.';
        }
    }

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
            : 'One or more selected documents are not available for this enrollment status.';
    } elseif (count($validDocTypeIds) !== count($data['document_type_ids'])) {
        $errors['document_type_ids'] = 'One or more selected documents are not available for this enrollment status.';
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
            foreach ($termLines as $termLine) {
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
        $errors['purpose'] = 'Please select a valid purpose for this enrollment status.';
    }

    if (!isValidCopyRequestType($data['copy_request_type'])) {
        $errors['copy_request_type'] = 'Please select whether this is a first request or a second copy.';
    }

    if (empty($errors)) {
        try {
            $resolved = resolveOnsiteRequestor($requestorInput);
            $itemDrafts = [];

            foreach ($validDocTypeIds as $documentTypeId) {
                $docType = $docTypesById[$documentTypeId] ?? null;
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
            }

            $createdResult = createOnsiteCredentialRequest(
                $resolved['user'],
                (int) $user['id'],
                $data,
                $itemDrafts
            );

            $requiresClearance = !empty($createdResult['requires_clearance']);
            if ($requiresClearance) {
                setFlash('success', 'Onsite request created with online clearance required. Payment can be verified only after all offices clear.', [
                    'title' => 'Clearance Required Before Payment',
                    'context' => [
                        'Request #' => $createdResult['request_number'],
                        'Payment Code' => $createdResult['payment_code'],
                        'Amount' => formatMoney((float) $createdResult['amount']),
                        'Requestor' => trim(($createdResult['user']['first_name'] ?? '') . ' ' . ($createdResult['user']['last_name'] ?? '')),
                    ],
                    'next_step' => 'Give the requestor the slip. They must complete online clearance at all offices before paying at the cashier.',
                ]);
            } else {
                setFlash('success', 'Onsite request created. Print the request slip and give it to the requestor for cashier payment.', [
                    'title' => 'Ready for Cashier Payment',
                    'context' => [
                        'Request #' => $createdResult['request_number'],
                        'Payment Code' => $createdResult['payment_code'],
                        'Amount' => formatMoney((float) $createdResult['amount']),
                        'Requestor' => trim(($createdResult['user']['first_name'] ?? '') . ' ' . ($createdResult['user']['last_name'] ?? '')),
                    ],
                    'next_step' => 'Print the 4.25 × 6.5 in request slip and instruct the requestor to present the payment code at the cashier.',
                ]);
            }
            redirect(APP_URL . '/registrar/onsite-request-slip.php?id=' . (int) $createdResult['request_id'] . '&print=1');
        } catch (InvalidArgumentException $e) {
            $errors['general'] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Onsite request failed: ' . $e->getMessage());
            $errors['general'] = 'Unable to create the onsite request. Please try again.';
        }
    }
}

$pageTitle = 'Onsite Request';
$activeNav = 'onsite-request';
$selectedDocIds = array_map('intval', $_POST['document_type_ids'] ?? []);
$postedCopies = array_map('intval', $_POST['document_copies'] ?? []);
$postedTermLinesByDoc = $_POST['document_term_lines'] ?? [];
$postedAuthItems = $_POST['document_auth_items'] ?? [];
$defaultSchoolYear = '';
$defaultSemester = '';
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

<?php if ($createdResult): ?>
<div class="card onsite-request-success-card">
    <div class="card-header">
        <h2><i class="fas fa-check-circle"></i> Onsite Request Created</h2>
    </div>
    <div class="card-body">
        <div class="alert alert-success onsite-payment-code-alert">
            <div>
                <strong>Give this payment code to the cashier</strong>
                <div class="onsite-payment-code"><?= e($createdResult['payment_code']) ?></div>
            </div>
        </div>
        <div class="detail-grid">
            <div class="detail-item">
                <label>Request #</label>
                <span><?= e($createdResult['request_number']) ?></span>
            </div>
            <div class="detail-item">
                <label>Requestor</label>
                <span><?= e(trim(($createdResult['user']['first_name'] ?? '') . ' ' . ($createdResult['user']['last_name'] ?? ''))) ?></span>
            </div>
            <div class="detail-item">
                <label>Student ID</label>
                <span><?= e($createdResult['user']['student_id'] ?? '—') ?></span>
            </div>
            <div class="detail-item">
                <label>Amount Due</label>
                <span class="amount-large"><?= formatMoney((float) $createdResult['amount']) ?></span>
            </div>
            <div class="detail-item">
                <label>Documents</label>
                <span><?= (int) $createdResult['document_count'] ?> item<?= (int) $createdResult['document_count'] === 1 ? '' : 's' ?></span>
            </div>
            <div class="detail-item">
                <label>Status</label>
                <span>Ready for cashier payment</span>
            </div>
        </div>
        <p class="text-muted" style="margin-top:1rem">
            Instruct the requestor to pay at the cashier and present payment code
            <strong><?= e($createdResult['payment_code']) ?></strong>.
            Cashiers can look it up under Verify Payments.
        </p>
        <div class="form-actions" style="margin-top:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap">
            <a class="btn btn-primary" href="<?= APP_URL ?>/registrar/verify-request.php?id=<?= (int) $createdResult['request_id'] ?>">
                <i class="fas fa-eye"></i> View Request
            </a>
            <a class="btn btn-outline" href="<?= APP_URL ?>/registrar/onsite-requests.php">
                <i class="fas fa-list"></i> Onsite Request Records
            </a>
            <a class="btn btn-outline" href="<?= APP_URL ?>/registrar/new-onsite-request.php">
                <i class="fas fa-plus"></i> Create Another
            </a>
            <button type="button" class="btn btn-outline" onclick="window.print()">
                <i class="fas fa-print"></i> Print Slip
            </button>
        </div>
    </div>
</div>
<?php else: ?>

<div class="card">
    <div class="card-header">
        <div>
            <h2>Onsite Credential Request</h2>
            <p class="text-muted request-form-subtitle">Enter requestor details and documents, then send the requestor to the cashier with a payment code.</p>
        </div>
        <div class="card-header-actions">
            <a href="<?= APP_URL ?>/registrar/onsite-requests.php" class="btn btn-outline btn-sm">
                <i class="fas fa-list"></i> Onsite Request Records
            </a>
        </div>
    </div>
    <div class="card-body">
        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($errors['general']) ?></div>
        <?php endif; ?>

        <div class="onsite-student-picker form-section-collapsible request-form-step <?= $onsiteSectionExpanded('students') ? 'is-expanded' : 'is-collapsed' ?>" data-form-section-collapsible>
            <button type="button"
                class="form-section-toggle"
                aria-expanded="<?= $onsiteSectionExpanded('students') ? 'true' : 'false' ?>"
                aria-controls="onsiteSectionStudents">
                <span class="form-section-toggle-label">
                    <span class="request-step-icon" aria-hidden="true"><i class="fas fa-users"></i></span>
                    <span class="form-section-title">Existing Students</span>
                </span>
                <i class="fas fa-chevron-down form-section-chevron" aria-hidden="true"></i>
            </button>
            <div class="form-section-body" id="onsiteSectionStudents">
                <p class="text-muted onsite-student-picker-note">
                    Select a saved student record, or search below to filter the list.
                </p>

            <form method="GET" class="filter-bar onsite-student-search">
                <input type="text" name="search" placeholder="Search by name, student ID, email, or course..." value="<?= e($search) ?>" autofocus>
                <input type="hidden" name="enrollment_status" value="<?= e($enrollmentStatus) ?>">
                <?php if ($selectedUserId > 0): ?>
                    <input type="hidden" name="student_user_id" value="<?= $selectedUserId ?>">
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
                <?php if ($selectedStudent || $isFilteredStudentSearch): ?>
                    <a href="<?= APP_URL ?>/registrar/new-onsite-request.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </form>

            <?php if ($isFilteredStudentSearch && empty($searchResults)): ?>
                <div class="alert alert-warning">No matching students found. Enter requestor details below to create a walk-in record.</div>
            <?php elseif (empty($searchResults)): ?>
                <div class="empty-state onsite-student-empty">
                    <i class="fas fa-user-slash"></i>
                    <p>No student records saved in the system yet.</p>
                </div>
            <?php else: ?>
                <?php if (!$isFilteredStudentSearch): ?>
                    <p class="text-muted onsite-student-list-note">Showing up to 50 saved students. Search to narrow the list.</p>
                <?php endif; ?>
                <div class="table-wrap onsite-student-results">
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Course</th>
                                <th>Year</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($searchResults as $row): ?>
                                <?php
                                    $rowSelected = $selectedUserId > 0 && (int) $row['id'] === $selectedUserId;
                                    $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                                ?>
                                <tr class="<?= $rowSelected ? 'is-selected' : '' ?>">
                                    <td data-label="Student ID"><strong><?= e($row['student_id'] ?? '—') ?></strong></td>
                                    <td data-label="Name"><?= e($fullName) ?></td>
                                    <td data-label="Email"><?= e($row['email'] ?? '—') ?></td>
                                    <td data-label="Course"><?= e($row['course'] ?? '—') ?></td>
                                    <td data-label="Year"><?= e($row['year_level'] ?? '—') ?></td>
                                    <td data-label="Status"><?= e(enrollmentStatusLabel($row['enrollment_status'] ?? null)) ?></td>
                                    <td data-label="Action">
                                        <?php if ($rowSelected): ?>
                                            <span class="onsite-student-selected-pill"><i class="fas fa-check"></i> Selected</span>
                                        <?php else: ?>
                                            <a class="btn btn-sm btn-primary"
                                               href="?student_user_id=<?= (int) $row['id'] ?>&enrollment_status=<?= urlencode((string) ($row['enrollment_status'] ?? 'enrolled')) ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>">
                                                Select
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <?php if ($selectedStudent): ?>
            <div class="alert alert-info" style="margin-bottom:1rem">
                <i class="fas fa-user-check"></i>
                Creating onsite request for
                <strong><?= e(trim(($selectedStudent['first_name'] ?? '') . ' ' . ($selectedStudent['last_name'] ?? ''))) ?></strong>
                (<?= e($selectedStudent['student_id'] ?? '—') ?>).
            </div>
        <?php endif; ?>

        <?php if (empty($docTypes)): ?>
            <div class="empty-state">
                <i class="fas fa-file-circle-xmark"></i>
                <p>No credentials are available for <?= e(enrollmentStatusLabel($enrollmentStatus)) ?> requestors.</p>
            </div>
        <?php else: ?>
        <form method="POST" class="form-grid request-form-simple" id="requestForm">
            <?= csrfField() ?>
            <input type="hidden" name="student_user_id" value="<?= (int) ($requestorDefaults['student_user_id'] ?? 0) ?>">

            <section class="form-section request-form-step form-section-collapsible <?= $onsiteSectionExpanded('requestor') ? 'is-expanded' : 'is-collapsed' ?>" data-form-section-collapsible>
                <button type="button"
                    class="form-section-toggle"
                    aria-expanded="<?= $onsiteSectionExpanded('requestor') ? 'true' : 'false' ?>"
                    aria-controls="onsiteSectionRequestor">
                    <span class="form-section-toggle-label">
                        <span class="request-step-num">1</span>
                        <span class="form-section-title">Requestor</span>
                    </span>
                    <i class="fas fa-chevron-down form-section-chevron" aria-hidden="true"></i>
                </button>
                <div class="form-section-body" id="onsiteSectionRequestor">
                <div class="form-row">
                    <div class="form-group">
                        <label for="student_id">Student / Requestor ID</label>
                        <input type="text" id="student_id" name="student_id" value="<?= e($requestorDefaults['student_id'] ?? '') ?>" placeholder="Leave blank to auto-generate REQ-<?= date('Y') ?>-XXXXX">
                        <small class="text-muted">Optional. If blank, the system generates an ID like REQ-<?= date('Y') ?>-XXXXX.</small>
                        <?php if (!empty($errors['student_id'])): ?><span class="field-error"><?= e($errors['student_id']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="enrollment_status">Enrollment Status *</label>
                        <select id="enrollment_status" name="enrollment_status" required>
                            <?php foreach (enrollmentStatusOptions() as $statusValue => $statusLabel): ?>
                                <option value="<?= e($statusValue) ?>" <?= $enrollmentStatus === $statusValue ? 'selected' : '' ?>><?= e($statusLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" class="input-uppercase" autocapitalize="characters" required value="<?= e(normalizePersonName($requestorDefaults['first_name'] ?? '')) ?>">
                        <?php if (!empty($errors['first_name'])): ?><span class="field-error"><?= e($errors['first_name']) ?></span><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" class="input-uppercase" autocapitalize="characters" required value="<?= e(normalizePersonName($requestorDefaults['last_name'] ?? '')) ?>">
                        <?php if (!empty($errors['last_name'])): ?><span class="field-error"><?= e($errors['last_name']) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="middle_name">Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name" class="input-uppercase" autocapitalize="characters" value="<?= e(normalizePersonName($requestorDefaults['middle_name'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="text" id="phone" name="phone" value="<?= e($requestorDefaults['phone'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= e($requestorDefaults['email'] ?? '') ?>" placeholder="Optional — auto-generated if blank for new walk-ins">
                        <?php if (!empty($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
                    </div>
                </div>

                <input type="hidden" name="course_id" id="course_id" value="<?= (int) ($requestorDefaults['course_id'] ?? 0) ?>">
                <input type="hidden" name="origin_campus_id" id="origin_campus_id" value="<?= (int) ($requestorDefaults['origin_campus_id'] ?? 0) ?>">

                <div class="onsite-academic-fields">
                    <div id="onsiteAcademicEnrolled"
                        class="onsite-academic-panel academic-status-panel"
                        data-enrollment-panel="enrolled"
                        style="display: <?= $isEnrolledStatus ? 'block' : 'none' ?>;">
                        <p class="text-muted academic-panel-note">Provide current enrollment details.</p>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="onsite_course_id_enrolled">Course / Program</label>
                                <select id="onsite_course_id_enrolled" data-course-select="enrolled" <?= $isEnrolledStatus ? '' : 'disabled' ?>>
                                    <option value="">— Select course/program —</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= (int) $program['id'] ?>" <?= (int) ($requestorDefaults['course_id'] ?? 0) === (int) $program['id'] ? 'selected' : '' ?>>
                                            <?= e($program['name']) ?> (<?= e($program['code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="year_level">Year Level</label>
                                <select id="year_level" name="year_level" <?= $isEnrolledStatus ? '' : 'disabled' ?>>
                                    <option value="">— Select year level —</option>
                                    <?php foreach (yearLevelOptions() as $yearValue => $yearLabel): ?>
                                        <option value="<?= e($yearValue) ?>" <?= ($requestorDefaults['year_level'] ?? '') === $yearValue ? 'selected' : '' ?>>
                                            <?= e($yearLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!empty($errors['year_level'])): ?><span class="field-error"><?= e($errors['year_level']) ?></span><?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div id="onsiteAcademicGraduated"
                        class="onsite-academic-panel academic-status-panel"
                        data-enrollment-panel="graduated"
                        style="display: <?= $isGraduatedStatus ? 'block' : 'none' ?>;">
                        <p class="text-muted academic-panel-note">Provide graduation details.</p>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="onsite_course_id_graduated">Course / Program</label>
                                <select id="onsite_course_id_graduated" data-course-select="graduated" <?= $isGraduatedStatus ? '' : 'disabled' ?>>
                                    <option value="">— Select course/program —</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= (int) $program['id'] ?>" <?= (int) ($requestorDefaults['course_id'] ?? 0) === (int) $program['id'] ? 'selected' : '' ?>>
                                            <?= e($program['name']) ?> (<?= e($program['code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="year_graduated">Year of Graduation *</label>
                                <select id="year_graduated" name="year_graduated" <?= $isGraduatedStatus ? 'required' : 'disabled' ?>>
                                    <option value="">— Select year —</option>
                                    <?php foreach (yearGraduatedOptions() as $yearValue => $yearLabel): ?>
                                        <option value="<?= e($yearValue) ?>" <?= (int) ($requestorDefaults['year_graduated'] ?? 0) === (int) $yearValue ? 'selected' : '' ?>>
                                            <?= e($yearLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!empty($errors['year_graduated'])): ?><span class="field-error"><?= e($errors['year_graduated']) ?></span><?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="onsite_origin_campus_id_graduated">Campus *</label>
                                <select id="onsite_origin_campus_id_graduated" data-campus-select="graduated" <?= $isGraduatedStatus ? 'required' : 'disabled' ?>>
                                    <option value="">— Select campus —</option>
                                    <?php foreach ($campuses as $campus): ?>
                                        <option value="<?= (int) $campus['id'] ?>" <?= (int) ($requestorDefaults['origin_campus_id'] ?? 0) === (int) $campus['id'] ? 'selected' : '' ?>>
                                            <?= e($campus['name']) ?> (<?= e($campus['code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div id="onsiteAcademicInactive"
                        class="onsite-academic-panel academic-status-panel"
                        data-enrollment-panel="inactive"
                        style="display: <?= $isInactiveStatus ? 'block' : 'none' ?>;">
                        <p class="text-muted academic-panel-note">Provide details from the last active enrollment.</p>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="onsite_course_id_inactive">Last Course / Program Attended</label>
                                <select id="onsite_course_id_inactive" data-course-select="inactive" <?= $isInactiveStatus ? '' : 'disabled' ?>>
                                    <option value="">— Select course/program —</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= (int) $program['id'] ?>" <?= (int) ($requestorDefaults['course_id'] ?? 0) === (int) $program['id'] ? 'selected' : '' ?>>
                                            <?= e($program['name']) ?> (<?= e($program['code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="last_school_year">Last School Year Attended *</label>
                                <select id="last_school_year" name="last_school_year" <?= $isInactiveStatus ? 'required' : 'disabled' ?>>
                                    <option value="">— Select school year —</option>
                                    <?php foreach (schoolYearOptions() as $yearValue => $yearLabel): ?>
                                        <option value="<?= e($yearValue) ?>" <?= ($requestorDefaults['last_school_year'] ?? '') === $yearValue ? 'selected' : '' ?>>
                                            <?= e($yearLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!empty($errors['last_school_year'])): ?><span class="field-error"><?= e($errors['last_school_year']) ?></span><?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="last_semester">Semester Attended *</label>
                                <select id="last_semester" name="last_semester" <?= $isInactiveStatus ? 'required' : 'disabled' ?>>
                                    <option value="">— Select semester —</option>
                                    <?php foreach (semesterOptions() as $semValue => $semLabel): ?>
                                        <option value="<?= e($semValue) ?>" <?= ($requestorDefaults['last_semester'] ?? '') === $semValue ? 'selected' : '' ?>>
                                            <?= e($semLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!empty($errors['last_semester'])): ?><span class="field-error"><?= e($errors['last_semester']) ?></span><?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="onsite_origin_campus_id_inactive">Campus *</label>
                                <select id="onsite_origin_campus_id_inactive" data-campus-select="inactive" <?= $isInactiveStatus ? 'required' : 'disabled' ?>>
                                    <option value="">— Select campus —</option>
                                    <?php foreach ($campuses as $campus): ?>
                                        <option value="<?= (int) $campus['id'] ?>" <?= (int) ($requestorDefaults['origin_campus_id'] ?? 0) === (int) $campus['id'] ? 'selected' : '' ?>>
                                            <?= e($campus['name']) ?> (<?= e($campus['code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($errors['course_id'])): ?>
                        <span class="field-error"><?= e($errors['course_id']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($errors['origin_campus_id'])): ?>
                        <span class="field-error"><?= e($errors['origin_campus_id']) ?></span>
                    <?php endif; ?>
                </div>
                </div>
            </section>

            <section class="form-section request-form-step form-section-collapsible <?= $onsiteSectionExpanded('purpose') ? 'is-expanded' : 'is-collapsed' ?>" data-form-section-collapsible>
                <button type="button"
                    class="form-section-toggle"
                    aria-expanded="<?= $onsiteSectionExpanded('purpose') ? 'true' : 'false' ?>"
                    aria-controls="onsiteSectionPurpose">
                    <span class="form-section-toggle-label">
                        <span class="request-step-num">2</span>
                        <span class="form-section-title">Purpose &amp; type</span>
                    </span>
                    <i class="fas fa-chevron-down form-section-chevron" aria-hidden="true"></i>
                </button>
                <div class="form-section-body" id="onsiteSectionPurpose">
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
                    <input type="text" id="purpose_other" name="purpose_other" value="<?= e($_POST['purpose_other'] ?? '') ?>" placeholder="Describe the purpose">
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
                </div>
            </section>

            <section class="form-section request-form-step form-section-collapsible <?= $onsiteSectionExpanded('documents') ? 'is-expanded' : 'is-collapsed' ?>" data-form-section-collapsible>
                <button type="button"
                    class="form-section-toggle"
                    aria-expanded="<?= $onsiteSectionExpanded('documents') ? 'true' : 'false' ?>"
                    aria-controls="onsiteSectionDocuments">
                    <span class="form-section-toggle-label">
                        <span class="request-step-num">3</span>
                        <span class="form-section-title">Requested documents</span>
                    </span>
                    <i class="fas fa-chevron-down form-section-chevron" aria-hidden="true"></i>
                </button>
                <div class="form-section-body" id="onsiteSectionDocuments">
                <p class="text-muted document-checklist-note">Available for <?= e(enrollmentStatusLabel($enrollmentStatus)) ?> requestors</p>
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
                </div>
            </section>

            <section class="form-section request-form-step form-section-collapsible <?= $onsiteSectionExpanded('clearance') ? 'is-expanded' : 'is-collapsed' ?>" data-form-section-collapsible>
                <button type="button"
                    class="form-section-toggle"
                    aria-expanded="<?= $onsiteSectionExpanded('clearance') ? 'true' : 'false' ?>"
                    aria-controls="onsiteSectionClearance">
                    <span class="form-section-toggle-label">
                        <span class="request-step-num">4</span>
                        <span class="form-section-title">Online Clearance <span class="request-optional-tag">optional</span></span>
                    </span>
                    <i class="fas fa-chevron-down form-section-chevron" aria-hidden="true"></i>
                </button>
                <div class="form-section-body" id="onsiteSectionClearance">
                <label class="checkbox-label onsite-clearance-option">
                    <input type="checkbox"
                        name="require_online_clearance"
                        value="1"
                        <?= !empty($_POST['require_online_clearance']) ? 'checked' : '' ?>>
                    <span>
                        <strong>Require online clearance before cashier payment</strong>
                        <small class="text-muted">Activate clearance signing for all offices. Cashier verification stays blocked until clearance is complete.</small>
                    </span>
                </label>
                </div>
            </section>

            <section class="form-section request-form-step form-section-collapsible <?= $onsiteSectionExpanded('notes') ? 'is-expanded' : 'is-collapsed' ?>" data-form-section-collapsible>
                <button type="button"
                    class="form-section-toggle"
                    aria-expanded="<?= $onsiteSectionExpanded('notes') ? 'true' : 'false' ?>"
                    aria-controls="onsiteSectionNotes">
                    <span class="form-section-toggle-label">
                        <span class="request-step-num">5</span>
                        <span class="form-section-title">Notes <span class="request-optional-tag">optional</span></span>
                    </span>
                    <i class="fas fa-chevron-down form-section-chevron" aria-hidden="true"></i>
                </button>
                <div class="form-section-body" id="onsiteSectionNotes">
                <div class="form-group">
                    <textarea id="notes" name="notes" rows="2" placeholder="Any notes for cashier or processing staff..."><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
                </div>
            </section>

            <section class="request-form-footer">
                <div class="fee-summary payment-breakdown-panel">
                    <p class="payment-breakdown-empty" id="paymentBreakdownEmpty">Select documents to estimate fees.</p>
                    <div class="payment-breakdown-list" id="paymentBreakdownList" hidden></div>
                    <div class="payment-breakdown-total">
                        <div>
                            <span class="payment-breakdown-total-label">Amount for cashier</span>
                            <small class="text-muted" id="selectedDocCount">No documents selected</small>
                        </div>
                        <strong id="totalFee">₱ 0.00</strong>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-lg request-submit-btn">
                    <i class="fas fa-cash-register"></i> Create &amp; Generate Payment Code
                </button>
            </section>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>
const purposeSuggestions = <?= json_encode($purposeSuggestions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const purposeHints = <?= json_encode($purposeHints, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

document.getElementById('enrollment_status')?.addEventListener('change', function () {
    toggleOnsiteAcademicPanels();
    const params = new URLSearchParams();
    const studentUserId = document.querySelector('input[name="student_user_id"]')?.value || '';
    if (studentUserId && studentUserId !== '0') {
        params.set('student_user_id', studentUserId);
    }
    params.set('enrollment_status', this.value);
    window.location.href = '<?= APP_URL ?>/registrar/new-onsite-request.php?' + params.toString();
});

function syncOnsiteCourseIdField() {
    const status = document.getElementById('enrollment_status')?.value || 'enrolled';
    const map = {
        enrolled: 'onsite_course_id_enrolled',
        graduated: 'onsite_course_id_graduated',
        inactive: 'onsite_course_id_inactive',
    };
    const select = document.getElementById(map[status] || map.enrolled);
    const hidden = document.getElementById('course_id');
    if (hidden) {
        hidden.value = select ? select.value : '';
    }
}

function syncOnsiteOriginCampusField() {
    const status = document.getElementById('enrollment_status')?.value || 'enrolled';
    const map = {
        graduated: 'onsite_origin_campus_id_graduated',
        inactive: 'onsite_origin_campus_id_inactive',
    };
    const select = document.getElementById(map[status] || '');
    const hidden = document.getElementById('origin_campus_id');
    if (!hidden) {
        return;
    }
    hidden.value = select ? select.value : '0';
}

function toggleOnsiteAcademicPanels() {
    const status = document.getElementById('enrollment_status')?.value || 'enrolled';
    const panels = {
        enrolled: 'onsiteAcademicEnrolled',
        graduated: 'onsiteAcademicGraduated',
        inactive: 'onsiteAcademicInactive',
    };

    Object.keys(panels).forEach(function (key) {
        const panel = document.getElementById(panels[key]);
        if (!panel) {
            return;
        }
        const active = key === status;
        panel.style.display = active ? 'block' : 'none';
        panel.querySelectorAll('input, select, textarea').forEach(function (field) {
            field.disabled = !active;
        });
    });

    const yearGraduated = document.getElementById('year_graduated');
    const lastSchoolYear = document.getElementById('last_school_year');
    const lastSemester = document.getElementById('last_semester');
    const campusGraduated = document.getElementById('onsite_origin_campus_id_graduated');
    const campusInactive = document.getElementById('onsite_origin_campus_id_inactive');

    if (yearGraduated) {
        yearGraduated.required = status === 'graduated';
    }
    if (lastSchoolYear) {
        lastSchoolYear.required = status === 'inactive';
    }
    if (lastSemester) {
        lastSemester.required = status === 'inactive';
    }
    if (campusGraduated) {
        campusGraduated.required = status === 'graduated';
    }
    if (campusInactive) {
        campusInactive.required = status === 'inactive';
    }

    syncOnsiteCourseIdField();
    syncOnsiteOriginCampusField();
}

document.querySelectorAll('[data-course-select]').forEach(function (select) {
    select.addEventListener('change', syncOnsiteCourseIdField);
});

document.querySelectorAll('[data-campus-select]').forEach(function (select) {
    select.addEventListener('change', syncOnsiteOriginCampusField);
});

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
    syncOnsiteCourseIdField();
    syncOnsiteOriginCampusField();
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
toggleOnsiteAcademicPanels();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
