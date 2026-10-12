<?php

function clearanceDepartments(): array {
    return [
        ['code' => 'guidance',         'name' => 'Guidance Office',           'icon' => 'fa-hands-helping',        'sort' => 1],
        ['code' => 'library',          'name' => 'Library',                   'icon' => 'fa-book',                 'sort' => 2],
        ['code' => 'student_affairs',  'name' => 'Student Affairs',           'icon' => 'fa-users',              'sort' => 3],
        ['code' => 'program_chair',    'name' => 'Program Chair',             'icon' => 'fa-chalkboard-teacher',   'sort' => 4],
        ['code' => 'campus_director',  'name' => 'Campus Director',           'icon' => 'fa-user-tie',             'sort' => 5],
    ];
}

function ensureClearanceSchema(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS clearance_departments (
        id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        icon VARCHAR(50) DEFAULT 'fa-check',
        sort_order INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS request_clearances (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        department_id TINYINT UNSIGNED NOT NULL,
        status ENUM('pending','cleared','on_hold') DEFAULT 'pending',
        cleared_by INT UNSIGNED NULL,
        cleared_at DATETIME NULL,
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (department_id) REFERENCES clearance_departments(id) ON DELETE CASCADE,
        FOREIGN KEY (cleared_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY uk_request_department (request_id, department_id)
    )");

    $column = $db->query("SHOW COLUMNS FROM users LIKE 'clearance_department_id'")->fetch();
    if (!$column) {
        $db->exec('ALTER TABLE users ADD COLUMN clearance_department_id TINYINT UNSIGNED NULL AFTER role_id');
    }

    $programCol = $db->query("SHOW COLUMNS FROM users LIKE 'clearance_program_id'")->fetch();
    if (!$programCol) {
        $db->exec('ALTER TABLE users ADD COLUMN clearance_program_id TINYINT UNSIGNED NULL AFTER clearance_department_id');
    }

    $db->exec("CREATE TABLE IF NOT EXISTS clearance_officer_programs (
        user_id INT UNSIGNED NOT NULL,
        program_id TINYINT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, program_id),
        KEY idx_clearance_officer_programs_program (program_id)
    )");
    $db->exec('INSERT IGNORE INTO clearance_officer_programs (user_id, program_id)
        SELECT id, clearance_program_id FROM users
        WHERE clearance_program_id IS NOT NULL AND clearance_program_id > 0');

    $role = $db->query("SELECT id FROM roles WHERE name = 'clearance_officer'")->fetch();
    if (!$role) {
        $db->exec("INSERT INTO roles (name, description) VALUES ('clearance_officer', 'Clearance Signing Officer')");
    }

    seedClearanceDepartments();
    removeExcludedClearanceOffices();
    seedClearanceRequirement();
}

function isProgramChairDepartment(?array $department): bool {
    return ($department['code'] ?? '') === 'program_chair';
}

/**
 * Courses assigned to a program chair.
 * null = not a program chair (no course filter).
 * [] = program chair with no courses assigned (should see no requests).
 *
 * @return list<int>|null
 */
function getClearanceOfficerProgramIds(array $user): ?array {
    $department = getUserClearanceDepartment($user);
    if (!isProgramChairDepartment($department)) {
        return null;
    }

    return loadClearanceOfficerProgramIds((int) ($user['id'] ?? 0));
}

/**
 * @param list<int> $userIds
 * @return array<int, list<int>>
 */
function getClearanceOfficerProgramIdsByUser(array $userIds): array {
    ensureClearanceSchema();
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
    if ($userIds === []) {
        return [];
    }

    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = $db->prepare("SELECT user_id, program_id
        FROM clearance_officer_programs
        WHERE user_id IN ($placeholders)
        ORDER BY program_id");
    $stmt->execute($userIds);

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(int) $row['user_id']][] = (int) $row['program_id'];
    }

    $missing = array_values(array_filter($userIds, static fn(int $id): bool => empty($map[$id])));
    if ($missing !== []) {
        $missingPlaceholders = implode(',', array_fill(0, count($missing), '?'));
        $legacy = $db->prepare("SELECT id, clearance_program_id FROM users
            WHERE id IN ($missingPlaceholders) AND clearance_program_id IS NOT NULL AND clearance_program_id > 0");
        $legacy->execute($missing);
        foreach ($legacy->fetchAll() as $row) {
            $map[(int) $row['id']] = [(int) $row['clearance_program_id']];
        }
    }

    return $map;
}

/** @return list<int> */
function loadClearanceOfficerProgramIds(int $userId): array {
    if ($userId <= 0) {
        return [];
    }

    $map = getClearanceOfficerProgramIdsByUser([$userId]);
    return $map[$userId] ?? [];
}

/** @return list<array<string,mixed>> */
function getClearanceOfficerPrograms(int $userId): array {
    $programIds = loadClearanceOfficerProgramIds($userId);
    if ($programIds === []) {
        return [];
    }

    if (!function_exists('getAcademicProgramById')) {
        require_once __DIR__ . '/programs.php';
    }

    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($programIds), '?'));
    $stmt = $db->prepare("SELECT id, code, name FROM academic_programs
        WHERE id IN ($placeholders)
        ORDER BY code, name");
    $stmt->execute($programIds);
    return $stmt->fetchAll() ?: [];
}

function formatClearanceOfficerProgramLabel(array $programs): string {
    $labels = [];
    foreach ($programs as $program) {
        $code = trim((string) ($program['code'] ?? ''));
        $name = trim((string) ($program['name'] ?? ''));
        if ($code !== '' && $name !== '') {
            $labels[] = $code . ' — ' . $name;
        } elseif ($code !== '' || $name !== '') {
            $labels[] = $code !== '' ? $code : $name;
        }
    }

    return implode(', ', $labels);
}

/**
 * @param list<int> $programIds
 */
function saveClearanceOfficerPrograms(int $userId, array $programIds): void {
    if ($userId <= 0) {
        return;
    }

    ensureClearanceSchema();
    $programIds = array_values(array_unique(array_filter(array_map('intval', $programIds), static fn(int $id): bool => $id > 0)));

    $db = getDB();
    $db->prepare('DELETE FROM clearance_officer_programs WHERE user_id = ?')->execute([$userId]);
    if ($programIds !== []) {
        $insert = $db->prepare('INSERT INTO clearance_officer_programs (user_id, program_id) VALUES (?, ?)');
        foreach ($programIds as $programId) {
            $insert->execute([$userId, $programId]);
        }
    }

    $db->prepare('UPDATE users SET clearance_program_id = ? WHERE id = ?')
       ->execute([$programIds[0] ?? null, $userId]);
}

function getRequestorCourseIdForRequest(int $requestId): int {
    $db = getDB();
    $stmt = $db->prepare('SELECT sp.course_id
        FROM requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        WHERE r.id = ?');
    $stmt->execute([$requestId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function canClearanceOfficerAccessRequest(array $user, int $requestId): bool {
    $department = getUserClearanceDepartment($user);
    if (!$department && !hasRole('admin')) {
        return false;
    }
    if (hasRole('admin') && !$department) {
        return true;
    }

    $programIds = getClearanceOfficerProgramIds($user);
    if ($programIds === null) {
        return true;
    }
    if ($programIds === []) {
        return false;
    }

    return in_array(getRequestorCourseIdForRequest($requestId), $programIds, true);
}

function removeExcludedClearanceOffices(): void {
    $db = getDB();
    $excluded = ['cashier', 'registrar'];

    foreach ($excluded as $code) {
        $stmt = $db->prepare('SELECT id FROM clearance_departments WHERE code = ?');
        $stmt->execute([$code]);
        $deptId = $stmt->fetchColumn();
        if (!$deptId) {
            continue;
        }

        $db->prepare('DELETE FROM request_clearances WHERE department_id = ?')->execute([$deptId]);
        $db->prepare('UPDATE clearance_departments SET is_active = 0 WHERE id = ?')->execute([$deptId]);
    }

    foreach (clearanceDepartments() as $dept) {
        $db->prepare('UPDATE clearance_departments SET name = ?, icon = ?, sort_order = ?, is_active = 1 WHERE code = ?')
           ->execute([$dept['name'], $dept['icon'], $dept['sort'], $dept['code']]);
    }

    $description = 'Guidance, Library, Student Affairs, Program Chair, and Campus Director must sign clearance';
    $db->prepare("UPDATE document_requirements SET description = ? WHERE requirement_name = 'Online clearance completed (all offices)'")
       ->execute([$description]);
}

function seedClearanceDepartments(): void {
    $db = getDB();
    foreach (clearanceDepartments() as $dept) {
        $stmt = $db->prepare('INSERT IGNORE INTO clearance_departments (code, name, icon, sort_order) VALUES (?, ?, ?, ?)');
        $stmt->execute([$dept['code'], $dept['name'], $dept['icon'], $dept['sort']]);
    }
}

function seedClearanceRequirement(): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM document_requirements WHERE requirement_name = 'Online clearance completed (all offices)'");
    $stmt->execute();
    if ($stmt->fetch()) {
        return;
    }

    $db->prepare('INSERT INTO document_requirements (document_type_id, requirement_name, description, is_required, sort_order) VALUES (NULL, ?, ?, 1, 10)')
       ->execute([
           'Online clearance completed (all offices)',
           'Guidance, Library, Student Affairs, Program Chair, and Campus Director must sign clearance',
       ]);
}

function notifyClearanceOfficersPendingSigning(
    int $requestId,
    array $departmentIds = [],
    ?int $excludeUserId = null,
    bool $allOfficers = false
): int {
    $db = getDB();
    $departmentIds = array_values(array_unique(array_filter(array_map('intval', $departmentIds))));

    $info = $db->prepare('SELECT r.request_number, u.first_name, u.last_name, u.student_id, sp.course_id
        FROM requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        WHERE r.id = ?');
    $info->execute([$requestId]);
    $request = $info->fetch();
    if (!$request) {
        return 0;
    }

    $roleId = $db->query("SELECT id FROM roles WHERE name = 'clearance_officer'")->fetchColumn();
    if (!$roleId) {
        return 0;
    }

    $requestorCourseId = (int) ($request['course_id'] ?? 0);
    $studentName = trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
    $studentLabel = $studentName !== '' ? $studentName : 'A student';
    if (!empty($request['student_id'])) {
        $studentLabel .= ' (' . $request['student_id'] . ')';
    }

    if ($allOfficers) {
        $title = 'Clearance to sign';
        $message = $studentLabel . ' — ' . $request['request_number'] . ' is waiting for your office to sign.';
    } else {
        $title = 'Clearance to sign';
        $message = $studentLabel . ' — ' . $request['request_number'] . ' needs your office signature.';
    }
    $link = APP_URL . '/clearance/sign.php?request_id=' . $requestId;

    $sql = 'SELECT u.id, u.clearance_program_id, cd.code AS department_code
        FROM users u
        LEFT JOIN clearance_departments cd ON u.clearance_department_id = cd.id
        WHERE u.role_id = ? AND u.is_active = 1';
    $params = [(int) $roleId];

    // New requests notify every clearance officer account.
    // Department filter is used for targeted re-alerts (e.g. reset to pending).
    if (!$allOfficers && $departmentIds) {
        $placeholders = implode(',', array_fill(0, count($departmentIds), '?'));
        $sql .= ' AND u.clearance_department_id IN (' . $placeholders . ')';
        $params = array_merge($params, $departmentIds);
    }

    $users = $db->prepare($sql);
    $users->execute($params);
    $officerRows = $users->fetchAll() ?: [];
    $chairIds = [];
    foreach ($officerRows as $row) {
        if (($row['department_code'] ?? '') === 'program_chair') {
            $chairIds[] = (int) $row['id'];
        }
    }
    $programIdsByUser = getClearanceOfficerProgramIdsByUser($chairIds);

    $count = 0;
    foreach ($officerRows as $row) {
        $userId = (int) $row['id'];
        if ($excludeUserId !== null && $userId === $excludeUserId) {
            continue;
        }

        // Program chairs only receive requests for their assigned courses.
        if (($row['department_code'] ?? '') === 'program_chair') {
            $officerProgramIds = $programIdsByUser[$userId] ?? [];
            if ($requestorCourseId <= 0 || !in_array($requestorCourseId, $officerProgramIds, true)) {
                continue;
            }
        }

        sendNotification($userId, $title, $message, 'info', $link);
        $count++;
    }

    return $count;
}

function initRequestClearance(int $requestId, bool $notifyOfficers = true): void {
    $db = getDB();

    $existingStmt = $db->prepare('SELECT department_id FROM request_clearances WHERE request_id = ?');
    $existingStmt->execute([$requestId]);
    $before = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));

    $departments = $db->query('SELECT id FROM clearance_departments WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
    $insert = $db->prepare('INSERT IGNORE INTO request_clearances (request_id, department_id) VALUES (?, ?)');
    foreach ($departments as $dept) {
        $insert->execute([$requestId, (int) $dept['id']]);
    }

    $existingStmt->execute([$requestId]);
    $after = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));
    $newDepartmentIds = array_values(array_diff($after, $before));

    if ($notifyOfficers && $newDepartmentIds) {
        // Alert every active clearance officer account about the new pending clearance.
        notifyClearanceOfficersPendingSigning($requestId, [], null, true);
    }
}

function getRequestClearances(int $requestId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT rc.*, cd.code, cd.name as department_name, cd.icon,
            u.first_name as cleared_first, u.last_name as cleared_last
        FROM request_clearances rc
        JOIN clearance_departments cd ON rc.department_id = cd.id
        LEFT JOIN users u ON rc.cleared_by = u.id
        WHERE rc.request_id = ? AND cd.is_active = 1
        ORDER BY cd.sort_order, cd.id');
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
}

function getClearanceProgress(int $requestId): array {
    $clearances = getRequestClearances($requestId);
    $total = count($clearances);
    $cleared = count(array_filter($clearances, fn($c) => $c['status'] === 'cleared'));
    $onHold = count(array_filter($clearances, fn($c) => $c['status'] === 'on_hold'));
    $pending = $total - $cleared - $onHold;

    return compact('total', 'cleared', 'onHold', 'pending');
}

function isClearanceComplete(int $requestId): bool {
    $progress = getClearanceProgress($requestId);
    return $progress['total'] > 0 && $progress['cleared'] === $progress['total'];
}

function syncClearanceComplianceCheck(int $requestId): void {
    $db = getDB();
    $req = $db->prepare("SELECT id FROM document_requirements WHERE requirement_name = 'Online clearance completed (all offices)'");
    $req->execute();
    $requirementId = $req->fetchColumn();
    if (!$requirementId) {
        return;
    }

    $isMet = isClearanceComplete($requestId) ? 1 : 0;
    $db->prepare('UPDATE request_compliance SET is_met = ?, verified_at = IF(? = 1, NOW(), NULL) WHERE request_id = ? AND requirement_id = ?')
       ->execute([$isMet, $isMet, $requestId, $requirementId]);
}

function getDepartmentByCode(string $code): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM clearance_departments WHERE code = ?');
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

function getUserClearanceDepartment(array $user): ?array {
    $db = getDB();
    ensureClearanceSchema();

    if (!empty($user['clearance_department_id'])) {
        $stmt = $db->prepare('SELECT * FROM clearance_departments WHERE id = ?');
        $stmt->execute([$user['clearance_department_id']]);
        return $stmt->fetch() ?: null;
    }

    return null;
}

function canAccessClearance(array $user): bool {
    return getUserClearanceDepartment($user) !== null || hasRole('admin');
}

function requireClearanceAccess(): void {
    requireLogin();
    if (!canAccessClearance(currentUser())) {
        setFlash('error', 'You do not have clearance signing access.');
        redirect(dashboardUrl());
    }
}

/**
 * @param list<int>|null $programIds null = no course filter; [] = match nothing
 */
function getClearanceRequestsForDepartment(int $departmentId, string $status = '', string $search = '', ?array $programIds = null): array {
    $db = getDB();
    $where = ['rc.department_id = ?'];
    $params = [$departmentId];

    if ($status) {
        $where[] = 'rc.status = ?';
        $params[] = $status;
    }

    $search = trim($search);
    if ($search !== '') {
        $where[] = '(r.request_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_id LIKE ? OR sp.course LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    if ($programIds !== null) {
        $programIds = array_values(array_unique(array_filter(array_map('intval', $programIds), static fn(int $id): bool => $id > 0)));
        if ($programIds === []) {
            $where[] = '1 = 0';
        } else {
            $placeholders = implode(',', array_fill(0, count($programIds), '?'));
            $where[] = 'sp.course_id IN (' . $placeholders . ')';
            $params = array_merge($params, $programIds);
        }
    }

    $where[] = "r.status NOT IN ('completed','rejected')";

    $sql = 'SELECT rc.*, cd.name as department_name, r.request_number, r.status as request_status,
                   r.request_channel, r.created_at as request_date, dt.name as document_name,
                   u.first_name, u.last_name, u.student_id, u.email, u.phone,
                   sp.course, sp.course_id, sp.year_level, sp.enrollment_status
            FROM request_clearances rc
            JOIN requests r ON rc.request_id = r.id
            JOIN document_types dt ON r.document_type_id = dt.id
            JOIN users u ON r.user_id = u.id
            LEFT JOIN student_profiles sp ON u.id = sp.user_id
            JOIN clearance_departments cd ON rc.department_id = cd.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY rc.updated_at DESC, r.created_at ASC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function processClearanceAction(int $requestId, int $departmentId, int $userId, string $action, string $remarks = ''): bool {
    if (!in_array($action, ['cleared', 'on_hold', 'reset'], true)) {
        return false;
    }

    $db = getDB();
    $officerStmt = $db->prepare('SELECT u.*, cd.code AS department_code
        FROM users u
        LEFT JOIN clearance_departments cd ON u.clearance_department_id = cd.id
        WHERE u.id = ?');
    $officerStmt->execute([$userId]);
    $officer = $officerStmt->fetch();
    if ($officer && ($officer['department_code'] ?? '') === 'program_chair') {
        $officerProgramIds = loadClearanceOfficerProgramIds($userId);
        $requestorCourseId = getRequestorCourseIdForRequest($requestId);
        if ($officerProgramIds === [] || !in_array($requestorCourseId, $officerProgramIds, true)) {
            return false;
        }
    }
    $stmt = $db->prepare('SELECT rc.*, r.request_number, r.user_id, cd.name as department_name
        FROM request_clearances rc
        JOIN requests r ON rc.request_id = r.id
        JOIN clearance_departments cd ON rc.department_id = cd.id
        WHERE rc.request_id = ? AND rc.department_id = ?');
    $stmt->execute([$requestId, $departmentId]);
    $clearance = $stmt->fetch();

    if (!$clearance) {
        return false;
    }

    if ($action === 'reset') {
        $db->prepare("UPDATE request_clearances SET status = 'pending', cleared_by = NULL, cleared_at = NULL, remarks = ? WHERE id = ?")
           ->execute([$remarks ?: null, $clearance['id']]);
    } else {
        $db->prepare('UPDATE request_clearances SET status = ?, cleared_by = ?, cleared_at = NOW(), remarks = ? WHERE id = ?')
           ->execute([$action, $userId, $remarks ?: null, $clearance['id']]);
    }

    syncClearanceComplianceCheck($requestId);
    require_once __DIR__ . '/compliance.php';
    syncAssignedClearanceRequirement($requestId);
    maybeAdvanceToRequirementsSubmitted($requestId);

    $studentId = (int) $clearance['user_id'];
    if ($action === 'cleared' && !isClearanceComplete($requestId)) {
        sendNotification(
            $studentId,
            'Office cleared',
            $clearance['department_name'] . ' cleared ' . $clearance['request_number'] . '. Other offices still need to sign.',
            'success',
            APP_URL . '/student/request-view.php?id=' . $requestId
        );
    } elseif ($action === 'on_hold') {
        sendNotification(
            $studentId,
            'Clearance on hold',
            $clearance['department_name'] . ' put ' . $clearance['request_number'] . ' on hold'
                . ($remarks !== '' ? ': ' . $remarks : '.') ,
            'warning',
            APP_URL . '/student/request-view.php?id=' . $requestId
        );
    } elseif ($action === 'reset') {
        notifyClearanceOfficersPendingSigning($requestId, [(int) $departmentId], $userId);
    }

    auditLog('clearance_' . $action, 'request_clearances', (int) $clearance['id']);
    return true;
}

function clearanceStatusBadge(string $status): string {
    $classes = [
        'pending'  => 'badge-submitted',
        'cleared'  => 'badge-completed',
        'on_hold'  => 'badge-rejected',
    ];
    $class = $classes[$status] ?? 'badge-submitted';
    return '<span class="badge ' . $class . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

function ensureDefaultClearanceUsers(): void {
    $db = getDB();
    ensureClearanceSchema();

    $roleId = $db->query("SELECT id FROM roles WHERE name = 'clearance_officer'")->fetchColumn();
    if (!$roleId) {
        return;
    }

    $accounts = [
        ['guidance@regdum.edu.ph',        'Guidance',        'Officer',       'guidance'],
        ['library@regdum.edu.ph',         'Library',         'Officer',       'library'],
        ['studentaffairs@regdum.edu.ph',  'Student Affairs', 'Officer',       'student_affairs'],
        ['programchair@regdum.edu.ph',    'Program',         'Chair',         'program_chair'],
        ['campusdirector@regdum.edu.ph',  'Campus',          'Director',      'campus_director'],
    ];

    $hash = password_hash('Clearance@123', PASSWORD_BCRYPT);

    foreach ($accounts as [$email, $first, $last, $code]) {
        $exists = $db->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) {
            continue;
        }

        $deptId = $db->prepare('SELECT id FROM clearance_departments WHERE code = ?');
        $deptId->execute([$code]);
        $departmentId = $deptId->fetchColumn();
        if (!$departmentId) {
            continue;
        }

        $db->prepare('INSERT INTO users (role_id, clearance_department_id, email, password, first_name, last_name, is_active, email_verified) VALUES (?, ?, ?, ?, ?, ?, 1, 1)')
           ->execute([$roleId, $departmentId, $email, $hash, $first, $last]);
    }
}

function renderClearanceGrid(int $requestId, bool $compact = false): string {
    $clearances = getRequestClearances($requestId);
    $progress = getClearanceProgress($requestId);

    ob_start();
    ?>
    <div class="clearance-section">
        <div class="clearance-header">
            <h4><i class="fas fa-clipboard-list"></i> Online Clearance</h4>
            <span class="clearance-progress-label"><?= $progress['cleared'] ?> / <?= $progress['total'] ?> cleared</span>
        </div>
        <div class="clearance-progress-bar">
            <div class="clearance-progress-fill" style="width:<?= $progress['total'] ? ($progress['cleared'] / $progress['total'] * 100) : 0 ?>%"></div>
        </div>
        <div class="clearance-grid<?= $compact ? ' clearance-grid-compact' : '' ?>">
            <?php foreach ($clearances as $c): ?>
                <div class="clearance-card status-<?= e($c['status']) ?>">
                    <div class="clearance-card-icon"><i class="fas <?= e($c['icon']) ?>"></i></div>
                    <div class="clearance-card-body">
                        <strong><?= e($c['department_name']) ?></strong>
                        <?= clearanceStatusBadge($c['status']) ?>
                        <?php if (!$compact && $c['cleared_first']): ?>
                            <small class="text-muted">By <?= e($c['cleared_first'] . ' ' . $c['cleared_last']) ?> · <?= formatDateTime($c['cleared_at']) ?></small>
                        <?php endif; ?>
                        <?php if ($c['remarks']): ?><small class="clearance-remarks"><?= e($c['remarks']) ?></small><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
