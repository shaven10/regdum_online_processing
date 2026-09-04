<?php

require_once __DIR__ . '/xlsx.php';

function enrolmentReportTemplateHeaders(): array {
    return [
        'No.',
        'Family Name',
        'Given Name',
        'Middle Name',
        'Home Address',
        'Sex',
        'CS',
        'Birthdate',
        'Birth Place',
        'Contact Person',
        'Relationship',
        'Contact #',
        'Contact Address',
        'Course',
        'Year',
        'Major',
        'Mobile #',
        'Email Address',
        'ID No.',
    ];
}

function enrolmentReportColumnAliases(): array {
    return [
        'family name'      => 'last_name',
        'last name'        => 'last_name',
        'surname'          => 'last_name',
        'given name'       => 'first_name',
        'first name'       => 'first_name',
        'middle name'      => 'middle_name',
        'home address'     => 'address',
        'address'          => 'address',
        'sex'              => 'sex',
        'gender'           => 'sex',
        'cs'               => 'civil_status',
        'civil status'     => 'civil_status',
        'birthdate'        => 'birth_date',
        'birth date'       => 'birth_date',
        'date of birth'    => 'birth_date',
        'dob'              => 'birth_date',
        'birth place'      => 'birth_place',
        'birthplace'       => 'birth_place',
        'place of birth'   => 'birth_place',
        'contact person'   => 'emergency_contact',
        'guardian'         => 'emergency_contact',
        'relationship'     => 'emergency_relationship',
        'contact'          => 'emergency_phone',
        'contact no'       => 'emergency_phone',
        'contact number'   => 'emergency_phone',
        'contact num'      => 'emergency_phone',
        'contact address'  => 'emergency_address',
        'course'           => 'course',
        'program'          => 'course',
        'year'             => 'year_level',
        'year level'       => 'year_level',
        'yr'               => 'year_level',
        'major'            => 'major',
        'mobile'           => 'phone',
        'mobile no'        => 'phone',
        'mobile number'    => 'phone',
        'cellphone'        => 'phone',
        'email address'    => 'email',
        'email'            => 'email',
        'e mail'           => 'email',
        'id no'            => 'student_id',
        'id number'        => 'student_id',
        'student id'       => 'student_id',
        'student no'       => 'student_id',
        'student number'   => 'student_id',
        'id'               => 'student_id',
    ];
}

function normalizeImportHeaderLabel(string $header): string {
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
    $header = strtolower(trim($header));
    $header = str_replace(['.', '#', '/', '-', '_', '(', ')'], ' ', $header);
    $header = preg_replace('/\s+/', ' ', $header) ?? $header;
    return trim($header);
}

function mapEnrolmentReportHeaderRow(array $cells): array {
    $aliases = enrolmentReportColumnAliases();
    $map = [];

    foreach ($cells as $index => $label) {
        $normalized = normalizeImportHeaderLabel((string) $label);
        if ($normalized === '' || in_array($normalized, ['no', 'n', 'number'], true)) {
            continue;
        }
        if (isset($aliases[$normalized])) {
            $map[$aliases[$normalized]] = (int) $index;
        }
    }

    return $map;
}

function findEnrolmentReportHeader(array $rows): array {
    $scanLimit = min(count($rows), 15);
    for ($i = 0; $i < $scanLimit; $i++) {
        $map = mapEnrolmentReportHeaderRow($rows[$i] ?? []);
        if (isset($map['last_name'], $map['first_name']) && (isset($map['student_id']) || isset($map['email']))) {
            return ['index' => $i, 'map' => $map];
        }
    }

    throw new InvalidArgumentException(
        'This file does not match the Enrolment Report template. The header row must include Family Name, Given Name, and ID No.'
    );
}

