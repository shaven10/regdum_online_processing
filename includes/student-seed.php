<?php

require_once __DIR__ . '/student-import.php';
require_once __DIR__ . '/programs.php';
require_once __DIR__ . '/campuses.php';
require_once __DIR__ . '/auth.php';

function sampleStudentSeedRecords(): array {
    $academicYear = defaultImportAcademicYear();

    return [
        [
            'student_id' => '2024-00001',
            'email' => 'juan.delacruz@student.regdum.edu.ph',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'middle_name' => 'Santos',
            'phone' => '09171234501',
            'program_code' => 'BSIT',
            'year_level' => '2nd Year',
            'section' => 'A',
            'sex' => 'M',
            'campus_code' => 'MAIN',
        ],
        [
            'student_id' => '2024-00002',
            'email' => 'maria.garcia@student.regdum.edu.ph',
            'first_name' => 'Maria',
            'last_name' => 'Garcia',
            'middle_name' => 'Lopez',
            'phone' => '09171234502',
            'program_code' => 'BSCS',
            'year_level' => '3rd Year',
            'section' => 'B',
            'sex' => 'F',
            'campus_code' => 'MAIN',
        ],
        [
            'student_id' => '2024-00003',
            'email' => 'pedro.reyes@student.regdum.edu.ph',
            'first_name' => 'Pedro',
            'last_name' => 'Reyes',
            'middle_name' => '',
            'phone' => '09171234503',
            'program_code' => 'BSBA',
            'year_level' => '1st Year',
            'section' => 'A',
            'sex' => 'M',
            'campus_code' => 'NORTH',
        ],
        [
            'student_id' => '2024-00004',
            'email' => 'ana.cruz@student.regdum.edu.ph',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'middle_name' => 'Santos',
            'phone' => '09171234504',
            'program_code' => 'BSN',
            'year_level' => '4th Year',
            'section' => 'C',
            'sex' => 'F',
            'campus_code' => 'SOUTH',
        ],
        [
            'student_id' => '2024-00005',
            'email' => 'miguel.tan@student.regdum.edu.ph',
            'first_name' => 'Miguel',
            'last_name' => 'Tan',
            'middle_name' => 'Rivera',
            'phone' => '09171234505',
            'program_code' => 'BSCPE',
            'year_level' => '2nd Year',
            'section' => 'B',
            'sex' => 'M',
            'campus_code' => 'MAIN',
        ],
    ];
}

/**
 * Pick one of the programs this installation actually offers.
 * The choice is derived from the sample code so each seed record lands on a
 * different program instead of piling everyone into the first one.
 */
function sampleStudentFallbackProgram(string $code): ?array {
    static $programs = null;
    if ($programs === null) {
        $programs = getDB()->query('SELECT * FROM academic_programs WHERE is_active = 1 ORDER BY id')->fetchAll();
    }
    if ($programs === []) {
        return null;
    }

    return $programs[abs(crc32($code)) % count($programs)];
}

function resolveSampleStudentProgram(string $code): ?array {
    ensureAcademicProgramsSchema();
    $stmt = getDB()->prepare('SELECT * FROM academic_programs WHERE code = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    // The sample records use generic program codes, which not every campus offers.
    return sampleStudentFallbackProgram($code);
}

function resolveSampleStudentCampus(string $code): ?array {
    ensureCampusesSchema();
    $stmt = getDB()->prepare('SELECT * FROM campuses WHERE code = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    static $fallback = null;
    if ($fallback === null) {
        $fallback = getDB()->query('SELECT * FROM campuses WHERE is_active = 1 ORDER BY id LIMIT 1')->fetch() ?: false;
    }

    return $fallback ?: null;
}

function seedSampleStudents(bool $skipExisting = true): array {
    ensurePrivacyConsentSchema();
    ensureEnrollmentStatuses();
    ensureStudentAcademicTermFields();
    ensureStudentImportProfileFields();
    ensureAcademicProgramsSchema();
    ensureCampusesSchema();

    $db = getDB();
    $roleId = studentRoleId();
    $academicYear = defaultImportAcademicYear();
    $semester = defaultImportSemester();

    $result = [
        'created' => 0,
        'skipped' => 0,
        'failed' => 0,
        'students' => [],
        'errors' => [],
    ];

    foreach (sampleStudentSeedRecords() as $record) {
        $studentId = (string) $record['student_id'];
        $email = (string) $record['email'];
        $label = $record['last_name'] . ', ' . $record['first_name'];

        $program = resolveSampleStudentProgram((string) $record['program_code']);
        $campus = resolveSampleStudentCampus((string) $record['campus_code']);

        if (!$program) {
            $result['failed']++;
            $result['errors'][] = $label . ': program ' . $record['program_code'] . ' not found.';
            continue;
        }

        if (!$campus) {
            $result['failed']++;
            $result['errors'][] = $label . ': campus ' . $record['campus_code'] . ' not found.';
            continue;
        }

        $existing = findUserByStudentIdOrEmail($studentId, $email);
        if ($existing) {
            if ($skipExisting) {
                $result['skipped']++;
                $result['students'][] = [
                    'student_id' => $studentId,
                    'email' => $email,
                    'name' => $label,
                    'password' => importInitialPassword($studentId),
                    'status' => 'skipped',
                ];
                continue;
            }

            $result['failed']++;
            $result['errors'][] = $label . ': student ID or email already exists.';
            continue;
        }

        $profile = [
            'course' => (string) ($program['name'] ?? ''),
            'course_id' => (int) ($program['id'] ?? 0),
            'year_level' => (string) $record['year_level'],
            'current_academic_year' => $academicYear,
            'current_semester' => $semester,
            'major' => null,
            'birth_date' => null,
            'sex' => (string) $record['sex'],
            'civil_status' => null,
            'birth_place' => null,
            'address' => null,
            'emergency_contact' => null,
            'emergency_relationship' => null,
            'emergency_phone' => null,
            'emergency_address' => null,
            'enrollment_status' => 'enrolled',
            'origin_campus_id' => (int) ($campus['id'] ?? 0),
        ];

        $password = importInitialPassword($studentId);

        try {
            $db->beginTransaction();
            $db->prepare('INSERT INTO users (role_id, email, password, student_id, first_name, last_name, middle_name, phone, is_active, email_verified, privacy_consent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW())')
               ->execute([
                   $roleId,
                   $email,
                   password_hash($password, PASSWORD_BCRYPT),
                   $studentId,
                   normalizePersonName($record['first_name']),
                   normalizePersonName($record['last_name']),
                   normalizePersonName($record['middle_name']) !== '' ? normalizePersonName($record['middle_name']) : null,
                   (string) $record['phone'],
               ]);
            $userId = (int) $db->lastInsertId();
            insertImportedStudentProfile($userId, $profile);
            $db->commit();

            auditLog('seed_sample_student', 'users', $userId, null, [
                'student_id' => $studentId,
                'source' => 'sample_seed',
            ]);

            $result['created']++;
            $result['students'][] = [
                'student_id' => $studentId,
                'email' => $email,
                'name' => $label,
                'password' => $password,
                'status' => 'created',
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $result['failed']++;
            $result['errors'][] = $label . ': ' . $e->getMessage();
        }
    }

    return $result;
}
