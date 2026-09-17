<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

ensureDeliveryMethods();
ensureStudentEmploymentFields();
ensureAcademicProgramsSchema();
ensureEnrollmentStatuses();
ensureCampusesSchema();
ensureStudentAcademicTermFields();
ensureStudentValidIdField();
require_once __DIR__ . '/../includes/academic-term.php';

$db = getDB();
$studentId = (int) ($_GET['id'] ?? $_POST['user_id'] ?? 0);
$returnUrl = sanitizeAdminStudentsReturnUrl($_POST['return_url'] ?? $_GET['return'] ?? '');

$student = loadStudentAccountForAdminEdit($studentId);
if (!$student) {
    setFlash('error', 'Student account not found.', ['title' => 'Student Not Found']);
    redirect($returnUrl);
}

$programs = getAcademicProgramsForStudent((int) ($student['course_id'] ?? 0));
$majorsByProgram = getAcademicMajorsGroupedByProgram(true);
$selectedMajorId = (int) ($student['major_id'] ?? 0);
if ($selectedMajorId > 0) {
    $selectedMajor = getAcademicMajorById($selectedMajorId);
    $selectedProgramId = (int) ($student['course_id'] ?? 0);
    if ($selectedMajor && $selectedProgramId > 0 && (int) $selectedMajor['program_id'] === $selectedProgramId) {
        $majorsByProgram[$selectedProgramId] ??= [];
        $alreadyListed = array_filter(
            $majorsByProgram[$selectedProgramId],
            static fn($m) => (int) $m['id'] === $selectedMajorId
        );
        if (!$alreadyListed) {
            array_unshift($majorsByProgram[$selectedProgramId], $selectedMajor);
        }
    }
}
$campuses = getCampusesForStudent((int) ($student['origin_campus_id'] ?? 0));
$currentEnrollment = $student['enrollment_status'] ?? 'enrolled';
$formErrors = [];
$lockStudentId = isEnrolledEnrollment($currentEnrollment);
$lockedStudentId = trim((string) ($student['student_id'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $validIdPath = trim($student['valid_id_path'] ?? '');
    $validIdOriginalName = trim($student['valid_id_original_name'] ?? '');

    if (!empty($_FILES['valid_id']['name'])) {
        $uploaded = saveStudentValidIdUpload($studentId, $_FILES['valid_id']);
        if (!$uploaded) {
            setFlash('error', 'Unable to upload Valid ID. Use PDF, JPG, PNG, or DOC up to 5 MB.', [
                'title' => 'Invalid ID File',
            ]);
            redirect(APP_URL . '/admin/student-edit.php?id=' . $studentId . '&return=' . urlencode($returnUrl));
        }
        $validIdPath = $uploaded['path'];
        $validIdOriginalName = $uploaded['original_name'];
    }

    $fields = [
        'first_name'            => normalizePersonName($_POST['first_name'] ?? ''),
        'last_name'             => normalizePersonName($_POST['last_name'] ?? ''),
        'middle_name'           => normalizePersonName($_POST['middle_name'] ?? ''),
        'student_id'            => substr(trim((string) ($_POST['student_id'] ?? '')), 0, 50),
        'email'                 => strtolower(trim((string) ($_POST['email'] ?? ''))),
        'phone'                 => trim($_POST['phone'] ?? ''),
        'is_active'             => !empty($_POST['is_active']) ? 1 : 0,
        'course_id'             => (int) ($_POST['course_id'] ?? 0),
        'major_id'              => (int) ($_POST['major_id'] ?? 0),
        'year_level'            => trim($_POST['year_level'] ?? ''),
        'current_academic_year' => trim($_POST['current_academic_year'] ?? ''),
        'current_semester'      => trim($_POST['current_semester'] ?? ''),
        'year_graduated'        => (int) ($_POST['year_graduated'] ?? 0),
        'origin_campus_id'      => (int) ($_POST['origin_campus_id'] ?? 0),
        'last_school_year'      => trim($_POST['last_school_year'] ?? ''),
        'birth_date'            => trim($_POST['birth_date'] ?? ''),
        'emergency_contact'     => trim($_POST['emergency_contact'] ?? ''),
        'emergency_phone'       => trim($_POST['emergency_phone'] ?? ''),
        'enrollment_status'     => trim($_POST['enrollment_status'] ?? ''),
        'employment_status'     => trim($_POST['employment_status'] ?? ''),
        'employer_name'         => trim($_POST['employer_name'] ?? ''),
        'job_title'             => trim($_POST['job_title'] ?? ''),
        'employer_address'      => trim($_POST['employer_address'] ?? ''),
        'employment_start_date' => trim($_POST['employment_start_date'] ?? ''),
        'valid_id_path'         => $validIdPath,
        'new_password'          => (string) ($_POST['new_password'] ?? ''),
        'reset_password_to_id'  => !empty($_POST['reset_password_to_id']),
    ];

    // Enrolled (active) students keep their official ID number.
    if ($lockStudentId) {
        $fields['student_id'] = $lockedStudentId;
    }
    if (!array_key_exists($fields['enrollment_status'], enrollmentStatusOptions())) {
        $fields['enrollment_status'] = 'enrolled';
    }

    $fields = normalizeStudentProfileFields($fields);
    $missing = validateStudentProfileFields($fields);

    if ($fields['student_id'] === '') {
        $missing[] = 'Student ID';
    }
    if ($fields['email'] === '' || !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        $missing[] = 'Valid email';
    }

    $selectedProgram = resolveAcademicProgramForProfile((int) $fields['course_id']);
    if ((int) $fields['course_id'] > 0 && !$selectedProgram) {
        $missing[] = 'Valid course/program selection';
    }

    $selectedMajor = null;
    $programMajors = $selectedProgram ? getActiveAcademicMajorsForProgram((int) $selectedProgram['id']) : [];
    if (!empty($programMajors)) {
        $selectedMajor = resolveAcademicMajorForProgram((int) ($selectedProgram['id'] ?? 0), (int) $fields['major_id']);
        if (!$selectedMajor) {
            $existingMajor = getAcademicMajorById((int) $fields['major_id']);
            if ($existingMajor
                && (int) $existingMajor['program_id'] === (int) $selectedProgram['id']
                && (int) $fields['major_id'] === (int) ($student['major_id'] ?? 0)) {
                $selectedMajor = $existingMajor;
            } else {
                $missing[] = 'Major';
            }
        }
    }

    if (isGraduatedEnrollment($fields['enrollment_status'])
        || isEnrolledEnrollment($fields['enrollment_status'])
        || isInactiveEnrollment($fields['enrollment_status'])) {
        if ((int) $fields['origin_campus_id'] > 0 && !getCampusById((int) $fields['origin_campus_id'])) {
            $missing[] = 'Valid origin campus selection';
        }
    }

    $identityConflicts = studentAccountIdentityConflicts($studentId, $fields['email'], $fields['student_id']);
    foreach ($identityConflicts as $conflict) {
        $missing[] = $conflict;
    }

    $passwordToSet = null;
    if ($fields['reset_password_to_id']) {
        if ($fields['student_id'] === '') {
            $missing[] = 'Student ID (required to reset password)';
        } else {
            $passwordToSet = $fields['student_id'];
        }
    } elseif (trim($fields['new_password']) !== '') {
        if (strlen($fields['new_password']) < 6) {
            $missing[] = 'New password (at least 6 characters)';
        } else {
            $passwordToSet = $fields['new_password'];
        }
    }

    if (!empty($missing)) {
        $formErrors = $missing;
        setFlash('error', 'Please complete all required student fields before saving.', [
            'title' => 'Unable to Save Student',
            'next_step' => 'Fix: ' . implode(', ', $missing),
        ]);
        $student = array_merge($student, [
            'first_name' => $fields['first_name'],
            'last_name' => $fields['last_name'],
            'middle_name' => $fields['middle_name'],
            'student_id' => $fields['student_id'],
            'email' => $fields['email'],
            'phone' => $fields['phone'],
            'is_active' => $fields['is_active'],
            'course_id' => $fields['course_id'],
            'major_id' => $fields['major_id'],
            'year_level' => $fields['year_level'],
            'current_academic_year' => $fields['current_academic_year'],
            'current_semester' => $fields['current_semester'],
            'year_graduated' => $fields['year_graduated'] ?: null,
            'origin_campus_id' => $fields['origin_campus_id'] ?: null,
            'last_school_year' => $fields['last_school_year'],
            'birth_date' => $fields['birth_date'],
            'emergency_contact' => $fields['emergency_contact'],
            'emergency_phone' => $fields['emergency_phone'],
            'enrollment_status' => $fields['enrollment_status'],
            'employment_status' => $fields['employment_status'],
            'employer_name' => $fields['employer_name'],
            'job_title' => $fields['job_title'],
            'employer_address' => $fields['employer_address'],
            'employment_start_date' => $fields['employment_start_date'],
            'valid_id_path' => $validIdPath,
            'valid_id_original_name' => $validIdOriginalName,
        ]);
        $currentEnrollment = $fields['enrollment_status'];
        $programs = getAcademicProgramsForStudent((int) $fields['course_id']);
        $campuses = getCampusesForStudent((int) $fields['origin_campus_id']);
    } else {
        try {
            ensureStudentProfileRowExists($studentId);

            $userSql = 'UPDATE users SET phone = ?, first_name = ?, last_name = ?, middle_name = ?, email = ?, student_id = ?, is_active = ?';
            $userParams = [
                $fields['phone'],
                $fields['first_name'],
                $fields['last_name'],
                $fields['middle_name'] ?: null,
                $fields['email'],
                $fields['student_id'],
                $fields['is_active'],
            ];
            if ($passwordToSet !== null) {
                $userSql .= ', password = ?';
                $userParams[] = password_hash($passwordToSet, PASSWORD_BCRYPT);
            }
            $userSql .= ' WHERE id = ?';
            $userParams[] = $studentId;
            $db->prepare($userSql)->execute($userParams);

            $db->prepare('UPDATE student_profiles SET course=?, course_id=?, year_level=?, current_academic_year=?, current_semester=?, section=?, major=?, major_id=?, birth_date=?, valid_id_path=?, valid_id_original_name=?, address=?, city=?, province=?, postal_code=?, emergency_contact=?, emergency_phone=?, enrollment_status=?, graduation_date=?, origin_campus_id=?, year_graduated=?, last_school_year=?, employment_status=?, employer_name=?, job_title=?, employer_address=?, employment_start_date=? WHERE user_id=?')
               ->execute([
                   $selectedProgram['name'], (int) $selectedProgram['id'],
                   isEnrolledEnrollment($fields['enrollment_status']) ? ($fields['year_level'] ?: null) : null,
                   isEnrolledEnrollment($fields['enrollment_status']) ? ($fields['current_academic_year'] ?: null) : null,
                   isEnrolledEnrollment($fields['enrollment_status']) ? ($fields['current_semester'] ?: null) : null,
                   null,
                   $selectedMajor['name'] ?? null,
                   $selectedMajor ? (int) $selectedMajor['id'] : null,
                   $fields['birth_date'] ?: null,
                   $validIdPath ?: null,
                   $validIdOriginalName ?: null,
                   null, null, null, null,
                   $fields['emergency_contact'],
                   $fields['emergency_phone'], $fields['enrollment_status'] ?: 'enrolled',
                   isGraduatedEnrollment($fields['enrollment_status']) && $fields['year_graduated']
                       ? $fields['year_graduated'] . '-06-01' : null,
                   (isEnrolledEnrollment($fields['enrollment_status'])
                       || isGraduatedEnrollment($fields['enrollment_status'])
                       || isInactiveEnrollment($fields['enrollment_status']))
                       ? ((int) $fields['origin_campus_id'] ?: null) : null,
                   isGraduatedEnrollment($fields['enrollment_status']) ? ((int) $fields['year_graduated'] ?: null) : null,
                   isInactiveEnrollment($fields['enrollment_status']) ? ($fields['last_school_year'] ?: null) : null,
                   isGraduatedEnrollment($fields['enrollment_status']) ? ($fields['employment_status'] ?: null) : null,
                   isGraduatedEnrollment($fields['enrollment_status']) ? ($fields['employer_name'] ?: null) : null,
                   isGraduatedEnrollment($fields['enrollment_status']) ? ($fields['job_title'] ?: null) : null,
                   isGraduatedEnrollment($fields['enrollment_status']) ? ($fields['employer_address'] ?: null) : null,
                   isGraduatedEnrollment($fields['enrollment_status']) && $fields['employment_start_date'] ? $fields['employment_start_date'] : null,
                   $studentId,
               ]);

            auditLog('admin_student_update', 'users', $studentId, null, [
                'student_id' => $fields['student_id'],
                'password_changed' => $passwordToSet !== null,
                'is_active' => $fields['is_active'],
            ]);

            $flashContext = [
                'Student' => trim($fields['last_name'] . ', ' . $fields['first_name']),
                'Student ID' => $fields['student_id'],
            ];
            if ($passwordToSet !== null) {
                $flashContext['Password'] = $fields['reset_password_to_id']
                    ? 'Reset to Student ID'
                    : 'Updated';
            }

            setFlash('success', 'Student information updated successfully.', [
                'title' => 'Student Updated',
                'context' => $flashContext,
                'action_url' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'view=' . $studentId,
                'action_label' => 'View Student',
            ]);
            redirect($returnUrl);
        } catch (PDOException $e) {
            $message = str_contains($e->getMessage(), 'Duplicate')
                ? 'Email or Student ID is already used by another account.'
                : 'Unable to save student information.';
            setFlash('error', $message, ['title' => 'Save Failed']);
            redirect(APP_URL . '/admin/student-edit.php?id=' . $studentId . '&return=' . urlencode($returnUrl));
        }
    }
}

$displayName = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? ''), ' ,');
if ($displayName === '') {
    $displayName = $student['student_id'] ?: 'Student';
}
$profileCompletion = getStudentProfileCompletion($studentId);
$pageTitle = 'Edit Student';
$activeNav = 'students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <a href="<?= e($returnUrl) ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Student List</a>
            <h2 style="margin-top:.75rem">Edit Student Information</h2>
            <p class="text-muted" style="margin:.35rem 0 0"><?= e($displayName) ?> · <?= e($student['student_id'] ?: 'No student ID') ?></p>
        </div>
        <a href="<?= e($returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'view=' . $studentId) ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-eye"></i> View
        </a>
    </div>
    <div class="card-body">
        <?= renderStudentRegistrationStatus($profileCompletion, 'inline') ?>

        <?php if ($formErrors !== []): ?>
            <div class="alert alert-error" style="margin-bottom:1rem">
                <strong>Fix the following:</strong> <?= e(implode(', ', $formErrors)) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="form-grid" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="user_id" value="<?= (int) $studentId ?>">
            <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">

            <div class="form-section">
                <h3>Account</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="student_id">Student ID *</label>
                        <?php if ($lockStudentId): ?>
                            <input type="text" id="student_id" value="<?= e($lockedStudentId) ?>" readonly>
                            <input type="hidden" name="student_id" value="<?= e($lockedStudentId) ?>">
                            <small class="text-muted">Student ID cannot be changed for enrolled (active) students.</small>
                        <?php else: ?>
                            <input type="text" id="student_id" name="student_id" value="<?= e($student['student_id'] ?? '') ?>" required maxlength="50">
                        <?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" value="<?= e($student['email'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_active" value="1" <?= !empty($student['is_active']) ? 'checked' : '' ?>>
                            Active account (can sign in)
                        </label>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="text" id="new_password" name="new_password" minlength="6" autocomplete="new-password" placeholder="Leave blank to keep current password">
                        <small class="text-muted">Optional. Minimum 6 characters.</small>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="reset_password_to_id" value="1">
                            Reset password to Student ID
                        </label>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Personal Information</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" class="input-uppercase" autocapitalize="characters" value="<?= e(normalizePersonName($student['first_name'] ?? '')) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="middle_name">Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name" class="input-uppercase" autocapitalize="characters" value="<?= e(normalizePersonName($student['middle_name'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" class="input-uppercase" autocapitalize="characters" value="<?= e(normalizePersonName($student['last_name'] ?? '')) ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone *</label>
                        <input type="tel" id="phone" name="phone" value="<?= e($student['phone'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="birth_date">Birth Date *</label>
                        <input type="date" id="birth_date" name="birth_date" value="<?= e($student['birth_date'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="valid_id">Identification ID / Valid ID <span id="valid_id_required_mark"<?= studentValidIdRequired(array_merge($student, ['enrollment_status' => $currentEnrollment])) && empty($student['valid_id_path']) ? '' : ' hidden' ?>>*</span></label>
                    <input type="file" id="valid_id" name="valid_id" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" <?= studentValidIdRequired(array_merge($student, ['enrollment_status' => $currentEnrollment])) && empty($student['valid_id_path']) ? 'required' : '' ?>>
                    <small class="text-muted">
                        PDF, JPG, PNG, or DOC up to 5 MB.
                        Optional for enrolled students on the active school year and semester.
                    </small>
                    <?php if (!empty($student['valid_id_path'])): ?>
                        <div class="profile-id-preview">
                            <i class="fas fa-id-card"></i>
                            <a href="<?= e(UPLOAD_URL . '/' . ltrim($student['valid_id_path'], '/')) ?>" target="_blank">
                                <?= e($student['valid_id_original_name'] ?? 'View uploaded ID') ?>
                            </a>
                            <small class="text-muted">Upload a new file to replace the current ID.</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-section academic-info-section">
                <h3>Academic Information</h3>
                <div class="form-group">
                    <label for="enrollment_status">Enrollment Status *</label>
                    <select id="enrollment_status" name="enrollment_status" required onchange="toggleAcademicSections()">
                        <?php foreach (enrollmentStatusOptions() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $currentEnrollment === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="academicEnrolled" class="academic-status-panel">
                    <p class="text-muted academic-panel-note">Current enrollment details.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="course_id_enrolled">Current Course/Program *</label>
                            <select id="course_id_enrolled" data-course-select="enrolled">
                                <option value="">— Select Course / Program —</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= (int) $program['id'] ?>" <?= (int) ($student['course_id'] ?? 0) === (int) $program['id'] ? 'selected' : '' ?>>
                                        <?= e($program['name']) ?> (<?= e($program['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="year_level">Year Level *</label>
                            <select id="year_level" name="year_level">
                                <option value="">— Select —</option>
                                <?php foreach (['1st Year','2nd Year','3rd Year','4th Year'] as $yl): ?>
                                    <option value="<?= $yl ?>" <?= ($student['year_level'] ?? '') === $yl ? 'selected' : '' ?>><?= $yl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="origin_campus_id_enrolled">Origin Campus *</label>
                            <select id="origin_campus_id_enrolled" data-campus-select="enrolled">
                                <option value="">— Select Campus —</option>
                                <?php foreach ($campuses as $campus): ?>
                                    <option value="<?= (int) $campus['id'] ?>" <?= (int) ($student['origin_campus_id'] ?? 0) === (int) $campus['id'] ? 'selected' : '' ?>>
                                        <?= e($campus['name']) ?> (<?= e($campus['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="current_academic_year">Current Academic Year *</label>
                            <select id="current_academic_year" name="current_academic_year">
                                <option value="">— Select Academic Year —</option>
                                <?php foreach (currentAcademicYearOptions() as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= ($student['current_academic_year'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="current_semester">Current Semester *</label>
                            <select id="current_semester" name="current_semester">
                                <option value="">— Select Semester —</option>
                                <?php foreach (semesterOptions() as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= ($student['current_semester'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="academicGraduated" class="academic-status-panel">
                    <p class="text-muted academic-panel-note">Graduation details.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="course_id_graduated">Course/Program *</label>
                            <select id="course_id_graduated" data-course-select="graduated">
                                <option value="">— Select Course / Program —</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= (int) $program['id'] ?>" <?= (int) ($student['course_id'] ?? 0) === (int) $program['id'] ? 'selected' : '' ?>>
                                        <?= e($program['name']) ?> (<?= e($program['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="year_graduated">Year Graduated *</label>
                            <select id="year_graduated" name="year_graduated">
                                <option value="">— Select Year —</option>
                                <?php
                                $selectedYear = (int) ($student['year_graduated'] ?? 0);
                                if (!$selectedYear && !empty($student['graduation_date'])) {
                                    $selectedYear = (int) date('Y', strtotime($student['graduation_date']));
                                }
                                foreach (yearGraduatedOptions() as $value => $label):
                                ?>
                                    <option value="<?= e($value) ?>" <?= $selectedYear === (int) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="origin_campus_id_graduated">Origin Campus *</label>
                            <select id="origin_campus_id_graduated" data-campus-select="graduated">
                                <option value="">— Select Campus —</option>
                                <?php foreach ($campuses as $campus): ?>
                                    <option value="<?= (int) $campus['id'] ?>" <?= (int) ($student['origin_campus_id'] ?? 0) === (int) $campus['id'] ? 'selected' : '' ?>>
                                        <?= e($campus['name']) ?> (<?= e($campus['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="academicInactive" class="academic-status-panel">
                    <p class="text-muted academic-panel-note">Last active enrollment details.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="course_id_inactive">Last Course/Program Attended *</label>
                            <select id="course_id_inactive" data-course-select="inactive">
                                <option value="">— Select Course / Program —</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= (int) $program['id'] ?>" <?= (int) ($student['course_id'] ?? 0) === (int) $program['id'] ? 'selected' : '' ?>>
                                        <?= e($program['name']) ?> (<?= e($program['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="last_school_year">Last School Year Enrolled *</label>
                            <select id="last_school_year" name="last_school_year">
                                <option value="">— Select School Year —</option>
                                <?php foreach (schoolYearOptions() as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= ($student['last_school_year'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="origin_campus_id_inactive">Origin Campus *</label>
                            <select id="origin_campus_id_inactive" data-campus-select="inactive">
                                <option value="">— Select Campus —</option>
                                <?php foreach ($campuses as $campus): ?>
                                    <option value="<?= (int) $campus['id'] ?>" <?= (int) ($student['origin_campus_id'] ?? 0) === (int) $campus['id'] ? 'selected' : '' ?>>
                                        <?= e($campus['name']) ?> (<?= e($campus['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-group" id="majorFieldGroup" hidden>
                    <label for="major_id">Major *</label>
                    <select id="major_id" name="major_id">
                        <option value="">— Select Major —</option>
                    </select>
                    <small class="text-muted">Required for programs that offer majors (e.g. BSED).</small>
                </div>

                <input type="hidden" name="course_id" id="course_id" value="<?= (int) ($student['course_id'] ?? 0) ?>">
                <input type="hidden" name="origin_campus_id" id="origin_campus_id" value="<?= (int) ($student['origin_campus_id'] ?? 0) ?>">
            </div>

            <div class="form-section employment-section" id="employmentSection">
                <h3><i class="fas fa-briefcase"></i> Employment Information</h3>
                <p class="text-muted employment-section-note">Required for students with <strong>Graduated</strong> enrollment status.</p>
                <div class="form-group">
                    <label for="employment_status">Employment Status *</label>
                    <select id="employment_status" name="employment_status" onchange="toggleEmploymentDetails()">
                        <option value="">— Select —</option>
                        <?php foreach (employmentStatusOptions() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($student['employment_status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="employmentDetails">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="employer_name">Employer / Company Name *</label>
                            <input type="text" id="employer_name" name="employer_name" value="<?= e($student['employer_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="job_title">Job Title / Position *</label>
                            <input type="text" id="job_title" name="job_title" value="<?= e($student['job_title'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="employment_start_date">Employment Start Date *</label>
                            <input type="date" id="employment_start_date" name="employment_start_date" value="<?= e($student['employment_start_date'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="employer_address">Employer Address</label>
                            <input type="text" id="employer_address" name="employer_address" value="<?= e($student['employer_address'] ?? '') ?>" placeholder="Office location (optional)">
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Emergency Contact</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="emergency_contact">Contact Name *</label>
                        <input type="text" id="emergency_contact" name="emergency_contact" value="<?= e($student['emergency_contact'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="emergency_phone">Contact Phone *</label>
                        <input type="tel" id="emergency_phone" name="emergency_phone" value="<?= e($student['emergency_phone'] ?? '') ?>" required>
                    </div>
                </div>
            </div>

            <div class="form-row" style="gap:.75rem; align-items:center">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Student Information</button>
                <a href="<?= e($returnUrl) ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
const majorsByProgram = <?= json_encode(array_map(
    static function (array $majors): array {
        return array_map(static function (array $major): array {
            return [
                'id' => (int) $major['id'],
                'name' => $major['name'],
                'code' => $major['code'],
            ];
        }, $majors);
    },
    $majorsByProgram
), JSON_UNESCAPED_UNICODE) ?>;
const initialMajorId = <?= (int) ($student['major_id'] ?? 0) ?>;
const activeSchoolYear = <?= json_encode(getActiveSchoolYear(), JSON_UNESCAPED_UNICODE) ?>;
const activeSemester = <?= json_encode(getActiveSemester(), JSON_UNESCAPED_UNICODE) ?>;
const hasExistingValidId = <?= !empty($student['valid_id_path']) ? 'true' : 'false' ?>;

function syncValidIdRequirement() {
    const input = document.getElementById('valid_id');
    const mark = document.getElementById('valid_id_required_mark');
    if (!input) return;

    const status = document.getElementById('enrollment_status')?.value || '';
    const year = document.getElementById('current_academic_year')?.value || '';
    const semester = document.getElementById('current_semester')?.value || '';
    const onActiveTerm = status === 'enrolled' && year === activeSchoolYear && semester === activeSemester;
    const required = !onActiveTerm && !hasExistingValidId;

    input.required = required;
    if (mark) mark.hidden = !required;
}

function syncOriginCampusField() {
    const status = document.getElementById('enrollment_status').value;
    const map = {
        enrolled: 'origin_campus_id_enrolled',
        graduated: 'origin_campus_id_graduated',
        inactive: 'origin_campus_id_inactive',
    };
    const select = document.getElementById(map[status] || '');
    document.getElementById('origin_campus_id').value = select ? select.value : '';
}

function syncCourseIdField() {
    const status = document.getElementById('enrollment_status').value;
    const map = { enrolled: 'course_id_enrolled', graduated: 'course_id_graduated', inactive: 'course_id_inactive' };
    const select = document.getElementById(map[status] || 'course_id_enrolled');
    document.getElementById('course_id').value = select ? select.value : '';
    syncMajorField();
}

function syncMajorField() {
    const group = document.getElementById('majorFieldGroup');
    const select = document.getElementById('major_id');
    if (!group || !select) return;

    const programId = String(document.getElementById('course_id').value || '');
    const majors = majorsByProgram[programId] || [];
    const previousValue = select.value || (initialMajorId ? String(initialMajorId) : '');

    select.innerHTML = '<option value="">— Select Major —</option>';
    majors.forEach(function (major) {
        const option = document.createElement('option');
        option.value = String(major.id);
        option.textContent = major.name + ' (' + major.code + ')';
        select.appendChild(option);
    });

    if (majors.length) {
        group.hidden = false;
        select.required = true;
        const stillValid = majors.some(function (major) { return String(major.id) === previousValue; });
        select.value = stillValid ? previousValue : '';
    } else {
        group.hidden = true;
        select.required = false;
        select.value = '';
    }
}

function toggleAcademicSections() {
    const status = document.getElementById('enrollment_status').value;
    const panels = { enrolled: 'academicEnrolled', graduated: 'academicGraduated', inactive: 'academicInactive' };

    Object.values(panels).forEach(function (id) {
        document.getElementById(id).style.display = 'none';
    });
    if (panels[status]) {
        document.getElementById(panels[status]).style.display = 'block';
    }

    document.getElementById('year_level').required = status === 'enrolled';
    document.getElementById('current_academic_year').required = status === 'enrolled';
    document.getElementById('current_semester').required = status === 'enrolled';
    document.getElementById('year_graduated').required = status === 'graduated';
    document.getElementById('last_school_year').required = status === 'inactive';

    const campusMap = {
        enrolled: 'origin_campus_id_enrolled',
        graduated: 'origin_campus_id_graduated',
        inactive: 'origin_campus_id_inactive',
    };
    const campusSelect = document.getElementById(campusMap[status] || '');
    if (campusSelect) {
        campusSelect.required = true;
    }
    Object.values(campusMap).forEach(function (id) {
        const el = document.getElementById(id);
        if (el && el !== campusSelect) {
            el.required = false;
        }
    });

    syncCourseIdField();
    syncOriginCampusField();
    syncValidIdRequirement();
    toggleEmploymentSection();
}

function toggleEmploymentSection() {
    const graduated = document.getElementById('enrollment_status').value === 'graduated';
    const section = document.getElementById('employmentSection');
    section.style.display = graduated ? 'block' : 'none';
    document.getElementById('employment_status').required = graduated;
    if (!graduated) {
        document.getElementById('employment_status').value = '';
        toggleEmploymentDetails();
    } else {
        toggleEmploymentDetails();
    }
}

function toggleEmploymentDetails() {
    const status = document.getElementById('employment_status').value;
    const needsEmployer = status === 'employed' || status === 'self_employed';
    document.getElementById('employmentDetails').style.display = needsEmployer ? 'block' : 'none';
    ['employer_name', 'job_title', 'employment_start_date'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.required = needsEmployer;
    });
}

document.querySelectorAll('[data-course-select]').forEach(function (select) {
    select.addEventListener('change', syncCourseIdField);
});

document.querySelectorAll('[data-campus-select]').forEach(function (select) {
    select.addEventListener('change', syncOriginCampusField);
});

['current_academic_year', 'current_semester'].forEach(function (id) {
    document.getElementById(id)?.addEventListener('change', syncValidIdRequirement);
});

document.querySelector('form.form-grid')?.addEventListener('submit', function () {
    syncCourseIdField();
    syncOriginCampusField();
    syncValidIdRequirement();
});

const resetToId = document.querySelector('input[name="reset_password_to_id"]');
const newPassword = document.getElementById('new_password');
resetToId?.addEventListener('change', function () {
    if (!newPassword) return;
    if (resetToId.checked) {
        newPassword.value = '';
        newPassword.disabled = true;
    } else {
        newPassword.disabled = false;
    }
});

toggleAcademicSections();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