function parseEnrolmentReportMeta(array $rows, int $headerIndex): array {
    $meta = [
        'title' => '',
        'academic_year' => '',
        'semester' => '',
    ];

    $scan = array_slice($rows, 0, $headerIndex);
    foreach ($scan as $row) {
        $text = trim(implode(' ', array_map('strval', $row)));
        if ($text === '') {
            continue;
        }
        if ($meta['title'] === '' && preg_match('/enrol?ment\s+report/i', $text)) {
            $meta['title'] = $text;
        }

        if ($meta['semester'] === '') {
            if (preg_match('/1st|first/i', $text) && preg_match('/sem/i', $text)) {
                $meta['semester'] = '1st_semester';
            } elseif (preg_match('/2nd|second/i', $text) && preg_match('/sem/i', $text)) {
                $meta['semester'] = '2nd_semester';
            } elseif (preg_match('/summer|midyear|mid-year/i', $text)) {
                $meta['semester'] = 'summer';
            }
        }

        if ($meta['academic_year'] === '' && preg_match('/(?:s\.?\s*y\.?\s*)?(20\d{2})\s*[-–\/]\s*(20\d{2})/i', $text, $match)) {
            $meta['academic_year'] = $match[1] . '-' . $match[2];
        }
    }

    return $meta;
}

function rowLooksEmpty(array $row): bool {
    foreach ($row as $cell) {
        if (trim((string) $cell) !== '') {
            return false;
        }
    }
    return true;
}

function mappedImportValue(array $row, array $map, string $field): string {
    if (!isset($map[$field])) {
        return '';
    }
    $index = $map[$field];
    return trim((string) ($row[$index] ?? ''));
}

function normalizeImportBlank(string $value): string {
    $value = trim($value);
    if ($value === '' || $value === '-' || $value === '—' || strcasecmp($value, 'n/a') === 0) {
        return '';
    }
    return $value;
}

function normalizeImportIdNumber(string $value): string {
    $value = normalizeImportBlank($value);
    if ($value === '') {
        return '';
    }

    $value = normalizeExcelNumericCell($value);
    $value = preg_replace('/\s+/', '', $value) ?? $value;
    $value = rtrim($value, '.');

    if (preg_match('/^\d+\.0+$/', $value)) {
        $value = preg_replace('/\.0+$/', '', $value) ?? $value;
    }

    return $value;
}

function normalizeImportPhoneNumber(string $value): string {
    $value = normalizeImportIdNumber($value);
    if ($value === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits === '') {
        return $value;
    }

    if (strlen($digits) === 10 && $digits[0] === '9') {
        $digits = '0' . $digits;
    } elseif (strlen($digits) === 12 && str_starts_with($digits, '63')) {
        $digits = '0' . substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '63')) {
        $digits = '0' . substr($digits, 2);
    }

    return $digits;
}

