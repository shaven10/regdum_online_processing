<?php

function ensureCampusesSchema(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS campuses (
        id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        description TEXT,
        is_active TINYINT(1) DEFAULT 1,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $originCol = $db->query("SHOW COLUMNS FROM student_profiles LIKE 'origin_campus_id'")->fetch();
    if (!$originCol) {
        $db->exec('ALTER TABLE student_profiles ADD COLUMN origin_campus_id TINYINT UNSIGNED NULL AFTER graduation_date');
    }

    $yearGradCol = $db->query("SHOW COLUMNS FROM student_profiles LIKE 'year_graduated'")->fetch();
    if (!$yearGradCol) {
        $db->exec('ALTER TABLE student_profiles ADD COLUMN year_graduated SMALLINT UNSIGNED NULL AFTER origin_campus_id');
    }

    $lastSyCol = $db->query("SHOW COLUMNS FROM student_profiles LIKE 'last_school_year'")->fetch();
    if (!$lastSyCol) {
        $db->exec("ALTER TABLE student_profiles ADD COLUMN last_school_year VARCHAR(20) NULL AFTER year_graduated");
    }

    seedDefaultCampuses();
    syncGraduationYearFromDate();
}

function seedDefaultCampuses(): void {
    $db = getDB();
    if ((int) $db->query('SELECT COUNT(*) FROM campuses')->fetchColumn() > 0) {
        return;
    }

    $defaults = [
        ['MAIN', 'Main Campus', 'Central registrar campus', 1],
        ['NORTH', 'North Campus', 'Northern extension campus', 2],
        ['SOUTH', 'South Campus', 'Southern extension campus', 3],
    ];

    $stmt = $db->prepare('INSERT INTO campuses (code, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, 1)');
    foreach ($defaults as [$code, $name, $desc, $order]) {
        $stmt->execute([$code, $name, $desc, $order]);
    }
}

function syncGraduationYearFromDate(): void {
    $db = getDB();
    $db->exec('UPDATE student_profiles SET year_graduated = YEAR(graduation_date)
        WHERE year_graduated IS NULL AND graduation_date IS NOT NULL');
}

function getActiveCampuses(): array {
    ensureCampusesSchema();
    $db = getDB();
    return $db->query('SELECT * FROM campuses WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
}

function getCampusesForStudent(?int $selectedId = null): array {
    ensureCampusesSchema();
    $campuses = getActiveCampuses();

    if ($selectedId && !array_filter($campuses, fn($c) => (int) $c['id'] === $selectedId)) {
        $selected = getCampusById($selectedId);
        if ($selected) {
            array_unshift($campuses, $selected);
        }
    }

    return $campuses;
}

function getAllCampuses(): array {
    ensureCampusesSchema();
    $db = getDB();
    return $db->query('SELECT c.*, (SELECT COUNT(*) FROM student_profiles sp WHERE sp.origin_campus_id = c.id) AS student_count
        FROM campuses c ORDER BY c.sort_order, c.name')->fetchAll();
}

function getCampusById(int $id): ?array {
    ensureCampusesSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM campuses WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function campusLabel(?int $campusId, ?string $fallback = null): string {
    if ($campusId) {
        $campus = getCampusById($campusId);
        if ($campus) {
            return $campus['name'];
        }
    }
    return $fallback ?: '—';
}

function resolveCampusFromPost(int $campusId): ?array {
    if ($campusId <= 0) {
        return null;
    }
    return getCampusById($campusId) ?: null;
}

function yearGraduatedOptions(): array {
    $current = (int) date('Y');
    $years = [];
    for ($y = $current; $y >= $current - 60; $y--) {
        $years[(string) $y] = (string) $y;
    }
    return $years;
}

function schoolYearOptions(): array {
    $current = (int) date('Y');
    $month = (int) date('n');
    $startYear = $month >= 6 ? $current : $current - 1;
    $options = [];

    for ($i = 0; $i < 15; $i++) {
        $from = $startYear - $i;
        $to = $from + 1;
        $value = $from . '-' . $to;
        $options[$value] = $value;
    }

    return $options;
}
