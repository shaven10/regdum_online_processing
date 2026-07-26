<?php

function ensureAcademicProgramsSchema(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS academic_programs (
        id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        description TEXT,
        is_active TINYINT(1) DEFAULT 1,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $courseIdCol = $db->query("SHOW COLUMNS FROM student_profiles LIKE 'course_id'")->fetch();
    if (!$courseIdCol) {
        $db->exec('ALTER TABLE student_profiles ADD COLUMN course_id TINYINT UNSIGNED NULL AFTER course');
    }

    seedDefaultAcademicPrograms();
    syncStudentProfileCourseIds();
}

function seedDefaultAcademicPrograms(): void {
    $db = getDB();
    $count = (int) $db->query('SELECT COUNT(*) FROM academic_programs')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $defaults = [
        ['BSIT', 'BS Information Technology', 'Information Technology program', 1],
        ['BSCS', 'BS Computer Science', 'Computer Science program', 2],
        ['BSBA', 'BS Business Administration', 'Business Administration program', 3],
        ['BSA', 'BS Accountancy', 'Accountancy program', 4],
        ['BSED', 'BS Education', 'Education program', 5],
        ['BSN', 'BS Nursing', 'Nursing program', 6],
        ['BSCPE', 'BS Computer Engineering', 'Computer Engineering program', 7],
        ['BSHM', 'BS Hospitality Management', 'Hospitality Management program', 8],
    ];

    $stmt = $db->prepare('INSERT INTO academic_programs (code, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, 1)');
    foreach ($defaults as [$code, $name, $desc, $order]) {
        $stmt->execute([$code, $name, $desc, $order]);
    }
}

function syncStudentProfileCourseIds(): void {
    $db = getDB();
    $db->exec('UPDATE student_profiles sp
        INNER JOIN academic_programs ap ON sp.course = ap.name
        SET sp.course_id = ap.id
        WHERE sp.course_id IS NULL AND sp.course IS NOT NULL AND sp.course != ""');
}

function getActiveAcademicPrograms(): array {
    ensureAcademicProgramsSchema();
    $db = getDB();
    return $db->query('SELECT * FROM academic_programs WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
}

function getAcademicProgramsForStudent(?int $selectedId = null): array {
    ensureAcademicProgramsSchema();
    $db = getDB();
    $programs = getActiveAcademicPrograms();

    if ($selectedId && !array_filter($programs, fn($p) => (int) $p['id'] === $selectedId)) {
        $selected = getAcademicProgramById($selectedId);
        if ($selected) {
            array_unshift($programs, $selected);
        }
    }

    return $programs;
}

function getAllAcademicPrograms(): array {
    ensureAcademicProgramsSchema();
    $db = getDB();
    return $db->query('SELECT ap.*, (SELECT COUNT(*) FROM student_profiles sp WHERE sp.course_id = ap.id) AS student_count
        FROM academic_programs ap ORDER BY ap.sort_order, ap.name')->fetchAll();
}

function getAcademicProgramById(int $id): ?array {
    ensureAcademicProgramsSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM academic_programs WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function academicProgramLabel(?int $programId, ?string $fallbackName = null): string {
    if ($programId) {
        $program = getAcademicProgramById($programId);
        if ($program) {
            return $program['name'];
        }
    }
    return $fallbackName ?: '—';
}

function resolveAcademicProgramForProfile(int $courseId): ?array {
    if ($courseId <= 0) {
        return null;
    }
    return getAcademicProgramById($courseId) ?: null;
}

function resolveAcademicProgramFromPost(int $courseId): ?array {
    $program = resolveAcademicProgramForProfile($courseId);
    if (!$program || !(int) $program['is_active']) {
        return null;
    }
    return $program;
}
