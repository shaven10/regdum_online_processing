<?php

function renderClearStudentGradesForm(int $userId, string $studentName, string $variant = 'outline'): string {
    if ($userId <= 0 || !function_exists('hasRole') || !hasRole('admin', 'registrar')) {
        return '';
    }

    $name = trim($studentName) !== '' ? $studentName : 'this student';
    $confirm = 'Clear all saved grades for ' . $name . '? This cannot be undone.';
    $csrf = csrfField();
    $hidden = '<input type="hidden" name="action" value="clear_grades">'
        . '<input type="hidden" name="user_id" value="' . (int) $userId . '">';
    $formOpen = '<form method="POST" class="student-clear-grades-form" onsubmit="return confirm(' . e(json_encode($confirm)) . ')">';

    if ($variant === 'icon') {
        return $formOpen . $csrf . $hidden
            . '<button type="submit" ' . adminSettingsIconBtnAttrs('clear_grades', 'danger') . '>'
            . adminSettingsIconBtnContent('clear_grades') . '</button></form>';
    }

    if ($variant === 'link') {
        return $formOpen . $csrf . $hidden
            . '<button type="submit" class="student-record-clear">Clear grades</button></form>';
    }

    return $formOpen . $csrf . $hidden
        . '<button type="submit" class="btn btn-outline"><i class="fas fa-eraser"></i> Clear grades</button></form>';
}

function renderClearStudentGradesFormSm(int $userId, string $studentName): string {
    if ($userId <= 0 || !function_exists('hasRole') || !hasRole('admin', 'registrar')) {
        return '';
    }

    $name = trim($studentName) !== '' ? $studentName : 'this student';
    $confirm = 'Clear all saved grades for ' . $name . '? This cannot be undone.';

    return '<form method="POST" class="student-clear-grades-form" onsubmit="return confirm(' . e(json_encode($confirm)) . ')">'
        . csrfField()
        . '<input type="hidden" name="action" value="clear_grades">'
        . '<input type="hidden" name="user_id" value="' . (int) $userId . '">'
        . '<button type="submit" class="btn btn-outline btn-sm btn-danger"><i class="fas fa-eraser"></i> Clear grades</button>'
        . '</form>';
}

function studentRecordsPageUrl(array $params, ?int $viewId = null): string {
    $params = array_filter($params, static function ($value) {
        return $value !== '' && $value !== null && $value !== false;
    });
    unset($params['view']);
    if ($viewId !== null && $viewId > 0) {
        $params['view'] = (string) $viewId;
    }
    $query = http_build_query($params);
    return $query === '' ? '?' : '?' . $query;
}