function normalizeImportBirthDate(string $value): ?string {
    $value = normalizeImportBlank($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    if (is_numeric($value) && !preg_match('/^\d{8,}$/', $value)) {
        $fromSerial = excelSerialToDateString((float) $value);
        if ($fromSerial) {
            return $fromSerial;
        }
    }

    $formats = ['n/j/Y', 'n/j/y', 'm/d/Y', 'm/d/y', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'M d, Y', 'F d, Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat('!' . $format, $value);
        if (!$date) {
            continue;
        }
        $errors = DateTime::getLastErrors();
        if (!empty($errors['warning_count']) || !empty($errors['error_count'])) {
            continue;
        }

        $year = (int) $date->format('Y');
        if ($year < 100) {
            $year += $year <= ((int) date('y') + 1) ? 2000 : 1900;
            $date->setDate($year, (int) $date->format('n'), (int) $date->format('j'));
        }

        if ($year < 1940 || $year > ((int) date('Y'))) {
            continue;
        }

        return $date->format('Y-m-d');
    }

    $timestamp = strtotime($value);
    if ($timestamp) {
        $year = (int) date('Y', $timestamp);
        if ($year >= 1940 && $year <= (int) date('Y')) {
            return date('Y-m-d', $timestamp);
        }
    }

    return null;
}

function normalizeImportYearLevel(string $value): string {
    $value = normalizeImportBlank($value);
    if ($value === '') {
        return '';
    }

    $compact = strtoupper(preg_replace('/[\s.]+/', '', $value) ?? $value);
    $map = [
        'I' => '1st Year',
        '1' => '1st Year',
        '1ST' => '1st Year',
        '1STYEAR' => '1st Year',
        'FIRST' => '1st Year',
        'FIRSTYEAR' => '1st Year',
        'II' => '2nd Year',
        '2' => '2nd Year',
        '2ND' => '2nd Year',
        '2NDYEAR' => '2nd Year',
        'SECOND' => '2nd Year',
        'SECONDYEAR' => '2nd Year',
        'III' => '3rd Year',
        '3' => '3rd Year',
        '3RD' => '3rd Year',
        '3RDYEAR' => '3rd Year',
        'THIRD' => '3rd Year',
        'THIRDYEAR' => '3rd Year',
        'IV' => '4th Year',
        '4' => '4th Year',
        '4TH' => '4th Year',
        '4THYEAR' => '4th Year',
        'FOURTH' => '4th Year',
        'FOURTHYEAR' => '4th Year',
        'V' => '5th Year',
        '5' => '5th Year',
        '5TH' => '5th Year',
        '5THYEAR' => '5th Year',
    ];

    return $map[$compact] ?? $value;
}

function normalizeImportSex(string $value): string {
    $value = strtoupper(normalizeImportBlank($value));
    return match ($value) {
        'M', 'MALE' => 'M',
        'F', 'FEMALE' => 'F',
        default => $value,
    };
}

function normalizeImportCivilStatus(string $value): string {
    $value = normalizePersonName(normalizeImportBlank($value));
    return $value;
}

function normalizeImportEmail(string $value, string $studentId): array {
    $email = strtolower(normalizeImportBlank($value));
    $generated = false;

    if ($email === '') {
        $local = preg_replace('/[^a-zA-Z0-9]/', '', $studentId) ?: ('student' . substr(sha1($studentId . microtime()), 0, 8));
        $email = strtolower($local) . '@import.regdum.edu.ph';
        $generated = true;
    }

    return [$email, $generated];
}

function defaultImportAcademicYear(): string {
    require_once __DIR__ . '/academic-term.php';
    return getActiveSchoolYear();
}

function defaultImportSemester(): string {
    require_once __DIR__ . '/academic-term.php';
    return getActiveSemester();
}

function resolveImportAcademicProgram(string $course): ?array {
    $course = normalizeImportBlank($course);
    if ($course === '') {
        return null;
    }

    static $programs = null;
    if ($programs === null) {
        $programs = getAllAcademicPrograms();
    }

    $needle = strtoupper(preg_replace('/\s+/', '', $course) ?? $course);

    foreach ($programs as $program) {
        $code = strtoupper(preg_replace('/\s+/', '', (string) ($program['code'] ?? '')) ?? '');
        if ($code !== '' && $code === $needle) {
            return $program;
        }
    }

    foreach ($programs as $program) {
        $name = strtoupper(preg_replace('/\s+/', '', (string) ($program['name'] ?? '')) ?? '');
        if ($name !== '' && ($name === $needle || str_contains($name, $needle) || str_contains($needle, $name))) {
            return $program;
        }
    }

    return null;
}

function studentRoleId(): int {
    static $id = null;
    if ($id === null) {
        $id = (int) getDB()->query("SELECT id FROM roles WHERE name = 'student'")->fetchColumn();
        if ($id <= 0) {
            $id = 1;
        }
    }
    return $id;
}

function findUserByStudentIdOrEmail(string $studentId, string $email): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT u.*, r.name AS role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE (u.student_id = ? AND u.student_id IS NOT NULL AND u.student_id != "")
           OR (u.email = ?)
        ORDER BY CASE WHEN u.student_id = ? THEN 0 ELSE 1 END
        LIMIT 1');
    $stmt->execute([$studentId, $email, $studentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function importInitialPassword(string $studentId): string {
    $password = $studentId;
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $password = str_pad($password, PASSWORD_MIN_LENGTH, 'X');
    }
    return $password;
}

function isValidStudentImportProgressToken(string $token): bool {
    return (bool) preg_match('/^[a-f0-9]{32}$/', $token);
}

function studentImportProgressFile(string $token): string {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'regdum_student_import_' . $token . '.json';
}

function writeStudentImportProgress(string $token, int $userId, array $data): void {
    if ($userId <= 0 || !isValidStudentImportProgressToken($token)) {
        return;
    }

    $payload = array_merge([
        'user_id'    => $userId,
        'status'     => 'importing',
        'percent'    => 0,
        'processed'  => 0,
        'total'      => 0,
        'created'    => 0,
        'updated'    => 0,
        'skipped'    => 0,
        'failed'     => 0,
        'message'    => '',
        'error'      => '',
        'updated_at' => time(),
    ], $data);

    $payload['user_id'] = $userId;
    $payload['percent'] = max(0, min(100, (int) $payload['percent']));
    $payload['updated_at'] = time();

    @file_put_contents(studentImportProgressFile($token), json_encode($payload), LOCK_EX);
}

function readStudentImportProgress(string $token, int $userId): ?array {
    if ($userId <= 0 || !isValidStudentImportProgressToken($token)) {
        return null;
    }

    $path = studentImportProgressFile($token);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || (int) ($data['user_id'] ?? 0) !== $userId) {
        return null;
    }

    return $data;
}

function clearStudentImportProgress(string $token): void {
    if (!isValidStudentImportProgressToken($token)) {
        return;
    }
    $path = studentImportProgressFile($token);
    if (is_file($path)) {
        @unlink($path);
    }
}

function importActiveStudentsFromUpload(array $file, array $options): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Please choose an Enrolment Report Excel file to import.');
    }

    $maxBytes = 10 * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        throw new InvalidArgumentException('The file is too large. Maximum size is 10 MB.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension === 'xls') {
        throw new InvalidArgumentException('Please save the workbook as .xlsx (Excel 2007 or later) and try again.');
    }
    if (!in_array($extension, ['xlsx', 'csv'], true)) {
        throw new InvalidArgumentException('Only .xlsx Excel files are accepted for enrolment imports.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('The uploaded file could not be read.');
    }

    $progressToken = (string) ($options['progress_token'] ?? '');
    $progressUserId = (int) ($options['progress_user_id'] ?? 0);
    writeStudentImportProgress($progressToken, $progressUserId, [
        'status'  => 'reading',
        'percent' => 8,
        'message' => 'Reading the Enrolment Report…',
    ]);

    $rows = readSpreadsheetRows($tmp, $extension);
    return importActiveStudentsFromRows($rows, $options);
}

