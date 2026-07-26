<?php

function ensureRequestCopyTypeSchema(): void {
    $db = getDB();
    $column = $db->query("SHOW COLUMNS FROM requests LIKE 'copy_request_type'")->fetch();
    if (!$column) {
        $db->exec("ALTER TABLE requests
            ADD COLUMN copy_request_type ENUM('first_request','second_copy') NOT NULL DEFAULT 'first_request'
            AFTER purpose_other");
    }
}

function copyRequestTypeOptions(): array {
    return [
        'first_request' => 'First Request',
        'second_copy'   => 'Second Copy',
    ];
}

function copyRequestTypeLabel(?string $type): string {
    $options = copyRequestTypeOptions();
    return $options[$type ?? ''] ?? 'First Request';
}

function isValidCopyRequestType(?string $type): bool {
    return array_key_exists((string) $type, copyRequestTypeOptions());
}

function isSecondCopyRequest(array|int $requestOrId): bool {
    if (is_array($requestOrId)) {
        return ($requestOrId['copy_request_type'] ?? '') === 'second_copy';
    }

    ensureRequestCopyTypeSchema();
    $stmt = getDB()->prepare('SELECT copy_request_type FROM requests WHERE id = ?');
    $stmt->execute([(int) $requestOrId]);
    return $stmt->fetchColumn() === 'second_copy';
}

function ensureDeliveryMethods(): void {
    $db = getDB();
    ensureRequestCopyTypeSchema();

    $column = $db->query("SHOW COLUMNS FROM requests LIKE 'delivery_method'")->fetch();
    if ($column) {
        $needsUpdate = !str_contains($column['Type'], 'authorized_representative')
            || ($column['Null'] ?? '') !== 'YES'
            || (($column['Default'] ?? null) === 'pickup');
        if ($needsUpdate) {
            $db->exec("ALTER TABLE requests MODIFY delivery_method ENUM('pickup','courier','authorized_representative') NULL DEFAULT NULL");
        }
    }

    $repColumns = [
        'representative_name'         => 'VARCHAR(150) NULL AFTER delivery_postal_code',
        'representative_relationship'   => 'VARCHAR(100) NULL AFTER representative_name',
        'representative_phone'        => 'VARCHAR(20) NULL AFTER representative_relationship',
        'representative_id_number'    => 'VARCHAR(50) NULL AFTER representative_phone',
    ];

    foreach ($repColumns as $name => $definition) {
        $exists = $db->query("SHOW COLUMNS FROM requests LIKE " . $db->quote($name))->fetch();
        if (!$exists) {
            $db->exec("ALTER TABLE requests ADD COLUMN $name $definition");
        }
    }

    ensureRepresentativeDocumentSchema();
}

function ensureRepresentativeDocumentSchema(): void {
    $db = getDB();
    $exists = $db->query("SHOW COLUMNS FROM request_documents LIKE 'document_category'")->fetch();
    if (!$exists) {
        $db->exec("ALTER TABLE request_documents ADD COLUMN document_category VARCHAR(50) NULL AFTER request_id");
    }
}

function representativeAttachmentCategories(): array {
    return [
        'rep_authorization_letter' => 'Authorization Letter',
        'rep_valid_id'             => 'Valid ID',
    ];
}

function isRepresentativeDocumentCategory(?string $category): bool {
    return array_key_exists($category ?? '', representativeAttachmentCategories());
}

function getRepresentativeDocuments(int $requestId): array {
    ensureRepresentativeDocumentSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM request_documents WHERE request_id = ? AND document_category IN (?, ?) ORDER BY uploaded_at ASC');
    $stmt->execute([$requestId, 'rep_authorization_letter', 'rep_valid_id']);
    $docs = [];
    foreach ($stmt->fetchAll() as $row) {
        $docs[$row['document_category']] = $row;
    }
    return $docs;
}

function saveRepresentativeDocument(int $requestId, array $file, string $category, string $storedPath): int {
    ensureRepresentativeDocumentSchema();
    $db = getDB();

    $old = $db->prepare('SELECT id, file_name FROM request_documents WHERE request_id = ? AND document_category = ?');
    $old->execute([$requestId, $category]);
    if ($previous = $old->fetch()) {
        $fullPath = UPLOAD_PATH . '/' . ltrim((string) $previous['file_name'], '/');
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        $db->prepare('DELETE FROM request_documents WHERE id = ?')->execute([(int) $previous['id']]);
    }

    $db->prepare('INSERT INTO request_documents (request_id, document_category, file_name, original_name, file_type, file_size)
        VALUES (?, ?, ?, ?, ?, ?)')->execute([
        $requestId,
        $category,
        $storedPath,
        $file['name'],
        $file['type'] ?? null,
        $file['size'] ?? null,
    ]);

    return (int) $db->lastInsertId();
}