function loadStudentRecordForView(int $userId): ?array {
    if ($userId <= 0) {
        return null;
    }

    ensureStudentImportProfileFields();
    ensureStudentEmploymentFields();
    ensureStudentValidIdField();
    ensureCampusesSchema();
    ensureEnrollmentStatuses();

    $db = getDB();
    $stmt = $db->prepare('SELECT u.id, u.first_name, u.last_name, u.middle_name, u.email, u.student_id, u.phone,
            u.is_active, u.created_at AS account_created_at, u.last_login,
            sp.course, sp.course_id, sp.year_level, sp.current_academic_year, sp.current_semester,
            sp.section, sp.major, sp.major_id, sp.birth_date, sp.sex, sp.civil_status, sp.birth_place,
            sp.valid_id_path, sp.valid_id_original_name, sp.address, sp.city, sp.province, sp.postal_code,
            sp.emergency_contact, sp.emergency_relationship, sp.emergency_phone, sp.emergency_address,
            sp.enrollment_status, sp.graduation_date, sp.origin_campus_id, sp.year_graduated, sp.last_school_year,
            sp.employment_status, sp.employer_name, sp.job_title, sp.employer_address, sp.employment_start_date,
            c.name AS campus_name,
            (SELECT COUNT(*) FROM requests req WHERE req.user_id = u.id) AS request_count
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN campuses c ON c.id = sp.origin_campus_id
        WHERE u.id = ? AND r.name = \'student\'');
    $stmt->execute([$userId]);
    $student = $stmt->fetch();
    if (!$student) {
        return null;
    }

    $reqStmt = $db->prepare('SELECT r.id, r.request_number, r.status, r.created_at, dt.name AS document_name
        FROM requests r
        LEFT JOIN document_types dt ON r.document_type_id = dt.id
        WHERE r.user_id = ?
        ORDER BY r.created_at DESC
        LIMIT 5');
    $reqStmt->execute([$userId]);
    $student['recent_requests'] = $reqStmt->fetchAll();

    return $student;
}

function studentRecordDisplayName(array $student): string {
    $name = studentRecordName($student);
    return $name !== '' ? $name : 'Student record';
}

function renderStudentRecordNameLink(array $student, string $viewUrl): string {
    $name = studentRecordName($student);
    if ($name === '') {
        $name = '—';
    }

    return '<a class="student-record-name" href="' . e($viewUrl) . '" title="' . e($name) . '">'
        . '<strong>' . e($name) . '</strong>'
        . '</a>';
}

function studentViewText(?string $value): string {
    $value = trim((string) $value);
    return $value !== '' ? e($value) : '<span class="text-muted">—</span>';
}

function studentViewField(string $label, string $valueHtml): string {
    return '<div class="student-view-field"><dt>' . e($label) . '</dt><dd>' . $valueHtml . '</dd></div>';
}

function studentRecordAddressLine(array $student): string {
    $parts = array_filter([
        trim((string) ($student['address'] ?? '')),
        trim((string) ($student['city'] ?? '')),
        trim((string) ($student['province'] ?? '')),
        trim((string) ($student['postal_code'] ?? '')),
    ], static fn($part) => $part !== '');

    return implode(', ', $parts);
}

function studentEnrollmentBadge(?string $status): string {
    $class = match ($status) {
        'enrolled' => 'badge-processing',
        'graduated' => 'badge-completed',
        'inactive' => 'badge-rejected',
        default => 'badge-submitted',
    };

    return '<span class="badge ' . $class . '">' . e(enrollmentStatusLabel($status)) . '</span>';
}

function renderStudentRecordViewModal(?array $student, string $closeUrl, ?string $editUrl = null): void {
    if (!$student) {
        return;
    }

    $name = studentRecordDisplayName($student);
    $email = trim((string) ($student['email'] ?? ''));
    $phone = trim((string) ($student['phone'] ?? ''));
    $emergencyPhone = trim((string) ($student['emergency_phone'] ?? ''));
    $address = studentRecordAddressLine($student);
    $emergencyAddress = trim((string) ($student['emergency_address'] ?? ''));
    $validIdPath = trim((string) ($student['valid_id_path'] ?? ''));
    $validIdName = trim((string) ($student['valid_id_original_name'] ?? ''));
    $validIdUrl = $validIdPath !== '' ? UPLOAD_URL . '/' . ltrim($validIdPath, '/') : '';
    $validIdExt = strtolower(pathinfo($validIdPath, PATHINFO_EXTENSION));
    $isImageId = in_array($validIdExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    $hasEmployment = trim((string) ($student['employment_status'] ?? '')) !== ''
        || trim((string) ($student['employer_name'] ?? '')) !== ''
        || trim((string) ($student['job_title'] ?? '')) !== '';
    $recentRequests = $student['recent_requests'] ?? [];
    $editUrl = $editUrl !== null ? trim($editUrl) : '';
    ?>
<div class="student-view-modal is-open" id="studentRecordViewModal" aria-hidden="false" data-close-url="<?= e($closeUrl) ?>">
    <a class="student-view-overlay" href="<?= e($closeUrl) ?>" aria-label="Close student information"></a>
    <div class="student-view-dialog" role="dialog" aria-modal="true" aria-labelledby="studentRecordViewTitle">
        <div class="student-view-header">
            <div class="student-view-header-text">
                <span class="student-view-eyebrow">Student information</span>
                <h2 id="studentRecordViewTitle"><?= e($name) ?></h2>
                <p class="student-view-subtitle">
                    <?= e($student['student_id'] ?: 'No student ID') ?>
                    <?php if ($email !== ''): ?>
                        · <?= e($email) ?>
                    <?php endif; ?>
                </p>
                <div class="student-view-badges">
                    <?= studentEnrollmentBadge($student['enrollment_status'] ?? null) ?>
                    <?= !empty($student['is_active'])
                        ? '<span class="badge badge-completed">Active account</span>'
                        : '<span class="badge badge-rejected">Inactive account</span>' ?>
                </div>
            </div>
            <a href="<?= e($closeUrl) ?>" class="student-view-close" aria-label="Close">
                <i class="fas fa-times"></i>
            </a>
        </div>
        <div class="student-view-body">
            <section class="student-view-section">
                <h3>Personal</h3>
                <dl class="student-view-grid">
                    <?= studentViewField('First name', studentViewText($student['first_name'] ?? '')) ?>
                    <?= studentViewField('Middle name', studentViewText($student['middle_name'] ?? '')) ?>
                    <?= studentViewField('Last name', studentViewText($student['last_name'] ?? '')) ?>
                    <?= studentViewField('Student ID', studentViewText($student['student_id'] ?? '')) ?>
                    <?= studentViewField('Email', $email !== ''
                        ? '<a href="mailto:' . e($email) . '">' . e($email) . '</a>'
                        : studentViewText('')) ?>
                    <?= studentViewField('Mobile #', $phone !== ''
                        ? '<a href="tel:' . e($phone) . '">' . e($phone) . '</a>'
                        : studentViewText('')) ?>
                    <?= studentViewField('Sex', studentViewText($student['sex'] ?? '')) ?>
                    <?= studentViewField('Civil status', studentViewText($student['civil_status'] ?? '')) ?>
                    <?= studentViewField('Birthdate', studentViewText(formatDate($student['birth_date'] ?? null))) ?>
                    <?= studentViewField('Birth place', studentViewText($student['birth_place'] ?? '')) ?>
                    <?= studentViewField('Home address', studentViewText($address)) ?>
                </dl>
            </section>

            <section class="student-view-section">
                <h3>Academic</h3>
                <dl class="student-view-grid">
                    <?= studentViewField('Course / program', studentViewText($student['course'] ?? '')) ?>
                    <?= studentViewField('Major', studentViewText($student['major'] ?? '')) ?>
                    <?= studentViewField('Year level', studentViewText($student['year_level'] ?? '')) ?>
                    <?= studentViewField('Academic year', studentViewText($student['current_academic_year'] ?? '')) ?>
                    <?= studentViewField('Semester', studentViewText(
                        !empty($student['current_semester']) ? semesterLabel($student['current_semester']) : ''
                    )) ?>
                    <?= studentViewField('Campus', studentViewText($student['campus_name'] ?? '')) ?>
                    <?= studentViewField('Enrollment', studentEnrollmentBadge($student['enrollment_status'] ?? null)) ?>
                    <?= studentViewField('Year graduated', studentViewText(
                        !empty($student['year_graduated']) ? (string) $student['year_graduated'] : ''
                    )) ?>
                    <?= studentViewField('Last school year', studentViewText($student['last_school_year'] ?? '')) ?>
                </dl>
            </section>

            <section class="student-view-section">
                <h3>Emergency contact</h3>
                <dl class="student-view-grid">
                    <?= studentViewField('Contact person', studentViewText($student['emergency_contact'] ?? '')) ?>
                    <?= studentViewField('Relationship', studentViewText($student['emergency_relationship'] ?? '')) ?>
                    <?= studentViewField('Contact #', $emergencyPhone !== ''
                        ? '<a href="tel:' . e($emergencyPhone) . '">' . e($emergencyPhone) . '</a>'
                        : studentViewText('')) ?>
                    <?= studentViewField('Contact address', studentViewText($emergencyAddress)) ?>
                </dl>
            </section>

            <?php if ($hasEmployment || isGraduatedEnrollment($student['enrollment_status'] ?? null)): ?>
            <section class="student-view-section">
                <h3>Employment</h3>
                <dl class="student-view-grid">
                    <?= studentViewField('Status', studentViewText(
                        ($student['employment_status'] ?? '') !== '' ? employmentStatusLabel($student['employment_status']) : ''
                    )) ?>
                    <?= studentViewField('Employer', studentViewText($student['employer_name'] ?? '')) ?>
                    <?= studentViewField('Job title', studentViewText($student['job_title'] ?? '')) ?>
                    <?= studentViewField('Started', studentViewText(formatDate($student['employment_start_date'] ?? null))) ?>
                    <?= studentViewField('Employer address', studentViewText($student['employer_address'] ?? '')) ?>
                </dl>
            </section>
            <?php endif; ?>

            <section class="student-view-section">
                <h3>Identification</h3>
                <?php if ($validIdUrl !== ''): ?>
                    <div class="student-view-id">
                        <?php if ($isImageId): ?>
                            <a href="<?= e($validIdUrl) ?>" target="_blank" rel="noopener">
                                <img src="<?= e($validIdUrl) ?>" alt="Uploaded valid ID">
                            </a>
                        <?php endif; ?>
                        <a href="<?= e($validIdUrl) ?>" target="_blank" rel="noopener">
                            <i class="fas fa-id-card"></i>
                            <?= e($validIdName !== '' ? $validIdName : 'View uploaded ID') ?>
                        </a>
                    </div>
                <?php else: ?>
                    <p class="text-muted student-view-empty">No valid ID uploaded.</p>
                <?php endif; ?>
            </section>

            <section class="student-view-section">
                <h3>Account</h3>
                <dl class="student-view-grid">
                    <?= studentViewField('Account status', !empty($student['is_active'])
                        ? '<span class="badge badge-completed">Active</span>'
                        : '<span class="badge badge-rejected">Inactive</span>') ?>
                    <?= studentViewField('Created', studentViewText(formatDateTime($student['account_created_at'] ?? null))) ?>
                    <?= studentViewField('Last login', studentViewText(formatDateTime($student['last_login'] ?? null))) ?>
                    <?= studentViewField('Requests', e((string) (int) ($student['request_count'] ?? 0))) ?>
                </dl>
            </section>

            <section class="student-view-section">
                <h3>Recent requests</h3>
                <?php if (empty($recentRequests)): ?>
                    <p class="text-muted student-view-empty">No credential requests on file.</p>
                <?php else: ?>
                    <ul class="student-view-requests">
                        <?php foreach ($recentRequests as $request): ?>
                            <li>
                                <span class="student-view-request-ref"><?= e($request['request_number'] ?? '—') ?></span>
                                <span><?= e($request['document_name'] ?? 'Request') ?></span>
                                <?= statusBadge((string) ($request['status'] ?? '')) ?>
                                <small><?= e(formatDate($request['created_at'] ?? null)) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
        <div class="student-view-footer">
            <?php if ($editUrl !== '' && function_exists('hasRole') && hasRole('admin')): ?>
                <a href="<?= e($editUrl) ?>" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Information
                </a>
            <?php endif; ?>
            <?php if (function_exists('hasRole') && hasRole('admin', 'registrar')): ?>
                <a href="<?= e(APP_URL . '/registrar/grades-evaluation.php?student_user_id=' . (int) $student['id']) ?>" class="btn <?= $editUrl !== '' ? 'btn-outline' : 'btn-primary' ?>">
                    <i class="fas fa-clipboard-list"></i> Grades Evaluation
                </a>
                <?= renderClearStudentGradesForm((int) $student['id'], studentRecordDisplayName($student)) ?>
            <?php endif; ?>
            <a href="<?= e($closeUrl) ?>" class="btn btn-outline">Close</a>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('studentRecordViewModal');
    if (!modal) return;
    document.body.classList.add('student-view-open');
    var closeUrl = modal.getAttribute('data-close-url');
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && closeUrl) {
            window.location.href = closeUrl;
        }
    });
})();
</script>
    <?php
}