function importActiveStudentsFromRows(array $rows, array $options): array {
    ensureAcademicProgramsSchema();
    ensureCampusesSchema();
    ensureEnrollmentStatuses();
    ensureStudentAcademicTermFields();
    ensureStudentImportProfileFields();

    $header = findEnrolmentReportHeader($rows);
    $meta = parseEnrolmentReportMeta($rows, $header['index']);
    $map = $header['map'];

    $academicYear = trim((string) ($options['academic_year'] ?? '')) ?: ($meta['academic_year'] ?: defaultImportAcademicYear());
    $semester = trim((string) ($options['semester'] ?? ''));
    if (!array_key_exists($semester, semesterOptions())) {
        $semester = $meta['semester'] !== '' && array_key_exists($meta['semester'], semesterOptions())
            ? $meta['semester']
            : defaultImportSemester();
    }

    $campusId = (int) ($options['origin_campus_id'] ?? 0);
    $campus = $campusId > 0 ? getCampusById($campusId) : null;
    if (!$campus) {
        $campuses = getActiveCampuses();
        $campus = $campuses[0] ?? null;
        $campusId = $campus ? (int) $campus['id'] : 0;
    }

    $updateExisting = !empty($options['update_existing']);
    $progressToken = (string) ($options['progress_token'] ?? '');
    $progressUserId = (int) ($options['progress_user_id'] ?? 0);

    $result = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
        'total_rows' => 0,
        'academic_year' => $academicYear,
        'semester' => $semester,
        'warnings' => [],
        'errors' => [],
        'unmatched_courses' => [],
    ];

    $dataRowIndexes = [];
    for ($i = $header['index'] + 1; $i < count($rows); $i++) {
        if (!rowLooksEmpty($rows[$i] ?? [])) {
            $dataRowIndexes[] = $i;
        }
    }
    $totalRows = count($dataRowIndexes);
    $progressStep = $totalRows > 0 ? max(1, (int) floor($totalRows / 80)) : 1;

    $reportProgress = static function (array $extra) use ($progressToken, $progressUserId, $totalRows, &$result): void {
        writeStudentImportProgress($progressToken, $progressUserId, array_merge([
            'status'    => 'importing',
            'total'     => $totalRows,
            'created'   => $result['created'],
            'updated'   => $result['updated'],
            'skipped'   => $result['skipped'],
            'failed'    => $result['failed'],
        ], $extra));
    };

    $reportProgress([
        'status'    => 'importing',
        'percent'   => $totalRows > 0 ? 12 : 100,
        'processed' => 0,
        'message'   => $totalRows > 0
            ? ('Preparing to import ' . $totalRows . ' student record' . ($totalRows === 1 ? '' : 's') . '…')
            : 'No student rows were found in the file.',
    ]);

    $db = getDB();
    $roleId = studentRoleId();
    $seenIds = [];
    $seenEmails = [];
    $processed = 0;

    foreach ($dataRowIndexes as $i) {
        $row = $rows[$i] ?? [];
        $excelRow = $i + 1;
        $result['total_rows']++;
        $processed++;

        $lastName = normalizePersonName(mappedImportValue($row, $map, 'last_name'));
        $firstName = normalizePersonName(mappedImportValue($row, $map, 'first_name'));
        $middleName = normalizePersonName(mappedImportValue($row, $map, 'middle_name'));
        $studentId = substr(normalizeImportIdNumber(mappedImportValue($row, $map, 'student_id')), 0, 50);
        $rawEmail = mappedImportValue($row, $map, 'email');
        $courseCode = normalizeImportBlank(mappedImportValue($row, $map, 'course'));
        $yearLevel = normalizeImportYearLevel(mappedImportValue($row, $map, 'year_level'));
        $major = normalizeImportBlank(mappedImportValue($row, $map, 'major'));
        $address = normalizeImportBlank(mappedImportValue($row, $map, 'address'));
        $sex = normalizeImportSex(mappedImportValue($row, $map, 'sex'));
        $civilStatus = normalizeImportCivilStatus(mappedImportValue($row, $map, 'civil_status'));
        $birthPlace = normalizeImportBlank(mappedImportValue($row, $map, 'birth_place'));
        $emergencyContact = normalizePersonName(mappedImportValue($row, $map, 'emergency_contact'));
        $emergencyRelationship = normalizeImportBlank(mappedImportValue($row, $map, 'emergency_relationship'));
        $emergencyAddress = normalizeImportBlank(mappedImportValue($row, $map, 'emergency_address'));
        $phone = substr(normalizeImportPhoneNumber(mappedImportValue($row, $map, 'phone')), 0, 20);
        $emergencyPhone = substr(normalizeImportPhoneNumber(mappedImportValue($row, $map, 'emergency_phone')), 0, 20);
        $birthDate = normalizeImportBirthDate(mappedImportValue($row, $map, 'birth_date'));

        $label = trim($lastName . ', ' . $firstName);
        if ($label === ',') {
            $label = $studentId !== '' ? $studentId : ('Row ' . $excelRow);
        }

        if ($processed === 1 || $processed === $totalRows || ($processed % $progressStep) === 0) {
            $percent = 12 + (int) floor(($processed / max(1, $totalRows)) * 86);
            $reportProgress([
                'percent'   => min(98, $percent),
                'processed' => $processed,
                'message'   => 'Importing ' . $label . '…',
            ]);
        }

        if ($lastName === '' || $firstName === '') {
            $result['failed']++;
            $result['errors'][] = 'Row ' . $excelRow . ': Family Name and Given Name are required.';
            continue;
        }

        if ($studentId === '') {
            $result['failed']++;
            $result['errors'][] = 'Row ' . $excelRow . ' (' . $label . '): ID No. is required.';
            continue;
        }

        [$email, $emailGenerated] = normalizeImportEmail($rawEmail, $studentId);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result['failed']++;
            $result['errors'][] = 'Row ' . $excelRow . ' (' . $label . '): Invalid email address.';
            continue;
        }

        if (isset($seenIds[$studentId])) {
            $result['failed']++;
            $result['errors'][] = 'Row ' . $excelRow . ' (' . $label . '): Duplicate ID No. in this file.';
            continue;
        }
        if (isset($seenEmails[$email])) {
            $result['failed']++;
            $result['errors'][] = 'Row ' . $excelRow . ' (' . $label . '): Duplicate email in this file.';
            continue;
        }
        $seenIds[$studentId] = true;
        $seenEmails[$email] = true;

        $program = resolveImportAcademicProgram($courseCode);
        $courseName = $program['name'] ?? ($courseCode !== '' ? $courseCode : null);
        $courseId = $program ? (int) $program['id'] : null;
        if ($courseCode !== '' && !$program) {
            $result['unmatched_courses'][$courseCode] = ($result['unmatched_courses'][$courseCode] ?? 0) + 1;
        }

        if ($emailGenerated) {
            $result['warnings'][] = 'Row ' . $excelRow . ' (' . $label . '): No email in the file. A placeholder email was assigned.';
        }

        $profile = [
            'course' => $courseName,
            'course_id' => $courseId,
            'year_level' => $yearLevel !== '' ? $yearLevel : null,
            'current_academic_year' => $academicYear !== '' ? $academicYear : null,
            'current_semester' => $semester,
            'major' => $major !== '' ? $major : null,
            'birth_date' => $birthDate,
            'sex' => $sex !== '' ? $sex : null,
            'civil_status' => $civilStatus !== '' ? $civilStatus : null,
            'birth_place' => $birthPlace !== '' ? $birthPlace : null,
            'address' => $address !== '' ? $address : null,
            'emergency_contact' => $emergencyContact !== '' ? $emergencyContact : null,
            'emergency_relationship' => $emergencyRelationship !== '' ? $emergencyRelationship : null,
            'emergency_phone' => $emergencyPhone !== '' ? $emergencyPhone : null,
            'emergency_address' => $emergencyAddress !== '' ? $emergencyAddress : null,
            'enrollment_status' => 'enrolled',
            'origin_campus_id' => $campusId > 0 ? $campusId : null,
        ];

        try {
            $existing = findUserByStudentIdOrEmail($studentId, $email);
            if ($existing) {
                if (($existing['role_name'] ?? '') !== 'student') {
                    $result['failed']++;
                    $result['errors'][] = 'Row ' . $excelRow . ' (' . $label . '): ID or email belongs to a non-student account.';
                    continue;
                }

                if (!$updateExisting) {
                    $result['skipped']++;
                    continue;
                }

                $db->beginTransaction();
                try {
                    updateImportedStudentUser((int) $existing['id'], $email, $studentId, $firstName, $lastName, $middleName, $phone);
                    upsertImportedStudentProfile((int) $existing['id'], $profile);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }

                auditLog('import_update_active_student', 'users', (int) $existing['id'], null, [
                    'student_id' => $studentId,
                    'source' => 'enrolment_report',
                ]);
                $result['updated']++;
                continue;
            }

            $password = importInitialPassword($studentId);
            $db->beginTransaction();
            try {
                $db->prepare('INSERT INTO users (role_id, email, password, student_id, first_name, last_name, middle_name, phone, is_active, email_verified, privacy_consent_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())')
                   ->execute([
                       $roleId,
                       $email,
                       password_hash($password, PASSWORD_BCRYPT),
                       $studentId,
                       $firstName,
                       $lastName,
                       $middleName !== '' ? $middleName : null,
                       $phone !== '' ? $phone : null,
                   ]);
                $userId = (int) $db->lastInsertId();
                insertImportedStudentProfile($userId, $profile);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            auditLog('import_active_student', 'users', $userId, null, [
                'student_id' => $studentId,
                'source' => 'enrolment_report',
            ]);
            $result['created']++;
        } catch (PDOException $e) {
            $result['failed']++;
            $message = str_contains($e->getMessage(), 'Duplicate')
                ? 'A student with this ID or email already exists.'
                : 'Unable to save this record.';
            $result['errors'][] = 'Row ' . $excelRow . ' (' . $label . '): ' . $message;
        } catch (Throwable $e) {
            $result['failed']++;
            $result['errors'][] = 'Row ' . $excelRow . ' (' . $label . '): Unable to save this record.';
        }
    }

    if ($result['unmatched_courses'] !== []) {
        foreach ($result['unmatched_courses'] as $code => $count) {
            $result['warnings'][] = 'Course "' . $code . '" was not found in Courses & Programs (' . $count . ' student' . ($count === 1 ? '' : 's') . '). Add it under Settings so future requests can match correctly.';
        }
    }

    $result['errors'] = array_slice($result['errors'], 0, 100);
    $result['warnings'] = array_slice($result['warnings'], 0, 50);

    $reportProgress([
        'status'    => 'complete',
        'percent'   => 100,
        'processed' => $processed,
        'message'   => 'Import complete.',
    ]);

    auditLog('import_active_students', 'users', null, null, [
        'created' => $result['created'],
        'updated' => $result['updated'],
        'skipped' => $result['skipped'],
        'failed' => $result['failed'],
        'academic_year' => $academicYear,
        'semester' => $semester,
    ]);

    return $result;
}