function clearRepresentativeDocuments(int $requestId): void {
    ensureRepresentativeDocumentSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT id, file_name FROM request_documents WHERE request_id = ? AND document_category IN (?, ?)');
    $stmt->execute([$requestId, 'rep_authorization_letter', 'rep_valid_id']);
    foreach ($stmt->fetchAll() as $doc) {
        $fullPath = UPLOAD_PATH . '/' . ltrim((string) $doc['file_name'], '/');
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        $db->prepare('DELETE FROM request_documents WHERE id = ?')->execute([(int) $doc['id']]);
    }
}

function renderRepresentativePickupDetailsHtml(int $requestId, array $request): string {
    if (($request['delivery_method'] ?? '') !== 'authorized_representative') {
        return '';
    }

    require_once __DIR__ . '/attachments.php';

    $docs = getRepresentativeDocuments($requestId);
    ob_start();
    ?>
    <div class="detail-item"><label>Representative Name</label><span><?= e($request['representative_name'] ?? '—') ?></span></div>
    <div class="detail-item"><label>Relationship</label><span><?= e($request['representative_relationship'] ?? '—') ?></span></div>
    <?php foreach (representativeAttachmentCategories() as $category => $label): ?>
        <div class="detail-item">
            <label><?= e($label) ?></label>
            <span>
                <?php if (!empty($docs[$category])): ?>
                    <a href="<?= e(attachmentUrl($docs[$category]['file_name'])) ?>" target="_blank">
                        <i class="fas <?= e(attachmentIcon(attachmentFileExt($docs[$category]['original_name'] ?? $docs[$category]['file_name']))) ?>"></i>
                        <?= e($docs[$category]['original_name']) ?>
                    </a>
                <?php else: ?>
                    —
                <?php endif; ?>
            </span>
        </div>
    <?php endforeach;
    return (string) ob_get_clean();
}

function ensureStudentAcademicTermFields(): void {
    $db = getDB();

    $columns = [
        'current_academic_year' => 'VARCHAR(20) NULL AFTER year_level',
        'current_semester'      => "ENUM('1st_semester','2nd_semester','summer') NULL AFTER current_academic_year",
    ];

    foreach ($columns as $name => $definition) {
        $exists = $db->query("SHOW COLUMNS FROM student_profiles LIKE " . $db->quote($name))->fetch();
        if (!$exists) {
            $db->exec("ALTER TABLE student_profiles ADD COLUMN $name $definition");
        }
    }
}

function ensureStudentValidIdField(): void {
    $db = getDB();
    $columns = [
        'valid_id_path'          => 'VARCHAR(255) NULL AFTER birth_date',
        'valid_id_original_name' => 'VARCHAR(255) NULL AFTER valid_id_path',
    ];

    foreach ($columns as $name => $definition) {
        $exists = $db->query("SHOW COLUMNS FROM student_profiles LIKE " . $db->quote($name))->fetch();
        if (!$exists) {
            $db->exec("ALTER TABLE student_profiles ADD COLUMN $name $definition");
        }
    }
}

