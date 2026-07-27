<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('student');
$user = currentUser();

ensureDeliveryMethods();
ensureStudentEmploymentFields();
ensureAcademicProgramsSchema();
ensureEnrollmentStatuses();
ensureCampusesSchema();
ensureStudentAcademicTermFields();
ensureStudentValidIdField();

$db = getDB();
$profile = $db->prepare('SELECT * FROM student_profiles WHERE user_id = ?');
$profile->execute([$user['id']]);
$profileData = $profile->fetch() ?: [];
$programs = getAcademicProgramsForStudent((int) ($profileData['course_id'] ?? 0));
$campuses = getCampusesForStudent((int) ($profileData['origin_campus_id'] ?? 0));
$currentEnrollment = $profileData['enrollment_status'] ?? 'enrolled';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $validIdPath = trim($profileData['valid_id_path'] ?? '');
    $validIdOriginalName = trim($profileData['valid_id_original_name'] ?? '');

    if (!empty($_FILES['valid_id']['name'])) {
        $uploaded = saveStudentValidIdUpload((int) $user['id'], $_FILES['valid_id']);
        if (!$uploaded) {
            setFlash('error', 'Unable to upload Valid ID. Use PDF, JPG, PNG, or DOC up to 5 MB.', [
                'title' => 'Invalid ID File',
            ]);
            redirect(APP_URL . '/student/profile.php');
        }
        $validIdPath = $uploaded['path'];
        $validIdOriginalName = $uploaded['original_name'];
    }

    $fields = [
        'first_name'        => normalizePersonName($_POST['first_name'] ?? ''),
        'last_name'         => normalizePersonName($_POST['last_name'] ?? ''),
        'middle_name'       => normalizePersonName($_POST['middle_name'] ?? ''),
        'phone'             => trim($_POST['phone'] ?? ''),
        'course_id'         => (int) ($_POST['course_id'] ?? 0),
        'year_level'            => trim($_POST['year_level'] ?? ''),
        'current_academic_year' => trim($_POST['current_academic_year'] ?? ''),
        'current_semester'      => trim($_POST['current_semester'] ?? ''),
        'year_graduated'        => (int) ($_POST['year_graduated'] ?? 0),
        'origin_campus_id'  => (int) ($_POST['origin_campus_id'] ?? 0),
        'last_school_year'  => trim($_POST['last_school_year'] ?? ''),
        'birth_date'        => trim($_POST['birth_date'] ?? ''),
        'emergency_contact' => trim($_POST['emergency_contact'] ?? ''),
        'emergency_phone'   => trim($_POST['emergency_phone'] ?? ''),
        'enrollment_status' => trim($_POST['enrollment_status'] ?? ''),
        'employment_status' => trim($_POST['employment_status'] ?? ''),
        'employer_name'     => trim($_POST['employer_name'] ?? ''),
        'job_title'         => trim($_POST['job_title'] ?? ''),
        'employer_address'  => trim($_POST['employer_address'] ?? ''),
        'employment_start_date' => trim($_POST['employment_start_date'] ?? ''),
        'valid_id_path'         => $validIdPath,
    ];

    if (!array_key_exists($fields['enrollment_status'], enrollmentStatusOptions())) {
        $fields['enrollment_status'] = 'enrolled';
    }

    $fields = normalizeStudentProfileFields($fields);

    $missing = validateStudentProfileFields($fields);
    $selectedProgram = resolveAcademicProgramForProfile((int) $fields['course_id']);
    if ((int) $fields['course_id'] > 0 && !$selectedProgram) {
        $missing[] = 'Valid course/program selection';
    }

    if (isGraduatedEnrollment($fields['enrollment_status'])
        || isEnrolledEnrollment($fields['enrollment_status'])
        || isInactiveEnrollment($fields['enrollment_status'])) {
        if ((int) $fields['origin_campus_id'] > 0 && !getCampusById((int) $fields['origin_campus_id'])) {
            $missing[] = 'Valid origin campus selection';
        }
    }

    if (!empty($missing)) {
        setFlash('error', 'Please complete all required profile fields before saving.', [
            'title' => 'Profile Incomplete',
            'next_step' => 'Fill in: ' . implode(', ', $missing),
        ]);
    } else {
        $db->prepare('UPDATE users SET phone = ?, first_name = ?, last_name = ?, middle_name = ? WHERE id = ?')
           ->execute([$fields['phone'], $fields['first_name'], $fields['last_name'], $fields['middle_name'] ?: null, $user['id']]);

        $db->prepare('UPDATE student_profiles SET course=?, course_id=?, year_level=?, current_academic_year=?, current_semester=?, section=?, birth_date=?, valid_id_path=?, valid_id_original_name=?, address=?, city=?, province=?, postal_code=?, emergency_contact=?, emergency_phone=?, enrollment_status=?, graduation_date=?, origin_campus_id=?, year_graduated=?, last_school_year=?, employment_status=?, employer_name=?, job_title=?, employer_address=?, employment_start_date=? WHERE user_id=?')
           ->execute([
               $selectedProgram['name'], (int) $selectedProgram['id'],
               isEnrolledEnrollment($fields['enrollment_status']) ? ($fields['year_level'] ?: null) : null,
               isEnrolledEnrollment($fields['enrollment_status']) ? ($fields['current_academic_year'] ?: null) : null,
               isEnrolledEnrollment($fields['enrollment_status']) ? ($fields['current_semester'] ?: null) : null,
               null,
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
               $user['id'],
           ]);

        auditLog('profile_update', 'users', $user['id']);
        setFlash('success', 'Profile updated successfully. You can now submit document requests.', [
            'title' => 'Profile Complete',
            'next_step' => 'Go to New Request to submit a credential request.',
            'action_url' => APP_URL . '/student/new-request.php',
            'action_label' => 'New Request',
        ]);
    }

    redirect(APP_URL . '/student/profile.php');
}