function findUserIdByEmail(string $email): ?int {
    $stmt = getDB()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function updateImportedStudentUser(int $userId, string $email, string $studentId, string $firstName, string $lastName, string $middleName, string $phone): void {
    $db = getDB();
    $current = $db->prepare('SELECT email FROM users WHERE id = ?');
    $current->execute([$userId]);
    $currentEmail = (string) $current->fetchColumn();

    $emailToSet = $email;
    if (strcasecmp($currentEmail, $email) !== 0) {
        $taken = findUserIdByEmail($email);
        if ($taken && $taken !== $userId) {
            $emailToSet = $currentEmail;
        }
    }

    $db->prepare('UPDATE users
        SET email = ?, student_id = ?, first_name = ?, last_name = ?, middle_name = ?, phone = ?, is_active = 1
        WHERE id = ?')
       ->execute([
           $emailToSet,
           $studentId,
           $firstName,
           $lastName,
           $middleName !== '' ? $middleName : null,
           $phone !== '' ? $phone : null,
           $userId,
       ]);
}

function importedStudentProfileColumns(): array {
    return [
        'course', 'course_id', 'year_level', 'current_academic_year', 'current_semester', 'major',
        'birth_date', 'sex', 'civil_status', 'birth_place', 'address',
        'emergency_contact', 'emergency_relationship', 'emergency_phone', 'emergency_address',
        'enrollment_status', 'origin_campus_id',
    ];
}

function insertImportedStudentProfile(int $userId, array $profile): void {
    $columns = importedStudentProfileColumns();
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO student_profiles (user_id, ' . implode(', ', $columns) . ') VALUES (?, ' . $placeholders . ')';
    $params = [$userId];
    foreach ($columns as $column) {
        $params[] = $profile[$column] ?? null;
    }
    getDB()->prepare($sql)->execute($params);
}

function upsertImportedStudentProfile(int $userId, array $profile): void {
    $db = getDB();
    $exists = $db->prepare('SELECT user_id FROM student_profiles WHERE user_id = ?');
    $exists->execute([$userId]);
    if (!$exists->fetch()) {
        insertImportedStudentProfile($userId, $profile);
        return;
    }

    $columns = importedStudentProfileColumns();
    $assignments = implode(', ', array_map(static fn($column) => $column . ' = ?', $columns));
    $params = [];
    foreach ($columns as $column) {
        $params[] = $profile[$column] ?? null;
    }
    $params[] = $userId;
    $db->prepare('UPDATE student_profiles SET ' . $assignments . ' WHERE user_id = ?')->execute($params);
}

function buildEnrolmentReportTemplateBinary(?string $academicYear = null, ?string $semester = null): string {
    $academicYear = $academicYear ?: defaultImportAcademicYear();
    $semesterLabel = semesterLabel($semester ?: defaultImportSemester());

    $rows = [
        ['Enrolment Report'],
        [$semesterLabel . ', S.Y. ' . $academicYear],
        [],
        enrolmentReportTemplateHeaders(),
    ];

    return buildSimpleXlsxWorkbook('Enrolment Report', $rows);
}