function saveStudentValidIdUpload(int $userId, array $file): ?array {
    if (empty($file['name'])) {
        return null;
    }

    $path = uploadFile($file, 'student_ids');
    if (!$path) {
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT valid_id_path FROM student_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $previous = $stmt->fetchColumn();
    if ($previous) {
        $fullPath = UPLOAD_PATH . '/' . ltrim((string) $previous, '/');
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    return [
        'path' => $path,
        'original_name' => $file['name'],
    ];
}

function ensureStudentEmploymentFields(): void {
    $db = getDB();

    $columns = [
        'employment_status'     => "ENUM('employed','self_employed','unemployed','seeking_employment','further_studies') NULL AFTER graduation_date",
        'employer_name'         => 'VARCHAR(200) NULL AFTER employment_status',
        'job_title'             => 'VARCHAR(150) NULL AFTER employer_name',
        'employer_address'      => 'TEXT NULL AFTER job_title',
        'employment_start_date' => 'DATE NULL AFTER employer_address',
    ];

    foreach ($columns as $name => $definition) {
        $exists = $db->query("SHOW COLUMNS FROM student_profiles LIKE " . $db->quote($name))->fetch();
        if (!$exists) {
            $db->exec("ALTER TABLE student_profiles ADD COLUMN $name $definition");
        }
    }
}

function ensureEnrollmentStatuses(): void {
    $db = getDB();
    $column = $db->query("SHOW COLUMNS FROM student_profiles LIKE 'enrollment_status'")->fetch();
    if (!$column || !str_contains($column['Type'], 'enum')) {
        return;
    }

    if (str_contains($column['Type'], 'alumni') || str_contains($column['Type'], 'withdrawn')) {
        $db->exec("UPDATE student_profiles SET enrollment_status = 'graduated' WHERE enrollment_status = 'alumni'");
        $db->exec("UPDATE student_profiles SET enrollment_status = 'inactive' WHERE enrollment_status = 'withdrawn'");
        $db->exec("ALTER TABLE student_profiles MODIFY enrollment_status ENUM('enrolled','graduated','inactive') DEFAULT 'enrolled'");
    } elseif (!str_contains($column['Type'], 'inactive')) {
        $db->exec("ALTER TABLE student_profiles MODIFY enrollment_status ENUM('enrolled','graduated','inactive') DEFAULT 'enrolled'");
    }
}

function enrollmentStatusOptions(): array {
    return [
        'graduated' => 'Graduated',
        'enrolled'  => 'Enrolled',
        'inactive'  => 'Inactive',
    ];
}

function semesterOptions(): array {
    return [
        '1st_semester' => '1st Semester',
        '2nd_semester' => '2nd Semester',
        'summer'       => 'Summer / Midyear',
    ];
}

function semesterLabel(?string $semester): string {
    return semesterOptions()[$semester ?? ''] ?? '—';
}

function currentAcademicYearOptions(): array {
    return schoolYearOptions();
}

function enrollmentStatusLabel(?string $status): string {
    return enrollmentStatusOptions()[$status ?? ''] ?? ucwords(str_replace('_', ' ', $status ?? '—'));
}

function employmentStatusOptions(): array {
    return [
        'employed'            => 'Employed (Company / Organization)',
        'self_employed'       => 'Self-Employed / Freelancer',
        'unemployed'          => 'Unemployed',
        'seeking_employment'  => 'Seeking Employment',
        'further_studies'     => 'Pursuing Further Studies',
    ];
}

function employmentStatusLabel(?string $status): string {
    return employmentStatusOptions()[$status ?? ''] ?? '—';
}

function studentEmploymentFieldRequirements(?string $employmentStatus = null): array {
    $requirements = [
        'employment_status' => 'Employment status',
    ];

    if (in_array($employmentStatus, ['employed', 'self_employed'], true)) {
        $requirements['employer_name'] = 'Employer / company name';
        $requirements['job_title'] = 'Job title / position';
        $requirements['employment_start_date'] = 'Employment start date';
    }

    return $requirements;
}

function isGraduatedEnrollment(?string $enrollmentStatus): bool {
    return $enrollmentStatus === 'graduated';
}

function isInactiveEnrollment(?string $enrollmentStatus): bool {
    return $enrollmentStatus === 'inactive';
}

function isEnrolledEnrollment(?string $enrollmentStatus): bool {
    return ($enrollmentStatus ?? 'enrolled') === 'enrolled';
}

function studentAcademicFieldRequirements(?string $enrollmentStatus): array {
    return match ($enrollmentStatus) {
        'graduated' => [
            'course_id'         => 'Course/Program',
            'year_graduated'    => 'Year graduated',
            'origin_campus_id'  => 'Origin campus',
        ],
        'inactive' => [
            'course_id'        => 'Last course/program attended',
            'last_school_year' => 'Last school year enrolled',
            'origin_campus_id' => 'Origin campus',
        ],
        default => [
            'course_id'             => 'Current course/program',
            'year_level'            => 'Year level',
            'current_academic_year'   => 'Current academic year',
            'current_semester'      => 'Current semester',
            'origin_campus_id'      => 'Origin campus',
        ],
    };
}

function getStudentProfileFieldRequirements(array $profile): array {
    $enrollmentStatus = $profile['enrollment_status'] ?? 'enrolled';
    $requirements = array_merge(
        studentProfileFieldRequirements(),
        studentAcademicFieldRequirements($enrollmentStatus)
    );

    if (isGraduatedEnrollment($enrollmentStatus)) {
        $requirements = array_merge(
            $requirements,
            studentEmploymentFieldRequirements($profile['employment_status'] ?? null)
        );
    }

    return $requirements;
}

function normalizeStudentProfileFields(array $fields): array {
    $status = $fields['enrollment_status'] ?? 'enrolled';

    if (isEnrolledEnrollment($status)) {
        $fields['year_graduated'] = '';
        $fields['section'] = '';
        $fields['last_school_year'] = '';
        $fields['employment_status'] = '';
        $fields['employer_name'] = '';
        $fields['job_title'] = '';
        $fields['employer_address'] = '';
        $fields['employment_start_date'] = '';
    } elseif (isGraduatedEnrollment($status)) {
        $fields['year_level'] = '';
        $fields['section'] = '';
        $fields['last_school_year'] = '';
        $fields['current_academic_year'] = '';
        $fields['current_semester'] = '';
    } elseif (isInactiveEnrollment($status)) {
        $fields['year_level'] = '';
        $fields['section'] = '';
        $fields['year_graduated'] = '';
        $fields['current_academic_year'] = '';
        $fields['current_semester'] = '';
        $fields['employment_status'] = '';
        $fields['employer_name'] = '';
        $fields['job_title'] = '';
        $fields['employer_address'] = '';
        $fields['employment_start_date'] = '';
    }

    return $fields;
}

function validateStudentProfileFields(array $fields): array {
    $fields = normalizeStudentProfileFields($fields);
    $requirements = getStudentProfileFieldRequirements($fields);
    $missing = [];

    foreach ($requirements as $field => $label) {
        if (in_array($field, ['student_id', 'email'], true)) {
            continue;
        }
        if (in_array($field, ['course_id', 'origin_campus_id'], true)) {
            if ((int) ($fields[$field] ?? 0) <= 0) {
                $missing[] = $label;
            }
            continue;
        }
        if ($field === 'year_graduated') {
            if ((int) ($fields['year_graduated'] ?? 0) <= 0) {
                $missing[] = $label;
            }
            continue;
        }
        if ($field === 'current_semester') {
            if (!array_key_exists(trim((string) ($fields['current_semester'] ?? '')), semesterOptions())) {
                $missing[] = $label;
            }
            continue;
        }
        if (trim((string) ($fields[$field] ?? '')) === '') {
            $missing[] = $label;
        }
    }

    return $missing;
}

function deliveryMethodOptions(): array {
    return [
        'pickup' => 'On-Site Pickup (Student)',
        'authorized_representative' => 'On-Site Pickup (Authorized Representative)',
    ];
}

function deliveryMethodLabel(?string $method): string {
    if ($method === null || $method === '') {
        return 'Pending — available after registrar staff assignment';
    }
    return deliveryMethodOptions()[$method] ?? ucwords(str_replace('_', ' ', $method));
}

function isPickupOptionPending(?string $deliveryMethod): bool {
    return $deliveryMethod === null || $deliveryMethod === '';
}

function canStudentSelectPickupOption(array $request): bool {
    $status = $request['status'] ?? '';
    if (!in_array($status, ['processing', 'ready_for_pickup'], true)) {
        return false;
    }

    if (!isPickupOptionPending($request['delivery_method'] ?? null)) {
        return false;
    }

    if (!empty($request['assigned_to'])) {
        return true;
    }

    $requestId = (int) ($request['id'] ?? 0);
    if ($requestId <= 0) {
        return false;
    }

    if (!function_exists('requestHasAssignedStaff')) {
        require_once __DIR__ . '/request-items.php';
    }

    return requestHasAssignedStaff($requestId);
}

function validatePickupOptionData(array $data): array {
    $errors = [];
    $method = $data['delivery_method'] ?? '';

    if (!array_key_exists($method, deliveryMethodOptions())) {
        $errors['delivery_method'] = 'Please select a pickup option.';
    }

    if ($method === 'authorized_representative') {
        if (trim($data['representative_name'] ?? '') === '') {
            $errors['representative_name'] = 'Representative name is required.';
        }
        if (trim($data['representative_relationship'] ?? '') === '') {
            $errors['representative_relationship'] = 'Relationship to student is required.';
        }
    }

    return $errors;
}

function validateRepresentativeAttachments(array $uploads): array {
    $errors = [];
    foreach (representativeAttachmentCategories() as $category => $label) {
        if (empty($uploads[$category]['name'])) {
            $errors[$category] = $label . ' upload is required.';
        }
    }
    return $errors;
}

function processStudentPickupOption(int $requestId, int $userId, array $post, array $uploads = []): array {
    ensureRepresentativeDocumentSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM requests WHERE id = ? AND user_id = ?');
    $stmt->execute([$requestId, $userId]);
    $request = $stmt->fetch();

    if (!$request || !canStudentSelectPickupOption($request)) {
        return [
            'success' => false,
            'message' => 'Pickup option is available after the Registrar assigns your document(s) to staff for processing.',
            'errors'  => [],
        ];
    }

    $data = [
        'delivery_method'             => trim($post['delivery_method'] ?? ''),
        'representative_name'         => trim($post['representative_name'] ?? ''),
        'representative_relationship' => trim($post['representative_relationship'] ?? ''),
    ];

    $errors = validatePickupOptionData($data);
    if ($data['delivery_method'] === 'authorized_representative') {
        $errors = array_merge($errors, validateRepresentativeAttachments($uploads));
    }
    if (!empty($errors)) {
        return [
            'success' => false,
            'message' => 'Please complete all required pickup fields and uploads.',
            'errors'  => $errors,
        ];
    }

    if ($data['delivery_method'] === 'authorized_representative') {
        foreach (representativeAttachmentCategories() as $category => $label) {
            $path = uploadFile($uploads[$category], 'request_docs');
            if (!$path) {
                return [
                    'success' => false,
                    'message' => 'Unable to upload ' . strtolower($label) . '. Use PDF, JPG, PNG, or DOC up to 5 MB.',
                    'errors'  => [$category => 'Invalid or missing file.'],
                ];
            }
            saveRepresentativeDocument($requestId, $uploads[$category], $category, $path);
        }
    } else {
        clearRepresentativeDocuments($requestId);
    }

    $db->prepare('UPDATE requests SET delivery_method = ?, representative_name = ?, representative_relationship = ?, representative_phone = NULL, representative_id_number = NULL WHERE id = ?')
       ->execute([
           $data['delivery_method'],
           $data['delivery_method'] === 'authorized_representative' ? $data['representative_name'] : null,
           $data['delivery_method'] === 'authorized_representative' ? $data['representative_relationship'] : null,
           $requestId,
       ]);

    auditLog('pickup_option_set', 'requests', $requestId, null, ['delivery_method' => $data['delivery_method']]);

    return [
        'success' => true,
        'message' => 'Pickup option saved. Your scheduled pickup date and time remain as set by the Registrar.',
        'errors'  => [],
    ];
}

function renderStudentPickupOptionForm(array $request, array $errors = [], array $formData = []): string {
    $selectedMethod = $formData['delivery_method'] ?? '';
    $pickupDate = $request['pickup_date'] ?? $request['release_date'] ?? null;
    $pickupTime = $request['pickup_time'] ?? $request['release_time'] ?? null;

    ob_start();
    ?>
    <div class="card pickup-option-card" id="pickup-option">
        <div class="card-header">
            <h2><i class="fas fa-building"></i> Select Pickup Option</h2>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-check-circle"></i>
                Your request has been assigned for processing. Choose how you will collect your document on-site at the Registrar's Office.
                <?php if ($pickupDate): ?>
                    <br><strong>Scheduled pickup:</strong> <?= formatDate($pickupDate) ?><?= $pickupTime ? ' at ' . date('g:i A', strtotime($pickupTime)) : '' ?>.
                <?php endif; ?>
            </div>

            <form method="POST" class="form-grid pickup-option-form" id="pickupOptionForm" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_pickup_option">

                <div class="form-group">
                    <label>Pickup Option *</label>
                    <div class="radio-group delivery-option-group">
                        <?php foreach (deliveryMethodOptions() as $value => $label): ?>
                            <label class="radio-label delivery-option-card">
                                <input type="radio" name="delivery_method" value="<?= e($value) ?>"
                                    <?= $selectedMethod === $value ? 'checked' : '' ?>
                                    onchange="togglePickupOptionForm()">
                                <span><?= e($label) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!empty($errors['delivery_method'])): ?><span class="field-error"><?= e($errors['delivery_method']) ?></span><?php endif; ?>
                </div>

                <div id="representativeFields" style="display:none">
                    <div class="alert alert-info">
                        <i class="fas fa-id-card"></i>
                        Upload a signed authorization letter and a valid ID for your representative. They must present these when claiming the document on-site.
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="representative_name">Representative Full Name *</label>
                            <input type="text" id="representative_name" name="representative_name" value="<?= e($formData['representative_name'] ?? '') ?>">
                            <?php if (!empty($errors['representative_name'])): ?><span class="field-error"><?= e($errors['representative_name']) ?></span><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="representative_relationship">Relationship to Student *</label>
                            <input type="text" id="representative_relationship" name="representative_relationship" value="<?= e($formData['representative_relationship'] ?? '') ?>" placeholder="e.g. Parent, Guardian">
                            <?php if (!empty($errors['representative_relationship'])): ?><span class="field-error"><?= e($errors['representative_relationship']) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="rep_authorization_letter">Authorization Letter *</label>
                            <input type="file" id="rep_authorization_letter" name="rep_authorization_letter" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <small class="text-muted">PDF, JPG, PNG, or DOC up to 5 MB</small>
                            <?php if (!empty($errors['rep_authorization_letter'])): ?><span class="field-error"><?= e($errors['rep_authorization_letter']) ?></span><?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="rep_valid_id">Valid ID *</label>
                            <input type="file" id="rep_valid_id" name="rep_valid_id" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <small class="text-muted">Government-issued or school ID</small>
                            <?php if (!empty($errors['rep_valid_id'])): ?><span class="field-error"><?= e($errors['rep_valid_id']) ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Pickup Option</button>
            </form>
        </div>
    </div>
    <script>
    function togglePickupOptionForm() {
        const selected = document.querySelector('#pickupOptionForm input[name="delivery_method"]:checked');
        const method = selected ? selected.value : '';
        document.getElementById('representativeFields').style.display = method === 'authorized_representative' ? 'block' : 'none';
        ['representative_name', 'representative_relationship', 'rep_authorization_letter', 'rep_valid_id'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.required = method === 'authorized_representative';
        });
    }
    togglePickupOptionForm();
    </script>
    <?php
    return (string) ob_get_clean();
}