$user = currentUser();
$profileCompletion = getStudentProfileCompletion($user['id']);
$pageTitle = 'My Profile';
$activeNav = 'profile';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2>Profile Information</h2>
    </div>
    <div class="card-body">
        <?= renderStudentRegistrationStatus($profileCompletion, 'inline') ?>

        <form method="POST" class="form-grid" enctype="multipart/form-data">
            <?= csrfField() ?>
            <div class="form-section">
                <h3>Personal Information</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label>Student ID *</label>
                        <input type="text" value="<?= e($user['student_id']) ?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" value="<?= e($user['email']) ?>" disabled>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" class="input-uppercase" autocapitalize="characters" value="<?= e(normalizePersonName($user['first_name'] ?? '')) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="middle_name">Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name" class="input-uppercase" autocapitalize="characters" value="<?= e(normalizePersonName($user['middle_name'] ?? '')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" class="input-uppercase" autocapitalize="characters" value="<?= e(normalizePersonName($user['last_name'] ?? '')) ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone *</label>
                        <input type="tel" id="phone" name="phone" value="<?= e($user['phone'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="birth_date">Birth Date *</label>
                        <input type="date" id="birth_date" name="birth_date" value="<?= e($profileData['birth_date'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label for="valid_id">Identification ID / Valid ID *</label>
                    <input type="file" id="valid_id" name="valid_id" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" <?= empty($profileData['valid_id_path']) ? 'required' : '' ?>>
                    <small class="text-muted">Upload a clear copy of your school ID or government-issued ID (PDF, JPG, PNG, or DOC up to 5 MB).</small>
                    <?php if (!empty($profileData['valid_id_path'])): ?>
                        <div class="profile-id-preview">
                            <i class="fas fa-id-card"></i>
                            <a href="<?= e(UPLOAD_URL . '/' . ltrim($profileData['valid_id_path'], '/')) ?>" target="_blank">
                                <?= e($profileData['valid_id_original_name'] ?? 'View uploaded ID') ?>
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
                    <p class="text-muted academic-panel-note">Provide your current enrollment details.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="course_id_enrolled">Current Course/Program *</label>
                            <select id="course_id_enrolled" data-course-select="enrolled">
                                <option value="">— Select Course / Program —</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= (int) $program['id'] ?>" <?= (int) ($profileData['course_id'] ?? 0) === (int) $program['id'] ? 'selected' : '' ?>>
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
                                    <option value="<?= $yl ?>" <?= ($profileData['year_level'] ?? '') === $yl ? 'selected' : '' ?>><?= $yl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="origin_campus_id_enrolled">Origin Campus *</label>
                            <select id="origin_campus_id_enrolled" data-campus-select="enrolled">
                                <option value="">— Select Campus —</option>
                                <?php foreach ($campuses as $campus): ?>
                                    <option value="<?= (int) $campus['id'] ?>" <?= (int) ($profileData['origin_campus_id'] ?? 0) === (int) $campus['id'] ? 'selected' : '' ?>>
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
                                    <option value="<?= e($value) ?>" <?= ($profileData['current_academic_year'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="current_semester">Current Semester *</label>
                            <select id="current_semester" name="current_semester">
                                <option value="">— Select Semester —</option>
                                <?php foreach (semesterOptions() as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= ($profileData['current_semester'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="academicGraduated" class="academic-status-panel">
                    <p class="text-muted academic-panel-note">Provide your graduation details.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="course_id_graduated">Course/Program *</label>
                            <select id="course_id_graduated" data-course-select="graduated">
                                <option value="">— Select Course / Program —</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= (int) $program['id'] ?>" <?= (int) ($profileData['course_id'] ?? 0) === (int) $program['id'] ? 'selected' : '' ?>>
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
                                $selectedYear = (int) ($profileData['year_graduated'] ?? 0);
                                if (!$selectedYear && !empty($profileData['graduation_date'])) {
                                    $selectedYear = (int) date('Y', strtotime($profileData['graduation_date']));
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
                                    <option value="<?= (int) $campus['id'] ?>" <?= (int) ($profileData['origin_campus_id'] ?? 0) === (int) $campus['id'] ? 'selected' : '' ?>>
                                        <?= e($campus['name']) ?> (<?= e($campus['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="academicInactive" class="academic-status-panel">
                    <p class="text-muted academic-panel-note">Provide details from your last active enrollment.</p>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="course_id_inactive">Last Course/Program Attended *</label>
                            <select id="course_id_inactive" data-course-select="inactive">
                                <option value="">— Select Course / Program —</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= (int) $program['id'] ?>" <?= (int) ($profileData['course_id'] ?? 0) === (int) $program['id'] ? 'selected' : '' ?>>
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
                                    <option value="<?= e($value) ?>" <?= ($profileData['last_school_year'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="origin_campus_id_inactive">Origin Campus *</label>
                            <select id="origin_campus_id_inactive" data-campus-select="inactive">
                                <option value="">— Select Campus —</option>
                                <?php foreach ($campuses as $campus): ?>
                                    <option value="<?= (int) $campus['id'] ?>" <?= (int) ($profileData['origin_campus_id'] ?? 0) === (int) $campus['id'] ? 'selected' : '' ?>>
                                        <?= e($campus['name']) ?> (<?= e($campus['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="course_id" id="course_id" value="<?= (int) ($profileData['course_id'] ?? 0) ?>">
                <input type="hidden" name="origin_campus_id" id="origin_campus_id" value="<?= (int) ($profileData['origin_campus_id'] ?? 0) ?>">
            </div>

            <div class="form-section employment-section" id="employmentSection">
                <h3><i class="fas fa-briefcase"></i> Employment Information</h3>
                <p class="text-muted employment-section-note">Required for students with <strong>Graduated</strong> enrollment status.</p>
                <div class="form-group">
                    <label for="employment_status">Employment Status *</label>
                    <select id="employment_status" name="employment_status" onchange="toggleEmploymentDetails()">
                        <option value="">— Select —</option>
                        <?php foreach (employmentStatusOptions() as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($profileData['employment_status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="employmentDetails">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="employer_name">Employer / Company Name *</label>
                            <input type="text" id="employer_name" name="employer_name" value="<?= e($profileData['employer_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="job_title">Job Title / Position *</label>
                            <input type="text" id="job_title" name="job_title" value="<?= e($profileData['job_title'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="employment_start_date">Employment Start Date *</label>
                            <input type="date" id="employment_start_date" name="employment_start_date" value="<?= e($profileData['employment_start_date'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label for="employer_address">Employer Address</label>
                            <input type="text" id="employer_address" name="employer_address" value="<?= e($profileData['employer_address'] ?? '') ?>" placeholder="Office location (optional)">
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3>Emergency Contact</h3>
                <div class="form-row">
                    <div class="form-group">
                        <label for="emergency_contact">Contact Name *</label>
                        <input type="text" id="emergency_contact" name="emergency_contact" value="<?= e($profileData['emergency_contact'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="emergency_phone">Contact Phone *</label>
                        <input type="tel" id="emergency_phone" name="emergency_phone" value="<?= e($profileData['emergency_phone'] ?? '') ?>" required>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
        </form>
    </div>
</div>

<script>
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

document.querySelector('form.form-grid')?.addEventListener('submit', function () {
    syncCourseIdField();
    syncOriginCampusField();
});

toggleAcademicSections();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
