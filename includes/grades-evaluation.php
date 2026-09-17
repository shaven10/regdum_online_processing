<?php

function prospectusYearLevelOptions(): array {
    return [
        '1st Year' => 'First Year',
        '2nd Year' => 'Second Year',
        '3rd Year' => 'Third Year',
        '4th Year' => 'Fourth Year',
    ];
}

function prospectusSemesterOptions(): array {
    return [
        '1st_semester' => 'First Semester',
        '2nd_semester' => 'Second Semester',
        'summer'       => 'Summer / Midyear',
    ];
}

function ensureGradesEvaluationSchema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!function_exists('ensureAcademicProgramsSchema')) {
        require_once __DIR__ . '/programs.php';
    }
    if (!function_exists('schoolYearOptions')) {
        require_once __DIR__ . '/campuses.php';
    }

    ensureAcademicProgramsSchema();
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS course_prospectuses (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        program_id INT UNSIGNED NOT NULL,
        curriculum_year VARCHAR(20) NOT NULL,
        title VARCHAR(200) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_prospectus_program_year (program_id, curriculum_year),
        KEY idx_prospectus_program (program_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS prospectus_subjects (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        prospectus_id INT UNSIGNED NOT NULL,
        year_level VARCHAR(20) NOT NULL,
        semester ENUM('1st_semester','2nd_semester','summer') NOT NULL DEFAULT '1st_semester',
        course_code VARCHAR(40) NOT NULL,
        course_no VARCHAR(20) NOT NULL DEFAULT '',
        title VARCHAR(255) NOT NULL,
        units DECIMAL(4,1) NOT NULL DEFAULT 3.0,
        prereq VARCHAR(255) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_prospectus_subjects_parent (prospectus_id, year_level, semester, sort_order),
        CONSTRAINT fk_prospectus_subjects_prospectus FOREIGN KEY (prospectus_id)
            REFERENCES course_prospectuses(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS student_subject_grades (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        prospectus_subject_id INT UNSIGNED NOT NULL,
        grade VARCHAR(20) NULL,
        remarks VARCHAR(40) NULL,
        school_year VARCHAR(20) NULL,
        semester ENUM('1st_semester','2nd_semester','summer') NULL,
        updated_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_student_subject_grade (user_id, prospectus_subject_id),
        KEY idx_student_grades_user (user_id),
        CONSTRAINT fk_student_subject_grades_subject FOREIGN KEY (prospectus_subject_id)
            REFERENCES prospectus_subjects(id) ON DELETE CASCADE,
        CONSTRAINT fk_student_subject_grades_user FOREIGN KEY (user_id)
            REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    seedBscrimProspectus();
}

function prospectusSubjectCode(array $subject): string {
    return trim((string) ($subject['course_code'] ?? '') . ' ' . (string) ($subject['course_no'] ?? ''));
}

function gradeRemarkOptions(): array {
    return [
        ''            => '',
        'passed'      => 'Passed',
        'failed'      => 'Failed',
        'incomplete'  => 'Incomplete',
        'dropped'     => 'Dropped',
        'in_progress' => 'In Progress',
    ];
}

function formatStudentGrade(?string $grade): string {
    $grade = trim((string) $grade);
    if ($grade === '' || $grade === '-' || $grade === '—') {
        return '';
    }
    $numeric = str_replace(',', '.', $grade);
    if (is_numeric($numeric)) {
        $value = (float) $numeric;
        if ($value <= 0) {
            return '';
        }
        return number_format($value, 2, '.', '');
    }
    return strtoupper($grade);
}

function inferGradeRemark(?string $grade): string {
    $grade = formatStudentGrade($grade);
    if ($grade === '') {
        return '';
    }
    if (in_array($grade, ['INC', 'INCOMPLETE'], true)) {
        return 'incomplete';
    }
    if (in_array($grade, ['DRP', 'DROP', 'DROPPED', 'W', 'UD', 'OW'], true)) {
        return 'dropped';
    }
    if (in_array($grade, ['IP', 'IN PROGRESS', 'NG', 'N/A'], true)) {
        return 'in_progress';
    }
    if (!is_numeric($grade)) {
        return '';
    }
    $value = (float) $grade;
    if ($value <= 0) {
        return '';
    }
    return $value <= 3.0 ? 'passed' : 'failed';
}

function gradeRemarkLabel(?string $remark): string {
    return gradeRemarkOptions()[$remark ?? ''] ?? '—';
}

function isPassingGradeRemark(?string $remark): bool {
    return ($remark ?? '') === 'passed';
}

function gradesEvaluationVoidReasonLabel(string $reason): string {
    return match ($reason) {
        'prerequisite_not_taken'     => 'Prerequisite not yet taken',
        'prerequisite_failed'        => 'Prerequisite failed',
        'prerequisite_not_passed'    => 'Prerequisite not passed',
        'taken_before_prerequisite'  => 'Taken before prerequisite',
        default                      => 'Prerequisite requirement not met',
    };
}

function prospectusSubjectLookupIndex(array $subjects): array {
    $index = [];
    foreach ($subjects as $subject) {
        $code = normalizeGradeCodeKey(prospectusSubjectCode($subject));
        $index[$code] = $subject;
        $index[str_replace(' ', '', $code)] = $subject;
    }
    return $index;
}

function findProspectusSubjectByRef(array $index, string $ref): ?array {
    $norm = normalizeGradeCodeKey($ref);
    if ($norm === '') {
        return null;
    }
    return $index[$norm] ?? $index[str_replace(' ', '', $norm)] ?? null;
}

function prospectusTakenTermOrder(?string $schoolYear, ?string $semester): ?int {
    $schoolYear = trim((string) $schoolYear);
    $semester = trim((string) $semester);
    if ($schoolYear === '' || $semester === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})/', $schoolYear, $match)) {
        return null;
    }
    $semOrder = match ($semester) {
        '1st_semester' => 1,
        '2nd_semester' => 2,
        'summer'       => 3,
        default        => 0,
    };
    if ($semOrder === 0) {
        return null;
    }
    return ((int) $match[1]) * 10 + $semOrder;
}

function prospectusSubjectSavedRow(array $gradeMap, int $subjectId): array {
    return $gradeMap[$subjectId] ?? [];
}

function prospectusSubjectHasGrade(array $gradeMap, int $subjectId): bool {
    $saved = prospectusSubjectSavedRow($gradeMap, $subjectId);
    return formatStudentGrade((string) ($saved['grade'] ?? '')) !== '';
}

function prospectusSubjectRemarkFromSaved(array $saved): string {
    $grade = formatStudentGrade((string) ($saved['grade'] ?? ''));
    $remark = (string) ($saved['remarks'] ?? '');
    return $remark !== '' ? $remark : inferGradeRemark($grade);
}

function prospectusPrerequisiteIssueForRef(array $subject, array $prereqSubject, array $gradeMap): string {
    $prereqSaved = prospectusSubjectSavedRow($gradeMap, (int) $prereqSubject['id']);
    $prereqRemark = prospectusSubjectRemarkFromSaved($prereqSaved);
    $prereqGrade = formatStudentGrade((string) ($prereqSaved['grade'] ?? ''));
    $subjectSaved = prospectusSubjectSavedRow($gradeMap, (int) $subject['id']);

    if ($prereqGrade === '') {
        return 'prerequisite_not_taken';
    }
    if ($prereqRemark === 'failed') {
        return 'prerequisite_failed';
    }
    if (!isPassingGradeRemark($prereqRemark)) {
        return 'prerequisite_not_passed';
    }

    $subjectOrder = prospectusTakenTermOrder($subjectSaved['school_year'] ?? '', $subjectSaved['semester'] ?? '');
    $prereqOrder = prospectusTakenTermOrder($prereqSaved['school_year'] ?? '', $prereqSaved['semester'] ?? '');
    if ($subjectOrder !== null && $prereqOrder !== null && $subjectOrder < $prereqOrder) {
        return 'taken_before_prerequisite';
    }

    return '';
}

function prospectusSubjectVoidReason(array $subject, array $subjects, array $gradeMap, array $passedCodes): string {
    if (!prospectusSubjectHasGrade($gradeMap, (int) $subject['id'])) {
        return '';
    }

    $prereq = trim((string) ($subject['prereq'] ?? ''));
    if ($prereq === '' || strcasecmp($prereq, 'none') === 0) {
        return '';
    }

    $normalized = strtoupper($prereq);
    if (str_contains($normalized, 'ALL PROF')) {
        foreach ($subjects as $candidate) {
            if (!prospectusSubjectIsProfessional($candidate)) {
                continue;
            }
            if ((int) $candidate['id'] === (int) $subject['id']) {
                continue;
            }
            $saved = prospectusSubjectSavedRow($gradeMap, (int) $candidate['id']);
            $remark = prospectusSubjectRemarkFromSaved($saved);
            if (!isPassingGradeRemark($remark)) {
                return prospectusSubjectHasGrade($gradeMap, (int) $candidate['id'])
                    ? ($remark === 'failed' ? 'prerequisite_failed' : 'prerequisite_not_passed')
                    : 'prerequisite_not_taken';
            }
        }
        return '';
    }

    $index = prospectusSubjectLookupIndex($subjects);
    $parts = preg_split('/\s*(?:,|&|\/| and )\s*/i', $prereq) ?: [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        $prereqSubject = findProspectusSubjectByRef($index, $part);
        if ($prereqSubject) {
            $issue = prospectusPrerequisiteIssueForRef($subject, $prereqSubject, $gradeMap);
            if ($issue !== '') {
                return $issue;
            }
            continue;
        }

        $ref = normalizeGradeCodeKey($part);
        $refCompact = str_replace(' ', '', $ref);
        if (empty($passedCodes[$ref]) && empty($passedCodes[$refCompact])) {
            return 'prerequisite_not_taken';
        }
    }

    return '';
}

function gradesEvaluationRowClass(array $subject): string {
    if (!empty($subject['_is_void'])) {
        return 'is-void';
    }
    $remark = (string) ($subject['_remarks'] ?? '');
    if ($remark === 'failed') {
        return 'is-failed';
    }
    if ($remark === 'passed') {
        return 'is-passed';
    }
    return '';
}

function getProspectusById(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT p.*, ap.code AS program_code, ap.name AS program_name
        FROM course_prospectuses p
        LEFT JOIN academic_programs ap ON ap.id = p.program_id
        WHERE p.id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getProspectusesForProgram(int $programId): array {
    $stmt = getDB()->prepare('SELECT p.*, ap.code AS program_code, ap.name AS program_name,
            (SELECT COUNT(*) FROM prospectus_subjects s WHERE s.prospectus_id = p.id) AS subject_count
        FROM course_prospectuses p
        LEFT JOIN academic_programs ap ON ap.id = p.program_id
        WHERE p.program_id = ?
        ORDER BY p.curriculum_year DESC, p.id DESC');
    $stmt->execute([$programId]);
    return $stmt->fetchAll();
}

function getActiveProspectusForProgram(int $programId): ?array {
    $stmt = getDB()->prepare('SELECT p.*, ap.code AS program_code, ap.name AS program_name
        FROM course_prospectuses p
        LEFT JOIN academic_programs ap ON ap.id = p.program_id
        WHERE p.program_id = ? AND p.is_active = 1
        ORDER BY p.curriculum_year DESC, p.id DESC
        LIMIT 1');
    $stmt->execute([$programId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function listCourseProspectuses(): array {
    return getDB()->query('SELECT ap.id AS program_id, ap.code AS program_code, ap.name AS program_name, ap.is_active AS program_active,
            p.id AS prospectus_id, p.curriculum_year, p.title, p.is_active,
            (SELECT COUNT(*) FROM prospectus_subjects s WHERE s.prospectus_id = p.id) AS subject_count
        FROM academic_programs ap
        LEFT JOIN course_prospectuses p ON p.program_id = ap.id
            AND p.id = (
                SELECT p2.id FROM course_prospectuses p2
                WHERE p2.program_id = ap.id
                ORDER BY p2.is_active DESC, p2.curriculum_year DESC, p2.id DESC
                LIMIT 1
            )
        ORDER BY ap.sort_order, ap.name')->fetchAll();
}

function getProspectusSubjects(int $prospectusId): array {
    $stmt = getDB()->prepare('SELECT * FROM prospectus_subjects
        WHERE prospectus_id = ?
        ORDER BY FIELD(year_level, "1st Year","2nd Year","3rd Year","4th Year"),
            FIELD(semester, "1st_semester","2nd_semester","summer"),
            sort_order, id');
    $stmt->execute([$prospectusId]);
    return $stmt->fetchAll();
}

function groupProspectusSubjects(array $subjects): array {
    $grouped = [];
    foreach (prospectusYearLevelOptions() as $year => $yearLabel) {
        $grouped[$year] = [
            'label' => $yearLabel,
            'semesters' => [],
        ];
        foreach (prospectusSemesterOptions() as $sem => $semLabel) {
            $grouped[$year]['semesters'][$sem] = [
                'label'    => $semLabel,
                'subjects' => [],
                'units'    => 0,
            ];
        }
    }

    foreach ($subjects as $subject) {
        $year = $subject['year_level'] ?? '';
        $sem = $subject['semester'] ?? '1st_semester';
        if (!isset($grouped[$year])) {
            $grouped[$year] = [
                'label' => $year,
                'semesters' => [],
            ];
        }
        if (!isset($grouped[$year]['semesters'][$sem])) {
            $grouped[$year]['semesters'][$sem] = [
                'label'    => prospectusSemesterOptions()[$sem] ?? $sem,
                'subjects' => [],
                'units'    => 0,
            ];
        }
        $grouped[$year]['semesters'][$sem]['subjects'][] = $subject;
        $grouped[$year]['semesters'][$sem]['units'] += (float) ($subject['units'] ?? 0);
    }

    return $grouped;
}

function saveCourseProspectus(array $data, int $userId = 0): array {
    $programId = (int) ($data['program_id'] ?? 0);
    $curriculumYear = trim((string) ($data['curriculum_year'] ?? ''));
    $title = trim((string) ($data['title'] ?? ''));
    $isActive = !empty($data['is_active']) ? 1 : 0;
    $id = (int) ($data['id'] ?? 0);

    if ($programId <= 0) {
        return ['ok' => false, 'error' => 'Select a course.'];
    }
    if ($curriculumYear === '') {
        return ['ok' => false, 'error' => 'Curriculum year is required.'];
    }
    if (!getAcademicProgramById($programId)) {
        return ['ok' => false, 'error' => 'Course not found.'];
    }

    $db = getDB();
    if ($id > 0) {
        $existing = getProspectusById($id);
        if (!$existing) {
            return ['ok' => false, 'error' => 'Prospectus not found.'];
        }
        $dup = $db->prepare('SELECT id FROM course_prospectuses WHERE program_id = ? AND curriculum_year = ? AND id <> ?');
        $dup->execute([$programId, $curriculumYear, $id]);
        if ($dup->fetch()) {
            return ['ok' => false, 'error' => 'A prospectus for this course and curriculum year already exists.'];
        }
        $db->prepare('UPDATE course_prospectuses SET program_id = ?, curriculum_year = ?, title = ?, is_active = ? WHERE id = ?')
            ->execute([$programId, $curriculumYear, $title !== '' ? $title : null, $isActive, $id]);
    } else {
        $dup = $db->prepare('SELECT id FROM course_prospectuses WHERE program_id = ? AND curriculum_year = ?');
        $dup->execute([$programId, $curriculumYear]);
        $found = $dup->fetch();
        if ($found) {
            return ['ok' => false, 'error' => 'A prospectus for this course and curriculum year already exists.', 'id' => (int) $found['id']];
        }
        $db->prepare('INSERT INTO course_prospectuses (program_id, curriculum_year, title, is_active, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$programId, $curriculumYear, $title !== '' ? $title : null, $isActive, $userId > 0 ? $userId : null]);
        $id = (int) $db->lastInsertId();
    }

    if ($isActive) {
        $db->prepare('UPDATE course_prospectuses SET is_active = 0 WHERE program_id = ? AND id <> ?')
            ->execute([$programId, $id]);
    }

    return ['ok' => true, 'id' => $id];
}

function saveProspectusSubjects(int $prospectusId, array $rows): array {
    if (!getProspectusById($prospectusId)) {
        return ['ok' => false, 'error' => 'Prospectus not found.'];
    }

    $db = getDB();
    $keptIds = [];
    $sort = [];
    $insert = $db->prepare('INSERT INTO prospectus_subjects
        (prospectus_id, year_level, semester, course_code, course_no, title, units, prereq, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $update = $db->prepare('UPDATE prospectus_subjects
        SET year_level = ?, semester = ?, course_code = ?, course_no = ?, title = ?, units = ?, prereq = ?, sort_order = ?
        WHERE id = ? AND prospectus_id = ?');

    $yearOptions = prospectusYearLevelOptions();
    $semOptions = prospectusSemesterOptions();

    foreach ($rows as $row) {
        $title = trim((string) ($row['title'] ?? ''));
        $code = trim((string) ($row['course_code'] ?? ''));
        if ($title === '' && $code === '') {
            continue;
        }
        if ($title === '' || $code === '') {
            return ['ok' => false, 'error' => 'Each subject needs a course code and descriptive title.'];
        }

        $year = (string) ($row['year_level'] ?? '');
        $sem = (string) ($row['semester'] ?? '1st_semester');
        if (!isset($yearOptions[$year])) {
            return ['ok' => false, 'error' => 'Invalid year level on one of the subjects.'];
        }
        if (!isset($semOptions[$sem])) {
            return ['ok' => false, 'error' => 'Invalid semester on one of the subjects.'];
        }

        $key = $year . '|' . $sem;
        $sort[$key] = ($sort[$key] ?? 0) + 1;
        $units = (float) ($row['units'] ?? 0);
        if ($units <= 0) {
            $units = 3;
        }
        $prereq = trim((string) ($row['prereq'] ?? ''));
        $id = (int) ($row['id'] ?? 0);

        if ($id > 0) {
            $update->execute([
                $year, $sem, $code, trim((string) ($row['course_no'] ?? '')),
                $title, $units, $prereq !== '' ? $prereq : null, $sort[$key], $id, $prospectusId,
            ]);
            $keptIds[] = $id;
        } else {
            $insert->execute([
                $prospectusId, $year, $sem, $code, trim((string) ($row['course_no'] ?? '')),
                $title, $units, $prereq !== '' ? $prereq : null, $sort[$key],
            ]);
            $keptIds[] = (int) $db->lastInsertId();
        }
    }

    $existing = getProspectusSubjects($prospectusId);
    $skipped = 0;
    foreach ($existing as $subject) {
        $sid = (int) $subject['id'];
        if (in_array($sid, $keptIds, true)) {
            continue;
        }
        $used = $db->prepare('SELECT COUNT(*) FROM student_subject_grades WHERE prospectus_subject_id = ?');
        $used->execute([$sid]);
        if ((int) $used->fetchColumn() > 0) {
            $skipped++;
            continue;
        }
        $db->prepare('DELETE FROM prospectus_subjects WHERE id = ? AND prospectus_id = ?')->execute([$sid, $prospectusId]);
    }

    return ['ok' => true, 'count' => count($keptIds), 'kept_graded' => $skipped];
}

function deleteCourseProspectus(int $id): array {
    $prospectus = getProspectusById($id);
    if (!$prospectus) {
        return ['ok' => false, 'error' => 'Prospectus not found.'];
    }
    $used = getDB()->prepare('SELECT COUNT(*) FROM student_subject_grades g
        INNER JOIN prospectus_subjects s ON s.id = g.prospectus_subject_id
        WHERE s.prospectus_id = ?');
    $used->execute([$id]);
    if ((int) $used->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'This prospectus has saved student grades and cannot be deleted.'];
    }
    getDB()->prepare('DELETE FROM course_prospectuses WHERE id = ?')->execute([$id]);
    return ['ok' => true];
}

function loadStudentForGradesEvaluation(int $userId): ?array {
    if ($userId <= 0) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT u.id, u.student_id, u.first_name, u.last_name, u.middle_name, u.email, u.phone, u.is_active,
            sp.course, sp.course_id, sp.year_level, sp.major, sp.enrollment_status, sp.current_academic_year, sp.current_semester,
            ap.code AS program_code, ap.name AS program_name
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN academic_programs ap ON ap.id = sp.course_id
        WHERE u.id = ? AND u.role_id = 1
        LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function resolveStudentProgramId(array $student): int {
    $programId = (int) ($student['course_id'] ?? 0);
    if ($programId > 0) {
        return $programId;
    }
    $code = trim((string) ($student['program_code'] ?? ''));
    $course = trim((string) ($student['course'] ?? ''));
    if ($code === '' && $course === '') {
        return 0;
    }
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM academic_programs
        WHERE (code <> "" AND (code = ? OR code = ?))
           OR (name <> "" AND (name = ? OR name = ?))
        LIMIT 1');
    $stmt->execute([$code, $course, $code, $course]);
    $found = (int) $stmt->fetchColumn();
    if ($found > 0) {
        return $found;
    }
    foreach ([$code, $course] as $value) {
        if ($value === '') {
            continue;
        }
        $stmt = $db->prepare('SELECT id FROM academic_programs WHERE name LIKE ? OR code LIKE ? ORDER BY LENGTH(code) ASC, id ASC LIMIT 1');
        $like = '%' . $value . '%';
        $stmt->execute([$like, $like]);
        $found = (int) $stmt->fetchColumn();
        if ($found > 0) {
            return $found;
        }
    }
    return 0;
}

function searchStudentsForGradesEvaluation(string $search, int $limit = 15): array {
    $search = trim($search);
    if (strlen($search) < 2) {
        return [];
    }

    $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $where = [
        'u.role_id = 1',
        'u.is_active = 1',
        "(sp.enrollment_status = 'enrolled' OR sp.enrollment_status IS NULL OR sp.enrollment_status = '')",
    ];
    $params = [];
    foreach ($terms as $term) {
        $like = '%' . $term . '%';
        $prefix = $term . '%';
        $where[] = '(u.student_id LIKE ? OR u.student_id LIKE ? OR u.last_name LIKE ? OR u.first_name LIKE ?
            OR u.middle_name LIKE ? OR CONCAT(u.last_name, ", ", u.first_name) LIKE ?
            OR ap.code LIKE ? OR sp.course LIKE ?)';
        array_push($params, $prefix, $like, $prefix, $prefix, $like, $like, $prefix, $like);
    }

    $limit = max(1, min(25, $limit));
    $stmt = getDB()->prepare('SELECT u.id, u.student_id, u.first_name, u.last_name, u.middle_name,
            sp.course, sp.course_id, sp.year_level, sp.enrollment_status,
            ap.code AS program_code, ap.name AS program_name
        FROM users u
        LEFT JOIN student_profiles sp ON sp.user_id = u.id
        LEFT JOIN academic_programs ap ON ap.id = sp.course_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY u.last_name, u.first_name, u.id
        LIMIT ' . $limit);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getStudentSubjectGrades(int $userId, int $prospectusId): array {
    $stmt = getDB()->prepare('SELECT g.*
        FROM student_subject_grades g
        INNER JOIN prospectus_subjects s ON s.id = g.prospectus_subject_id
        WHERE g.user_id = ? AND s.prospectus_id = ?');
    $stmt->execute([$userId, $prospectusId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['prospectus_subject_id']] = $row;
    }
    return $map;
}

function saveStudentSubjectGrades(int $userId, int $prospectusId, array $grades, int $updatedBy = 0): array {
    $student = loadStudentForGradesEvaluation($userId);
    if (!$student) {
        return ['ok' => false, 'error' => 'Student not found.'];
    }
    if (!studentAllowsGradesEvaluation($student)) {
        return ['ok' => false, 'error' => 'Grades evaluation is only available for enrolled (active) students.'];
    }
    if (!getProspectusById($prospectusId)) {
        return ['ok' => false, 'error' => 'Prospectus not found.'];
    }

    $subjects = getProspectusSubjects($prospectusId);
    $validIds = [];
    foreach ($subjects as $subject) {
        $validIds[(int) $subject['id']] = true;
    }

    $db = getDB();
    $upsert = $db->prepare('INSERT INTO student_subject_grades
            (user_id, prospectus_subject_id, grade, remarks, school_year, semester, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            grade = VALUES(grade),
            remarks = VALUES(remarks),
            school_year = VALUES(school_year),
            semester = VALUES(semester),
            updated_by = VALUES(updated_by)');

    foreach ($grades as $subjectId => $row) {
        $subjectId = (int) $subjectId;
        if (!isset($validIds[$subjectId])) {
            continue;
        }
        $grade = formatStudentGrade((string) ($row['grade'] ?? ''));
        $remark = trim((string) ($row['remarks'] ?? ''));
        if ($remark === '' || !array_key_exists($remark, gradeRemarkOptions())) {
            $remark = inferGradeRemark($grade);
        }
        if ($grade === '' && $remark === '') {
            $db->prepare('DELETE FROM student_subject_grades WHERE user_id = ? AND prospectus_subject_id = ?')
                ->execute([$userId, $subjectId]);
            continue;
        }
        $schoolYear = trim((string) ($row['school_year'] ?? ''));
        $semester = trim((string) ($row['semester'] ?? ''));
        if (!array_key_exists($semester, prospectusSemesterOptions())) {
            $semester = null;
        }
        $upsert->execute([
            $userId,
            $subjectId,
            $grade !== '' ? $grade : null,
            $remark !== '' ? $remark : null,
            $schoolYear !== '' ? $schoolYear : null,
            $semester,
            $updatedBy > 0 ? $updatedBy : null,
        ]);
    }

    return ['ok' => true];
}

function countStudentSubjectGrades(int $userId): int {
    if ($userId <= 0) {
        return 0;
    }
    ensureGradesEvaluationSchema();
    $stmt = getDB()->prepare('SELECT COUNT(*) FROM student_subject_grades WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function clearStudentSubjectGrades(int $userId): array {
    $student = loadStudentForGradesEvaluation($userId);
    if (!$student) {
        return ['ok' => false, 'error' => 'Student not found.'];
    }
    if (!studentAllowsGradesEvaluation($student)) {
        return ['ok' => false, 'error' => 'Clear grades is only available for enrolled (active) students.'];
    }
    ensureGradesEvaluationSchema();
    $stmt = getDB()->prepare('DELETE FROM student_subject_grades WHERE user_id = ?');
    $stmt->execute([$userId]);
    return [
        'ok'      => true,
        'deleted' => $stmt->rowCount(),
        'name'    => studentRecordName($student),
    ];
}

function handleStudentRecordsClearGradesPost(string $redirectUrl): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== 'clear_grades') {
        return;
    }
    if (!hasRole('admin', 'registrar')) {
        return;
    }
    if (!verifyCsrf()) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect($redirectUrl);
    }

    $result = clearStudentSubjectGrades((int) ($_POST['user_id'] ?? 0));
    if (empty($result['ok'])) {
        setFlash('error', $result['error'] ?? 'Unable to clear grades.');
        redirect($redirectUrl);
    }

    $count = (int) ($result['deleted'] ?? 0);
    $name = trim((string) ($result['name'] ?? ''));
    $who = $name !== '' ? $name : 'this student';
    if ($count === 0) {
        setFlash('success', 'No saved grades to clear for ' . $who . '.');
    } else {
        setFlash('success', 'Cleared ' . $count . ' grade' . ($count === 1 ? '' : 's') . ' for ' . $who . '.');
    }
    redirect($redirectUrl);
}

function normalizeGradeCodeKey(string $code): string {
    $code = strtoupper(trim($code));
    $code = str_replace(['-', '_', '.', '/', '\\'], ' ', $code);
    $code = preg_replace('/\s+/', ' ', $code) ?? $code;
    return trim($code);
}

function normalizeSubjectTitleKey(string $title): string {
    $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = strtolower($title);
    $title = preg_replace('/\([^)]*\)/', ' ', $title) ?? $title;
    $title = str_replace(['&', '/', '-', ',', '.', ':', ';', '—', '–'], ' ', $title);
    $title = str_replace([' and '], ' ', $title);
    $title = preg_replace('/\s+/', ' ', $title) ?? $title;
    return trim($title);
}

function prospectusSubjectIndex(array $subjects): array {
    $index = [];
    foreach ($subjects as $subject) {
        $code = prospectusSubjectCode($subject);
        $norm = normalizeGradeCodeKey($code);
        $index[$norm] = $subject;
        $index[str_replace(' ', '', $norm)] = $subject;
        $titleKey = normalizeSubjectTitleKey((string) ($subject['title'] ?? ''));
        if ($titleKey !== '') {
            $index['t:' . $titleKey] = $subject;
        }
    }
    return $index;
}

function lookupProspectusSubject(array $index, string $code, string $number = '', string $title = ''): ?array {
    $code = trim(html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $number = trim($number);
    $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($code !== '') {
        $combined = $number !== '' ? $code . ' ' . $number : $code;
        $norm = normalizeGradeCodeKey($combined);
        if (isset($index[$norm])) {
            return $index[$norm];
        }
        $compact = str_replace(' ', '', $norm);
        if (isset($index[$compact])) {
            return $index[$compact];
        }
        if ($number === '' && preg_match('/^(.+?)\s+(\S+)$/', $code, $parts)) {
            $split = lookupProspectusSubject($index, $parts[1], $parts[2], '');
            if ($split) {
                return $split;
            }
        }
    }
    if ($title !== '') {
        $titleKey = normalizeSubjectTitleKey($title);
        if ($titleKey !== '' && isset($index['t:' . $titleKey])) {
            return $index['t:' . $titleKey];
        }
    }
    return null;
}

function normalizeStudentIdKey(string $id): string {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $id) ?? '');
}

function gradePasteStatusLabel(string $status): string {
    return match ($status) {
        'ok' => 'Ready',
        'unknown_student' => 'Student not found',
        'unknown_subject' => 'Not in prospectus',
        'empty' => 'On sheet, no grade',
        default => $status,
    };
}

function looksLikeCourseCode(string $value): bool {
    $value = trim($value);
    if ($value === '' || looksLikeGradeValue($value)) {
        return false;
    }
    return (bool) preg_match('/^[A-Za-z]{1,12}(?:[ ._-]+[A-Za-z]{1,12})*(?:[ ._-]*\d{1,4}[A-Za-z]{0,3})?$/', $value);
}

function isPlaceholderPastedGrade(string $value): bool {
    $value = strtoupper(trim($value));
    return $value === ''
        || $value === '-'
        || $value === '—'
        || $value === '0'
        || $value === '0.0'
        || $value === '0.00'
        || $value === 'NA'
        || $value === 'N/A';
}

function resolveTranscriptGrade(string $grade, string $reexam): array {
    $grade = strtoupper(trim($grade));
    $reexam = strtoupper(trim($reexam));
    $reexamUsable = !isPlaceholderPastedGrade($reexam) && looksLikeGradeValue($reexam);
    if ($reexamUsable) {
        return ['grade' => formatStudentGrade($reexam), 'note' => 'Re-Ex'];
    }
    if (isPlaceholderPastedGrade($grade)) {
        return ['grade' => '', 'note' => ''];
    }
    return ['grade' => formatStudentGrade($grade), 'note' => ''];
}

function pastedRowLooksLikeTermHeader(array $cells): bool {
    $text = pastedHeaderKey(implode(' ', $cells));
    if (preg_match('/\b(first|second|1st|2nd|third|3rd|summer|midyear|mid year)\b.*\b(sem|semester|s y|sy)\b/', $text)) {
        return true;
    }
    return (bool) preg_match('/\bs y\s*\d{4}\s*-?\s*\d{2,4}/', $text);
}

function parsePastedTermHeader(array $cells): array {
    $text = html_entity_decode(implode(' ', $cells), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $semester = '';
    if (preg_match('/\b(first|1st)\s*(semester|sem)\b/i', $text)) {
        $semester = '1st_semester';
    } elseif (preg_match('/\b(second|2nd)\s*(semester|sem)\b/i', $text)) {
        $semester = '2nd_semester';
    } elseif (preg_match('/\b(summer|mid[\s-]?year)\b/i', $text)) {
        $semester = 'summer';
    }
    $schoolYear = '';
    if (preg_match('/(\d{4})\s*[-–]\s*(\d{2,4})/', $text, $match)) {
        $to = $match[2];
        if (strlen($to) === 2) {
            $to = substr($match[1], 0, 2) . $to;
        }
        $schoolYear = $match[1] . '-' . $to;
    }
    return ['semester' => $semester, 'school_year' => $schoolYear];
}

function pasteLooksLikeTranscript(array $rows): bool {
    if ($rows === []) {
        return false;
    }
    foreach (array_slice($rows, 0, 8) as $row) {
        if (pastedRowLooksLikeTermHeader($row)) {
            return true;
        }
        $joined = pastedHeaderKey(implode(' ', $row));
        if (preg_match('/\b(course description|course #|re ex|reex|preq)\b/', $joined)
            && preg_match('/\b(grade|units|lec|lab)\b/', $joined)) {
            return true;
        }
        if (count($row) >= 6 && looksLikeCourseCode((string) $row[0]) && is_numeric((string) ($row[5] ?? ''))) {
            return true;
        }
    }
    return false;
}

function looksLikeGradeValue(string $value): bool {
    $value = strtoupper(trim($value));
    if ($value === '') {
        return false;
    }
    if (in_array($value, ['INC', 'INCOMPLETE', 'DRP', 'DROP', 'DROPPED', 'W', 'UD', 'OW', 'IP', 'NG', 'N/A', 'NA', 'PASSED', 'FAILED'], true)) {
        return true;
    }
    return (bool) preg_match('/^\d+(\.\d+)?$/', $value);
}

function looksLikeStudentId(string $value): bool {
    $value = trim($value);
    if ($value === '' || looksLikeGradeValue($value) || looksLikeCourseCode($value) || strlen($value) < 3) {
        return false;
    }
    return (bool) preg_match('/\d/', $value);
}

function pasteLooksLikeVerticalTranscript(string $text): bool {
    if (str_contains($text, "\t")) {
        return false;
    }
    $lower = strtolower($text);
    if (!preg_match('/\b(course description|course #)\b/', $lower)) {
        return false;
    }
    if (!preg_match('/\b(first|second|1st|2nd|third|3rd)\s+semester\b.*\b(s\.?\s*y\.?|sy)\s*\d{4}/i', $text)) {
        return false;
    }
    $lineCount = count(array_filter(preg_split("/\r?\n/", $text) ?: [], static function (string $line): bool {
        return trim($line) !== '';
    }));
    return $lineCount >= 20;
}

function verticalTranscriptHeaderLabels(): array {
    return ['course #', 'course description', 'preq', 'lec', 'lab', 'units', 'grade', 're-ex', 'professor'];
}

function verticalTranscriptLineKey(string $line): string {
    return pastedHeaderKey($line);
}

function verticalTranscriptHeaderStartsAt(array $lines, int $index): bool {
    $labels = verticalTranscriptHeaderLabels();
    $offset = 0;
    foreach ($labels as $label) {
        $line = (string) ($lines[$index + $offset] ?? '');
        $lineKey = verticalTranscriptLineKey($line);
        if ($label === 'course #') {
            if ($lineKey === 'course' || str_contains(strtolower($line), 'course #')) {
                $offset++;
                continue;
            }
            return false;
        }
        if ($label === 're-ex' && in_array($lineKey, ['re ex', 'reex', 're-ex'], true)) {
            $offset++;
            continue;
        }
        if ($lineKey !== $label) {
            return false;
        }
        $offset++;
    }
    return true;
}

function findVerticalTranscriptHeaderIndex(array $lines): ?int {
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        if (verticalTranscriptHeaderStartsAt($lines, $i)) {
            return $i;
        }
    }
    return null;
}

function looksLikeProfessorName(string $value): bool {
    $value = trim($value);
    if ($value === '' || $value === '-' || looksLikeGradeValue($value) || looksLikeCourseCode($value)) {
        return false;
    }
    return (bool) preg_match('/[A-Za-z]{2,}/', $value);
}

function parseVerticalTranscriptRecord(array $lines, int &$index): ?array {
    $n = count($lines);
    if ($index >= $n) {
        return null;
    }

    $line = $lines[$index];
    if (pastedRowLooksLikeTermHeader([$line]) || verticalTranscriptHeaderStartsAt($lines, $index)) {
        return null;
    }
    if (!looksLikeCourseCode($line)) {
        return null;
    }

    $course = $line;
    $index++;
    if ($index >= $n) {
        return null;
    }

    $title = $lines[$index++];
    $preq = $index < $n ? $lines[$index++] : '';
    $lec = $index < $n ? $lines[$index++] : '';
    $lab = $index < $n ? $lines[$index++] : '';
    $units = $index < $n ? $lines[$index++] : '';
    $grade = $index < $n ? $lines[$index++] : '';
    $reexam = '';
    $professor = '';

    if ($index < $n) {
        $next = $lines[$index];
        if (looksLikeGradeValue($next) && !looksLikeProfessorName($next)) {
            $reexam = $next;
            $index++;
            if ($index < $n) {
                $professor = $lines[$index++];
            }
        } elseif (looksLikeProfessorName($next)) {
            $professor = $next;
            $index++;
        } else {
            $professor = $next;
            $index++;
        }
    }

    return [$course, $title, $preq, $lec, $lab, $units, $grade, $reexam, $professor];
}

function splitVerticalTranscriptText(string $text): array {
    $lines = [];
    foreach (preg_split("/\r?\n/", str_replace(["\r\n", "\r"], "\n", $text)) ?: [] as $line) {
        $line = html_entity_decode(trim($line), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $line = str_replace("\xc2\xa0", ' ', $line);
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    $headerIndex = findVerticalTranscriptHeaderIndex($lines);
    if ($headerIndex === null) {
        return [];
    }

    $rows = [['Course #', 'Course Description', 'Preq', 'Lec', 'Lab', 'Units', 'Grade', 'Re-Ex', 'Professor']];
    $columns = count(verticalTranscriptHeaderLabels());
    $i = $headerIndex + $columns;
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];
        if (pastedRowLooksLikeTermHeader([$line])) {
            $rows[] = [$line];
            $i++;
            continue;
        }
        if (verticalTranscriptHeaderStartsAt($lines, $i)) {
            $i += $columns;
            continue;
        }

        $start = $i;
        $record = parseVerticalTranscriptRecord($lines, $i);
        if ($record === null) {
            $i = $start + 1;
            continue;
        }
        $rows[] = $record;
    }
    return $rows;
}

function splitPastedGradeText(string $text): array {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = preg_split("/\n/", $text) ?: [];
    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line, " \t\0\x0B");
        if ($line === '') {
            continue;
        }
        if (str_contains($line, "\t")) {
            $cells = explode("\t", $line);
        } elseif (substr_count($line, ',') >= 1) {
            $cells = str_getcsv($line);
        } else {
            $cells = preg_split('/\s{2,}/', $line) ?: [$line];
            if (count($cells) === 1) {
                $cells = preg_split('/\s+/', $line) ?: [$line];
            }
        }
        $cells = array_map(static function ($cell): string {
            $cell = html_entity_decode(trim((string) $cell), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $cell = str_replace("\xc2\xa0", ' ', $cell);
            return trim($cell);
        }, $cells);
        while ($cells !== [] && end($cells) === '') {
            array_pop($cells);
        }
        if ($cells !== []) {
            $rows[] = $cells;
        }
    }
    return $rows;
}

function pastedHeaderKey(string $value): string {
    $value = strtolower(trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    $value = str_replace(['.', '#', '_'], ' ', $value);
    $value = str_replace(['–', '—'], '-', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return trim($value);
}

function detectPastedHeaderMap(array $header): array {
    $map = [
        'student_id' => null,
        'last_name'  => null,
        'first_name' => null,
        'course'     => null,
        'number'     => null,
        'title'      => null,
        'grade'      => null,
        'reexam'     => null,
        'subjects'   => [],
    ];
    foreach ($header as $i => $cell) {
        $key = pastedHeaderKey((string) $cell);
        if ($key === '') {
            continue;
        }
        if ($map['student_id'] === null && preg_match('/\b(student id|id no|id number|student no|student number|sno)\b/', $key)) {
            $map['student_id'] = $i;
            continue;
        }
        if ($map['last_name'] === null && preg_match('/\b(last name|lastname|surname)\b/', $key)) {
            $map['last_name'] = $i;
            continue;
        }
        if ($map['first_name'] === null && preg_match('/\b(first name|firstname|given name)\b/', $key)) {
            $map['first_name'] = $i;
            continue;
        }
        if ($map['course'] === null && in_array($key, ['course', 'course #', 'subject', 'code', 'course code', 'course no'], true)) {
            $map['course'] = $i;
            continue;
        }
        if ($map['number'] === null && in_array($key, ['no', 'number'], true)) {
            $map['number'] = $i;
            continue;
        }
        if ($map['title'] === null && preg_match('/\b(title|descriptive|description)\b/', $key)) {
            $map['title'] = $i;
            continue;
        }
        if ($map['reexam'] === null && preg_match('/\b(re-?ex|re ex|reex|reexam|removal)\b/', $key)) {
            $map['reexam'] = $i;
            continue;
        }
        if ($map['grade'] === null && in_array($key, ['grade', 'rating', 'final', 'final grade', 'fg'], true)) {
            $map['grade'] = $i;
            continue;
        }
        if (preg_match('/\b(units|pre-req|prereq|preq|prerequisite|remarks|year|sy taken|status|section|gender|sex|middle|m i|lec|lab|lecture|laboratory)\b/', $key)) {
            continue;
        }
        if (in_array($key, ['name', 'student', 'student name', 'full name', 'year level'], true)) {
            continue;
        }
        $map['subjects'][$i] = (string) $cell;
    }
    return $map;
}

function pastedRowLooksLikeHeader(array $cells): bool {
    $first = pastedHeaderKey((string) ($cells[0] ?? ''));
    if ($first !== '' && in_array($first, [
        'course', 'subject', 'code', 'course code', 'course no', 'student', 'student id',
        'id no', 'name', 'last name', 'student no', 'student number',
    ], true)) {
        return true;
    }
    $joined = pastedHeaderKey(implode(' ', $cells));
    if (preg_match('/\b(student id|student no|id no|id number|last name|first name|descriptive title|course description|course code|course no|course #|final grade|re ex|re-ex|reexam)\b/', $joined)) {
        return true;
    }
    if ($first !== '' && looksLikeCourseCode((string) ($cells[0] ?? ''))) {
        return false;
    }
    return (bool) preg_match('/\b(grade|descriptive|description|units)\b/', $joined);
}

function findStudentsForGradeEntry(array $studentIds, array $nameKeys = []): array {
    $db = getDB();
    $byId = [];
    $ids = [];
    foreach ($studentIds as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);
    $select = "SELECT u.id, u.student_id, u.first_name, u.last_name, u.middle_name, sp.course_id
            FROM users u
            LEFT JOIN student_profiles sp ON sp.user_id = u.id
            WHERE u.role_id = 1";
    if ($ids !== []) {
        foreach (array_chunk($ids, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $db->prepare("$select AND u.student_id IN ($placeholders)");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                indexGradeEntryStudent($byId, $row);
            }
        }
        $normIds = [];
        foreach ($ids as $id) {
            $norm = normalizeStudentIdKey($id);
            if ($norm !== '' && !isset($byId[strtoupper($id)]) && !isset($byId[$norm])) {
                $normIds[$norm] = $norm;
            }
        }
        $normIds = array_values($normIds);
        if ($normIds !== []) {
            foreach (array_chunk($normIds, 400) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $db->prepare("$select AND REPLACE(REPLACE(REPLACE(UPPER(IFNULL(u.student_id, '')), '-', ''), ' ', ''), '/', '') IN ($placeholders)");
                $stmt->execute($chunk);
                foreach ($stmt->fetchAll() as $row) {
                    indexGradeEntryStudent($byId, $row);
                }
            }
        }
    }

    $byName = [];
    if ($nameKeys !== []) {
        $stmt = $db->query("$select AND u.is_active = 1");
        foreach ($stmt->fetchAll() as $row) {
            $key = normalizeGradeCodeKey(($row['last_name'] ?? '') . ',' . ($row['first_name'] ?? ''));
            $byName[$key] = $row;
        }
    }

    return ['by_id' => $byId, 'by_name' => $byName];
}

function indexGradeEntryStudent(array &$byId, array $row): void {
    $sid = strtoupper(trim((string) ($row['student_id'] ?? '')));
    if ($sid !== '') {
        $byId[$sid] = $row;
    }
    $norm = normalizeStudentIdKey($sid);
    if ($norm !== '') {
        $byId[$norm] = $row;
    }
}

function resolvePastedStudent(?array $people, string $studentRef): ?array {
    $studentRef = trim($studentRef);
    if ($studentRef === '' || $people === null) {
        return null;
    }
    $upper = strtoupper($studentRef);
    return $people['by_id'][$upper] ?? $people['by_id'][normalizeStudentIdKey($studentRef)] ?? null;
}

function gradePasteEntry(?array $user, ?array $subject, string $grade, string $studentRef, string $subjectRef, bool $singleStudent, array $meta = []): array {
    $grade = formatStudentGrade($grade);
    $status = 'ok';
    if (!$singleStudent && !$user) {
        $status = 'unknown_student';
    } elseif (!$subject) {
        $status = 'unknown_subject';
    } elseif ($grade === '') {
        $status = 'empty';
    }
    return [
        'user'         => $user,
        'subject'      => $subject,
        'grade'        => $grade,
        'student_ref'  => $studentRef,
        'subject_ref'  => $subjectRef,
        'pasted_title' => (string) ($meta['pasted_title'] ?? ''),
        'status'       => $status,
        'school_year'  => (string) ($meta['school_year'] ?? ''),
        'semester'     => (string) ($meta['semester'] ?? ''),
        'reexam'       => (string) ($meta['reexam'] ?? ''),
        'grade_note'   => (string) ($meta['grade_note'] ?? ''),
        'source_grade' => (string) ($meta['source_grade'] ?? ''),
    ];
}

function transcriptDefaultColumnMap(array $sampleRow = []): array {
    $courseCol = 0;
    $numberCol = null;
    $titleCol = 1;
    if (isset($sampleRow[1]) && preg_match('/^\d{1,4}[A-Za-z]{0,3}$/', trim((string) $sampleRow[1]))) {
        $numberCol = 1;
        $titleCol = 2;
    }
    return [
        'course'  => $courseCol,
        'number'  => $numberCol,
        'title'   => $titleCol,
        'grade'   => 6,
        'reexam'  => 7,
    ];
}

function parseTranscriptGradeSheet(array $rows, array $subjects, array $options = []): array {
    $singleStudent = !empty($options['single_student']);
    $index = prospectusSubjectIndex($subjects);
    $headerMap = null;
    $dataStart = 0;
    foreach (array_slice($rows, 0, 12) as $i => $row) {
        if (pastedRowLooksLikeHeader($row) && !pastedRowLooksLikeTermHeader($row)) {
            $headerMap = detectPastedHeaderMap($row);
            $dataStart = $i + 1;
            break;
        }
    }

    $sampleRow = null;
    for ($i = $dataStart, $n = count($rows); $i < $n; $i++) {
        $row = $rows[$i];
        if (pastedRowLooksLikeTermHeader($row) || pastedRowLooksLikeHeader($row)) {
            continue;
        }
        $course = trim((string) ($row[0] ?? ''));
        if ($course !== '' && looksLikeCourseCode($course)) {
            $sampleRow = $row;
            break;
        }
    }

    $defaults = transcriptDefaultColumnMap($sampleRow ?? []);
    $courseCol = $headerMap['course'] ?? $defaults['course'];
    $titleCol = $headerMap['title'] ?? $defaults['title'];
    $numberCol = $headerMap['number'] ?? $defaults['number'];
    $gradeCol = $headerMap['grade'] ?? $defaults['grade'];
    $reexamCol = $headerMap['reexam'] ?? $defaults['reexam'];

    $entries = [];
    $term = ['semester' => '', 'school_year' => ''];
    $user = $options['student'] ?? null;
    $studentRef = (string) ($user['student_id'] ?? '');

    for ($r = $dataStart, $n = count($rows); $r < $n; $r++) {
        $row = $rows[$r];
        if (pastedRowLooksLikeTermHeader($row)) {
            $parsedTerm = parsePastedTermHeader($row);
            if ($parsedTerm['semester'] !== '' || $parsedTerm['school_year'] !== '') {
                $term = $parsedTerm;
            }
            continue;
        }
        if (pastedRowLooksLikeHeader($row)) {
            continue;
        }
        $course = trim((string) ($row[$courseCol] ?? ''));
        if ($course === '' || $course === '-' || $course === '—') {
            continue;
        }
        if (!looksLikeCourseCode($course) && count($row) < 6) {
            continue;
        }
        $title = trim((string) ($row[$titleCol] ?? ''));
        $number = $numberCol !== null ? trim((string) ($row[$numberCol] ?? '')) : '';
        $sourceGrade = (string) ($row[$gradeCol] ?? '');
        $reexam = (string) ($row[$reexamCol] ?? '');
        $resolved = resolveTranscriptGrade($sourceGrade, $reexam);
        $subject = lookupProspectusSubject($index, $course, $number, $title);
        $entries[] = gradePasteEntry($user, $subject, $resolved['grade'], $studentRef, trim($course . ' ' . $number), $singleStudent, [
            'school_year'  => $term['school_year'],
            'semester'     => $term['semester'],
            'reexam'       => $reexam,
            'grade_note'   => $resolved['note'],
            'source_grade' => $sourceGrade,
            'pasted_title' => $title,
        ]);
    }

    $ok = 0;
    $missingSubject = 0;
    foreach ($entries as $entry) {
        if ($entry['status'] === 'ok') {
            $ok++;
        } elseif ($entry['status'] === 'unknown_subject') {
            $missingSubject++;
        }
    }

    $warnings = [];
    if (!$singleStudent && !$user) {
        $warnings[] = 'Select the student this evaluation sheet belongs to, then paste again.';
        foreach ($entries as $i => $entry) {
            if ($entry['status'] === 'ok') {
                $entries[$i]['status'] = 'unknown_student';
            }
        }
        $ok = 0;
    }
    if ($ok === 0 && $warnings === []) {
        $warnings[] = 'No matching grades were recognized. Check that the course codes match the prospectus.';
    }

    return [
        'mode'            => 'transcript',
        'entries'         => $entries,
        'ok'              => $ok,
        'missing_student' => (!$singleStudent && !$user) ? count($entries) : 0,
        'missing_subject' => $missingSubject,
        'warnings'        => $warnings,
    ];
}

function parsePastedGrades(string $text, array $subjects, array $options = []): array {
    if (pasteLooksLikeVerticalTranscript($text)) {
        $rows = splitVerticalTranscriptText($text);
        if ($rows !== [] && pasteLooksLikeTranscript($rows)) {
            return parseTranscriptGradeSheet($rows, $subjects, $options);
        }
    }

    $rows = splitPastedGradeText($text);
    $singleStudent = !empty($options['single_student']);
    $index = prospectusSubjectIndex($subjects);
    $orderedSubjects = array_values($subjects);

    if ($rows === []) {
        return ['entries' => [], 'warnings' => ['Nothing to paste. Copy the evaluation sheet from Excel, then paste here.'], 'mode' => 'empty', 'ok' => 0, 'missing_student' => 0, 'missing_subject' => 0];
    }

    if (pasteLooksLikeTranscript($rows)) {
        return parseTranscriptGradeSheet($rows, $subjects, $options);
    }

    $headerMap = null;
    $dataRows = $rows;
    if (pastedRowLooksLikeHeader($rows[0])) {
        $headerMap = detectPastedHeaderMap($rows[0]);
        $dataRows = array_slice($rows, 1);
    }

    $mode = 'pairs';
    if ($headerMap && $headerMap['course'] !== null && $headerMap['grade'] !== null) {
        $mode = 'table';
    } elseif ($headerMap && $headerMap['subjects'] !== []) {
        $mode = 'matrix';
    } elseif (!$singleStudent && isset($rows[0][0]) && looksLikeStudentId((string) $rows[0][0]) && count($rows[0]) >= 3) {
        $first = $rows[0];
        if (count($first) >= 3 && !looksLikeGradeValue((string) $first[1]) && looksLikeGradeValue((string) $first[2])) {
            $mode = 'triples';
        } elseif (count($first) > 3) {
            $mode = 'ordered';
        }
    } elseif ($singleStudent && count($rows[0]) === 1) {
        $mode = 'ordered';
    }

    $studentIds = [];
    $nameKeys = [];
    foreach ($dataRows as $row) {
        if ($headerMap && $headerMap['student_id'] !== null) {
            $studentIds[] = (string) ($row[$headerMap['student_id']] ?? '');
        } elseif (!$singleStudent && isset($row[0]) && looksLikeStudentId((string) $row[0])) {
            $studentIds[] = (string) $row[0];
        }
        if ($headerMap && $headerMap['last_name'] !== null && $headerMap['first_name'] !== null) {
            $nameKeys[] = normalizeGradeCodeKey(($row[$headerMap['last_name']] ?? '') . ',' . ($row[$headerMap['first_name']] ?? ''));
        }
    }
    $people = $singleStudent ? ['by_id' => [], 'by_name' => []] : findStudentsForGradeEntry($studentIds, $nameKeys);

    $entries = [];
    foreach ($dataRows as $row) {
        $studentRef = '';
        $user = $options['student'] ?? null;

        if (!$singleStudent) {
            if ($headerMap && $headerMap['student_id'] !== null) {
                $studentRef = (string) ($row[$headerMap['student_id']] ?? '');
            } elseif (isset($row[0]) && looksLikeStudentId((string) $row[0])) {
                $studentRef = (string) $row[0];
            }
            if ($studentRef !== '') {
                $user = resolvePastedStudent($people, $studentRef);
            }
            if (!$user && $headerMap && $headerMap['last_name'] !== null && $headerMap['first_name'] !== null) {
                $nameKey = normalizeGradeCodeKey(($row[$headerMap['last_name']] ?? '') . ',' . ($row[$headerMap['first_name']] ?? ''));
                $user = $people['by_name'][$nameKey] ?? $user;
                if ($studentRef === '') {
                    $studentRef = trim(($row[$headerMap['last_name']] ?? '') . ', ' . ($row[$headerMap['first_name']] ?? ''));
                }
            }
        } elseif ($singleStudent && $user) {
            $mine = normalizeStudentIdKey((string) ($user['student_id'] ?? ''));
            $rowId = '';
            if ($headerMap && $headerMap['student_id'] !== null) {
                $rowId = trim((string) ($row[$headerMap['student_id']] ?? ''));
            } elseif (isset($row[0]) && looksLikeStudentId((string) $row[0])) {
                $rowId = trim((string) $row[0]);
            }
            if ($rowId !== '' && $mine !== '' && normalizeStudentIdKey($rowId) !== $mine) {
                continue;
            }
        }

        if ($mode === 'table' && $headerMap) {
            $course = (string) ($row[$headerMap['course']] ?? '');
            $number = $headerMap['number'] !== null ? (string) ($row[$headerMap['number']] ?? '') : '';
            $grade = $headerMap['grade'] !== null ? (string) ($row[$headerMap['grade']] ?? '') : '';
            if ($course === '' && $grade === '') {
                continue;
            }
            $subject = lookupProspectusSubject($index, $course, $number);
            $entries[] = gradePasteEntry($user, $subject, $grade, $studentRef, trim($course . ' ' . $number), $singleStudent);
            continue;
        }

        if ($mode === 'matrix' && $headerMap) {
            foreach ($headerMap['subjects'] as $col => $label) {
                $grade = (string) ($row[$col] ?? '');
                if ($grade === '') {
                    continue;
                }
                $subject = lookupProspectusSubject($index, $label);
                $entries[] = gradePasteEntry($user, $subject, $grade, $studentRef, $label, $singleStudent);
            }
            continue;
        }

        if ($mode === 'triples') {
            $studentRef = (string) ($row[0] ?? '');
            $user = resolvePastedStudent($people, $studentRef);
            $subjectRef = trim((string) ($row[1] ?? ''));
            $grade = (string) ($row[2] ?? '');
            $subject = lookupProspectusSubject($index, $subjectRef);
            $entries[] = gradePasteEntry($user, $subject, $grade, $studentRef, $subjectRef, false);
            continue;
        }

        if ($mode === 'ordered') {
            $offset = 0;
            if (!$singleStudent && isset($row[0]) && looksLikeStudentId((string) $row[0])) {
                $studentRef = (string) $row[0];
                $user = resolvePastedStudent($people, $studentRef) ?? $user;
                $offset = 1;
            }
            $grades = array_slice($row, $offset);
            foreach ($grades as $i => $grade) {
                if (trim((string) $grade) === '') {
                    continue;
                }
                $subject = $orderedSubjects[$i] ?? null;
                $entries[] = gradePasteEntry($user, $subject, (string) $grade, $studentRef, $subject ? prospectusSubjectCode($subject) : 'Column ' . ($i + 1), $singleStudent);
            }
            continue;
        }

        $start = (!$singleStudent && looksLikeStudentId((string) ($row[0] ?? ''))) ? 1 : 0;
        if (count($row) >= $start + 3 && looksLikeGradeValue((string) $row[count($row) - 1]) && (!looksLikeGradeValue((string) $row[$start + 1]) || looksLikeCourseCode((string) $row[$start]))) {
            if ($start === 1) {
                $studentRef = (string) $row[0];
                $user = resolvePastedStudent($people, $studentRef) ?? $user;
            }
            $subject = lookupProspectusSubject($index, (string) $row[$start], (string) ($row[$start + 1] ?? ''));
            $entries[] = gradePasteEntry($user, $subject, (string) $row[count($row) - 1], $studentRef, trim($row[$start] . ' ' . ($row[$start + 1] ?? '')), $singleStudent);
            continue;
        }

        if (count($row) >= 2) {
            $last = (string) $row[count($row) - 1];
            if ($start === 1) {
                $studentRef = (string) $row[0];
                $user = resolvePastedStudent($people, $studentRef) ?? $user;
            }
            $subjectRef = trim(implode(' ', array_slice($row, $start, count($row) - 1 - $start)));
            $subject = lookupProspectusSubject($index, $subjectRef);
            $entries[] = gradePasteEntry($user, $subject, $last, $studentRef, $subjectRef, $singleStudent);
        }
    }

    $ok = 0;
    $missingStudent = 0;
    $missingSubject = 0;
    foreach ($entries as $entry) {
        if ($entry['status'] === 'ok') {
            $ok++;
        } elseif ($entry['status'] === 'unknown_student') {
            $missingStudent++;
        } elseif ($entry['status'] === 'unknown_subject') {
            $missingSubject++;
        }
    }

    return [
        'mode'             => $mode,
        'entries'          => $entries,
        'ok'               => $ok,
        'missing_student'  => $missingStudent,
        'missing_subject'  => $missingSubject,
        'warnings'         => $ok === 0 ? ['No matching grades were recognized. Check the course codes and student IDs.'] : [],
    ];
}

function saveParsedGradeEntries(array $parsed, int $updatedBy = 0, array $meta = []): array {
    $saved = 0;
    $skipped = 0;
    $byStudent = [];
    foreach ($parsed['entries'] as $entry) {
        if (($entry['status'] ?? '') !== 'ok' || ($entry['grade'] ?? '') === '') {
            $skipped++;
            continue;
        }
        $userId = (int) ($entry['user']['id'] ?? 0);
        $subjectId = (int) ($entry['subject']['id'] ?? 0);
        if ($userId <= 0 || $subjectId <= 0) {
            $skipped++;
            continue;
        }
        $byStudent[$userId][$subjectId] = [
            'grade'       => $entry['grade'],
            'remarks'     => inferGradeRemark($entry['grade']),
            'school_year' => ($entry['school_year'] ?? '') !== '' ? $entry['school_year'] : ($meta['school_year'] ?? ''),
            'semester'    => ($entry['semester'] ?? '') !== '' ? $entry['semester'] : ($entry['subject']['semester'] ?? ''),
        ];
    }

    foreach ($byStudent as $userId => $grades) {
        $prospectusId = (int) ($meta['prospectus_id'] ?? 0);
        if ($prospectusId <= 0) {
            $firstSubjectId = (int) array_key_first($grades);
            $stmt = getDB()->prepare('SELECT prospectus_id FROM prospectus_subjects WHERE id = ?');
            $stmt->execute([$firstSubjectId]);
            $prospectusId = (int) $stmt->fetchColumn();
        }
        $result = saveStudentSubjectGrades((int) $userId, $prospectusId, $grades, $updatedBy);
        if (!empty($result['ok'])) {
            $saved += count($grades);
        } else {
            $skipped += count($grades);
        }
    }

    return ['saved' => $saved, 'skipped' => $skipped];
}

function postedSubjectMappings(): array {
    $mapped = [];
    foreach (($_POST['map_subject'] ?? []) as $index => $subjectId) {
        $mapped[(int) $index] = (int) $subjectId;
    }
    return $mapped;
}

function recountParsedPaste(array $parsed): array {
    $ok = 0;
    $missingStudent = 0;
    $missingSubject = 0;
    $empty = 0;
    foreach ($parsed['entries'] ?? [] as $entry) {
        $status = (string) ($entry['status'] ?? '');
        if ($status === 'ok') {
            $ok++;
        } elseif ($status === 'unknown_student') {
            $missingStudent++;
        } elseif ($status === 'unknown_subject') {
            $missingSubject++;
        } elseif ($status === 'empty') {
            $empty++;
        }
    }
    $parsed['ok'] = $ok;
    $parsed['missing_student'] = $missingStudent;
    $parsed['missing_subject'] = $missingSubject;
    $parsed['empty'] = $empty;
    if ($ok === 0 && empty($parsed['warnings'])) {
        $parsed['warnings'] = ['No matching grades are ready to apply. Review unmatched subjects or map them to the prospectus.'];
    } elseif ($ok > 0) {
        $parsed['warnings'] = [];
    }
    return $parsed;
}

function applyPastedSubjectMappings(array $parsed, array $subjects, array $mappings): array {
    if ($mappings === []) {
        return recountParsedPaste($parsed);
    }

    $byId = [];
    foreach ($subjects as $subject) {
        $byId[(int) $subject['id']] = $subject;
    }

    foreach ($parsed['entries'] as $index => $entry) {
        $mapId = (int) ($mappings[$index] ?? 0);
        if ($mapId <= 0 || !isset($byId[$mapId])) {
            continue;
        }
        $parsed['entries'][$index]['subject'] = $byId[$mapId];
        $parsed['entries'][$index]['status'] = ($entry['grade'] ?? '') === '' ? 'empty' : 'ok';
    }

    return recountParsedPaste($parsed);
}

function buildPastedSubjectComparison(array $parsed, array $subjects): array {
    $matched = [];
    $notInProspectus = [];
    $matchedIds = [];

    foreach ($parsed['entries'] ?? [] as $index => $entry) {
        $entry['_index'] = (int) $index;
        $subjectId = (int) ($entry['subject']['id'] ?? 0);
        if ($subjectId > 0) {
            $matched[] = $entry;
            $matchedIds[$subjectId] = true;
        } else {
            $notInProspectus[] = $entry;
        }
    }

    $notInPaste = [];
    foreach ($subjects as $subject) {
        if (!isset($matchedIds[(int) $subject['id']])) {
            $notInPaste[] = $subject;
        }
    }

    $ready = 0;
    foreach ($matched as $entry) {
        if (($entry['status'] ?? '') === 'ok' && ($entry['grade'] ?? '') !== '') {
            $ready++;
        }
    }

    return [
        'matched'                  => $matched,
        'not_in_prospectus'        => $notInProspectus,
        'not_in_paste'             => $notInPaste,
        'matched_count'            => count($matched),
        'not_in_prospectus_count'  => count($notInProspectus),
        'not_in_paste_count'       => count($notInPaste),
        'ready_count'              => $ready,
        'can_apply'                => $ready > 0,
    ];
}

function evaluatePastedSubjects(string $pasteText, array $subjects, array $options = [], array $mappings = []): array {
    $parsed = parsePastedGrades($pasteText, $subjects, $options);
    $parsed = applyPastedSubjectMappings($parsed, $subjects, $mappings);
    $parsed['comparison'] = buildPastedSubjectComparison($parsed, $subjects);
    if (($parsed['comparison']['ready_count'] ?? 0) > 0) {
        $parsed['warnings'] = [];
    }
    return $parsed;
}

function pastedEntryTermLabel(array $entry): string {
    $sem = prospectusSemesterOptions()[$entry['semester'] ?? ''] ?? '';
    $year = trim((string) ($entry['school_year'] ?? ''));
    return trim($year . ($sem !== '' ? ($year !== '' ? ' · ' : '') . $sem : ''));
}

function renderGradesPasteReview(array $parsed, array $subjects, array $hiddenFields = []): void {
    $comparison = $parsed['comparison'] ?? buildPastedSubjectComparison($parsed, $subjects);
    $warnings = $parsed['warnings'] ?? [];
    $ready = (int) ($comparison['ready_count'] ?? 0);
    $applyLabel = $ready === 1 ? 'Apply 1 matching grade' : 'Apply ' . $ready . ' matching grades';
    $mappings = postedSubjectMappings();
    ?>
    <section class="grades-compare-review" id="gradesCompareReview">
        <div class="grades-compare-review-head">
            <h3>Confirmation review</h3>
            <p class="text-muted" style="margin:.3rem 0 0">
                Compare the pasted sheet with the course prospectus. Map unmatched subjects if needed, re-evaluate, then apply matching grades.
            </p>
        </div>
        <div class="enrollment-report-summary grades-eval-summary">
            <div class="enrollment-report-summary-item"><span>Matched</span><strong><?= (int) ($comparison['matched_count'] ?? 0) ?></strong></div>
            <div class="enrollment-report-summary-item"><span>Not in prospectus</span><strong><?= (int) ($comparison['not_in_prospectus_count'] ?? 0) ?></strong></div>
            <?php if ((int) ($comparison['not_in_paste_count'] ?? 0) > 0): ?>
                <button type="button" class="enrollment-report-summary-item grades-compare-summary-btn" data-open-missing-paste-modal>
                    <span>Not in pasted sheet</span><strong><?= (int) $comparison['not_in_paste_count'] ?></strong>
                </button>
            <?php else: ?>
                <div class="enrollment-report-summary-item"><span>Not in pasted sheet</span><strong>0</strong></div>
            <?php endif; ?>
            <div class="enrollment-report-summary-item enrollment-report-summary-grand"><span>Ready to apply</span><strong><?= $ready ?></strong></div>
        </div>
        <?php foreach ($warnings as $warning): ?>
            <div class="alert alert-warning"><?= e((string) $warning) ?></div>
        <?php endforeach; ?>
        <?php if ((int) ($comparison['not_in_prospectus_count'] ?? 0) > 0): ?>
            <div class="alert alert-warning">
                Subjects not found on the prospectus will be skipped unless you map them below and re-evaluate.
            </div>
        <?php endif; ?>
        <?php if ((int) ($comparison['not_in_paste_count'] ?? 0) > 0): ?>
            <div class="alert alert-warning">
                Prospectus subjects missing from the pasted sheet keep their current evaluation grades.
            </div>
        <?php endif; ?>

        <form method="POST" class="grades-compare-form">
            <?= csrfField() ?>
            <?php foreach ($hiddenFields as $name => $value): ?>
                <?php if ((string) $name === 'paste_text'): ?>
                    <textarea name="paste_text" hidden><?= e((string) $value) ?></textarea>
                <?php else: ?>
                    <input type="hidden" name="<?= e((string) $name) ?>" value="<?= e((string) $value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="grades-compare-section">
                <h4>Matched subjects</h4>
                <?php if (empty($comparison['matched'])): ?>
                    <p class="text-muted">No pasted subjects matched the prospectus yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table data-table-responsive grades-compare-table">
                            <thead>
                                <tr>
                                    <th>Pasted</th>
                                    <th>Prospectus</th>
                                    <th>Term</th>
                                    <th>Grade</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comparison['matched'] as $entry): ?>
                                    <?php
                                    $subject = $entry['subject'] ?? [];
                                    $status = (string) ($entry['status'] ?? '');
                                    $rowClass = $status === 'ok' ? 'is-passed' : '';
                                    ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td data-label="Pasted">
                                            <?= e((string) ($entry['subject_ref'] ?: '—')) ?>
                                            <?php if (($entry['pasted_title'] ?? '') !== ''): ?>
                                                <div class="text-muted"><?= e((string) $entry['pasted_title']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Prospectus">
                                            <?= e(prospectusSubjectCode($subject)) ?>
                                            <div class="text-muted"><?= e((string) ($subject['title'] ?? '')) ?></div>
                                        </td>
                                        <td data-label="Term"><?= e(pastedEntryTermLabel($entry) ?: '—') ?></td>
                                        <td data-label="Grade">
                                            <?= e((string) ($entry['grade'] ?? '')) ?>
                                            <?php if (($entry['grade_note'] ?? '') === 'Re-Ex' && ($entry['source_grade'] ?? '') !== ''): ?>
                                                <div class="text-muted">Re-Ex (was <?= e((string) $entry['source_grade']) ?>)</div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Status"><?= e(gradePasteStatusLabel($status)) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="grades-compare-section">
                <h4>Not in prospectus</h4>
                <p class="text-muted">These pasted subjects were not found on the selected course prospectus.</p>
                <?php if (empty($comparison['not_in_prospectus'])): ?>
                    <p class="text-muted">None. Every pasted subject matched the prospectus.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="data-table data-table-responsive grades-compare-table">
                            <thead>
                                <tr>
                                    <th>Pasted subject</th>
                                    <th>Term</th>
                                    <th>Grade</th>
                                    <th>Map to prospectus</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comparison['not_in_prospectus'] as $entry): ?>
                                    <?php $index = (int) ($entry['_index'] ?? 0); ?>
                                    <tr class="is-failed">
                                        <td data-label="Pasted subject">
                                            <?= e((string) ($entry['subject_ref'] ?: '—')) ?>
                                            <?php if (($entry['pasted_title'] ?? '') !== ''): ?>
                                                <div class="text-muted"><?= e((string) $entry['pasted_title']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Term"><?= e(pastedEntryTermLabel($entry) ?: '—') ?></td>
                                        <td data-label="Grade"><?= e((string) ($entry['grade'] ?? '')) ?></td>
                                        <td data-label="Map to prospectus">
                                            <select name="map_subject[<?= $index ?>]" aria-label="Map pasted subject">
                                                <option value="">Skip — not in prospectus</option>
                                                <?php foreach ($subjects as $subject): ?>
                                                    <option value="<?= (int) $subject['id'] ?>" <?= (int) ($mappings[$index] ?? 0) === (int) $subject['id'] ? 'selected' : '' ?>>
                                                        <?= e(prospectusSubjectCode($subject) . ' — ' . ($subject['title'] ?? '')) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="grades-compare-section">
                <div class="grades-compare-section-head">
                    <div>
                        <h4>Not in pasted sheet</h4>
                        <p class="text-muted">These prospectus subjects were not in the pasted sheet and will keep their current grades.</p>
                    </div>
                    <?php if (!empty($comparison['not_in_paste'])): ?>
                        <button type="button" class="btn btn-outline btn-sm" data-open-missing-paste-modal>
                            <i class="fas fa-eye"></i>
                            Review <?= (int) $comparison['not_in_paste_count'] ?> subject<?= (int) $comparison['not_in_paste_count'] === 1 ? '' : 's' ?>
                        </button>
                    <?php endif; ?>
                </div>
                <?php if (empty($comparison['not_in_paste'])): ?>
                    <p class="text-muted">None. The pasted sheet covered every prospectus subject.</p>
                <?php else: ?>
                    <p class="text-muted" style="margin:0">
                        Open the review modal to inspect prospectus subjects that were not included in the pasted sheet.
                    </p>
                <?php endif; ?>
            </div>

            <div class="form-actions grades-compare-actions">
                <button type="submit" name="action" value="review_paste" class="btn btn-outline">
                    <i class="fas fa-rotate"></i> Re-evaluate subjects
                </button>
                <button type="submit" name="action" value="apply_paste" class="btn btn-primary" <?= $ready > 0 ? '' : 'disabled' ?>
                    onclick="return confirm(<?= e(json_encode('Apply ' . $ready . ' matching grade(s) to this evaluation? Unmatched subjects will be skipped.')) ?>);">
                    <i class="fas fa-check"></i> <?= e($applyLabel) ?>
                </button>
            </div>
        </form>
        <?php if (!empty($comparison['not_in_paste'])): ?>
            <div class="admin-form-modal grades-missing-paste-modal" id="gradesMissingPasteModal" aria-hidden="true">
                <div class="admin-form-modal-overlay" data-close-missing-paste-modal></div>
                <div class="admin-form-modal-dialog extra-wide" role="dialog" aria-modal="true" aria-labelledby="gradesMissingPasteTitle">
                    <div class="admin-form-modal-header">
                        <div>
                            <span class="admin-form-modal-eyebrow">Confirmation review</span>
                            <h2 class="admin-form-modal-title" id="gradesMissingPasteTitle">Subjects not in pasted sheet</h2>
                            <p class="text-muted" style="margin:.35rem 0 0">
                                <?= (int) $comparison['not_in_paste_count'] ?> prospectus subject<?= (int) $comparison['not_in_paste_count'] === 1 ? '' : 's' ?>
                                were not found in the pasted sheet and will keep their current grades.
                            </p>
                        </div>
                        <button type="button" class="admin-form-modal-close" data-close-missing-paste-modal aria-label="Close">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="admin-form-modal-body grades-missing-paste-modal-body">
                        <?php
                        $missingGrouped = [];
                        foreach ($comparison['not_in_paste'] as $subject) {
                            $year = (string) ($subject['year_level'] ?? '');
                            $sem = (string) ($subject['semester'] ?? '');
                            $missingGrouped[$year][$sem][] = $subject;
                        }
                        ?>
                        <?php foreach ($missingGrouped as $year => $semesters): ?>
                            <section class="grades-missing-paste-group">
                                <h3><?= e($year !== '' ? $year : 'Other') ?></h3>
                                <?php foreach ($semesters as $sem => $rows): ?>
                                    <h4><?= e(prospectusSemesterOptions()[$sem] ?? ($sem !== '' ? $sem : '—')) ?></h4>
                                    <div class="table-responsive">
                                        <table class="data-table data-table-responsive grades-compare-table">
                                            <thead>
                                                <tr>
                                                    <th>Course</th>
                                                    <th>No.</th>
                                                    <th>Descriptive Title</th>
                                                    <th>Units</th>
                                                    <th>Pre-req.</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($rows as $subject): ?>
                                                    <tr>
                                                        <td data-label="Course"><?= e((string) ($subject['course_code'] ?? '')) ?></td>
                                                        <td data-label="No."><?= e((string) ($subject['course_no'] ?? '')) ?></td>
                                                        <td data-label="Descriptive Title"><?= e((string) ($subject['title'] ?? '')) ?></td>
                                                        <td data-label="Units"><?= e(rtrim(rtrim(number_format((float) ($subject['units'] ?? 0), 1), '0'), '.')) ?></td>
                                                        <td data-label="Pre-req."><?= e((string) (($subject['prereq'] ?? '') !== '' ? $subject['prereq'] : '—')) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endforeach; ?>
                            </section>
                        <?php endforeach; ?>
                    </div>
                    <div class="admin-form-modal-footer">
                        <button type="button" class="btn btn-primary" data-close-missing-paste-modal>Done reviewing</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>
    <script>
    (function () {
        var review = document.getElementById('gradesCompareReview');
        if (review) {
            review.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        var modal = document.getElementById('gradesMissingPasteModal');
        if (!modal) return;

        function openModal() {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('grades-missing-paste-open');
        }

        function closeModal() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('grades-missing-paste-open');
        }

        document.querySelectorAll('[data-open-missing-paste-modal]').forEach(function (el) {
            el.addEventListener('click', openModal);
        });
        modal.querySelectorAll('[data-close-missing-paste-modal]').forEach(function (el) {
            el.addEventListener('click', closeModal);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
            }
        });
    })();
    </script>
    <?php
}

function buildStudentGradesEvaluation(array $student, array $prospectus, array $subjects, array $gradeMap): array {
    $passedCodes = [];
    foreach ($subjects as $subject) {
        $grade = $gradeMap[(int) $subject['id']] ?? null;
        if ($grade && isPassingGradeRemark($grade['remarks'] ?? inferGradeRemark($grade['grade'] ?? ''))) {
            $passedCodes[strtoupper(prospectusSubjectCode($subject))] = true;
        }
    }

    $rows = [];
    $unitsTotal = 0.0;
    $unitsEarned = 0.0;
    $unitsFailed = 0.0;
    $unitsIncomplete = 0.0;
    $gwaWeighted = 0.0;
    $gwaUnits = 0.0;
    $passed = 0;
    $failed = 0;
    $incomplete = 0;
    $noGrade = 0;
    $void = 0;

    foreach ($subjects as $subject) {
        $id = (int) $subject['id'];
        $saved = $gradeMap[$id] ?? [];
        $grade = formatStudentGrade((string) ($saved['grade'] ?? ''));
        $remark = (string) ($saved['remarks'] ?? '');
        if ($remark === '') {
            $remark = inferGradeRemark($grade);
        }

        $prereq = trim((string) ($subject['prereq'] ?? ''));
        $voidReason = prospectusSubjectVoidReason($subject, $subjects, $gradeMap, $passedCodes);
        $isVoid = $voidReason !== '';
        $prereqMet = !$isVoid;
        $units = (float) $subject['units'];
        $unitsTotal += $units;

        if ($isVoid) {
            $void++;
        } elseif ($remark === 'passed') {
            $passed++;
            $unitsEarned += $units;
        } elseif ($remark === 'failed') {
            $failed++;
            $unitsFailed += $units;
        } elseif ($remark === 'incomplete') {
            $incomplete++;
            $unitsIncomplete += $units;
        } elseif ($grade === '' && $remark === '') {
            $noGrade++;
        }

        if (!$isVoid && is_numeric($grade) && (float) $grade > 0) {
            $gwaWeighted += ((float) $grade) * $units;
            $gwaUnits += $units;
        }

        $rows[] = [
            'subject'     => $subject,
            'code'        => prospectusSubjectCode($subject),
            'grade'       => $grade,
            'remarks'     => $remark,
            'school_year' => $saved['school_year'] ?? '',
            'semester'    => $saved['semester'] ?? '',
            'prereq_met'  => $prereqMet,
            'is_void'     => $isVoid,
            'void_reason' => $voidReason,
        ];
    }

    return [
        'student'    => $student,
        'prospectus' => $prospectus,
        'rows'       => $rows,
        'grouped'    => groupProspectusSubjects(array_map(static function (array $row): array {
            $isTaken = ($row['grade'] ?? '') !== '';
            return array_merge($row['subject'], [
                '_grade'       => $row['grade'],
                '_remarks'     => $isTaken ? $row['remarks'] : '',
                '_school_year' => $isTaken ? $row['school_year'] : '',
                '_semester'    => $isTaken ? $row['semester'] : '',
                '_prereq_met'  => $row['prereq_met'],
                '_is_void'     => $row['is_void'],
                '_void_reason' => $row['void_reason'],
                '_is_taken'    => $isTaken,
            ]);
        }, $rows)),
        'summary' => [
            'subjects'         => count($subjects),
            'passed'           => $passed,
            'failed'           => $failed,
            'incomplete'       => $incomplete,
            'no_grade'         => $noGrade,
            'void'             => $void,
            'blocked'          => $void,
            'units_total'      => $unitsTotal,
            'units_earned'     => $unitsEarned,
            'units_failed'     => $unitsFailed,
            'units_incomplete' => $unitsIncomplete,
            'units_remaining'  => max(0, $unitsTotal - $unitsEarned),
            'gwa'              => $gwaUnits > 0 ? round($gwaWeighted / $gwaUnits, 2) : null,
        ],
    ];
}

function studentGradesEvaluationUrl(int $userId, int $prospectusId = 0): string {
    $query = ['student_user_id' => $userId];
    if ($prospectusId > 0) {
        $query['prospectus_id'] = $prospectusId;
    }
    return APP_URL . '/registrar/grades-evaluation.php?' . http_build_query($query);
}

function gradesEvaluationPrintUrl(int $userId, int $prospectusId = 0, array $extra = []): string {
    $query = array_filter([
        'student_user_id' => $userId > 0 ? (string) $userId : '',
        'prospectus_id'   => $prospectusId > 0 ? (string) $prospectusId : '',
    ] + $extra, static fn($value) => $value !== '' && $value !== null);
    return APP_URL . '/registrar/grades-evaluation-print.php' . ($query ? '?' . http_build_query($query) : '');
}

function loadGradesEvaluationBundle(int $studentId, int $prospectusId = 0): array {
    $student = $studentId > 0 ? loadStudentForGradesEvaluation($studentId) : null;
    $prospectus = null;
    $programId = 0;
    if ($student) {
        $programId = resolveStudentProgramId($student);
        if ($prospectusId > 0) {
            $prospectus = getProspectusById($prospectusId);
            if ($prospectus && $programId > 0 && (int) ($prospectus['program_id'] ?? 0) !== $programId) {
                $prospectus = null;
            }
        }
        if (!$prospectus && $programId > 0) {
            $prospectus = getActiveProspectusForProgram($programId);
        }
    }

    $evaluation = null;
    if ($student && $prospectus) {
        $subjects = getProspectusSubjects((int) $prospectus['id']);
        $gradeMap = getStudentSubjectGrades((int) $student['id'], (int) $prospectus['id']);
        $evaluation = buildStudentGradesEvaluation($student, $prospectus, $subjects, $gradeMap);
    }

    return [
        'student'    => $student,
        'prospectus' => $prospectus,
        'evaluation' => $evaluation,
        'program_id' => $programId,
    ];
}

function gradeTakenSemester(array $subject): string {
    $taken = trim((string) ($subject['_semester'] ?? ''));
    if ($taken !== '' && array_key_exists($taken, prospectusSemesterOptions())) {
        return $taken;
    }
    $placed = trim((string) ($subject['semester'] ?? ''));
    return array_key_exists($placed, prospectusSemesterOptions()) ? $placed : '';
}

function gradesEvaluationCsvHeaders(): array {
    return ['Year', 'Semester', 'Course #', 'Course Description', 'Preq', 'Units', 'Grade', 'Remarks', 'Semester Taken', 'SY Taken'];
}

function gradesEvaluationCsvRows(array $evaluation): array {
    $rows = [];
    foreach ($evaluation['grouped'] ?? [] as $yearBlock) {
        foreach ($yearBlock['semesters'] ?? [] as $semBlock) {
            foreach ($semBlock['subjects'] ?? [] as $subject) {
                $isTaken = !empty($subject['_is_taken']);
                $isVoid = $isTaken && !empty($subject['_is_void']);
                $rows[] = [
                    $yearBlock['label'] ?? '',
                    $semBlock['label'] ?? '',
                    prospectusSubjectCode($subject),
                    (string) ($subject['title'] ?? ''),
                    (string) ($subject['prereq'] ?? ''),
                    rtrim(rtrim(number_format((float) ($subject['units'] ?? 0), 1), '0'), '.'),
                    $isTaken ? ($isVoid ? '' : formatStudentGrade((string) ($subject['_grade'] ?? ''))) : '',
                    $isVoid ? 'Void' : ($isTaken ? gradeRemarkLabel($subject['_remarks'] ?? '') : ''),
                    $isTaken ? (prospectusSemesterOptions()[gradeTakenSemester($subject)] ?? '') : '',
                    $isTaken ? (string) ($subject['_school_year'] ?? '') : '',
                ];
            }
        }
    }
    return $rows;
}

function exportGradesEvaluationCsv(array $student, array $evaluation): void {
    $id = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($student['student_id'] ?: $student['id']));
    exportCSV(gradesEvaluationCsvHeaders(), gradesEvaluationCsvRows($evaluation), 'grades_evaluation_' . $id . '.csv');
}

function prospectusPrerequisitesMet(string $prereq, array $passedCodes, array $subjects, array $gradeMap): bool {
    $prereq = trim($prereq);
    if ($prereq === '' || strcasecmp($prereq, 'none') === 0) {
        return true;
    }

    $normalized = strtoupper($prereq);
    if (str_contains($normalized, 'ALL PROF')) {
        foreach ($subjects as $subject) {
            if (!prospectusSubjectIsProfessional($subject)) {
                continue;
            }
            $grade = $gradeMap[(int) $subject['id']] ?? null;
            $remark = $grade['remarks'] ?? inferGradeRemark($grade['grade'] ?? '');
            if (!isPassingGradeRemark($remark)) {
                return false;
            }
        }
        return true;
    }

    $parts = preg_split('/\s*(?:,|&|\/| and )\s*/i', $prereq) ?: [];
    foreach ($parts as $part) {
        $part = strtoupper(trim($part));
        if ($part === '') {
            continue;
        }
        if (empty($passedCodes[$part])) {
            return false;
        }
    }
    return true;
}

function prospectusSubjectIsProfessional(array $subject): bool {
    $code = strtoupper(trim((string) ($subject['course_code'] ?? '')));
    $gePrefixes = ['GE', 'GEC', 'GEE', 'GEM', 'PATHFIT', 'PE', 'NSTP', 'IC', 'ADGE'];
    return !in_array($code, $gePrefixes, true);
}

function findOrCreateProgramByCode(string $code, string $name): int {
    ensureAcademicProgramsSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM academic_programs WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    $id = (int) $stmt->fetchColumn();
    if ($id > 0) {
        return $id;
    }

    $maxOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) FROM academic_programs')->fetchColumn();
    $db->prepare('INSERT INTO academic_programs (code, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, 1)')
        ->execute([$code, $name, $name, $maxOrder + 1]);
    return (int) $db->lastInsertId();
}

function seedBscrimProspectus(): void {
    $db = getDB();
    $programId = findOrCreateProgramByCode('BSCRIM', 'Bachelor of Science in Criminology');

    $exists = $db->prepare('SELECT id FROM course_prospectuses WHERE program_id = ? LIMIT 1');
    $exists->execute([$programId]);
    if ($exists->fetch()) {
        return;
    }

    $yearOptions = schoolYearOptions();
    $curriculumYear = (string) array_key_first($yearOptions);
    $db->prepare('INSERT INTO course_prospectuses (program_id, curriculum_year, title, is_active) VALUES (?, ?, ?, 1)')
        ->execute([$programId, $curriculumYear, 'BS Criminology Course Prospectus']);
    $prospectusId = (int) $db->lastInsertId();

    $insert = $db->prepare('INSERT INTO prospectus_subjects
        (prospectus_id, year_level, semester, course_code, course_no, title, units, prereq, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $order = [];
    foreach (bscrimProspectusSubjectSeed() as $row) {
        $key = $row[0] . '|' . $row[1];
        $order[$key] = ($order[$key] ?? 0) + 1;
        $insert->execute([
            $prospectusId,
            $row[0],
            $row[1],
            $row[2],
            $row[3],
            $row[4],
            $row[5],
            $row[6] !== '' ? $row[6] : null,
            $order[$key],
        ]);
    }
}

function bscrimProspectusSubjectSeed(): array {
    return [
        ['1st Year', '1st_semester', 'GE', '105', 'Understanding the Self', 3, ''],
        ['1st Year', '1st_semester', 'GE', '106', 'Ethics', 3, ''],
        ['1st Year', '1st_semester', 'GEC', '107', 'The Contemporary World', 3, ''],
        ['1st Year', '1st_semester', 'CLJ', '1', 'Introduction to Philippine Criminal Justice System', 3, ''],
        ['1st Year', '1st_semester', 'CRIM', '1', 'Introduction to Criminology', 3, ''],
        ['1st Year', '1st_semester', 'AdGe', '001', 'General and Organic Chemistry', 3, ''],
        ['1st Year', '1st_semester', 'GEC', '108', 'Science, Technology and Society', 3, ''],
        ['1st Year', '1st_semester', 'IC', '101', 'JHCSC Civic Course', 1, ''],
        ['1st Year', '1st_semester', 'PATHFit', '1', 'Movement Competency Training', 2, ''],
        ['1st Year', '1st_semester', 'PE', '1', 'Fundamentals of Martial Arts', 2, ''],
        ['1st Year', '1st_semester', 'NSTP', '1', 'National Service Training Program 1 (ROTC)', 3, ''],

        ['1st Year', '2nd_semester', 'GEE', '101', 'Living in the IT Era', 3, ''],
        ['1st Year', '2nd_semester', 'GEC', '101', 'Purposive Communication', 3, ''],
        ['1st Year', '2nd_semester', 'GEC', '102', 'Readings in Philippine History', 3, ''],
        ['1st Year', '2nd_semester', 'GEC', '103', 'Mathematics in the Modern World', 3, ''],
        ['1st Year', '2nd_semester', 'GEC', '104', 'Art Appreciation', 3, ''],
        ['1st Year', '2nd_semester', 'LEA', '1', 'Law Enforcement Organization and Administration (Inter-Agency Approach)', 4, ''],
        ['1st Year', '2nd_semester', 'CLJ', '2', 'Human Rights Education', 3, ''],
        ['1st Year', '2nd_semester', 'PATHFit', '2', 'Exercise-Based Fitness Activities', 2, 'PATHFit 1'],
        ['1st Year', '2nd_semester', 'PE', '2', 'Arnis and Disarming Technique', 2, ''],
        ['1st Year', '2nd_semester', 'NSTP', '2', 'National Service Training Program 2 (ROTC)', 3, 'NSTP 1'],

        ['2nd Year', '1st_semester', 'CLJ', '3', 'Criminal Law (Book 1)', 3, ''],
        ['2nd Year', '1st_semester', 'FORENSIC', '1', 'Forensic Photography', 3, ''],
        ['2nd Year', '1st_semester', 'CRIM', '2', 'Theories of Crime Causation', 3, 'CRIM 1'],
        ['2nd Year', '1st_semester', 'LEA', '2', 'Comparative Models in Policing', 3, 'LEA 1'],
        ['2nd Year', '1st_semester', 'CDI', '1', 'Fundamentals of Criminal Investigation and Intelligence', 4, ''],
        ['2nd Year', '1st_semester', 'CA', '1', 'Institutional Correction', 3, 'CDI 1'],
        ['2nd Year', '1st_semester', 'CFLM', '1', 'Character Formation, Nationalism and Patriotism', 3, ''],
        ['2nd Year', '1st_semester', 'CRIM', '3', 'Human Behavior and Victimology', 3, 'CRIM 1'],
        ['2nd Year', '1st_semester', 'PE', '3', 'First Aid and Water Safety', 2, ''],

        ['2nd Year', '2nd_semester', 'CLJ', '4', 'Criminal Law (Book 2)', 4, 'CLJ 3'],
        ['2nd Year', '2nd_semester', 'CDI', '2', 'Specialized Crime Investigation 1 with Legal Medicine', 3, 'CDI 1'],
        ['2nd Year', '2nd_semester', 'FORENSIC', '2', 'Personal Identification Techniques', 3, ''],
        ['2nd Year', '2nd_semester', 'CRIM', '4', 'Professional Conduct and Ethical Standard', 3, 'GE 106'],
        ['2nd Year', '2nd_semester', 'LEA', '3', 'Introduction to Industrial Security Concept', 3, ''],
        ['2nd Year', '2nd_semester', 'GEE', '103', 'Entrepreneurial Mind', 3, ''],
        ['2nd Year', '2nd_semester', 'LEA', '4', 'Law Enforcement Operations and Planning with Crime Mapping', 3, ''],
        ['2nd Year', '2nd_semester', 'GEE', '105', 'Gender and Society', 3, ''],
        ['2nd Year', '2nd_semester', 'PE', '4', 'Fundamentals of Marksmanship', 2, ''],

        ['3rd Year', '1st_semester', 'CDI', '3', 'Specialized Crime Investigation 2 with Simulation or Role-Play', 3, 'CDI 2'],
        ['3rd Year', '1st_semester', 'CDI', '4', 'Traffic Management and Accident Investigation with Driving', 3, 'CDI 1'],
        ['3rd Year', '1st_semester', 'CRIM', '5', 'Juvenile Delinquency and Juvenile Justice System', 3, ''],
        ['3rd Year', '1st_semester', 'FORENSIC', '3', 'Forensic Chemistry and Toxicology', 5, 'AdGe 001'],
        ['3rd Year', '1st_semester', 'CA', '2', 'Non-Institutional Corrections', 3, 'CA 1'],
        ['3rd Year', '1st_semester', 'CRIM', '6', 'Dispute Resolution and Crisis/Incidents Management', 3, ''],
        ['3rd Year', '1st_semester', 'CDI', '5', 'Technical English 1 (Investigative Report Writing and Presentation)', 3, ''],
        ['3rd Year', '1st_semester', 'CRIM', '7', 'Criminological Research 1 (Research Methods with Applied Statistics)', 3, ''],
        ['3rd Year', '1st_semester', 'CLJ', '5', 'Evidence', 3, 'CLJ 4'],

        ['3rd Year', '2nd_semester', 'CFLM', '2', 'Character Formation 2 — Leadership, Decision Making, Management and Administration', 3, 'CFLM 1'],
        ['3rd Year', '2nd_semester', 'CDI', '6', 'Fire Protection and Arson Investigation', 3, ''],
        ['3rd Year', '2nd_semester', 'CDI', '7', 'Vice and Drug Education and Control', 3, ''],
        ['3rd Year', '2nd_semester', 'FORENSIC', '4', 'Questioned Documents Examination', 3, ''],
        ['3rd Year', '2nd_semester', 'FORENSIC', '5', 'Lie Detection Techniques', 3, ''],
        ['3rd Year', '2nd_semester', 'CDI', '8', 'Technical English 2 (Legal Forms)', 3, 'CDI 5'],
        ['3rd Year', '2nd_semester', 'CLJ', '6', 'Criminal Procedure and Court Testimony', 3, 'CLJ 5'],
        ['3rd Year', '2nd_semester', 'FORENSIC', '6', 'Forensic Ballistics', 3, ''],
        ['3rd Year', '2nd_semester', 'CRIM', '8', 'Criminological Research 2 (Thesis Administration and Presentation)', 3, 'CRIM 7'],
        ['3rd Year', '2nd_semester', 'CA', '3', 'Therapeutic Modalities', 2, 'CA 2'],

        ['4th Year', '1st_semester', 'Crim Pract', '1', 'Internship (On the Job Training 1)', 3, 'All Professional Subjects'],
        ['4th Year', '1st_semester', 'GEM', '101', 'Life and Works of Rizal', 3, ''],
        ['4th Year', '1st_semester', 'PS', '1', 'Professional Seminar 1', 3, ''],

        ['4th Year', '2nd_semester', 'Crim Pract', '2', 'Internship (On the Job Training 2)', 3, 'Crim Pract 1'],
        ['4th Year', '2nd_semester', 'CDI', '9', 'Introduction to Cybercrime and Environmental Laws and Protection', 3, ''],
        ['4th Year', '2nd_semester', 'PS', '2', 'Professional Seminar 2', 3, 'PS 1'],
    ];
}