function isOnSitePickupMethod(?string $method): bool {
    return in_array($method, ['pickup', 'authorized_representative'], true);
}

function getStudentProfile(int $userId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT u.id, u.first_name, u.last_name, u.middle_name, u.email, u.student_id, u.phone,
        sp.course, sp.course_id, sp.year_level, sp.current_academic_year, sp.current_semester, sp.section, sp.birth_date, sp.valid_id_path, sp.valid_id_original_name, sp.address, sp.city, sp.province, sp.postal_code,
        sp.emergency_contact, sp.emergency_phone, sp.enrollment_status, sp.graduation_date,
        sp.origin_campus_id, sp.year_graduated, sp.last_school_year,
        sp.employment_status, sp.employer_name, sp.job_title, sp.employer_address, sp.employment_start_date
        FROM users u
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        WHERE u.id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: [];
}

function studentProfileFieldRequirements(): array {
    return [
        'first_name'        => 'First name',
        'last_name'         => 'Last name',
        'student_id'        => 'Student ID',
        'email'             => 'Email',
        'phone'             => 'Phone number',
        'birth_date'        => 'Birth date',
        'valid_id_path'     => 'Valid ID upload',
        'address'           => 'Street address',
        'city'              => 'City',
        'province'          => 'Province',
        'postal_code'       => 'Postal code',
        'emergency_contact' => 'Emergency contact name',
        'emergency_phone'   => 'Emergency contact phone',
        'enrollment_status' => 'Enrollment status',
    ];
}

