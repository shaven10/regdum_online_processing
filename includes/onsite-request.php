<?php

require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/request-items.php';
require_once __DIR__ . '/compliance.php';
require_once __DIR__ . '/clearance.php';
require_once __DIR__ . '/student.php';
require_once __DIR__ . '/campuses.php';
require_once __DIR__ . '/document-rules.php';

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

function searchStudentsForOnsiteRequest(string $search, int $limit = 20): array {
    $search = trim($search);
    if ($search === '') {
        return [];
    }

    $limit = max(1, min(50, $limit));
    $db = getDB();
    $like = '%' . $search . '%';
    $stmt = $db->prepare('SELECT u.id, u.student_id, u.first_name, u.last_name, u.middle_name, u.email, u.phone,
            sp.course, sp.year_level, sp.enrollment_status
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        WHERE u.role_id = 1
          AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_id LIKE ? OR u.email LIKE ?
               OR CONCAT(u.first_name, \' \', u.last_name) LIKE ?)
        ORDER BY u.last_name, u.first_name
        LIMIT ' . $limit);
    $stmt->execute([$like, $like, $like, $like, $like]);

    return $stmt->fetchAll();
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
    array $itemDrafts
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

    $stmt = $db->prepare('INSERT INTO requests (
        request_number, user_id, document_type_id, purpose, purpose_other, copy_request_type, copies, delivery_method,
        pickup_date, pickup_time, representative_name, representative_relationship, representative_phone,
        representative_id_number, total_amount, verification_code, notes, request_channel, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, 1, ?, NULL, NULL, NULL, NULL, NULL, NULL, ?, ?, ?, \'onsite\', ?)');
    $stmt->execute([
        $requestNumber,
        $studentUserId,
        $primaryDocumentTypeId,
        $payload['purpose'],
        $payload['purpose_other'] ?: null,
        $payload['copy_request_type'],
        'pickup',
        $batchTotal,
        generateVerificationCode(),
        $notes,
        $createdByUserId > 0 ? $createdByUserId : null,
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
    $db->prepare('INSERT INTO payments (request_id, amount, payment_method, reference_number, status) VALUES (?, ?, ?, ?, ?)')
       ->execute([$requestId, $amount, 'onsite_payment', $paymentCode, 'pending']);

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
            sp.course, sp.year_level, sp.enrollment_status
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
 * List onsite walk-in credential requests for registrar records.
 *
 * @return list<array<string,mixed>>
 */
function getOnsiteRequestsList(string $status = '', string $search = '', int $limit = 200): array {
    ensureOnsiteRequestSchema();
    ensureRequestItemsSchema();
    require_once __DIR__ . '/payments.php';
    require_once __DIR__ . '/compliance.php';
    require_once __DIR__ . '/clearance.php';

    $limit = max(1, min(500, $limit));
    $db = getDB();
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

    $sql = 'SELECT r.id, r.request_number, r.status, r.purpose, r.copy_request_type, r.total_amount,
                   r.created_at, r.created_by,
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
            ORDER BY r.created_at DESC
            LIMIT ' . $limit;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $requestId = (int) $row['id'];
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

    $documentSummary = formatRequestItemsSummary($items, 3);
    if ($documentSummary === '—' && !empty($request['document_name'])) {
        $documentSummary = (string) $request['document_name'];
    }

    return [
        ['Requestor', $studentName !== '' ? $studentName : '—'],
        ['Requestor ID', $request['student_id'] ?? '—'],
        ['Course / Year', $courseYear !== '' ? $courseYear : '—'],
        ['Documents', $documentSummary],
        ['Request Type', copyRequestTypeLabel($request['copy_request_type'] ?? null)],
        ['Purpose', purposeLabel($request['purpose'] ?? '') . (!empty($request['purpose_other']) ? ' — ' . $request['purpose_other'] : '')],
        ['Amount Due', formatMoney((float) $data['amount'])],
    ];
}

function renderOnsiteRequestSlipSheetHtml(array $data): void {
    $request = $data['request'];
    $paymentCode = (string) ($data['payment_code'] ?? '—');
    $rows = buildOnsiteRequestSlipRows($data);
    $requiresClearance = !empty($data['requires_clearance']);
    $clearanceComplete = !empty($data['clearance_complete']);
    ?>
    <article class="onsite-slip-sheet regdum-slip-sheet" id="onsiteRequestSlipSheet"
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
