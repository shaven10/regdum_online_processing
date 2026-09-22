<?php

require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/request-items.php';
require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/clearance.php';
require_once __DIR__ . '/student.php';
require_once __DIR__ . '/campuses.php';
require_once __DIR__ . '/document-rules.php';

/** Maximum number of existing students allowed in one multi-student onsite request. */
const ONSITE_MULTI_STUDENT_MAX = 10;

function ensureOnsiteRequestSchema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db = getDB();

    $channelCol = $db->query("SHOW COLUMNS FROM requests LIKE 'request_channel'")->fetch();
    if (!$channelCol) {
        $db->exec("ALTER TABLE requests
            ADD COLUMN request_channel ENUM('online','onsite') NOT NULL DEFAULT 'online' AFTER notes");
    }

    $createdByCol = $db->query("SHOW COLUMNS FROM requests LIKE 'created_by'")->fetch();
    if (!$createdByCol) {
        $db->exec('ALTER TABLE requests
            ADD COLUMN created_by INT UNSIGNED NULL AFTER request_channel');
        try {
            $db->exec('ALTER TABLE requests
                ADD CONSTRAINT fk_requests_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');
        } catch (Throwable $e) {
            // Constraint may already exist on partial upgrades.
        }
    }

    $batchKeyCol = $db->query("SHOW COLUMNS FROM requests LIKE 'onsite_batch_key'")->fetch();
    if (!$batchKeyCol) {
        $db->exec("ALTER TABLE requests
            ADD COLUMN onsite_batch_key VARCHAR(32) NULL AFTER created_by");
        try {
            $db->exec('CREATE INDEX idx_requests_onsite_batch_key ON requests (onsite_batch_key)');
        } catch (Throwable $e) {
            // Index may already exist on partial upgrades.
        }
    }
}

function generateOnsiteBatchKey(): string {
    return 'OB' . date('Y') . strtoupper(bin2hex(random_bytes(6)));
}

function isOnsiteRequestChannel(?string $channel): bool {
    return ($channel ?? '') === 'onsite';
}

function findStudentUserById(int $userId): ?array {
    if ($userId <= 0) {
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT u.*, sp.course, sp.course_id, sp.year_level, sp.enrollment_status,
            sp.origin_campus_id, sp.year_graduated, sp.last_school_year, sp.current_semester
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.id = ? AND u.role_id = 1
        LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function findStudentUserByStudentId(string $studentId): ?array {
    $studentId = trim($studentId);
    if ($studentId === '') {
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT u.*, sp.course, sp.course_id, sp.year_level, sp.enrollment_status,
            sp.origin_campus_id, sp.year_graduated, sp.last_school_year, sp.current_semester
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.student_id = ? AND u.role_id = 1
        LIMIT 1');
    $stmt->execute([$studentId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function formatOnsitePickerStudent(array $row): array {
    return [
        'id' => (int) ($row['id'] ?? 0),
        'student_id' => (string) ($row['student_id'] ?? ''),
        'display_name' => studentRecordName($row),
        'email' => (string) ($row['email'] ?? ''),
        'phone' => (string) ($row['phone'] ?? ''),
        'course' => (string) ($row['course'] ?? ''),
        'year_level' => (string) ($row['year_level'] ?? ''),
        'enrollment_status' => (string) ($row['enrollment_status'] ?? ''),
        'enrollment_label' => enrollmentStatusLabel($row['enrollment_status'] ?? null),
    ];
}

function queryStudentsForOnsitePicker(array $filters = [], int $page = 1, int $perPage = 15): array {
    $search = trim((string) ($filters['search'] ?? ''));
    $courseId = (int) ($filters['course_id'] ?? 0);
    $yearLevel = trim((string) ($filters['year_level'] ?? ''));
    $enrollmentStatus = trim((string) ($filters['enrollment_status'] ?? ''));
    $activeOnly = array_key_exists('active_only', $filters) ? (bool) $filters['active_only'] : true;
    $requireSearch = !empty($filters['require_search']);
    $perPage = max(1, min(50, $perPage));

    if ($requireSearch && strlen($search) < 2) {
        return [
            'students'    => [],
            'total'       => 0,
            'page'        => 1,
            'per_page'    => $perPage,
            'total_pages' => 1,
        ];
    }

    $db = getDB();
    $where = ['u.role_id = 1'];
    $params = [];

    if ($activeOnly) {
        $where[] = 'u.is_active = 1';
    }

    if ($search !== '') {
        $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($terms as $term) {
            $like = '%' . $term . '%';
            $prefix = $term . '%';
            $where[] = '(u.student_id LIKE ? OR u.student_id LIKE ? OR u.last_name LIKE ? OR u.first_name LIKE ?
                OR u.middle_name LIKE ? OR u.email LIKE ?
                OR CONCAT(u.last_name, ", ", u.first_name) LIKE ?
                OR CONCAT(u.first_name, " ", u.last_name) LIKE ?
                OR sp.course LIKE ?)';
            array_push($params, $prefix, $like, $prefix, $prefix, $like, $like, $like, $like, $like);
        }
    }

    if ($courseId > 0) {
        $where[] = 'sp.course_id = ?';
        $params[] = $courseId;
    }

    $yearOptions = yearLevelOptions();
    if ($yearLevel !== '' && isset($yearOptions[$yearLevel])) {
        $where[] = 'sp.year_level = ?';
        $params[] = $yearLevel;
    }

    if ($enrollmentStatus !== '' && array_key_exists($enrollmentStatus, enrollmentStatusOptions())) {
        $where[] = 'sp.enrollment_status = ?';
        $params[] = $enrollmentStatus;
    }

    $from = ' FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE ' . implode(' AND ', $where);

    $countStmt = $db->prepare('SELECT COUNT(*)' . $from);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $pag = paginate($total, $page, $perPage);

    $stmt = $db->prepare('SELECT u.id, u.student_id, u.first_name, u.last_name, u.middle_name, u.email, u.phone,
            sp.course, sp.year_level, sp.enrollment_status' . $from . '
        ORDER BY u.last_name, u.first_name, u.middle_name, u.id
        LIMIT ' . (int) $pag['per_page'] . ' OFFSET ' . (int) $pag['offset']);
    $stmt->execute($params);

    return [
        'students'    => $stmt->fetchAll(),
        'total'       => $total,
        'page'        => (int) $pag['page'],
        'per_page'    => (int) $pag['per_page'],
        'total_pages' => (int) $pag['total_pages'],
    ];
}

function searchStudentsForOnsiteRequest(string $search = '', int $limit = 15): array {
    $search = trim($search);
    if (strlen($search) < 2) {
        return [];
    }

    $result = queryStudentsForOnsitePicker([
        'search'         => $search,
        'require_search' => true,
        'active_only'    => true,
    ], 1, $limit);

    return $result['students'];
}

/**
 * Generate a walk-in requestor ID: REQ-YEAR-XXXXX
 */
function generateOnsiteRequestorId(): string {
    $db = getDB();
    $year = date('Y');

    for ($attempt = 0; $attempt < 40; $attempt++) {
        $suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
        $candidate = 'REQ-' . $year . '-' . $suffix;

        $stmt = $db->prepare('SELECT id FROM users WHERE student_id = ? LIMIT 1');
        $stmt->execute([$candidate]);
        if (!$stmt->fetch()) {
            return $candidate;
        }
    }

    throw new RuntimeException('Unable to generate a unique requestor ID.');
}

/**
 * Resolve an existing student or create a walk-in student account for onsite requests.
 *
 * @return array{user: array, created: bool}
 */
function resolveOnsiteRequestor(array $input): array {
    ensurePrivacyConsentSchema();
    ensureEnrollmentStatuses();
    ensureAcademicProgramsSchema();
    ensureCampusesSchema();

    $existingUserId = (int) ($input['user_id'] ?? 0);
    $studentId = trim((string) ($input['student_id'] ?? ''));
    $firstName = normalizePersonName($input['first_name'] ?? '');
    $lastName = normalizePersonName($input['last_name'] ?? '');
    $middleName = normalizePersonName($input['middle_name'] ?? '');
    $email = trim((string) ($input['email'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $enrollmentStatus = trim((string) ($input['enrollment_status'] ?? 'enrolled'));
    $courseId = (int) ($input['course_id'] ?? 0);
    $yearLevel = trim((string) ($input['year_level'] ?? ''));
    $yearGraduated = (int) ($input['year_graduated'] ?? 0);
    $originCampusId = (int) ($input['origin_campus_id'] ?? 0);
    $lastSchoolYear = trim((string) ($input['last_school_year'] ?? ''));
    $lastSemester = trim((string) ($input['last_semester'] ?? ''));

    if (!array_key_exists($enrollmentStatus, enrollmentStatusOptions())) {
        $enrollmentStatus = 'enrolled';
    }

    $isGraduated = isGraduatedEnrollment($enrollmentStatus);
    $isInactive = isInactiveEnrollment($enrollmentStatus);

    if ($isGraduated) {
        $yearLevel = '';
        $lastSchoolYear = '';
        $lastSemester = '';
        if ($yearGraduated > 0 && !array_key_exists((string) $yearGraduated, yearGraduatedOptions())) {
            $yearGraduated = 0;
        }
        if ($originCampusId > 0 && !getCampusById($originCampusId)) {
            $originCampusId = 0;
        }
    } elseif ($isInactive) {
        $yearLevel = '';
        $yearGraduated = 0;
        if ($lastSchoolYear !== '' && !array_key_exists($lastSchoolYear, schoolYearOptions())) {
            $lastSchoolYear = '';
        }
        if ($lastSemester !== '' && !array_key_exists($lastSemester, semesterOptions())) {
            $lastSemester = '';
        }
        if ($originCampusId > 0 && !getCampusById($originCampusId)) {
            $originCampusId = 0;
        }
    } else {
        $yearGraduated = 0;
        $originCampusId = 0;
        $lastSchoolYear = '';
        $lastSemester = '';
        if ($yearLevel !== '' && !array_key_exists($yearLevel, yearLevelOptions())) {
            $yearLevel = '';
        }
    }

    $program = $courseId > 0 ? resolveAcademicProgramFromPost($courseId) : null;
    $course = $program ? (string) $program['name'] : '';
    $courseId = $program ? (int) $program['id'] : 0;
    $graduationDate = ($isGraduated && $yearGraduated > 0) ? ($yearGraduated . '-06-01') : null;

    $existing = null;
    if ($existingUserId > 0) {
        $existing = findStudentUserById($existingUserId);
    }
    if (!$existing && $studentId !== '') {
        $existing = findStudentUserByStudentId($studentId);
    }

    $db = getDB();

    if ($existing) {
        $updates = [];
        $params = [];

        if ($firstName !== '' && $firstName !== ($existing['first_name'] ?? '')) {
            $updates[] = 'first_name = ?';
            $params[] = $firstName;
        }
        if ($lastName !== '' && $lastName !== ($existing['last_name'] ?? '')) {
            $updates[] = 'last_name = ?';
            $params[] = $lastName;
        }
        if ($middleName !== '' && $middleName !== ($existing['middle_name'] ?? '')) {
            $updates[] = 'middle_name = ?';
            $params[] = $middleName;
        }
        if ($phone !== '' && $phone !== ($existing['phone'] ?? '')) {
            $updates[] = 'phone = ?';
            $params[] = $phone;
        }

        if ($updates) {
            $params[] = (int) $existing['id'];
            $db->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
        }

        $profileExists = $db->prepare('SELECT user_id FROM student_profiles WHERE user_id = ?');
        $profileExists->execute([(int) $existing['id']]);
        if (!$profileExists->fetch()) {
            $db->prepare('INSERT INTO student_profiles
                (user_id, enrollment_status, course, course_id, year_level, origin_campus_id, year_graduated, graduation_date, last_school_year, current_semester)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
               ->execute([
                   (int) $existing['id'],
                   $enrollmentStatus,
                   $course ?: null,
                   $courseId > 0 ? $courseId : null,
                   $yearLevel ?: null,
                   $originCampusId > 0 ? $originCampusId : null,
                   $yearGraduated > 0 ? $yearGraduated : null,
                   $graduationDate,
                   $lastSchoolYear !== '' ? $lastSchoolYear : null,
                   $lastSemester !== '' ? $lastSemester : null,
               ]);
        } else {
            $db->prepare('UPDATE student_profiles
                SET enrollment_status = ?,
                    course = COALESCE(NULLIF(?, \'\'), course),
                    course_id = COALESCE(?, course_id),
                    year_level = ?,
                    origin_campus_id = ?,
                    year_graduated = ?,
                    graduation_date = ?,
                    last_school_year = ?,
                    current_semester = ?
                WHERE user_id = ?')
               ->execute([
                   $enrollmentStatus,
                   $course,
                   $courseId > 0 ? $courseId : null,
                   $yearLevel !== '' ? $yearLevel : null,
                   $originCampusId > 0 ? $originCampusId : null,
                   $yearGraduated > 0 ? $yearGraduated : null,
                   $graduationDate,
                   $lastSchoolYear !== '' ? $lastSchoolYear : null,
                   $lastSemester !== '' ? $lastSemester : null,
                   (int) $existing['id'],
               ]);
        }

        $fresh = findStudentUserById((int) $existing['id']);
        return ['user' => $fresh ?: $existing, 'created' => false];
    }

    if ($firstName === '' || $lastName === '') {
        throw new InvalidArgumentException('First name and last name are required for a new requestor.');
    }

    if ($studentId === '') {
        $studentId = generateOnsiteRequestorId();
    }

    if ($email === '') {
        $safeId = preg_replace('/[^a-zA-Z0-9]/', '', $studentId) ?: ('walkin' . time());
        $email = 'walkin.' . strtolower($safeId) . '@regdum.edu.ph';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid email address for the requestor.');
    }

    $emailCheck = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $emailCheck->execute([$email]);
    if ($emailCheck->fetch()) {
        throw new InvalidArgumentException('That email is already registered to another account.');
    }

    $tempPassword = bin2hex(random_bytes(8));
    $hash = password_hash($tempPassword, PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO users (role_id, email, password, student_id, first_name, last_name, middle_name, phone, is_active, email_verified, privacy_consent_at)
        VALUES (1, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())')
       ->execute([
           $email,
           $hash,
           $studentId,
           $firstName,
           $lastName,
           $middleName ?: null,
           $phone ?: null,
       ]);
    $userId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO student_profiles
        (user_id, enrollment_status, course, course_id, year_level, origin_campus_id, year_graduated, graduation_date, last_school_year, current_semester)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
       ->execute([
           $userId,
           $enrollmentStatus,
           $course ?: null,
           $courseId > 0 ? $courseId : null,
           $yearLevel ?: null,
           $originCampusId > 0 ? $originCampusId : null,
           $yearGraduated > 0 ? $yearGraduated : null,
           $graduationDate,
           $lastSchoolYear !== '' ? $lastSchoolYear : null,
           $lastSemester !== '' ? $lastSemester : null,
       ]);

    auditLog('create_walkin_student', 'users', $userId, null, [
        'source' => 'onsite_request',
        'student_id' => $studentId,
    ]);

    $user = findStudentUserById($userId);
    if (!$user) {
        throw new RuntimeException('Unable to create walk-in requestor account.');
    }

    return ['user' => $user, 'created' => true];
}

/**
 * Create (or update) a graduate/inactive requestor for a multi-alumni onsite batch.
 * Forces enrollment_status to the batch status and returns a picker-ready row.
 *
 * @return array{ok:bool,student:?array,created:bool,error:?string,errors:array<string,string>}
 */
function createOnsiteAlumniRequestorForBatch(array $input, string $batchEnrollmentStatus): array {
    ensureEnrollmentStatuses();

    $status = trim($batchEnrollmentStatus);
    if (!in_array($status, ['graduated', 'inactive'], true)) {
        return [
            'ok' => false,
            'student' => null,
            'created' => false,
            'error' => 'Add requestor is only available for graduated or inactive batches.',
            'errors' => ['enrollment_status' => 'Invalid batch enrollment status.'],
        ];
    }

    $firstName = normalizePersonName($input['first_name'] ?? '');
    $lastName = normalizePersonName($input['last_name'] ?? '');
    $middleName = normalizePersonName($input['middle_name'] ?? '');
    $email = trim((string) ($input['email'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $studentId = trim((string) ($input['student_id'] ?? ''));
    $courseId = (int) ($input['course_id'] ?? 0);
    $yearGraduated = (int) ($input['year_graduated'] ?? 0);
    $originCampusId = (int) ($input['origin_campus_id'] ?? 0);
    $lastSchoolYear = trim((string) ($input['last_school_year'] ?? ''));
    $lastSemester = trim((string) ($input['last_semester'] ?? ''));

    $errors = [];
    if ($firstName === '') {
        $errors['first_name'] = 'First name is required.';
    }
    if ($lastName === '') {
        $errors['last_name'] = 'Last name is required.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if ($courseId > 0 && !resolveAcademicProgramFromPost($courseId)) {
        $errors['course_id'] = 'Please select a valid course/program.';
    }

    if ($status === 'graduated') {
        if ($yearGraduated <= 0 || !array_key_exists((string) $yearGraduated, yearGraduatedOptions())) {
            $errors['year_graduated'] = 'Please select a valid year of graduation.';
        }
        if ($originCampusId <= 0 || !getCampusById($originCampusId)) {
            $errors['origin_campus_id'] = 'Please select a campus.';
        }
        $lastSchoolYear = '';
        $lastSemester = '';
    } else {
        if ($lastSchoolYear === '' || !array_key_exists($lastSchoolYear, schoolYearOptions())) {
            $errors['last_school_year'] = 'Please select the last school year attended.';
        }
        if ($lastSemester === '' || !array_key_exists($lastSemester, semesterOptions())) {
            $errors['last_semester'] = 'Please select the semester attended.';
        }
        if ($originCampusId <= 0 || !getCampusById($originCampusId)) {
            $errors['origin_campus_id'] = 'Please select a campus.';
        }
        $yearGraduated = 0;
    }

    if ($errors !== []) {
        return [
            'ok' => false,
            'student' => null,
            'created' => false,
            'error' => reset($errors) ?: 'Please complete the required requestor fields.',
            'errors' => $errors,
        ];
    }

    try {
        $resolved = resolveOnsiteRequestor([
            'user_id' => 0,
            'student_id' => $studentId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'middle_name' => $middleName,
            'email' => $email,
            'phone' => $phone,
            'enrollment_status' => $status,
            'course_id' => $courseId,
            'year_level' => '',
            'year_graduated' => $yearGraduated,
            'origin_campus_id' => $originCampusId,
            'last_school_year' => $lastSchoolYear,
            'last_semester' => $lastSemester,
        ]);
    } catch (InvalidArgumentException $e) {
        return [
            'ok' => false,
            'student' => null,
            'created' => false,
            'error' => $e->getMessage(),
            'errors' => ['general' => $e->getMessage()],
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'student' => null,
            'created' => false,
            'error' => 'Unable to save the requestor right now. Try again.',
            'errors' => ['general' => 'Unable to save the requestor right now. Try again.'],
        ];
    }

    $user = $resolved['user'] ?? null;
    if (!$user) {
        return [
            'ok' => false,
            'student' => null,
            'created' => false,
            'error' => 'Unable to save the requestor right now. Try again.',
            'errors' => ['general' => 'Unable to save the requestor right now. Try again.'],
        ];
    }

    return [
        'ok' => true,
        'student' => formatOnsitePickerStudent($user),
        'created' => !empty($resolved['created']),
        'error' => null,
        'errors' => [],
    ];
}

/**
 * Normalize posted student user IDs for a multi-student onsite request.
 * Duplicates are dropped and the list is capped at ONSITE_MULTI_STUDENT_MAX.
 *
 * @return list<int>
 */
function normalizeOnsiteMultiStudentIds($rawIds): array {
    $ids = [];
    foreach ((array) $rawIds as $rawId) {
        $id = (int) $rawId;
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return array_slice($ids, 0, ONSITE_MULTI_STUDENT_MAX);
}

/**
 * Load the selected students and confirm they can share a single document selection.
 * Document availability, fees and copy limits are driven by enrollment status, so
 * every student in the batch must match the status the request was built for.
 *
 * @param list<int> $ids
 * @return array{students: list<array>, error: ?string}
 */
function resolveOnsiteMultiStudents(array $ids, string $enrollmentStatus): array {
    ensureEnrollmentStatuses();

    $students = [];
    $missing = false;
    $inactive = [];
    $mismatched = [];

    foreach ($ids as $id) {
        $student = findStudentUserById($id);
        if (!$student) {
            $missing = true;
            continue;
        }

        // The picker only offers active accounts; keep posted IDs to the same rule.
        if (empty($student['is_active'])) {
            $inactive[] = studentRecordName($student);
            continue;
        }

        $studentStatus = (string) ($student['enrollment_status'] ?? 'enrolled');
        if ($studentStatus === '') {
            $studentStatus = 'enrolled';
        }
        if ($studentStatus !== $enrollmentStatus) {
            $mismatched[] = studentRecordName($student) . ' — ' . enrollmentStatusLabel($studentStatus);
            continue;
        }

        $students[] = $student;
    }

    $error = null;
    if ($missing) {
        $error = 'One or more selected student records could not be found. Remove them and search again.';
    } elseif ($inactive !== []) {
        $error = 'These student accounts are deactivated and cannot be added: ' . implode('; ', $inactive) . '.';
    } elseif ($mismatched !== []) {
        $error = 'Every requestor in this batch must be '
            . enrollmentStatusLabel($enrollmentStatus)
            . ' to share the same documents. Remove or change: ' . implode('; ', $mismatched) . '.';
    }

    return ['students' => $students, 'error' => $error];
}

/**
 * Create one onsite credential request per student, all sharing the same documents.
 * Each student keeps an individual request number and payment code, but the
 * cashier verifies the group together under one OR number.
 *
 * @param list<array> $students   Existing student rows from findStudentUserById()
 * @param list<array> $itemDrafts Same shape as createOnsiteCredentialRequest()
 * @return array{created: list<array>, failed: list<array{student: string, message: string}>}
 */
function createOnsiteCredentialRequestsForStudents(
    array $students,
    int $createdByUserId,
    array $payload,
    array $itemDrafts
): array {
    if ($students === []) {
        throw new InvalidArgumentException('Select at least one requestor for a multi-requestor batch.');
    }
    if (count($students) > ONSITE_MULTI_STUDENT_MAX) {
        throw new InvalidArgumentException('A multi-requestor batch can include at most ' . ONSITE_MULTI_STUDENT_MAX . ' requestors.');
    }

    $created = [];
    $failed = [];
    $batchKey = generateOnsiteBatchKey();

    foreach ($students as $student) {
        try {
            $created[] = createOnsiteCredentialRequest($student, $createdByUserId, $payload, $itemDrafts, $batchKey);
        } catch (Throwable $e) {
            error_log('Multi-student onsite request failed for user ' . (int) ($student['id'] ?? 0) . ': ' . $e->getMessage());
            $failed[] = [
                'student' => studentRecordName($student),
                'message' => $e instanceof InvalidArgumentException
                    ? $e->getMessage()
                    : 'Unable to create a request for this student.',
            ];
        }
    }

    if ($created === []) {
        throw new RuntimeException('No requests could be created for the selected students. Please try again.');
    }

    auditLog('create_onsite_multi_request', 'requests', (int) $created[0]['request_id'], null, [
        'student_count' => count($created),
        'failed_count' => count($failed),
        'document_count' => count($itemDrafts),
        'onsite_batch_key' => $batchKey,
        'request_numbers' => array_map(static fn (array $row): string => (string) $row['request_number'], $created),
    ]);

    return ['created' => $created, 'failed' => $failed];
}

/**
 * Create an onsite credential request ready for cashier payment.
 * When require_online_clearance is set, payment code is still generated but cashier
 * verification is blocked until all clearance offices sign.
 *
 * @param array $itemDrafts Same shape as student/new-request item drafts
 * @return array{request_id:int,request_number:string,payment_code:string,amount:float,user:array,requires_clearance:bool}
 */
function createOnsiteCredentialRequest(
    array $studentUser,
    int $createdByUserId,
    array $payload,
    array $itemDrafts,
    ?string $onsiteBatchKey = null
): array {
    ensureOnsiteRequestSchema();
    ensureRequestItemsSchema();
    ensurePaymentMethodSchema();
    ensureComplianceSchema();
    ensureClearanceSchema();

    if (empty($itemDrafts)) {
        throw new InvalidArgumentException('Select at least one document to request.');
    }

    $requireClearance = !empty($payload['require_online_clearance']);
    $db = getDB();
    $studentUserId = (int) $studentUser['id'];
    $requestNumber = generateRequestNumber();
    $batchTotal = 0.0;
    foreach ($itemDrafts as $draft) {
        $batchTotal += (float) $draft['item_amount'];
    }

    $primaryDocumentTypeId = (int) $itemDrafts[0]['document_type_id'];
    $notes = trim((string) ($payload['notes'] ?? ''));
    if ($notes !== '') {
        $notes = '[Onsite walk-in] ' . $notes;
    } else {
        $notes = '[Onsite walk-in] Created by registrar for cashier payment.';
    }
    if ($requireClearance) {
        $notes .= ' Online clearance required before cashier payment verification.';
    }

    $onsiteBatchKey = trim((string) $onsiteBatchKey);
    if ($onsiteBatchKey === '') {
        $onsiteBatchKey = null;
    }

    $stmt = $db->prepare('INSERT INTO requests (
        request_number, user_id, document_type_id, purpose, purpose_other, tor_specific_purpose, copy_request_type, copies, delivery_method,
        pickup_date, pickup_time, representative_name, representative_relationship, representative_phone,
        representative_id_number, total_amount, verification_code, notes, request_channel, created_by, onsite_batch_key,
        created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NULL, NULL, NULL, NULL, NULL, NULL, ?, ?, ?, \'onsite\', ?, ?, ?, ?)');
    $createdAt = appNow();
    $stmt->execute([
        $requestNumber,
        $studentUserId,
        $primaryDocumentTypeId,
        $payload['purpose'],
        $payload['purpose_other'] ?: null,
        !empty($payload['tor_specific_purpose']) ? $payload['tor_specific_purpose'] : null,
        $payload['copy_request_type'],
        'pickup',
        $batchTotal,
        generateVerificationCode(),
        $notes,
        $createdByUserId > 0 ? $createdByUserId : null,
        $onsiteBatchKey,
        $createdAt,
        $createdAt,
    ]);
    $requestId = (int) $db->lastInsertId();

    foreach ($itemDrafts as $index => $draft) {
        $itemId = createRequestItem(
            $requestId,
            (int) $draft['document_type_id'],
            (int) $draft['copies'],
            (float) $draft['item_amount'],
            $index + 1,
            $draft['request_school_year'] ?? null,
            $draft['request_semester'] ?? null,
            $draft['request_soa_assessment_scope'] ?? null,
            $draft['request_soa_remarks'] ?? null
        );

        if (!empty($draft['auth_items'])) {
            saveRequestAuthenticationItems($requestId, $draft['auth_items'], $itemId);
        }

        initRequestCompliance($requestId, (int) $draft['document_type_id']);
    }

    if ($requireClearance) {
        $clearanceReqs = buildRequirementsFromCodes(['online_clearance']);
        saveAssignedRequirements($requestId, $clearanceReqs);
        initRequestClearance($requestId);
        syncAssignedClearanceRequirement($requestId);
        syncClearanceComplianceCheck($requestId);

        $db->prepare("UPDATE request_compliance_summary
            SET compliance_status = 'pending', remarks = ?, verified_by = ?, verified_at = NOW(), updated_at = NOW()
            WHERE request_id = ?")
           ->execute([
               'Onsite walk-in — in-person docs verified; awaiting online clearance before payment',
               $createdByUserId > 0 ? $createdByUserId : null,
               $requestId,
           ]);

        updateRequestStatus(
            $requestId,
            'awaiting_requirements',
            'Onsite walk-in — online clearance required before cashier payment verification'
        );
    } else {
        saveAssignedRequirements($requestId, []);
        $db->prepare("UPDATE request_compliance_summary
            SET compliance_status = 'compliant', remarks = ?, verified_by = ?, verified_at = NOW(), updated_at = NOW()
            WHERE request_id = ?")
           ->execute([
               'Onsite walk-in — requirements collected / verified in person by registrar',
               $createdByUserId > 0 ? $createdByUserId : null,
               $requestId,
           ]);

        updateRequestStatus($requestId, 'requirements_verified', 'Onsite walk-in request ready for cashier payment');
    }

    refreshRequestTotalAmount($requestId);

    $amountStmt = $db->prepare('SELECT total_amount FROM requests WHERE id = ?');
    $amountStmt->execute([$requestId]);
    $amount = (float) $amountStmt->fetchColumn();

    $paymentCode = generateOnsitePaymentReference();
    $db->prepare('INSERT INTO payments (request_id, amount, payment_method, reference_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
       ->execute([$requestId, $amount, 'onsite_payment', $paymentCode, 'pending', appNow(), appNow()]);

    auditLog('create_onsite_request', 'requests', $requestId, null, [
        'request_number' => $requestNumber,
        'payment_code' => $paymentCode,
        'student_user_id' => $studentUserId,
        'document_count' => count($itemDrafts),
        'require_online_clearance' => $requireClearance,
    ]);

    $studentName = trim(($studentUser['first_name'] ?? '') . ' ' . ($studentUser['last_name'] ?? ''));
    $docCount = count($itemDrafts);
    $studentLabel = $studentName !== '' ? $studentName : 'Walk-in requestor';

    if ($requireClearance) {
        sendNotification(
            $studentUserId,
            'Onsite Request Created — Clearance Required',
            'Request ' . $requestNumber . ' was created at the Registrar. Complete online clearance at all offices before payment. Payment code: ' . $paymentCode . '.',
            'info',
            APP_URL . '/student/request-view.php?id=' . $requestId
        );

        notifyUsersByRole(
            'cashier',
            'Onsite Payment Pending Clearance',
            $studentLabel . ' — code ' . $paymentCode . ' for ' . $requestNumber . ' (' . formatMoney($amount) . '). Verify only after online clearance is complete.',
            'warning',
            APP_URL . '/cashier/payments.php?onsite_code=' . urlencode($paymentCode)
        );
    } else {
        sendNotification(
            $studentUserId,
            'Onsite Request Created',
            'Request ' . $requestNumber . ' was created at the Registrar. Present payment code ' . $paymentCode . ' at the cashier.',
            'info',
            APP_URL . '/student/request-view.php?id=' . $requestId
        );

        notifyCashiersNewPayment($requestId, $requestNumber, $studentLabel);
        notifyUsersByRole(
            'cashier',
            'Onsite Payment Code Ready',
            $studentLabel . ' — code ' . $paymentCode . ' for ' . $requestNumber . ' (' . formatMoney($amount) . ').',
            'info',
            APP_URL . '/cashier/payments.php?onsite_code=' . urlencode($paymentCode)
        );
    }

    notifyUsersByRole(
        'admin',
        'Onsite Request Created',
        ($studentName !== '' ? $studentName : 'Walk-in') . ' — ' . $requestNumber . ' (' . $docCount . ' document' . ($docCount === 1 ? '' : 's') . ')'
            . ($requireClearance ? '; online clearance required' : '') . '.',
        'info',
        APP_URL . '/admin/request-manage.php?id=' . $requestId
    );

    return [
        'request_id' => $requestId,
        'request_number' => $requestNumber,
        'payment_code' => $paymentCode,
        'amount' => $amount,
        'user' => $studentUser,
        'document_count' => $docCount,
        'requires_clearance' => $requireClearance,
    ];
}

/**
 * After onsite online clearance is fully signed, mark the request ready for cashier verification.
 */
function advanceOnsiteRequestAfterClearance(int $requestId): bool {
    ensureOnsiteRequestSchema();

    $db = getDB();
    $stmt = $db->prepare('SELECT id, status, request_number, request_channel, user_id FROM requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request || !isOnsiteRequestChannel($request['request_channel'] ?? null)) {
        return false;
    }
    if (!in_array($request['status'], ['awaiting_requirements', 'needs_revision'], true)) {
        return false;
    }
    if (!hasAssignedRequirement($requestId, 'online_clearance') || !isClearanceComplete($requestId)) {
        return false;
    }

    updateRequestStatus(
        $requestId,
        'requirements_verified',
        'Online clearance completed — ready for cashier payment verification'
    );
    $db->prepare("UPDATE request_compliance_summary
        SET compliance_status = 'compliant', remarks = ?, updated_at = NOW()
        WHERE request_id = ?")
       ->execute([
           'Onsite walk-in — online clearance completed; ready for cashier payment',
           $requestId,
       ]);

    $paymentStmt = $db->prepare("SELECT reference_number, amount FROM payments
        WHERE request_id = ? AND payment_method = 'onsite_payment' AND status = 'pending'
        ORDER BY created_at DESC LIMIT 1");
    $paymentStmt->execute([$requestId]);
    $payment = $paymentStmt->fetch() ?: null;
    $paymentCode = (string) ($payment['reference_number'] ?? '');

    sendNotification(
        (int) $request['user_id'],
        'Clearance Complete — Proceed to Cashier',
        'Online clearance for ' . $request['request_number'] . ' is complete. You may now pay at the cashier'
            . ($paymentCode !== '' ? ' using code ' . $paymentCode : '') . '.',
        'success',
        APP_URL . '/student/request-view.php?id=' . $requestId
    );

    notifyUsersByRole(
        'cashier',
        'Onsite Clearance Complete — Ready to Verify',
        'Request ' . $request['request_number'] . ' clearance is complete'
            . ($paymentCode !== '' ? ' (code ' . $paymentCode . ')' : '')
            . '. Payment can now be verified.',
        'success',
        $paymentCode !== ''
            ? APP_URL . '/cashier/payments.php?onsite_code=' . urlencode($paymentCode)
            : APP_URL . '/cashier/payments.php?status=pending'
    );

    notifyUsersByRole(
        'registrar',
        'Onsite Clearance Complete',
        'Request ' . $request['request_number'] . ' online clearance is complete and ready for cashier payment.',
        'info',
        APP_URL . '/registrar/verify-request.php?id=' . $requestId
    );

    return true;
}

function fetchOnsiteRequestSlipData(int $requestId): ?array {
    ensureOnsiteRequestSchema();
    ensureRequestItemsSchema();
    require_once __DIR__ . '/payments.php';

    $db = getDB();
    $stmt = $db->prepare('SELECT r.*,
            u.first_name, u.last_name, u.student_id, u.email, u.phone,
            sp.course, sp.year_level, sp.enrollment_status,
            sp.current_academic_year, sp.current_semester, sp.year_graduated, sp.last_school_year
        FROM requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        WHERE r.id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        return null;
    }

    $paymentStmt = $db->prepare("SELECT * FROM payments
        WHERE request_id = ? AND payment_method = 'onsite_payment'
        ORDER BY created_at DESC
        LIMIT 1");
    $paymentStmt->execute([$requestId]);
    $payment = $paymentStmt->fetch() ?: null;

    $items = getRequestItems($requestId);
    $requiresClearance = hasAssignedRequirement($requestId, 'online_clearance');
    $clearanceComplete = $requiresClearance ? isClearanceComplete($requestId) : true;

    return [
        'request' => $request,
        'payment' => $payment,
        'items' => $items,
        'payment_code' => $payment['reference_number'] ?? null,
        'amount' => (float) ($payment['amount'] ?? $request['total_amount'] ?? 0),
        'requires_clearance' => $requiresClearance,
        'clearance_complete' => $clearanceComplete,
    ];
}

function canViewOnsiteRequestSlip(array $user, array $request): bool {
    if (hasRole('registrar', 'admin', 'cashier')) {
        return true;
    }
    if (hasRole('student') && (int) $user['id'] === (int) $request['user_id']) {
        return true;
    }
    return false;
}

/**
 * @return array{0:list<string>,1:list<mixed>}
 */
function buildOnsiteRequestsListFilters(string $status = '', string $search = ''): array {
    $where = ["r.request_channel = 'onsite'"];
    $params = [];

    if ($status !== '') {
        $where[] = 'r.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(r.request_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?
            OR u.student_id LIKE ? OR p.reference_number LIKE ?
            OR CONCAT(u.first_name, \' \', u.last_name) LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    return [$where, $params];
}

function countOnsiteRequestsList(string $status = '', string $search = ''): int {
    ensureOnsiteRequestSchema();
    ensurePaymentMethodSchema();
    [$where, $params] = buildOnsiteRequestsListFilters($status, $search);
    $sql = 'SELECT COUNT(*)
            FROM requests r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN payments p ON p.id = (
                SELECT p2.id FROM payments p2
                WHERE p2.request_id = r.id AND p2.payment_method = \'onsite_payment\'
                ORDER BY p2.created_at DESC
                LIMIT 1
            )
            WHERE ' . implode(' AND ', $where);
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/**
 * List onsite walk-in credential requests for registrar records.
 *
 * @return list<array<string,mixed>>
 */
function getOnsiteRequestsList(string $status = '', string $search = '', int $limit = 200, int $offset = 0, string $orderBy = 'r.created_at DESC'): array {
    ensureOnsiteRequestSchema();
    ensureRequestItemsSchema();
    require_once __DIR__ . '/payments.php';
    require_once __DIR__ . '/compliance.php';
    require_once __DIR__ . '/clearance.php';

    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $db = getDB();
    [$where, $params] = buildOnsiteRequestsListFilters($status, $search);
    $orderBy = trim($orderBy) !== '' ? $orderBy : 'r.created_at DESC';

    $sql = 'SELECT r.id, r.request_number, r.status, r.purpose, r.copy_request_type, r.total_amount,
                   r.created_at, r.created_by, r.onsite_batch_key,
                   u.first_name, u.last_name, u.student_id, u.email,
                   dt.name as document_name,
                   p.reference_number as payment_code, p.status as payment_status, p.amount as payment_amount,
                   cb.first_name as created_by_first, cb.last_name as created_by_last
            FROM requests r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN document_types dt ON r.document_type_id = dt.id
            LEFT JOIN users cb ON r.created_by = cb.id
            LEFT JOIN payments p ON p.id = (
                SELECT p2.id FROM payments p2
                WHERE p2.request_id = r.id AND p2.payment_method = \'onsite_payment\'
                ORDER BY p2.created_at DESC
                LIMIT 1
            )
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderBy . '
            LIMIT ' . $limit . ' OFFSET ' . $offset;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $batchSizes = [];
    $batchTotals = [];
    $batchRequestIds = [];
    $batchKeys = [];
    foreach ($rows as $row) {
        $batchKey = trim((string) ($row['onsite_batch_key'] ?? ''));
        if ($batchKey !== '') {
            $batchKeys[$batchKey] = true;
        }
    }
    if ($batchKeys !== []) {
        $placeholders = implode(',', array_fill(0, count($batchKeys), '?'));
        $sizeStmt = $db->prepare(
            "SELECT onsite_batch_key,
                    COUNT(*) AS batch_size,
                    COALESCE(SUM(total_amount), 0) AS batch_total
             FROM requests
             WHERE onsite_batch_key IN ($placeholders)
             GROUP BY onsite_batch_key"
        );
        $sizeStmt->execute(array_keys($batchKeys));
        foreach ($sizeStmt->fetchAll() as $sizeRow) {
            $key = (string) $sizeRow['onsite_batch_key'];
            $batchSizes[$key] = (int) $sizeRow['batch_size'];
            $batchTotals[$key] = (float) $sizeRow['batch_total'];
        }

        $idStmt = $db->prepare(
            "SELECT id, onsite_batch_key
             FROM requests
             WHERE onsite_batch_key IN ($placeholders)
             ORDER BY id ASC"
        );
        $idStmt->execute(array_keys($batchKeys));
        foreach ($idStmt->fetchAll() as $idRow) {
            $key = (string) $idRow['onsite_batch_key'];
            $batchRequestIds[$key][] = (int) $idRow['id'];
        }
    }

    foreach ($rows as &$row) {
        $requestId = (int) $row['id'];
        $batchKey = trim((string) ($row['onsite_batch_key'] ?? ''));
        $row['is_multiple'] = $batchKey !== '';
        $row['batch_size'] = $batchKey !== ''
            ? max(2, (int) ($batchSizes[$batchKey] ?? 2))
            : 1;
        $row['batch_request_ids'] = $batchKey !== ''
            ? array_values(array_filter($batchRequestIds[$batchKey] ?? [$requestId]))
            : [$requestId];
        $row['batch_total_amount'] = $batchKey !== ''
            ? (float) ($batchTotals[$batchKey] ?? ($row['total_amount'] ?? 0))
            : (float) ($row['payment_amount'] ?? $row['total_amount'] ?? 0);
        $row['display_amount'] = !empty($row['is_multiple'])
            ? (float) $row['batch_total_amount']
            : (float) ($row['payment_amount'] ?? $row['total_amount'] ?? 0);
        $row['request_type_label'] = $row['is_multiple'] ? 'Multiple' : 'Single';
        $row['items'] = getRequestItems($requestId);
        $row['document_summary'] = formatRequestItemsSummary($row['items'], 2);
        if ($row['document_summary'] === '—' && !empty($row['document_name'])) {
            $row['document_summary'] = (string) $row['document_name'];
        }
        $gate = getPaymentClearanceGate($requestId);
        $row['clearance_required'] = !empty($gate['required']);
        $row['clearance_blocked'] = !empty($gate['blocked']);
        $row['clearance_cleared'] = (int) ($gate['cleared'] ?? 0);
        $row['clearance_total'] = (int) ($gate['total'] ?? 0);
    }
    unset($row);

    return $rows;
}

function buildOnsiteRequestSlipRows(array $data): array {
    $request = $data['request'];
    $items = $data['items'] ?? [];
    $studentName = trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
    $courseYear = trim(
        (string) ($request['course'] ?? '')
        . (!empty($request['year_level']) ? ' · ' . $request['year_level'] : '')
    );

    $documentSummary = formatRequestItemsSummary($items, 0);
    if ($documentSummary === '—' && !empty($request['document_name'])) {
        $documentSummary = (string) $request['document_name'] . formatRequestItemTermSuffix($request);
    }

    $rows = [
        ['Requestor', $studentName !== '' ? $studentName : '—'],
        ['Requestor ID', $request['student_id'] ?? '—'],
        ['Course / Year', $courseYear !== '' ? $courseYear : '—'],
    ];
    foreach (studentAcademicSlipRows($request) as $academicRow) {
        $rows[] = $academicRow;
    }
    return array_merge($rows, [
        ['Documents', $documentSummary],
        ['Request Type', copyRequestTypeLabel($request['copy_request_type'] ?? null)],
        ['Purpose', purposeLabel($request['purpose'] ?? '') . (!empty($request['purpose_other']) ? ' — ' . $request['purpose_other'] : '')],
        ...(!empty($request['tor_specific_purpose']) ? [['Specific Purpose', (string) $request['tor_specific_purpose']]] : []),
        ['Amount Due', formatMoney((float) $data['amount'])],
    ]);
}

function renderOnsiteRequestSlipSheetHtml(array $data, string $elementId = 'onsiteRequestSlipSheet'): void {
    $request = $data['request'];
    $paymentCode = (string) ($data['payment_code'] ?? '—');
    $rows = buildOnsiteRequestSlipRows($data);
    $requiresClearance = !empty($data['requires_clearance']);
    $clearanceComplete = !empty($data['clearance_complete']);
    ?>
    <article class="onsite-slip-sheet regdum-slip-sheet" id="<?= e($elementId) ?>"
        data-request-number="<?= e($request['request_number']) ?>"
        data-slip-width="4.25"
        data-slip-height="6.5">
        <header class="onsite-slip-top regdum-slip-top">
            <img src="<?= e(APP_LOGO) ?>" alt="<?= e(APP_NAME) ?>" class="app-logo app-logo-claim onsite-slip-logo regdum-slip-logo">
            <div class="onsite-slip-brand regdum-slip-brand">
                <p class="onsite-slip-office regdum-slip-office"><?= e(APP_NAME) ?></p>
                <p class="onsite-slip-subtitle regdum-slip-subtitle"><?= e(APP_TAGLINE) ?></p>
            </div>
            <h1 class="onsite-slip-heading regdum-slip-heading">Onsite Request Slip</h1>
        </header>

        <div class="onsite-slip-code-block regdum-slip-code-block">
            <span class="onsite-slip-code-label regdum-slip-code-label">Cashier Payment Code</span>
            <strong class="onsite-slip-code-value regdum-slip-code-value"><?= e($paymentCode) ?></strong>
            <span class="onsite-slip-request-no regdum-slip-request-no">Request No. <?= e($request['request_number']) ?></span>
        </div>

        <table class="onsite-slip-table regdum-slip-table">
            <tbody>
                <?php foreach ($rows as [$label, $value]): ?>
                <tr>
                    <th scope="row"><?= e($label) ?></th>
                    <td><?= e($value) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($requiresClearance): ?>
                <tr>
                    <th scope="row">Online Clearance</th>
                    <td><?= $clearanceComplete ? 'Completed' : 'Required before payment' ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <p class="onsite-slip-note regdum-slip-note">
            <?php if ($requiresClearance && !$clearanceComplete): ?>
                Complete online clearance at all offices first, then present this slip and pay at the Cashier using the 6-digit code above.
            <?php else: ?>
                Present this slip and pay at the Cashier. Use the 6-digit payment code above.
            <?php endif; ?>
            Track status anytime at <?= e(publicOnsiteTrackingUrl($request['request_number'] ?? null, $paymentCode !== '—' ? $paymentCode : null)) ?>
        </p>

        <footer class="onsite-slip-footer regdum-slip-footer">
            Generated <?= formatDateTime(date('Y-m-d H:i:s')) ?>
        </footer>
    </article>
    <?php
}

function renderOnsiteRequestSlipDocument(array $data, bool $autoPrint = false): void {
    $request = $data['request'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onsite Request Slip — <?= e($request['request_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/print.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="onsite-slip-page regdum-slip-page<?= $autoPrint ? ' auto-print' : '' ?>">
    <div class="onsite-slip-toolbar regdum-slip-toolbar no-print">
        <a href="<?= APP_URL ?>/registrar/new-onsite-request.php" class="btn btn-outline btn-sm">
            <i class="fas fa-plus"></i> New Onsite Request
        </a>
        <div class="onsite-slip-toolbar-actions regdum-slip-toolbar-actions">
            <a href="<?= APP_URL ?>/registrar/verify-request.php?id=<?= (int) $request['id'] ?>" class="btn btn-outline btn-sm">
                <i class="fas fa-eye"></i> View Request
            </a>
            <button type="button" class="btn btn-outline btn-sm" data-onsite-slip-download="png">
                <i class="fas fa-image"></i> Download Image
            </button>
            <button type="button" class="btn btn-outline btn-sm" data-onsite-slip-download="pdf">
                <i class="fas fa-file-pdf"></i> Download PDF
            </button>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> Print Slip
            </button>
        </div>
    </div>

    <?php renderOnsiteRequestSlipSheetHtml($data); ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="<?= APP_URL ?>/assets/js/onsite-request-slip.js"></script>
</body>
</html>
    <?php
}

/**
 * Parse a comma separated or array list of request IDs (e.g. the ids= query param).
 *
 * @return list<int>
 */
function normalizeOnsiteRequestIdList($raw): array {
    $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
    $ids = [];
    foreach ($parts as $part) {
        $id = (int) trim((string) $part);
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * Load slip data for several onsite requests, skipping any the user may not view.
 *
 * @param list<int> $requestIds
 * @return list<array>
 */
function fetchOnsiteRequestSlipBatch(array $requestIds, array $viewer): array {
    $slips = [];
    foreach ($requestIds as $requestId) {
        $data = fetchOnsiteRequestSlipData($requestId);
        if (!$data) {
            continue;
        }
        if (!isOnsiteRequestChannel($data['request']['request_channel'] ?? null)) {
            continue;
        }
        if (!canViewOnsiteRequestSlip($viewer, $data['request'])) {
            continue;
        }
        $slips[] = $data;
    }

    return $slips;
}

/**
 * All requestors that share an onsite multi-student batch key.
 *
 * @return list<array{
 *   request_id:int,
 *   request_number:string,
 *   status:string,
 *   total_amount:float,
 *   first_name:string,
 *   last_name:string,
 *   middle_name:string,
 *   student_id:string,
 *   course:string,
 *   year_level:string,
 *   enrollment_status:string,
 *   payment_code:string,
 *   payment_status:string
 * }>
 */
function listOnsiteBatchRequestors(string $batchKey): array {
    $batchKey = trim($batchKey);
    if ($batchKey === '') {
        return [];
    }

    ensureOnsiteRequestSchema();
    ensurePaymentMethodSchema();

    $stmt = getDB()->prepare(
        "SELECT r.id AS request_id, r.request_number, r.status, r.total_amount,
                u.first_name, u.last_name, u.middle_name, u.student_id,
                sp.course, sp.year_level, sp.enrollment_status,
                p.reference_number AS payment_code, p.status AS payment_status
         FROM requests r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN student_profiles sp ON sp.user_id = u.id
         LEFT JOIN payments p ON p.id = (
             SELECT p2.id FROM payments p2
             WHERE p2.request_id = r.id AND p2.payment_method = 'onsite_payment'
             ORDER BY p2.created_at DESC
             LIMIT 1
         )
         WHERE r.onsite_batch_key = ?
         ORDER BY u.last_name ASC, u.first_name ASC, r.id ASC"
    );
    $stmt->execute([$batchKey]);

    $members = [];
    foreach ($stmt->fetchAll() as $row) {
        $members[] = [
            'request_id' => (int) ($row['request_id'] ?? 0),
            'request_number' => (string) ($row['request_number'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'middle_name' => (string) ($row['middle_name'] ?? ''),
            'student_id' => (string) ($row['student_id'] ?? ''),
            'course' => (string) ($row['course'] ?? ''),
            'year_level' => (string) ($row['year_level'] ?? ''),
            'enrollment_status' => (string) ($row['enrollment_status'] ?? ''),
            'payment_code' => (string) ($row['payment_code'] ?? ''),
            'payment_status' => (string) ($row['payment_status'] ?? ''),
        ];
    }

    return $members;
}

/**
 * HTML block listing every student/requestor in an onsite batch.
 */
function renderOnsiteBatchRequestorsHtml(string $batchKey, int $currentRequestId = 0, string $viewBaseUrl = ''): string {
    $members = listOnsiteBatchRequestors($batchKey);
    if (count($members) < 2) {
        return '';
    }

    $viewBaseUrl = trim($viewBaseUrl);
    $showLinks = $viewBaseUrl !== '';
    $batchIds = array_values(array_filter(array_map(
        static fn(array $m): int => (int) ($m['request_id'] ?? 0),
        $members
    )));
    $batchPageUrl = APP_URL . '/registrar/onsite-request-batch.php?ids=' . implode(',', $batchIds);
    $canOpenBatchPage = function_exists('hasRole') && hasRole('registrar', 'admin');
    $batchTotal = 0.0;
    foreach ($members as $member) {
        $batchTotal += (float) ($member['total_amount'] ?? 0);
    }

    ob_start();
    ?>
    <section class="onsite-batch-requestors-panel assignment-detail-section">
        <div class="onsite-batch-requestors-head">
            <h3><i class="fas fa-users"></i> Batch Requestors (<?= count($members) ?>)</h3>
            <?php if ($canOpenBatchPage): ?>
                <a href="<?= e($batchPageUrl) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener">
                    <i class="fas fa-external-link-alt"></i> Open Batch
                </a>
            <?php endif; ?>
        </div>
        <p class="text-muted onsite-batch-requestors-note">
            These students share the same multi-requestor onsite batch.
            Batch total: <strong><?= e(formatMoney($batchTotal)) ?></strong>
        </p>
        <div class="table-responsive">
            <table class="data-table data-table-responsive onsite-batch-requestors-table">
                <thead>
                    <tr>
                        <th>Requestor</th>
                        <th>Request #</th>
                        <th>Payment Code</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <?php if ($showLinks): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $member): ?>
                        <?php
                        $memberId = (int) ($member['request_id'] ?? 0);
                        $isCurrent = $currentRequestId > 0 && $memberId === $currentRequestId;
                        $name = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                        $courseYear = trim(
                            (string) ($member['course'] ?? '')
                            . (!empty($member['year_level']) ? ' · ' . $member['year_level'] : '')
                        );
                        ?>
                        <tr class="<?= $isCurrent ? 'is-current-batch-requestor' : '' ?>">
                            <td data-label="Requestor">
                                <strong><?= e($name !== '' ? $name : '—') ?></strong>
                                <?php if ($isCurrent): ?>
                                    <span class="badge badge-processing">Current</span>
                                <?php endif; ?>
                                <br><small class="text-muted"><?= e($member['student_id'] !== '' ? $member['student_id'] : 'No ID') ?></small>
                                <?php if ($courseYear !== ''): ?>
                                    <br><small class="text-muted"><?= e($courseYear) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Request #"><strong><?= e($member['request_number'] ?: '—') ?></strong></td>
                            <td data-label="Payment Code">
                                <?php if ($member['payment_code'] !== ''): ?>
                                    <strong><?= e($member['payment_code']) ?></strong>
                                    <?php if ($member['payment_status'] !== ''): ?>
                                        <br><small class="text-muted"><?= e(ucfirst($member['payment_status'])) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Amount"><?= e(formatMoney((float) ($member['total_amount'] ?? 0))) ?></td>
                            <td data-label="Status"><?= statusBadge((string) ($member['status'] ?? '')) ?></td>
                            <?php if ($showLinks): ?>
                                <td data-label="Action">
                                    <?php if ($memberId > 0 && !$isCurrent): ?>
                                        <a href="<?= e($viewBaseUrl . (str_contains($viewBaseUrl, '?') ? '&' : '?') . 'id=' . $memberId) ?>"
                                           class="btn btn-sm btn-outline">View</a>
                                    <?php elseif ($isCurrent): ?>
                                        <span class="text-muted">Viewing</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
    return (string) ob_get_clean();
}

/**
 * Shared document / purpose / fee summary for a multi-student batch slip.
 *
 * @param list<array> $slips
 * @return array{
 *   document_summary:string,
 *   purpose:string,
 *   copy_type:string,
 *   enrollment_status:string,
 *   requestor_count:int,
 *   batch_total:float,
 *   requires_clearance:bool,
 *   clearance_complete:bool,
 *   printable_ids:list<int>,
 *   first_request_number:string
 * }
 */
function summarizeOnsiteBatchSlips(array $slips): array {
    $first = $slips[0] ?? [];
    $request = $first['request'] ?? [];
    $items = $first['items'] ?? [];

    $documentSummary = formatRequestItemsSummary($items, 0);
    if ($documentSummary === '—' && !empty($request['document_name'])) {
        $documentSummary = (string) $request['document_name'] . formatRequestItemTermSuffix($request);
    }
    if ($documentSummary === '—') {
        $documentSummary = 'Requested credentials';
    }

    $purpose = purposeLabel((string) ($request['purpose'] ?? ''));
    if (!empty($request['purpose_other'])) {
        $purpose .= ' — ' . $request['purpose_other'];
    }
    $torSpecificPurpose = trim((string) ($request['tor_specific_purpose'] ?? ''));
    if ($torSpecificPurpose !== '') {
        $purpose .= ' · TOR: ' . $torSpecificPurpose;
    }

    $batchTotal = 0.0;
    $printableIds = [];
    $requiresClearance = false;
    $clearanceComplete = true;
    foreach ($slips as $slip) {
        $batchTotal += (float) ($slip['amount'] ?? 0);
        if (!empty($slip['payment_code'])) {
            $printableIds[] = (int) ($slip['request']['id'] ?? 0);
        }
        if (!empty($slip['requires_clearance'])) {
            $requiresClearance = true;
            if (empty($slip['clearance_complete'])) {
                $clearanceComplete = false;
            }
        }
    }

    return [
        'document_summary' => $documentSummary,
        'purpose' => $purpose,
        'copy_type' => copyRequestTypeLabel($request['copy_request_type'] ?? null),
        'enrollment_status' => enrollmentStatusLabel($request['enrollment_status'] ?? null),
        'requestor_count' => count($slips),
        'batch_total' => $batchTotal,
        'requires_clearance' => $requiresClearance,
        'clearance_complete' => $clearanceComplete,
        'printable_ids' => array_values(array_filter($printableIds)),
        'first_request_number' => (string) ($request['request_number'] ?? 'onsite-batch'),
    ];
}

function onsiteBatchSlipQuery(array $requestIds, string $layout = 'separate', bool $autoPrint = false): string {
    $query = 'ids=' . implode(',', $requestIds);
    if ($layout === 'combined') {
        $query .= '&layout=combined';
    }
    if ($autoPrint) {
        $query .= '&print=1';
    }
    return $query;
}

/**
 * Render every slip in a multi-student batch, one per printed page.
 *
 * @param list<array> $slips
 */
function renderOnsiteRequestSlipBatchDocument(array $slips, bool $autoPrint = false): void {
    $slipCount = count($slips);
    $printableIds = summarizeOnsiteBatchSlips($slips)['printable_ids'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onsite Request Slips — <?= $slipCount ?> requestor<?= $slipCount === 1 ? '' : 's' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/print.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="onsite-slip-page onsite-slip-batch-page<?= $autoPrint ? ' auto-print' : '' ?>">
    <div class="onsite-slip-toolbar regdum-slip-toolbar no-print">
        <a href="<?= APP_URL ?>/registrar/new-onsite-request.php" class="btn btn-outline btn-sm">
            <i class="fas fa-plus"></i> New Onsite Request
        </a>
        <div class="onsite-slip-toolbar-actions regdum-slip-toolbar-actions">
            <span class="onsite-slip-batch-count"><?= $slipCount ?> slip<?= $slipCount === 1 ? '' : 's' ?></span>
            <?php if ($printableIds !== []): ?>
                <a href="<?= APP_URL ?>/registrar/onsite-request-slip.php?<?= e(onsiteBatchSlipQuery($printableIds, 'combined')) ?>"
                   class="btn btn-outline btn-sm">
                    <i class="fas fa-file-alt"></i> Combined Slip
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> Print All Slips
            </button>
        </div>
    </div>

    <?php foreach ($slips as $index => $data): ?>
        <?php renderOnsiteRequestSlipSheetHtml($data, 'onsiteRequestSlipSheet' . ($index + 1)); ?>
    <?php endforeach; ?>

    <script>
    if (document.body.classList.contains('auto-print')) {
        window.addEventListener('load', function () {
            window.setTimeout(function () { window.print(); }, 400);
        });
    }
    </script>
</body>
</html>
    <?php
}

/**
 * One 4.25 × 13 in slip covering every requestor in a multi-student batch.
 *
 * @param list<array> $slips
 */
function renderOnsiteRequestCombinedSlipSheetHtml(array $slips): void {
    $summary = summarizeOnsiteBatchSlips($slips);
    $requestorCount = (int) $summary['requestor_count'];
    ?>
    <article class="onsite-slip-sheet onsite-combined-slip-sheet regdum-slip-sheet" id="onsiteRequestSlipSheet"
        data-request-number="<?= e($summary['first_request_number'] . '-batch') ?>"
        data-slip-width="4.25"
        data-slip-height="13">
        <header class="onsite-slip-top regdum-slip-top">
            <img src="<?= e(APP_LOGO) ?>" alt="<?= e(APP_NAME) ?>" class="app-logo app-logo-claim onsite-slip-logo regdum-slip-logo">
            <div class="onsite-slip-brand regdum-slip-brand">
                <p class="onsite-slip-office regdum-slip-office"><?= e(APP_NAME) ?></p>
                <p class="onsite-slip-subtitle regdum-slip-subtitle"><?= e(APP_TAGLINE) ?></p>
            </div>
            <h1 class="onsite-slip-heading regdum-slip-heading">Onsite Batch Request Slip</h1>
        </header>

        <div class="onsite-combined-meta">
            <div>
                <span class="onsite-combined-meta-label">Requestors</span>
                <strong><?= $requestorCount ?> student<?= $requestorCount === 1 ? '' : 's' ?></strong>
            </div>
            <div>
                <span class="onsite-combined-meta-label">Documents</span>
                <strong><?= e($summary['document_summary']) ?></strong>
            </div>
            <div>
                <span class="onsite-combined-meta-label">Purpose</span>
                <strong><?= e($summary['purpose']) ?></strong>
            </div>
            <div>
                <span class="onsite-combined-meta-label">Request Type</span>
                <strong><?= e($summary['copy_type']) ?></strong>
            </div>
            <div>
                <span class="onsite-combined-meta-label">Enrollment Status</span>
                <strong><?= e($summary['enrollment_status']) ?></strong>
            </div>
            <div>
                <span class="onsite-combined-meta-label">Batch Total</span>
                <strong><?= formatMoney((float) $summary['batch_total']) ?></strong>
            </div>
        </div>

        <table class="onsite-combined-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Requestor</th>
                    <th>Code</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slips as $index => $slip): ?>
                    <?php
                    $request = $slip['request'] ?? [];
                    $paymentCode = (string) ($slip['payment_code'] ?? '');
                    $requestorMeta = trim(($request['student_id'] ?? '') . ' · ' . ($request['request_number'] ?? ''), ' ·');
                    ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td>
                            <strong><?= e(studentRecordName($request)) ?></strong>
                            <?php if ($requestorMeta !== ''): ?>
                                <small><?= e($requestorMeta) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="onsite-combined-code"><?= e($paymentCode !== '' ? $paymentCode : '—') ?></td>
                        <td><?= formatMoney((float) ($slip['amount'] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!empty($summary['requires_clearance'])): ?>
            <p class="onsite-slip-note regdum-slip-note">
                Online clearance is <?= !empty($summary['clearance_complete']) ? 'complete' : 'required before cashier payment' ?>
                for this batch.
            </p>
        <?php endif; ?>

        <p class="onsite-slip-note regdum-slip-note">
            Present this slip at the Cashier. Each requestor has a separate 6-digit payment code — verify one code at a time.
            Track any request at <?= e(publicOnsiteTrackingUrl()) ?>
        </p>

        <footer class="onsite-slip-footer regdum-slip-footer">
            Generated <?= formatDateTime(date('Y-m-d H:i:s')) ?>
        </footer>
    </article>
    <?php
}

/**
 * @param list<array> $slips
 */
function renderOnsiteRequestCombinedSlipDocument(array $slips, bool $autoPrint = false): void {
    $summary = summarizeOnsiteBatchSlips($slips);
    $printableIds = $summary['printable_ids'];
    $slipCount = (int) $summary['requestor_count'];
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Onsite Batch Request Slip — <?= $slipCount ?> requestor<?= $slipCount === 1 ? '' : 's' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/print.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="onsite-slip-page onsite-slip-combined-page<?= $autoPrint ? ' auto-print' : '' ?>">
    <div class="onsite-slip-toolbar regdum-slip-toolbar no-print">
        <a href="<?= APP_URL ?>/registrar/onsite-request-batch.php?ids=<?= e(implode(',', $printableIds)) ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-list"></i> Batch Summary
        </a>
        <div class="onsite-slip-toolbar-actions regdum-slip-toolbar-actions">
            <?php if ($printableIds !== []): ?>
                <a href="<?= APP_URL ?>/registrar/onsite-request-slip.php?<?= e(onsiteBatchSlipQuery($printableIds, 'separate')) ?>"
                   class="btn btn-outline btn-sm">
                    <i class="fas fa-copy"></i> Individual Slips
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-outline btn-sm" data-onsite-slip-download="png">
                <i class="fas fa-image"></i> Download Image
            </button>
            <button type="button" class="btn btn-outline btn-sm" data-onsite-slip-download="pdf">
                <i class="fas fa-file-pdf"></i> Download PDF
            </button>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> Print Combined Slip
            </button>
        </div>
    </div>

    <?php renderOnsiteRequestCombinedSlipSheetHtml($slips); ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="<?= APP_URL ?>/assets/js/onsite-request-slip.js"></script>
</body>
</html>
    <?php
}

/**
 * Public onsite request tracking lookup by request number and/or 6-digit payment code.
 *
 * @return array{
 *   request:array,
 *   items:array,
 *   payment:?array,
 *   clearance_required:bool,
 *   clearance_progress:array,
 *   next_hint:string
 * }|null
 */
function lookupPublicOnsiteTracking(?string $requestNumber, ?string $paymentCode, ?string $studentId = null): ?array {
    ensureOnsiteRequestSchema();
    require_once __DIR__ . '/request-items.php';
    require_once __DIR__ . '/compliance.php';
    require_once __DIR__ . '/clearance.php';
    require_once __DIR__ . '/payments.php';

    $requestNumber = strtoupper(trim((string) $requestNumber));
    $paymentCode = preg_replace('/\D+/', '', (string) $paymentCode) ?? '';
    $studentId = trim((string) $studentId);

    if ($requestNumber === '' && $paymentCode === '') {
        return null;
    }

    $db = getDB();
    $params = [];
    $where = ["r.request_channel = 'onsite'"];

    if ($paymentCode !== '') {
        if (!preg_match('/^\d{6}$/', $paymentCode)) {
            return null;
        }
        $where[] = "EXISTS (
            SELECT 1 FROM payments p
            WHERE p.request_id = r.id
              AND p.payment_method = 'onsite_payment'
              AND p.reference_number = ?
        )";
        $params[] = $paymentCode;
    }

    if ($requestNumber !== '') {
        $where[] = 'r.request_number = ?';
        $params[] = $requestNumber;
    }

    if ($studentId !== '') {
        $where[] = 'u.student_id = ?';
        $params[] = $studentId;
    }

    $sql = 'SELECT r.*,
            u.first_name, u.last_name, u.middle_name, u.student_id, u.email, u.phone,
            sp.course, sp.year_level, sp.enrollment_status
        FROM requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY r.id DESC
        LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $request = $stmt->fetch();
    if (!$request) {
        return null;
    }

    $requestId = (int) $request['id'];
    $paymentStmt = $db->prepare("SELECT * FROM payments
        WHERE request_id = ?
        ORDER BY CASE WHEN payment_method = 'onsite_payment' THEN 0 ELSE 1 END, created_at DESC
        LIMIT 1");
    $paymentStmt->execute([$requestId]);
    $payment = $paymentStmt->fetch() ?: null;

    $clearanceRequired = hasAssignedRequirement($requestId, 'online_clearance');
    $clearanceProgress = $clearanceRequired
        ? getClearanceProgress($requestId)
        : ['total' => 0, 'cleared' => 0, 'onHold' => 0, 'pending' => 0];

    return [
        'request' => $request,
        'items' => getRequestItems($requestId),
        'payment' => $payment,
        'clearance_required' => $clearanceRequired,
        'clearance_progress' => $clearanceProgress,
        'next_hint' => publicOnsiteTrackingNextHint($request, $payment, $clearanceRequired, $clearanceProgress),
    ];
}

function publicOnsiteTrackingNextHint(array $request, ?array $payment, bool $clearanceRequired, array $clearanceProgress): string {
    $status = (string) ($request['status'] ?? '');
    $paymentStatus = (string) ($payment['status'] ?? '');

    if ($status === 'completed') {
        return 'Your documents are ready / released. Bring a valid ID when claiming at the Registrar.';
    }
    if ($status === 'rejected') {
        return 'This request was rejected. Please visit the Registrar office for assistance.';
    }
    if ($status === 'ready_for_pickup' || $status === 'shipped') {
        return 'Your documents are ready for pickup. Bring your request slip and a valid ID.';
    }
    if (in_array($status, ['processing', 'payment_verified'], true)) {
        return 'Payment is verified and your documents are being processed. Check back for updates.';
    }
    if ($clearanceRequired && (int) ($clearanceProgress['cleared'] ?? 0) < (int) ($clearanceProgress['total'] ?? 0)) {
        $cleared = (int) ($clearanceProgress['cleared'] ?? 0);
        $total = (int) ($clearanceProgress['total'] ?? 0);
        $onHold = (int) ($clearanceProgress['onHold'] ?? 0);
        $hint = 'Complete online clearance at all offices before paying at the Cashier (' . $cleared . '/' . $total . ' cleared).';
        if ($onHold > 0) {
            $hint .= ' One or more offices placed your clearance on hold — visit those offices for guidance.';
        }
        return $hint;
    }
    if ($paymentStatus === 'pending' || $paymentStatus === '') {
        $code = (string) ($payment['reference_number'] ?? '');
        return $code !== ''
            ? 'Present your slip and pay at the Cashier using payment code ' . $code . '.'
            : 'Proceed to the Cashier to pay for this request.';
    }
    if ($paymentStatus === 'rejected') {
        return 'Payment was rejected. Visit the Cashier or Registrar for the next step.';
    }

    return 'Your request is in progress. Keep your request slip for reference.';
}

function publicOnsiteTrackingUrl(?string $requestNumber = null, ?string $paymentCode = null): string {
    $params = array_filter([
        'ref' => $requestNumber ?: null,
        'code' => $paymentCode ?: null,
    ]);
    return APP_URL . '/track.php' . ($params ? '?' . http_build_query($params) : '');
}