function getStudentProfileCompletion(int $userId): array {
    $profile = getStudentProfile($userId);
    $missing = [];

    foreach (getStudentProfileFieldRequirements($profile) as $field => $label) {
        if (in_array($field, ['course_id', 'origin_campus_id'], true)) {
            if ((int) ($profile[$field] ?? 0) <= 0) {
                $missing[$field] = $label;
            }
            continue;
        }
        if ($field === 'year_graduated') {
            if ((int) ($profile['year_graduated'] ?? 0) <= 0) {
                $missing[$field] = $label;
            }
            continue;
        }
        if ($field === 'current_semester') {
            if (!array_key_exists(trim((string) ($profile['current_semester'] ?? '')), semesterOptions())) {
                $missing[$field] = $label;
            }
            continue;
        }
        $value = trim((string) ($profile[$field] ?? ''));
        if ($value === '') {
            $missing[$field] = $label;
        }
    }

    $total = count(getStudentProfileFieldRequirements($profile));
    $completed = $total - count($missing);
    $percent = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

    return [
        'complete'      => empty($missing),
        'missing'       => $missing,
        'percent'       => $percent,
        'completed'     => $completed,
        'total'         => $total,
        'status_label'  => studentRegistrationStatusLabel($percent, empty($missing)),
        'profile'       => $profile,
        'is_graduated'  => isGraduatedEnrollment($profile['enrollment_status'] ?? null),
    ];
}

function studentRegistrationStatusLabel(int $percent, bool $complete): string {
    if ($complete) {
        return 'Complete';
    }
    if ($percent >= 80) {
        return 'Nearly Complete';
    }
    if ($percent >= 50) {
        return 'In Progress';
    }
    if ($percent > 0) {
        return 'Incomplete';
    }
    return 'Not Started';
}

function studentRegistrationStatusClass(int $percent, bool $complete): string {
    if ($complete) {
        return 'complete';
    }
    if ($percent >= 80) {
        return 'near-complete';
    }
    if ($percent >= 50) {
        return 'in-progress';
    }
    return 'incomplete';
}

function renderStudentRegistrationStatus(array $completion, string $variant = 'card'): string {
    $percent = (int) $completion['percent'];
    $completed = (int) ($completion['completed'] ?? 0);
    $total = (int) ($completion['total'] ?? count(studentProfileFieldRequirements()));
    $statusLabel = e($completion['status_label'] ?? studentRegistrationStatusLabel($percent, !empty($completion['complete'])));
    $statusClass = studentRegistrationStatusClass($percent, !empty($completion['complete']));
    $profileUrl = APP_URL . '/student/profile.php';

    if ($variant === 'mini') {
        if (!empty($completion['complete'])) {
            return '';
        }
        return '<span class="registration-status-mini ' . e($statusClass) . '" title="Registration ' . $percent . '% complete">' . $percent . '%</span>';
    }

    $ring = '<div class="registration-status-ring ' . e($statusClass) . '" style="--percent: ' . $percent . '">'
        . '<span><strong>' . $percent . '%</strong></span></div>';

    $track = '<div class="registration-status-track" role="progressbar" aria-valuenow="' . $percent . '" aria-valuemin="0" aria-valuemax="100">'
        . '<div class="registration-status-fill ' . e($statusClass) . '" style="width: ' . $percent . '%"></div></div>';

    $meta = '<p class="registration-status-meta">'
        . '<span class="registration-status-count">' . $completed . ' of ' . $total . ' required fields completed</span>'
        . '<span class="registration-status-badge badge badge-' . ($completion['complete'] ? 'success' : 'warning') . '">' . $statusLabel . '</span>'
        . '</p>';

    if ($variant === 'inline') {
        $html = '<div class="registration-status registration-status-inline ' . e($statusClass) . '">';
        $html .= $ring;
        $html .= '<div class="registration-status-body">';
        $html .= '<strong>Registration Completeness</strong>';
        $html .= $meta;
        $html .= $track;
        if (!$completion['complete']) {
            $html .= '<a href="' . e($profileUrl) . '" class="btn btn-sm btn-primary">Complete Registration</a>';
        }
        $html .= '</div></div>';
        return $html;
    }

    $html = '<div class="card registration-status-card ' . e($statusClass) . '">';
    $html .= '<div class="card-header"><h2><i class="fas fa-user-check"></i> Registration Completeness</h2>';
    $html .= '<span class="registration-status-badge badge badge-' . ($completion['complete'] ? 'success' : 'warning') . '">' . $statusLabel . '</span>';
    $html .= '</div><div class="card-body registration-status-body-grid">';
    $html .= $ring;
    $html .= '<div class="registration-status-details">';
    $html .= '<p class="registration-status-headline">Your account is <strong>' . $percent . '%</strong> complete.</p>';
    $html .= $meta;
    $html .= $track;

    if (!$completion['complete']) {
        $html .= '<p class="text-muted registration-status-hint">Complete all required profile fields to unlock document requests.';
        if (!empty($completion['is_graduated'])) {
            $html .= ' Graduated students must also provide employment information.';
        }
        $html .= '</p>';
        if (!empty($completion['missing'])) {
            $items = implode('', array_map(
                fn($label) => '<li>' . e($label) . '</li>',
                array_slice(array_values($completion['missing']), 0, 5)
            ));
            $remaining = count($completion['missing']) - 5;
            $html .= '<ul class="registration-status-missing">' . $items;
            if ($remaining > 0) {
                $html .= '<li class="text-muted">+' . $remaining . ' more field(s)</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<a href="' . e($profileUrl) . '" class="btn btn-primary btn-sm"><i class="fas fa-user-edit"></i> Complete Registration</a>';
    } else {
        $html .= '<p class="text-muted registration-status-hint"><i class="fas fa-check-circle"></i> Registration complete. You can submit document requests.</p>';
        $html .= '<a href="' . APP_URL . '/student/new-request.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New Request</a>';
    }

    $html .= '</div></div></div>';
    return $html;
}

function renderStudentProfileIncompleteAlert(array $completion): string {
    if ($completion['complete']) {
        return '';
    }

    $items = implode('', array_map(
        fn($label) => '<li>' . e($label) . '</li>',
        array_values($completion['missing'])
    ));

    $html = '<div class="alert alert-warning profile-incomplete-alert">';
    $html .= '<i class="fas fa-user-edit"></i>';
    $html .= '<div>';
    $html .= '<strong>Complete your profile before submitting a request</strong>';
    $html .= '<p>Your account is ' . (int) $completion['percent'] . '% complete. Please fill in the following:</p>';
    $html .= '<ul class="profile-missing-list">' . $items . '</ul>';
    $html .= '<a href="' . APP_URL . '/student/profile.php" class="btn btn-sm btn-primary">Complete Profile</a>';
    $html .= '</div></div>';

    return $html;
}
