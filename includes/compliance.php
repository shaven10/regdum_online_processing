<?php

function ensureComplianceSchema(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS document_requirements (
        id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        document_type_id TINYINT UNSIGNED NULL,
        requirement_name VARCHAR(255) NOT NULL,
        description TEXT,
        is_required TINYINT(1) DEFAULT 1,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS request_compliance (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        requirement_id TINYINT UNSIGNED NOT NULL,
        is_met TINYINT(1) DEFAULT 0,
        verified_by INT UNSIGNED NULL,
        verified_at DATETIME NULL,
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (requirement_id) REFERENCES document_requirements(id) ON DELETE CASCADE,
        FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY uk_request_requirement (request_id, requirement_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS request_compliance_summary (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL UNIQUE,
        compliance_status ENUM('pending','compliant','non_compliant','needs_revision') DEFAULT 'pending',
        verified_by INT UNSIGNED NULL,
        verified_at DATETIME NULL,
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS request_assigned_requirements (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        requirement_code VARCHAR(50) NULL,
        requirement_name VARCHAR(255) NOT NULL,
        description TEXT,
        requires_upload TINYINT(1) DEFAULT 1,
        document_id INT UNSIGNED NULL,
        is_met TINYINT(1) DEFAULT 0,
        verified_by INT UNSIGNED NULL,
        verified_at DATETIME NULL,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (document_id) REFERENCES request_documents(id) ON DELETE SET NULL,
        FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
    )");

    seedDocumentRequirements();
    ensureWorkflowSchema();
    ensureRequirementDefaultsSchema();
    removeRetiredRequirementCodes();
    ensureAdditionalRequirementDefaults(['final_clearance', 'other_enrollment_requirements']);
}

function ensureRequirementDefaultsSchema(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS document_type_requirement_defaults (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        document_type_id TINYINT UNSIGNED NOT NULL,
        requirement_code VARCHAR(50) NOT NULL,
        is_enabled TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE,
        UNIQUE KEY uk_doc_type_requirement (document_type_id, requirement_code)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $requirementsRequiredCol = $db->query("SHOW COLUMNS FROM document_types LIKE 'requirements_required'")->fetch();
    if (!$requirementsRequiredCol) {
        $db->exec('ALTER TABLE document_types ADD COLUMN requirements_required TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active');
    }

    ensureRequirementDefinitionsSchema();
    seedDocumentTypeRequirementDefaults();
}

function defaultRequirementDefinitionSeed(): array {
    return [
        [
            'code' => 'online_clearance',
            'name' => 'Online Clearance',
            'description' => 'Complete online clearance from all offices: Guidance, Library, Student Affairs, Program Chair, and Campus Director.',
            'requires_upload' => 0,
            'is_optional' => 0,
            'is_system' => 1,
            'sort_order' => 1,
        ],
        [
            'code' => 'thesis_distribution_list',
            'name' => 'Thesis Distribution List',
            'description' => 'Upload the thesis distribution list document.',
            'requires_upload' => 1,
            'is_optional' => 0,
            'is_system' => 1,
            'sort_order' => 2,
        ],
        [
            'code' => 'final_clearance',
            'name' => 'Final Clearance',
            'description' => 'Upload your final clearance document from the Registrar or relevant office.',
            'requires_upload' => 1,
            'is_optional' => 0,
            'is_system' => 1,
            'sort_order' => 3,
        ],
        [
            'code' => 'other_enrollment_requirements',
            'name' => 'Other Enrollment Requirements',
            'description' => 'Upload any other enrollment-related documents required for your request.',
            'requires_upload' => 1,
            'is_optional' => 0,
            'is_system' => 1,
            'sort_order' => 4,
        ],
        [
            'code' => 'affidavit_second_copy',
            'name' => 'Affidavit of 2nd copy or loss',
            'description' => 'Required for 2nd request of documents — upload affidavit of second copy or loss.',
            'requires_upload' => 1,
            'is_optional' => 1,
            'is_system' => 1,
            'sort_order' => 5,
        ],
    ];
}

function ensureRequirementDefinitionsSchema(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS requirement_definitions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        requires_upload TINYINT(1) NOT NULL DEFAULT 1,
        is_optional TINYINT(1) NOT NULL DEFAULT 0,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_requirement_code (code)
    )");

    seedRequirementDefinitions();
    ensureRequirementSubcategoriesSchema();
}

function ensureRequirementSubcategoriesSchema(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS requirement_subcategories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        requirement_code VARCHAR(50) NOT NULL,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_req_subcode (requirement_code, code),
        KEY idx_req_sub_parent (requirement_code)
    )");

    $subCol = $db->query("SHOW COLUMNS FROM request_assigned_requirements LIKE 'subcategory_code'")->fetch();
    if (!$subCol) {
        $db->exec('ALTER TABLE request_assigned_requirements ADD COLUMN subcategory_code VARCHAR(50) NULL AFTER requirement_code');
    }

    seedRequirementSubcategories();
}

function defaultRequirementSubcategorySeed(): array {
    return [
        [
            'requirement_code' => 'other_enrollment_requirements',
            'code' => 'hs_card',
            'name' => 'HS Card',
            'description' => 'Upload a clear copy of your High School Card.',
            'sort_order' => 1,
        ],
        [
            'requirement_code' => 'other_enrollment_requirements',
            'code' => 'live_birth_psa_photocopy',
            'name' => 'Live Birth PSA Photocopy',
            'description' => 'Upload a photocopy of your PSA Live Birth Certificate.',
            'sort_order' => 2,
        ],
        [
            'requirement_code' => 'other_enrollment_requirements',
            'code' => 'f137a',
            'name' => 'F137A',
            'description' => 'Upload your Form 137-A (Secondary Student Permanent Record).',
            'sort_order' => 3,
        ],
    ];
}

function seedRequirementSubcategories(): void {
    $db = getDB();
    $exists = $db->prepare('SELECT id FROM requirement_subcategories WHERE requirement_code = ? AND code = ? LIMIT 1');
    $insert = $db->prepare('INSERT INTO requirement_subcategories
        (requirement_code, code, name, description, is_active, sort_order)
        VALUES (?, ?, ?, ?, 1, ?)');

    foreach (defaultRequirementSubcategorySeed() as $item) {
        $exists->execute([$item['requirement_code'], $item['code']]);
        if ($exists->fetch()) {
            continue;
        }
        $insert->execute([
            $item['requirement_code'],
            $item['code'],
            $item['name'],
            $item['description'],
            $item['sort_order'],
        ]);
    }
}

function getRequirementSubcategories(string $requirementCode, bool $activeOnly = false): array {
    ensureRequirementSubcategoriesSchema();
    $db = getDB();
    $sql = 'SELECT * FROM requirement_subcategories WHERE requirement_code = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, name, id';
    $stmt = $db->prepare($sql);
    $stmt->execute([normalizeRequirementCode($requirementCode)]);
    return $stmt->fetchAll();
}

function getRequirementSubcategoryById(int $id): ?array {
    ensureRequirementSubcategoriesSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM requirement_subcategories WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function countRequirementSubcategories(string $requirementCode, bool $activeOnly = true): int {
    ensureRequirementSubcategoriesSchema();
    $db = getDB();
    $sql = 'SELECT COUNT(*) FROM requirement_subcategories WHERE requirement_code = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([normalizeRequirementCode($requirementCode)]);
    return (int) $stmt->fetchColumn();
}

function groupAssignedRequirements(array $requirements): array {
    $groups = [];
    $checklist = registrarRequirementChecklist();

    foreach ($requirements as $req) {
        $parentCode = (string) ($req['requirement_code'] ?? '');
        $parentName = $checklist[$parentCode]['name'] ?? ($parentCode !== '' ? ucwords(str_replace('_', ' ', $parentCode)) : 'Requirements');
        $key = $parentCode !== '' ? $parentCode : ('row_' . ($req['id'] ?? uniqid('', true)));

        if (!isset($groups[$key])) {
            $groups[$key] = [
                'code' => $parentCode,
                'name' => $parentName,
                'items' => [],
            ];
        }

        $groups[$key]['items'][] = $req;
    }

    return array_values($groups);
}

function seedRequirementDefinitions(): void {
    $db = getDB();
    $exists = $db->prepare('SELECT id FROM requirement_definitions WHERE code = ? LIMIT 1');
    $insert = $db->prepare('INSERT INTO requirement_definitions
        (code, name, description, requires_upload, is_optional, is_system, is_active, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, 1, ?)');

    foreach (defaultRequirementDefinitionSeed() as $item) {
        $exists->execute([$item['code']]);
        if ($exists->fetch()) {
            continue;
        }
        $insert->execute([
            $item['code'],
            $item['name'],
            $item['description'],
            $item['requires_upload'],
            $item['is_optional'],
            $item['is_system'],
            $item['sort_order'],
        ]);
    }
}

function normalizeRequirementCode(string $code): string {
    $code = strtolower(trim($code));
    $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
    return trim($code, '_');
}

function getAllRequirementDefinitions(bool $activeOnly = false): array {
    ensureRequirementDefinitionsSchema();
    $db = getDB();
    $sql = 'SELECT * FROM requirement_definitions';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, name, id';
    return $db->query($sql)->fetchAll();
}

function getRequirementDefinitionById(int $id): ?array {
    ensureRequirementDefinitionsSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM requirement_definitions WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getRequirementDefinitionByCode(string $code): ?array {
    ensureRequirementDefinitionsSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM requirement_definitions WHERE code = ?');
    $stmt->execute([normalizeRequirementCode($code)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function countDocumentTypesUsingRequirementCode(string $code): int {
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM document_type_requirement_defaults WHERE requirement_code = ? AND is_enabled = 1');
    $stmt->execute([$code]);
    return (int) $stmt->fetchColumn();
}

function countAssignedRequestsUsingRequirementCode(string $code): int {
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(DISTINCT request_id) FROM request_assigned_requirements WHERE requirement_code = ?');
    $stmt->execute([$code]);
    return (int) $stmt->fetchColumn();
}

function documentTypeRequiresRequirements(int $documentTypeId): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT requirements_required FROM document_types WHERE id = ?');
    $stmt->execute([$documentTypeId]);
    $value = $stmt->fetchColumn();

    return $value === false ? true : (bool) $value;
}

function getAppSetting(string $key, string $default = ''): string {
    $db = getDB();
    ensureRequirementDefaultsSchema();
    $stmt = $db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? (string) $value : $default;
}

function setAppSetting(string $key, string $value): void {
    $db = getDB();
    $db->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()')
       ->execute([$key, $value]);
}

function isAutoApplyRequirementsEnabled(): bool {
    return getAppSetting('auto_apply_requirement_defaults', '1') === '1';
}

function standardDefaultRequirementCodes(): array {
    return ['online_clearance', 'final_clearance', 'other_enrollment_requirements'];
}

function baseDocumentRequirementCodes(?string $documentTypeCode = null): array {
    $codes = ['online_clearance', 'final_clearance', 'other_enrollment_requirements'];
    $thesisRelated = ['TOR', 'DIPLOMA', 'OTHER'];

    if ($documentTypeCode !== null && in_array($documentTypeCode, $thesisRelated, true)) {
        $codes[] = 'thesis_distribution_list';
    }

    return $codes;
}

function ensureAdditionalRequirementDefaults(array $codes): void {
    $checklist = registrarRequirementChecklist();
    $db = getDB();
    $docTypes = $db->query('SELECT id FROM document_types WHERE is_active = 1')->fetchAll();

    foreach ($docTypes as $docType) {
        foreach ($codes as $code) {
            if (!array_key_exists($code, $checklist)) {
                continue;
            }

            $exists = $db->prepare('SELECT COUNT(*) FROM document_type_requirement_defaults WHERE document_type_id = ? AND requirement_code = ?');
            $exists->execute([(int) $docType['id'], $code]);
            if ((int) $exists->fetchColumn() > 0) {
                continue;
            }

            $db->prepare('INSERT INTO document_type_requirement_defaults (document_type_id, requirement_code, is_enabled) VALUES (?, ?, 1)')
               ->execute([(int) $docType['id'], $code]);
        }
    }
}

function removeRetiredRequirementCodes(): void {
    $db = getDB();
    $retired = ['student_identification', 'authorization_letter_id'];

    foreach ($retired as $code) {
        $db->prepare('DELETE FROM document_type_requirement_defaults WHERE requirement_code = ?')
           ->execute([$code]);
        $db->prepare('DELETE FROM request_assigned_requirements WHERE requirement_code = ?')
           ->execute([$code]);
    }
}

function seedDocumentTypeRequirementDefaults(?int $documentTypeId = null, ?string $code = null): void {
    $db = getDB();

    if ($documentTypeId && $code) {
        $existing = $db->prepare('SELECT COUNT(*) FROM document_type_requirement_defaults WHERE document_type_id = ?');
        $existing->execute([$documentTypeId]);
        if ((int) $existing->fetchColumn() > 0) {
            return;
        }

        $codes = baseDocumentRequirementCodes($code);

        foreach ($codes as $reqCode) {
            $db->prepare('INSERT INTO document_type_requirement_defaults (document_type_id, requirement_code, is_enabled) VALUES (?, ?, 1)')
               ->execute([$documentTypeId, $reqCode]);
        }
        return;
    }

    $docTypes = $db->query('SELECT id, code FROM document_types WHERE is_active = 1')->fetchAll();
    if (empty($docTypes)) {
        return;
    }

    foreach ($docTypes as $docType) {
        $existing = $db->prepare('SELECT COUNT(*) FROM document_type_requirement_defaults WHERE document_type_id = ?');
        $existing->execute([$docType['id']]);
        if ((int) $existing->fetchColumn() > 0) {
            continue;
        }

        $codes = baseDocumentRequirementCodes($docType['code']);

        foreach ($codes as $code) {
            $db->prepare('INSERT INTO document_type_requirement_defaults (document_type_id, requirement_code, is_enabled) VALUES (?, ?, 1)')
               ->execute([$docType['id'], $code]);
        }
    }
}

function getDocumentTypeRequirementDefaults(int $documentTypeId): array {
    if (!documentTypeRequiresRequirements($documentTypeId)) {
        return [];
    }

    $db = getDB();

    $stmt = $db->prepare('SELECT requirement_code FROM document_type_requirement_defaults
        WHERE document_type_id = ? AND is_enabled = 1
        ORDER BY id');
    $stmt->execute([$documentTypeId]);
    $codes = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($codes)) {
        return array_values($codes);
    }

    return standardDefaultRequirementCodes();
}

function saveDocumentTypeRequirementDefaults(int $documentTypeId, array $codes, bool $requirementsRequired = true): void {
    $db = getDB();
    ensureRequirementDefaultsSchema();

    $db->prepare('UPDATE document_types SET requirements_required = ? WHERE id = ?')
       ->execute([$requirementsRequired ? 1 : 0, $documentTypeId]);

    $checklist = registrarRequirementChecklist();
    $db->prepare('DELETE FROM document_type_requirement_defaults WHERE document_type_id = ?')->execute([$documentTypeId]);

    if (!$requirementsRequired) {
        auditLog('update_requirement_defaults', 'document_types', $documentTypeId, null, [
            'requirements_required' => 0,
            'codes' => [],
        ]);
        return;
    }

    foreach ($checklist as $code => $item) {
        if (!in_array($code, $codes, true)) {
            continue;
        }
        $db->prepare('INSERT INTO document_type_requirement_defaults (document_type_id, requirement_code, is_enabled) VALUES (?, ?, 1)')
           ->execute([$documentTypeId, $code]);
    }

    auditLog('update_requirement_defaults', 'document_types', $documentTypeId, null, [
        'requirements_required' => 1,
        'codes' => $codes,
    ]);
}

function getAllDocumentTypeRequirementSettings(): array {
    $db = getDB();
    ensureRequirementDefaultsSchema();

    $docTypes = $db->query('SELECT * FROM document_types ORDER BY name')->fetchAll();
    $settings = [];

    foreach ($docTypes as $docType) {
        $settings[] = [
            'document_type' => $docType,
            'codes' => getDocumentTypeRequirementDefaults((int) $docType['id']),
        ];
    }

    return $settings;
}

function applyNoRequirementsToRequest(int $requestId, int $documentTypeId, bool $changeStatus, ?string $remarks = null): bool {
    saveAssignedRequirements($requestId, []);

    $db = getDB();
    $db->prepare("UPDATE request_compliance_summary
        SET compliance_status = 'compliant', remarks = ?, verified_by = NULL, verified_at = NOW(), updated_at = NOW()
        WHERE request_id = ?")
       ->execute([$remarks ?: null, $requestId]);

    if (!$changeStatus) {
        return true;
    }

    $stmt = $db->prepare('SELECT request_number, user_id FROM requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        return false;
    }

    updateRequestStatus($requestId, 'requirements_verified', 'No requirements required for this credential');
    sendNotification(
        (int) $request['user_id'],
        'Ready for Payment',
        'Your request ' . $request['request_number'] . ' does not require additional documents. You may proceed to payment.',
        'success',
        APP_URL . '/student/payment.php?request_id=' . $requestId
    );
    auditLog('requirements_skipped', 'requests', $requestId, null, ['document_type_id' => $documentTypeId]);

    return true;
}

function applyRequirementDefaultsToRequest(int $requestId, int $documentTypeId, bool $changeStatus = true, ?int $requestItemId = null): bool {
    if (!documentTypeRequiresRequirements($documentTypeId)) {
        return applyNoRequirementsToRequest($requestId, $documentTypeId, $changeStatus);
    }

    $codes = getDocumentTypeRequirementDefaults($documentTypeId);
    if (empty($codes)) {
        return false;
    }

    $requirements = buildRequirementsFromCodes($codes);
    if (empty($requirements)) {
        return false;
    }

    saveAssignedRequirements($requestId, $requirements, $requestItemId);

    if (hasAssignedRequirement($requestId, 'online_clearance')) {
        require_once __DIR__ . '/clearance.php';
        initRequestClearance($requestId);
        syncAssignedClearanceRequirement($requestId);
    }

    $db = getDB();
    $db->prepare("UPDATE request_compliance_summary SET compliance_status = 'pending', updated_at = NOW() WHERE request_id = ?")
       ->execute([$requestId]);

    if ($changeStatus) {
        $stmt = $db->prepare('SELECT request_number, user_id FROM requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) {
            return false;
        }

        updateRequestStatus($requestId, 'awaiting_requirements', 'Requirements auto-assigned from credential settings');
        sendNotification(
            (int) $request['user_id'],
            'Requirements Assigned',
            'Your request ' . $request['request_number'] . ' has been reviewed. Please complete the listed requirements.',
            'info',
            APP_URL . '/student/request-view.php?id=' . $requestId
        );
        auditLog('requirements_auto_assigned', 'requests', $requestId, null, ['document_type_id' => $documentTypeId, 'codes' => $codes]);
    }

    return true;
}

function ensureWorkflowSchema(): void {
    $db = getDB();
    ensureRequestStatuses();

    foreach (['release_date' => 'DATE NULL', 'release_time' => 'TIME NULL'] as $column => $definition) {
        $exists = $db->query("SHOW COLUMNS FROM requests LIKE '$column'")->fetch();
        if (!$exists) {
            $after = $column === 'release_date' ? 'pickup_time' : 'release_date';
            $db->exec("ALTER TABLE requests ADD COLUMN $column $definition AFTER $after");
        }
    }

    $codeCol = $db->query("SHOW COLUMNS FROM request_assigned_requirements LIKE 'requirement_code'")->fetch();
    if (!$codeCol) {
        $db->exec('ALTER TABLE request_assigned_requirements ADD COLUMN requirement_code VARCHAR(50) NULL AFTER request_id');
    }
}

function defaultReleaseTime(): string {
    return '09:00:00';
}

function getRequestReleaseStartDate(int $requestId): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT verified_at FROM payments WHERE request_id = ? AND status = 'verified' ORDER BY verified_at DESC LIMIT 1");
    $stmt->execute([$requestId]);
    $verifiedAt = $stmt->fetchColumn();
    if ($verifiedAt) {
        return date('Y-m-d', strtotime((string) $verifiedAt));
    }

    $hist = $db->prepare("SELECT created_at FROM request_status_history WHERE request_id = ? AND new_status = 'payment_verified' ORDER BY created_at DESC LIMIT 1");
    $hist->execute([$requestId]);
    $statusDate = $hist->fetchColumn();
    if ($statusDate) {
        return date('Y-m-d', strtotime((string) $statusDate));
    }

    return date('Y-m-d');
}

function isWorkingDay(DateTimeInterface $date): bool {
    return (int) $date->format('N') <= 5;
}

function addWorkingDays(DateTime $startDate, int $workingDays): DateTime {
    $date = clone $startDate;
    $added = 0;
    $daysToAdd = max(1, $workingDays);

    while ($added < $daysToAdd) {
        $date->modify('+1 day');
        if (isWorkingDay($date)) {
            $added++;
        }
    }

    while (!isWorkingDay($date)) {
        $date->modify('+1 day');
    }

    return $date;
}

function calculateDefaultReleaseDate(int $processingDays, ?string $startDate = null): string {
    $start = new DateTime($startDate ?: 'today');
    return addWorkingDays($start, $processingDays)->format('Y-m-d');
}

function buildReleaseScheduleForRequest(int $requestId, int $processingDays, ?string $existingDate = null, ?string $existingTime = null): array {
    $startDate = getRequestReleaseStartDate($requestId);
    $suggestedDate = calculateDefaultReleaseDate($processingDays, $startDate);
    $suggestedTime = defaultReleaseTime();

    return [
        'processing_days' => max(1, $processingDays),
        'start_date' => $startDate,
        'suggested_date' => $suggestedDate,
        'suggested_time' => $suggestedTime,
        'release_date' => $existingDate ?: $suggestedDate,
        'release_time' => $existingTime ?: $suggestedTime,
    ];
}

function ensureRequestStatuses(): void {
    $db = getDB();
    $column = $db->query("SHOW COLUMNS FROM requests LIKE 'status'")->fetch();
    if (!$column) {
        return;
    }

    $required = [
        'submitted', 'under_review', 'awaiting_requirements', 'requirements_submitted',
        'needs_revision', 'requirements_verified', 'payment_verified', 'processing',
        'ready_for_pickup', 'shipped', 'completed', 'rejected',
    ];

    $missing = false;
    foreach ($required as $status) {
        if (strpos($column['Type'], "'$status'") === false) {
            $missing = true;
            break;
        }
    }

    if ($missing) {
        $db->exec("ALTER TABLE requests MODIFY status ENUM(
            'submitted','under_review','awaiting_requirements','requirements_submitted',
            'needs_revision','requirements_verified','payment_verified','processing',
            'ready_for_pickup','shipped','completed','rejected'
        ) DEFAULT 'submitted'");
    }
}

function registrarRequirementChecklist(): array {
    ensureRequirementDefinitionsSchema();
    $rows = getAllRequirementDefinitions(true);
    $checklist = [];

    foreach ($rows as $row) {
        $checklist[$row['code']] = [
            'name' => $row['name'],
            'description' => $row['description'] ?? '',
            'requires_upload' => !empty($row['requires_upload']),
            'sort_order' => (int) $row['sort_order'],
            'optional' => !empty($row['is_optional']),
        ];
    }

    if (!empty($checklist)) {
        return $checklist;
    }

    // Fallback if table seed failed
    $fallback = [];
    foreach (defaultRequirementDefinitionSeed() as $item) {
        $fallback[$item['code']] = [
            'name' => $item['name'],
            'description' => $item['description'],
            'requires_upload' => !empty($item['requires_upload']),
            'sort_order' => (int) $item['sort_order'],
            'optional' => !empty($item['is_optional']),
        ];
    }
    return $fallback;
}

/**
 * Build assignable requirement rows from parent codes.
 *
 * @param array $codes Parent requirement codes
 * @param array|null $selectedSubcodesByParent Map of parent_code => [subcategory_code, ...]
 *        When null, all active subcategories are included (used by document-type defaults).
 *        When provided, only the selected subcategories are included for parents that have them.
 */
function buildRequirementsFromCodes(array $codes, ?array $selectedSubcodesByParent = null): array {
    $checklist = registrarRequirementChecklist();
    $requirements = [];

    foreach ($checklist as $code => $item) {
        if (!in_array($code, $codes, true)) {
            continue;
        }

        $subcategories = getRequirementSubcategories($code, true);
        if (!empty($subcategories)) {
            if ($selectedSubcodesByParent !== null) {
                $wanted = array_values(array_filter(array_map(
                    static fn($value): string => normalizeRequirementCode((string) $value),
                    (array) ($selectedSubcodesByParent[$code] ?? [])
                )));
                if ($wanted === []) {
                    continue;
                }
                $subcategories = array_values(array_filter(
                    $subcategories,
                    static fn(array $sub): bool => in_array((string) $sub['code'], $wanted, true)
                ));
                if ($subcategories === []) {
                    continue;
                }
            }

            $baseOrder = (int) ($item['sort_order'] ?? 0) * 100;
            foreach ($subcategories as $index => $sub) {
                $requirements[] = [
                    'code' => $code,
                    'subcategory_code' => $sub['code'],
                    'name' => $sub['name'],
                    'description' => $sub['description'] ?: ($item['description'] ?? ''),
                    'requires_upload' => true,
                    'sort_order' => $baseOrder + (int) ($sub['sort_order'] ?? ($index + 1)),
                    'parent_name' => $item['name'],
                ];
            }
            continue;
        }

        $requirements[] = [
            'code' => $code,
            'subcategory_code' => null,
            'name' => $item['name'],
            'description' => $item['description'],
            'requires_upload' => $item['requires_upload'],
            'sort_order' => $item['sort_order'],
            'parent_name' => $item['name'],
        ];
    }

    usort($requirements, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
    return $requirements;
}

function assignedRequirementCodes(int $requestId): array {
    $requirements = getAssignedRequirements($requestId);
    return array_values(array_unique(array_filter(array_column($requirements, 'requirement_code'))));
}

/** @return array<string, list<string>> parent_code => subcategory codes */
function assignedRequirementSubcodes(int $requestId): array {
    $map = [];
    foreach (getAssignedRequirements($requestId) as $req) {
        $parent = normalizeRequirementCode((string) ($req['requirement_code'] ?? ''));
        $sub = normalizeRequirementCode((string) ($req['subcategory_code'] ?? ''));
        if ($parent === '' || $sub === '') {
            continue;
        }
        if (!isset($map[$parent])) {
            $map[$parent] = [];
        }
        if (!in_array($sub, $map[$parent], true)) {
            $map[$parent][] = $sub;
        }
    }
    return $map;
}

function hasAssignedRequirement(int $requestId, string $code): bool {
    return in_array($code, assignedRequirementCodes($requestId), true);
}

function syncAssignedClearanceRequirement(int $requestId): void {
    if (!hasAssignedRequirement($requestId, 'online_clearance')) {
        return;
    }

    require_once __DIR__ . '/clearance.php';
    $isMet = isClearanceComplete($requestId) ? 1 : 0;
    getDB()->prepare("UPDATE request_assigned_requirements
        SET is_met = ?, verified_at = IF(? = 1, NOW(), NULL)
        WHERE request_id = ? AND requirement_code = 'online_clearance'")
       ->execute([$isMet, $isMet, $requestId]);
}

function notifyRegistrarsRequirementsReady(int $requestId, string $requestNumber): void {
    notifyUsersByRole(
        'registrar',
        'Requirements Submitted',
        'Request ' . $requestNumber . ' is ready for re-evaluation.',
        'info',
        APP_URL . '/registrar/verify-request.php?id=' . $requestId
    );
}

function advanceToRequirementsSubmitted(int $requestId): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT status, request_number FROM requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        return false;
    }

    updateRequestStatus($requestId, 'requirements_submitted', 'All requirements completed — ready for registrar re-evaluation');
    $db->prepare("UPDATE request_compliance_summary SET compliance_status = 'pending', updated_at = NOW() WHERE request_id = ?")
       ->execute([$requestId]);
    notifyRegistrarsRequirementsReady($requestId, $request['request_number']);
    return true;
}

function maybeAdvanceToRequirementsSubmitted(int $requestId): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT status, request_number FROM requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request || !in_array($request['status'], ['awaiting_requirements', 'needs_revision'], true)) {
        return false;
    }

    if (!studentRequirementsComplete($requestId)) {
        return false;
    }

    return advanceToRequirementsSubmitted($requestId);
}

function seedDocumentRequirements(): void {
    $db = getDB();
    $count = (int) $db->query('SELECT COUNT(*) FROM document_requirements')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $general = [
        ['Valid School ID or Government ID', 'Upload a clear copy of valid identification', 1],
        ['Student identity verified', 'Requester matches official student records', 2],
        ['Complete request information', 'All required form fields are filled out correctly', 3],
        ['Purpose of request indicated', 'Valid purpose selected and supported if required', 4],
    ];

    foreach ($general as [$name, $desc, $order]) {
        $db->prepare('INSERT INTO document_requirements (document_type_id, requirement_name, description, is_required, sort_order) VALUES (NULL, ?, ?, 1, ?)')
           ->execute([$name, $desc, $order]);
    }

    $specific = [
        'TOR' => [['Supporting academic records uploaded', 'Previous TOR or grade slips if applicable', 5]],
        'DIPLOMA' => [['Graduation clearance', 'Proof of graduation or clearance certificate', 5]],
        'CTC' => [['Original document for certification', 'Copy of document to be authenticated', 5]],
        'OTHER' => [['Supporting documents uploaded', 'Relevant documents for the requested record', 5]],
    ];

    foreach ($specific as $code => $items) {
        $typeId = $db->prepare('SELECT id FROM document_types WHERE code = ?');
        $typeId->execute([$code]);
        $docTypeId = $typeId->fetchColumn();
        if (!$docTypeId) {
            continue;
        }

        foreach ($items as [$name, $desc, $order]) {
            $db->prepare('INSERT INTO document_requirements (document_type_id, requirement_name, description, is_required, sort_order) VALUES (?, ?, ?, 1, ?)')
               ->execute([$docTypeId, $name, $desc, $order]);
        }
    }
}

function initRequestCompliance(int $requestId, int $documentTypeId): void {
    $db = getDB();

    $existing = $db->prepare('SELECT id FROM request_compliance_summary WHERE request_id = ?');
    $existing->execute([$requestId]);
    if (!$existing->fetch()) {
        $db->prepare('INSERT INTO request_compliance_summary (request_id) VALUES (?)')->execute([$requestId]);
    }
}

function getDocumentRequirements(int $documentTypeId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM document_requirements
        WHERE document_type_id IS NULL OR document_type_id = ?
        ORDER BY sort_order, id');
    $stmt->execute([$documentTypeId]);
    return $stmt->fetchAll();
}

function getAssignedRequirements(int $requestId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT ar.*, rd.file_name, rd.original_name
        FROM request_assigned_requirements ar
        LEFT JOIN request_documents rd ON ar.document_id = rd.id
        WHERE ar.request_id = ?
        ORDER BY ar.sort_order, ar.id');
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
}

function getRequestCompliance(int $requestId): array {
    $db = getDB();
    $stmt = $db->prepare('SELECT rc.*, dr.requirement_name, dr.description, dr.is_required
        FROM request_compliance rc
        JOIN document_requirements dr ON rc.requirement_id = dr.id
        WHERE rc.request_id = ?
        ORDER BY dr.sort_order, dr.id');
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
}

function getComplianceSummary(int $requestId): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT cs.*, u.first_name, u.last_name
        FROM request_compliance_summary cs
        LEFT JOIN users u ON cs.verified_by = u.id
        WHERE cs.request_id = ?');
    $stmt->execute([$requestId]);
    return $stmt->fetch() ?: null;
}

function studentWorkflowStepCount(): int {
    return 6;
}

function studentWorkflowSteps(): array {
    return [
        ['label' => 'Request Submitted', 'icon' => 'fa-paper-plane'],
        ['label' => 'Requirements Set', 'icon' => 'fa-list-check'],
        ['label' => 'Requirements Approved', 'icon' => 'fa-clipboard-check'],
        ['label' => 'Payment', 'icon' => 'fa-credit-card'],
        ['label' => 'Document Processing', 'icon' => 'fa-cog'],
        ['label' => 'Document Release', 'icon' => 'fa-file-export'],
    ];
}

function getWorkflowStepIndex(string $status): int {
    $map = [
        'submitted'              => 0,
        'under_review'           => 0,
        'awaiting_requirements'  => 1,
        'needs_revision'         => 1,
        'requirements_submitted' => 2,
        'requirements_verified'  => 3,
        'payment_verified'       => 4,
        'processing'             => 4,
        'ready_for_pickup'       => 5,
        'shipped'                => 5,
        'completed'              => 5,
    ];
    return $map[$status] ?? 0;
}

function renderWorkflowTracker(string $status): string {
    $steps = studentWorkflowSteps();
    $currentIdx = getWorkflowStepIndex($status);
    $html = '<div class="status-tracker workflow-steps">';

    foreach ($steps as $i => $step) {
        $done = $i <= $currentIdx;
        $current = $i === $currentIdx;
        $classes = 'tracker-step' . ($done ? ' done' : '') . ($current ? ' current' : '');
        $html .= '<div class="' . $classes . '">';
        $html .= '<div class="tracker-dot"><i class="fas ' . e($step['icon']) . '"></i></div>';
        $html .= '<span>' . e($step['label']) . '</span>';
        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

function studentRequirementCompletion(int $requestId): array {
    $requirements = getAssignedRequirements($requestId);
    if (empty($requirements)) {
        return ['total' => 0, 'completed' => 0, 'percent' => 0];
    }

    require_once __DIR__ . '/clearance.php';
    $completed = 0;
    foreach ($requirements as $req) {
        if (($req['requirement_code'] ?? '') === 'online_clearance') {
            if (isClearanceComplete($requestId)) {
                $completed++;
            }
            continue;
        }
        if ($req['requires_upload'] && !empty($req['document_id'])) {
            $completed++;
        } elseif (!$req['requires_upload']) {
            $completed++;
        }
    }

    $total = count($requirements);
    return [
        'total' => $total,
        'completed' => $completed,
        'percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
    ];
}

function studentProgressPercent(string $status, ?int $requestId = null): int {
    if ($status === 'completed') {
        return 100;
    }
    if ($status === 'rejected') {
        return 0;
    }

    $percent = match ($status) {
        'submitted'              => 8,
        'under_review'           => 14,
        'awaiting_requirements'  => 22,
        'needs_revision'         => 18,
        'requirements_submitted' => 35,
        'requirements_verified'  => 50,
        'payment_verified'       => 62,
        'processing'             => 75,
        'ready_for_pickup'       => 88,
        'shipped'                => 94,
        default                  => max(5, (int) round(((getWorkflowStepIndex($status) + 1) / studentWorkflowStepCount()) * 100) - 8),
    };

    if ($requestId && in_array($status, ['awaiting_requirements', 'needs_revision'], true)) {
        $completion = studentRequirementCompletion($requestId);
        if ($completion['total'] > 0) {
            $percent = 28 + (int) round(($completion['percent'] / 100) * 22);
        }
    }

    return min(99, max(5, $percent));
}

function studentProgressStatusLabel(string $status): string {
    return match ($status) {
        'submitted'              => 'Request Submitted',
        'under_review'           => 'Under Registrar Review',
        'awaiting_requirements'  => 'Complete Your Requirements',
        'needs_revision'         => 'Revision Required',
        'requirements_submitted' => 'Awaiting Re-evaluation',
        'requirements_verified'  => 'Approved for Payment',
        'payment_verified'       => 'Payment Verified',
        'processing'             => 'Document Processing',
        'ready_for_pickup'       => 'Document Release',
        'shipped'                => 'Document Release',
        'completed'              => 'Completed',
        'rejected'               => 'Rejected',
        default                  => ucwords(str_replace('_', ' ', $status)),
    };
}

function studentProgressDescription(string $status, ?int $requestId = null): string {
    if ($status === 'awaiting_requirements' && $requestId) {
        $completion = studentRequirementCompletion($requestId);
        if ($completion['total'] > 0) {
            return $completion['completed'] . ' of ' . $completion['total'] . ' requirements completed. Upload remaining attachments and complete clearance.';
        }
    }

    return match ($status) {
        'submitted'              => 'Your request has been received and is waiting for the Registrar to review and assign requirements.',
        'under_review'           => 'The Registrar is reviewing your request details.',
        'awaiting_requirements'  => 'The Registrar assigned requirements. Complete all attachments and clearance to proceed.',
        'needs_revision'         => 'The Registrar requested corrections. Review the feedback and update your submission.',
        'requirements_submitted' => 'All requirements were submitted. The Registrar is re-evaluating before payment is allowed.',
        'requirements_verified'  => 'Your requirements were approved. You may now proceed to payment.',
        'payment_verified'       => 'Payment confirmed. The Registrar will assign your request to staff for document processing.',
        'processing'             => 'Your document is being prepared by the Registrar\'s Office.',
        'ready_for_pickup'       => 'Your document is ready for release. Collect it on your scheduled pickup date.',
        'shipped'                => 'Your document has been released. Check tracking details below.',
        'completed'              => 'Your request is complete. You may download your document or leave feedback.',
        'rejected'               => 'This request was rejected. Review the reason and resubmit corrected documents if allowed.',
        default                  => 'Track your request progress through each step below.',
    };
}

function studentProgressNextAction(string $status, int $requestId, ?string $deliveryMethod = null): ?array {
    return match ($status) {
        'awaiting_requirements', 'needs_revision' => [
            'label' => 'Complete Requirements',
            'hint'  => 'Scroll to the requirements section below.',
            'icon'  => 'fa-list-check',
            'anchor'=> '#assigned-requirements',
        ],
        'requirements_verified' => [
            'label' => 'Proceed to Payment',
            'hint'  => 'Submit your payment proof for cashier verification.',
            'icon'  => 'fa-credit-card',
            'url'   => APP_URL . '/student/payment.php?request_id=' . $requestId,
        ],
        'payment_verified' => null,
        'processing' => isPickupOptionPending($deliveryMethod) ? [
            'label' => 'Select Pickup Option',
            'hint'  => 'Choose how you will collect your document on-site.',
            'icon'  => 'fa-building',
            'anchor'=> '#pickup-option',
        ] : null,
        'rejected' => [
            'label' => 'Resubmit Documents',
            'hint'  => 'Upload corrected files and resubmit your request.',
            'icon'  => 'fa-redo',
            'anchor'=> '#resubmit-documents',
        ],
        'ready_for_pickup' => [
            'label' => 'Complete Pickup',
            'hint'  => 'Confirm receipt after collecting your document on-site.',
            'icon'  => 'fa-check-double',
            'anchor'=> '#pickup-complete',
        ],
        'completed' => [
            'label' => 'Leave Feedback',
            'hint'  => 'Tell us about your experience.',
            'icon'  => 'fa-star',
            'url'   => APP_URL . '/student/feedback.php?request_id=' . $requestId,
        ],
        default => null,
    };
}

function renderStudentProgressMini(string $status, ?int $requestId = null): string {
    $percent = studentProgressPercent($status, $requestId);
    $totalSteps = studentWorkflowStepCount();
    $stepNum = min($totalSteps, getWorkflowStepIndex($status) + 1);
    $label = studentProgressStatusLabel($status);
    $stateClass = $status === 'rejected' ? ' is-rejected' : ($status === 'completed' ? ' is-completed' : '');

    $html = '<div class="student-progress-mini' . $stateClass . '">';
    $html .= '<div class="student-progress-mini-top">';
    $html .= '<span class="student-progress-mini-label">' . e($label) . '</span>';
    if ($status !== 'rejected') {
        $html .= '<span class="student-progress-mini-step">Step ' . $stepNum . ' of ' . $totalSteps . '</span>';
    }
    $html .= '</div>';
    $html .= '<div class="student-progress-bar"><span style="width:' . $percent . '%"></span></div>';
    $html .= '<small class="student-progress-mini-percent">' . $percent . '% complete</small>';
    $html .= '</div>';
    return $html;
}

function renderStudentProgressPanel(string $status, int $requestId, ?string $deliveryMethod = null): string {
    $percent = studentProgressPercent($status, $requestId);
    $totalSteps = studentWorkflowStepCount();
    $stepNum = min($totalSteps, getWorkflowStepIndex($status) + 1);
    $label = studentProgressStatusLabel($status);
    $description = studentProgressDescription($status, $requestId);
    $nextAction = studentProgressNextAction($status, $requestId, $deliveryMethod);
    $stateClass = $status === 'completed' ? ' is-completed' : ($status === 'rejected' ? ' is-rejected' : '');

    $html = '<div class="student-progress-panel' . $stateClass . '">';
    $html .= '<div class="student-progress-header">';
    $html .= '<div class="student-progress-heading">';
    if ($status !== 'rejected') {
        $html .= '<span class="student-progress-step">Step ' . $stepNum . ' of ' . $totalSteps . '</span>';
    }
    $html .= '<h3>' . e($label) . '</h3>';
    $html .= '<p>' . e($description) . '</p>';
    $html .= '</div>';
    if ($status !== 'rejected') {
        $html .= '<div class="student-progress-percent-ring" style="--progress:' . $percent . '">';
        $html .= '<span>' . $percent . '%</span>';
        $html .= '</div>';
    }
    $html .= '</div>';

    if ($status !== 'rejected') {
        $html .= '<div class="student-progress-bar student-progress-bar-lg"><span style="width:' . $percent . '%"></span></div>';
        $html .= renderWorkflowTracker($status);
    }

    if ($nextAction) {
        $html .= '<div class="student-progress-next">';
        $html .= '<div><i class="fas ' . e($nextAction['icon']) . '"></i><div>';
        $html .= '<strong>Next action</strong>';
        $html .= '<p>' . e($nextAction['hint']) . '</p>';
        $html .= '</div></div>';
        if (!empty($nextAction['url'])) {
            $html .= '<a href="' . e($nextAction['url']) . '" class="btn btn-sm btn-primary">' . e($nextAction['label']) . '</a>';
        } elseif (!empty($nextAction['anchor'])) {
            $html .= '<a href="' . e($nextAction['anchor']) . '" class="btn btn-sm btn-primary">' . e($nextAction['label']) . '</a>';
        }
        $html .= '</div>';
    }

    if ($status === 'awaiting_requirements' || $status === 'needs_revision') {
        $completion = studentRequirementCompletion($requestId);
        if ($completion['total'] > 0) {
            $html .= '<div class="student-requirement-progress">';
            $html .= '<div class="student-requirement-progress-head">';
            $html .= '<span>Requirements checklist</span>';
            $html .= '<strong>' . $completion['completed'] . ' / ' . $completion['total'] . '</strong>';
            $html .= '</div>';
            $html .= '<div class="student-progress-bar"><span style="width:' . $completion['percent'] . '%"></span></div>';
            $html .= '</div>';
        }
    }

    $html .= '</div>';
    return $html;
}

function getComplianceStats(): array {
    $db = getDB();
    ensureComplianceSchema();

    return [
        'pending' => (int) $db->query("SELECT COUNT(*) FROM requests
            WHERE status IN ('submitted','under_review')")->fetchColumn(),
        'awaiting_student' => (int) $db->query("SELECT COUNT(*) FROM requests
            WHERE status IN ('awaiting_requirements','needs_revision')")->fetchColumn(),
        're_evaluation' => (int) $db->query("SELECT COUNT(*) FROM requests
            WHERE status = 'requirements_submitted'")->fetchColumn(),
        'compliant' => (int) $db->query("SELECT COUNT(*) FROM requests
            WHERE status = 'requirements_verified'")->fetchColumn(),
        'needs_revision' => (int) $db->query("SELECT COUNT(*) FROM requests WHERE status = 'needs_revision'")->fetchColumn(),
        'payment_ready' => (int) $db->query("SELECT COUNT(*) FROM requests WHERE status = 'payment_verified'")->fetchColumn(),
        'assignment_pending' => countRequestsAwaitingStaffAssignment(),
        'release_ready' => (int) $db->query("SELECT COUNT(*) FROM requests WHERE status IN ('processing','ready_for_pickup','shipped')")->fetchColumn(),
        'completed' => (int) $db->query("SELECT COUNT(*) FROM requests WHERE status = 'completed'")->fetchColumn(),
        'completed_today' => (int) $db->query("SELECT COUNT(*) FROM requests
            WHERE status = 'completed' AND DATE(completed_at) = CURDATE()")->fetchColumn(),
        'today' => (int) $db->query("SELECT COUNT(*) FROM request_compliance_summary
            WHERE compliance_status = 'compliant' AND DATE(verified_at) = CURDATE()")->fetchColumn(),
    ];
}

function countRequestsAwaitingStaffAssignment(): int {
    require_once __DIR__ . '/request-items.php';
    ensureRequestItemsSchema();
    $db = getDB();
    return (int) $db->query("SELECT COUNT(DISTINCT r.id)
        FROM requests r
        LEFT JOIN request_items ri ON ri.request_id = r.id
        WHERE r.status = 'payment_verified'
           OR (r.status = 'processing' AND ri.item_status = 'pending_assignment')")->fetchColumn();
}

/** @return list<array<string,mixed>> */
function getRequestsAwaitingStaffAssignment(string $search = ''): array {
    require_once __DIR__ . '/request-items.php';
    ensureRequestItemsSchema();
    $db = getDB();

    $sql = "SELECT r.*,
                   COALESCE(
                       (SELECT GROUP_CONCAT(dt2.name ORDER BY ri2.sort_order, ri2.id SEPARATOR ', ')
                        FROM request_items ri2
                        JOIN document_types dt2 ON dt2.id = ri2.document_type_id
                        WHERE ri2.request_id = r.id),
                       dt.name
                   ) AS document_name,
                   (SELECT COUNT(*) FROM request_items ri3 WHERE ri3.request_id = r.id) AS document_count,
                   (SELECT COUNT(*) FROM request_items ri4
                    WHERE ri4.request_id = r.id AND ri4.item_status = 'pending_assignment') AS pending_assignment_count,
                   u.first_name, u.last_name, u.student_id, u.email
            FROM requests r
            LEFT JOIN document_types dt ON r.document_type_id = dt.id
            JOIN users u ON r.user_id = u.id
            WHERE r.status = 'payment_verified'
               OR (
                    r.status = 'processing'
                    AND EXISTS (
                        SELECT 1 FROM request_items ri
                        WHERE ri.request_id = r.id AND ri.item_status = 'pending_assignment'
                    )
               )";

    $params = [];
    if ($search !== '') {
        $sql .= ' AND (r.request_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_id LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql .= ' ORDER BY r.updated_at ASC, r.created_at ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getRequestsForCompliance(string $filter = ''): array {
    $db = getDB();
    $params = [];

    if ($filter === 'completed') {
        $where = ["r.status = 'completed'"];
        $orderBy = 'r.completed_at DESC';
    } else {
        $where = ["r.status NOT IN ('completed','rejected')"];
        $orderBy = 'r.created_at ASC';

        if ($filter === 'pending') {
            $where[] = "r.status IN ('submitted','under_review')";
        } elseif ($filter === 'awaiting_student') {
            $where[] = "r.status IN ('awaiting_requirements','needs_revision')";
        } elseif ($filter === 're_evaluation') {
            $where[] = "r.status = 'requirements_submitted'";
        } elseif ($filter === 'verified') {
            $where[] = "r.status = 'requirements_verified'";
        } elseif ($filter === 'needs_revision') {
            $where[] = "r.status = 'needs_revision'";
        } elseif ($filter === 'payment_ready') {
            $where[] = "r.status = 'payment_verified'";
        } elseif ($filter === 'release_ready') {
            $where[] = "r.status IN ('processing','ready_for_pickup','shipped')";
        }
    }

    $sql = 'SELECT r.*, dt.name as document_name, dt.requires_upload,
                   u.first_name, u.last_name, u.student_id, u.email,
                   cs.compliance_status, cs.verified_at as compliance_verified_at,
                   (SELECT COUNT(*) FROM request_assigned_requirements ar WHERE ar.request_id = r.id) as requirement_count
            FROM requests r
            JOIN document_types dt ON r.document_type_id = dt.id
            JOIN users u ON r.user_id = u.id
            LEFT JOIN request_compliance_summary cs ON r.id = cs.request_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . $orderBy;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getStaffUsers(): array {
    require_once __DIR__ . '/assignment-offices.php';
    // Backward-compatible: registrar staff only.
    return array_values(array_filter(
        getAssignableProcessors(),
        static fn(array $user): bool => ($user['office'] ?? '') === 'registrar'
    ));
}

function saveAssignedRequirements(int $requestId, array $requirements, ?int $requestItemId = null): void {
    $db = getDB();
    if ($requestItemId) {
        $db->prepare('DELETE FROM request_assigned_requirements WHERE request_id = ? AND request_item_id = ?')
           ->execute([$requestId, $requestItemId]);
    } else {
        $db->prepare('DELETE FROM request_assigned_requirements WHERE request_id = ?')->execute([$requestId]);
    }

    foreach ($requirements as $i => $req) {
        $name = trim($req['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $db->prepare('INSERT INTO request_assigned_requirements
            (request_id, request_item_id, requirement_code, subcategory_code, requirement_name, description, requires_upload, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $requestId,
            $requestItemId,
            $req['code'] ?? null,
            !empty($req['subcategory_code']) ? $req['subcategory_code'] : null,
            $name,
            trim($req['description'] ?? '') ?: null,
            !empty($req['requires_upload']) ? 1 : 0,
            $req['sort_order'] ?? ($i + 1),
        ]);
    }
}

function studentRequirementsComplete(int $requestId): bool {
    require_once __DIR__ . '/clearance.php';

    $requirements = getAssignedRequirements($requestId);
    if (empty($requirements)) {
        return false;
    }

    foreach ($requirements as $req) {
        if (($req['requirement_code'] ?? '') === 'online_clearance') {
            if (!isClearanceComplete($requestId)) {
                return false;
            }
            continue;
        }
        if ($req['requires_upload'] && empty($req['document_id'])) {
            return false;
        }
    }
    return true;
}

function processStudentRequirementUploads(int $requestId, int $userId, array $uploads): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM requests WHERE id = ? AND user_id = ?');
    $stmt->execute([$requestId, $userId]);
    $request = $stmt->fetch();

    if (!$request || !in_array($request['status'], ['awaiting_requirements', 'needs_revision'], true)) {
        return false;
    }

    $requirements = getAssignedRequirements($requestId);
    if (empty($requirements)) {
        return false;
    }

    foreach ($requirements as $req) {
        if (!$req['requires_upload']) {
            continue;
        }

        $fileKey = 'requirement_' . $req['id'];
        if (empty($uploads[$fileKey]['name'])) {
            continue;
        }

        $path = uploadFile($uploads[$fileKey], 'request_docs');
        if (!$path) {
            continue;
        }

        $db->prepare('INSERT INTO request_documents (request_id, file_name, original_name, file_type, file_size)
            VALUES (?, ?, ?, ?, ?)')->execute([
            $requestId,
            $path,
            $uploads[$fileKey]['name'],
            $uploads[$fileKey]['type'] ?? null,
            $uploads[$fileKey]['size'] ?? null,
        ]);
        $documentId = (int) $db->lastInsertId();
        $db->prepare('UPDATE request_assigned_requirements SET document_id = ? WHERE id = ?')
           ->execute([$documentId, $req['id']]);
    }

    if (!studentRequirementsComplete($requestId)) {
        return true;
    }

    advanceToRequirementsSubmitted($requestId);
    return true;
}

function notifyRegistrarsRequestResubmitted(int $requestId, string $requestNumber): void {
    notifyUsersByRole(
        'registrar',
        'Request Resubmitted',
        'Request ' . $requestNumber . ' was resubmitted after rejection and needs review.',
        'warning',
        APP_URL . '/registrar/verify-request.php?id=' . $requestId
    );
}

function processStudentRejectedResubmit(int $requestId, int $userId, array $uploads): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM requests WHERE id = ? AND user_id = ?');
    $stmt->execute([$requestId, $userId]);
    $request = $stmt->fetch();

    if (!$request || $request['status'] !== 'rejected') {
        return false;
    }

    $uploaded = false;
    $requirements = getAssignedRequirements($requestId);

    foreach ($requirements as $req) {
        if (!$req['requires_upload']) {
            continue;
        }

        $fileKey = 'requirement_' . $req['id'];
        if (empty($uploads[$fileKey]['name'])) {
            continue;
        }

        $path = uploadFile($uploads[$fileKey], 'request_docs');
        if (!$path) {
            continue;
        }

        $db->prepare('INSERT INTO request_documents (request_id, file_name, original_name, file_type, file_size)
            VALUES (?, ?, ?, ?, ?)')->execute([
            $requestId,
            $path,
            $uploads[$fileKey]['name'],
            $uploads[$fileKey]['type'] ?? null,
            $uploads[$fileKey]['size'] ?? null,
        ]);
        $documentId = (int) $db->lastInsertId();
        $db->prepare('UPDATE request_assigned_requirements
            SET document_id = ?, is_met = 0, verified_by = NULL, verified_at = NULL
            WHERE id = ?')->execute([$documentId, $req['id']]);
        $uploaded = true;
    }

    if (!empty($uploads['documents']['name'])) {
        $fileNames = $uploads['documents']['name'];
        if (!is_array($fileNames)) {
            $fileNames = [$fileNames];
            $uploads['documents'] = [
                'name'     => [$uploads['documents']['name']],
                'type'     => [$uploads['documents']['type']],
                'tmp_name' => [$uploads['documents']['tmp_name']],
                'error'    => [$uploads['documents']['error']],
                'size'     => [$uploads['documents']['size']],
            ];
        }

        foreach ($fileNames as $i => $name) {
            if ($name === '') {
                continue;
            }

            $file = [
                'name'     => $name,
                'type'     => $uploads['documents']['type'][$i] ?? null,
                'tmp_name' => $uploads['documents']['tmp_name'][$i] ?? null,
                'error'    => $uploads['documents']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $uploads['documents']['size'][$i] ?? 0,
            ];
            $path = uploadFile($file, 'request_docs');
            if (!$path) {
                continue;
            }

            $db->prepare('INSERT INTO request_documents (request_id, file_name, original_name, file_type, file_size)
                VALUES (?, ?, ?, ?, ?)')->execute([
                $requestId,
                $path,
                $name,
                $file['type'],
                $file['size'],
            ]);
            $uploaded = true;
        }
    }

    if (!$uploaded) {
        if (empty($requirements) || !studentRequirementsComplete($requestId)) {
            return false;
        }
    }

    $db->prepare('UPDATE requests SET rejection_reason = NULL WHERE id = ?')->execute([$requestId]);
    $db->prepare("UPDATE request_compliance_summary
        SET compliance_status = 'pending', remarks = NULL, verified_by = NULL, verified_at = NULL, updated_at = NOW()
        WHERE request_id = ?")->execute([$requestId]);
    $db->prepare('UPDATE request_assigned_requirements
        SET is_met = 0, verified_by = NULL, verified_at = NULL
        WHERE request_id = ?')->execute([$requestId]);

    if (!empty($requirements)) {
        updateRequestStatus($requestId, 'awaiting_requirements', 'Student resubmitted documents after rejection');
        maybeAdvanceToRequirementsSubmitted($requestId);
    } else {
        updateRequestStatus($requestId, 'submitted', 'Student resubmitted documents after rejection');
    }

    notifyRegistrarsRequestResubmitted($requestId, $request['request_number']);
    auditLog('request_resubmitted', 'requests', $requestId);
    return true;
}

function processComplianceAction(int $requestId, array $checks, string $action, int $verifierId, string $remarks = '', array $extra = []): bool {
    $db = getDB();
    $stmt = $db->prepare('SELECT r.*, u.email, u.first_name FROM requests r JOIN users u ON r.user_id = u.id WHERE r.id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        return false;
    }

    initRequestCompliance($requestId, (int) $request['document_type_id']);

    if ($action === 'confirm_request') {
        if (!in_array($request['status'], ['submitted', 'under_review', 'needs_revision'], true)) {
            return false;
        }

        $requirements = $extra['requirements'] ?? [];
        if (empty($requirements)) {
            if (!documentTypeRequiresRequirements((int) $request['document_type_id'])) {
                return applyNoRequirementsToRequest($requestId, (int) $request['document_type_id'], true, $remarks ?: null);
            }

            return false;
        }

        saveAssignedRequirements($requestId, $requirements);

        if (hasAssignedRequirement($requestId, 'online_clearance')) {
            require_once __DIR__ . '/clearance.php';
            initRequestClearance($requestId);
            syncAssignedClearanceRequirement($requestId);
        }

        $db->prepare("UPDATE request_compliance_summary SET compliance_status = 'pending', remarks = ?, updated_at = NOW() WHERE request_id = ?")
           ->execute([$remarks ?: null, $requestId]);

        updateRequestStatus($requestId, 'awaiting_requirements', 'Registrar confirmed request and set requirements');
        $noteHint = $remarks !== '' ? ' Review the registrar instructions and any attached files.' : '';
        sendNotification(
            $request['user_id'],
            'Requirements Assigned',
            'Your request ' . $request['request_number'] . ' has been reviewed. Please complete the listed requirements.' . $noteHint,
            'info',
            APP_URL . '/student/request-view.php?id=' . $requestId
        );
        auditLog('requirements_assigned', 'requests', $requestId);
        return true;
    }

    if ($action === 'approve_for_payment') {
        if (!in_array($request['status'], ['requirements_submitted', 'awaiting_requirements', 'needs_revision'], true)) {
            return false;
        }

        require_once __DIR__ . '/clearance.php';
        syncAssignedClearanceRequirement($requestId);
        maybeAdvanceToRequirementsSubmitted($requestId);

        if (!studentRequirementsComplete($requestId)) {
            return false;
        }

        $assigned = getAssignedRequirements($requestId);
        foreach ($assigned as $req) {
            if (($req['requirement_code'] ?? '') === 'online_clearance') {
                if (!isClearanceComplete($requestId)) {
                    return false;
                }
                $db->prepare('UPDATE request_assigned_requirements SET is_met = 1, verified_by = ?, verified_at = NOW() WHERE id = ?')
                   ->execute([$verifierId, $req['id']]);
                continue;
            }

            $met = !empty($checks[$req['id']])
                || ($req['requires_upload'] && !empty($req['document_id']));
            if (!$met) {
                return false;
            }
            $db->prepare('UPDATE request_assigned_requirements SET is_met = ?, verified_by = ?, verified_at = NOW() WHERE id = ?')
               ->execute([1, $verifierId, $req['id']]);
        }

        $existingSummary = getComplianceSummary($requestId);
        $keptRemarks = $remarks !== '' ? $remarks : ($existingSummary['remarks'] ?? null);
        $db->prepare("UPDATE request_compliance_summary SET compliance_status = 'compliant', verified_by = ?, verified_at = NOW(), remarks = ? WHERE request_id = ?")
           ->execute([$verifierId, $keptRemarks ?: null, $requestId]);

        updateRequestStatus($requestId, 'requirements_verified', 'Requirements approved — proceed to payment');
        sendNotification(
            $request['user_id'],
            'Requirements Approved',
            'Your request ' . $request['request_number'] . ' has been approved. You may now proceed to payment.',
            'success',
            APP_URL . '/student/payment.php?request_id=' . $requestId
        );
        auditLog('requirements_approved', 'requests', $requestId);
        return true;
    }

    if ($action === 'needs_revision') {
        if (!in_array($request['status'], ['requirements_submitted', 'awaiting_requirements'], true)) {
            return false;
        }

        if ($remarks === '') {
            return false;
        }

        $db->prepare("UPDATE request_compliance_summary SET compliance_status = 'needs_revision', verified_by = ?, verified_at = NOW(), remarks = ? WHERE request_id = ?")
           ->execute([$verifierId, $remarks, $requestId]);

        updateRequestStatus($requestId, 'needs_revision', $remarks);
        sendNotification(
            $request['user_id'],
            'Requirements Need Revision',
            'Your request ' . $request['request_number'] . ' requires corrections: ' . $remarks
                . ' Check any instruction attachments from the registrar.',
            'warning',
            APP_URL . '/student/request-view.php?id=' . $requestId
        );
        auditLog('requirements_needs_revision', 'requests', $requestId);
        return true;
    }

    if ($action === 'reject') {
        $db->prepare("UPDATE request_compliance_summary SET compliance_status = 'non_compliant', verified_by = ?, verified_at = NOW(), remarks = ? WHERE request_id = ?")
           ->execute([$verifierId, $remarks, $requestId]);

        $db->prepare('UPDATE requests SET rejection_reason = ? WHERE id = ?')->execute([$remarks, $requestId]);
        updateRequestStatus($requestId, 'rejected', $remarks ?: 'Request rejected');
        sendNotification(
            $request['user_id'],
            'Request Rejected',
            'Your request ' . $request['request_number'] . ' was rejected: ' . $remarks,
            'error',
            APP_URL . '/student/request-view.php?id=' . $requestId
        );
        auditLog('request_rejected', 'requests', $requestId);
        return true;
    }

    if ($action === 'assign_processing') {
        require_once __DIR__ . '/request-items.php';

        $canAssign = $request['status'] === 'payment_verified'
            || ($request['status'] === 'processing' && requestHasPendingAssignmentItems($requestId));
        if (!$canAssign) {
            return false;
        }

        $itemAssignments = $extra['item_assignments'] ?? [];
        if (!empty($itemAssignments)) {
            $assignedCount = 0;
            foreach ($itemAssignments as $itemId => $assignment) {
                $itemId = (int) $itemId;
                $staffId = (int) ($assignment['assigned_to'] ?? 0);
                $releaseDate = trim((string) ($assignment['release_date'] ?? ''));
                $releaseTime = trim((string) ($assignment['release_time'] ?? ''));
                if (!$itemId || !$staffId || !$releaseDate || !$releaseTime) {
                    continue;
                }
                if (assignRequestItemProcessing($itemId, $staffId, $releaseDate, $releaseTime, $verifierId)) {
                    $assignedCount++;
                }
            }

            if ($assignedCount === 0) {
                return false;
            }

            auditLog('request_batch_assigned', 'requests', $requestId, null, ['items_assigned' => $assignedCount]);
            return true;
        }

        $staffId = (int) ($extra['assigned_to'] ?? 0);
        if (!$staffId) {
            return false;
        }

        $releaseDate = $extra['release_date'] ?? null;
        $releaseTime = $extra['release_time'] ?? null;
        $items = getRequestItems($requestId);
        $pendingItems = array_values(array_filter(
            $items,
            static fn(array $item): bool => ($item['item_status'] ?? '') === 'pending_assignment'
        ));
        if (count($pendingItems) === 1) {
            return assignRequestItemProcessing(
                (int) $pendingItems[0]['id'],
                $staffId,
                (string) $releaseDate,
                (string) $releaseTime,
                $verifierId
            );
        }

        if (count($items) === 1) {
            return assignRequestItemProcessing((int) $items[0]['id'], $staffId, (string) $releaseDate, (string) $releaseTime, $verifierId);
        }

        return false;
    }

    if ($action === 'update_release_schedule') {
        if (!in_array($request['status'], ['processing', 'ready_for_pickup', 'payment_verified'], true)) {
            return false;
        }

        if (!isPickupOptionPending($request['delivery_method']) && !isOnSitePickupMethod($request['delivery_method'])) {
            return false;
        }

        $releaseDate = $extra['release_date'] ?? null;
        $releaseTime = $extra['release_time'] ?? null;
        if (!$releaseDate || !$releaseTime) {
            return false;
        }

        $db->prepare('UPDATE requests SET release_date = ?, release_time = ?, pickup_date = ?, pickup_time = ? WHERE id = ?')
           ->execute([$releaseDate, $releaseTime, $releaseDate, $releaseTime, $requestId]);

        sendNotification(
            $request['user_id'],
            'Release Date Updated',
            'The on-site release schedule for ' . $request['request_number'] . ' is now ' . formatDate($releaseDate) . ' at ' . date('g:i A', strtotime($releaseTime)) . '.',
            'info',
            APP_URL . '/student/request-view.php?id=' . $requestId
        );
        auditLog('release_schedule_updated', 'requests', $requestId);
        return true;
    }

    return false;
}

function ensureDefaultRegistrarUser(): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE email = 'registrar@regdum.edu.ph'");
    $stmt->execute();
    if ($stmt->fetch()) {
        return;
    }

    $roleId = $db->query("SELECT id FROM roles WHERE name = 'registrar'")->fetchColumn();
    if (!$roleId) {
        return;
    }

    $hash = password_hash('Registrar@123', PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (?, ?, ?, ?, ?, 1, 1)')
       ->execute([$roleId, 'registrar@regdum.edu.ph', $hash, 'Records', 'Registrar']);
}

function requestStatusOptions(): array {
    return [
        'submitted', 'under_review', 'awaiting_requirements', 'requirements_submitted',
        'needs_revision', 'requirements_verified', 'payment_verified', 'processing',
        'ready_for_pickup', 'shipped', 'completed', 'rejected',
    ];
}

function studentTrackerStatuses(): array {
    return requestStatusOptions();
}

function complianceBadge(?string $status): string {
    $classes = [
        'pending'        => 'badge-submitted',
        'compliant'      => 'badge-completed',
        'non_compliant'  => 'badge-rejected',
        'needs_revision' => 'badge-review',
    ];
    $class = $classes[$status ?? 'pending'] ?? 'badge-submitted';
    $label = ucwords(str_replace('_', ' ', $status ?? 'pending'));
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

function workflowPhaseLabel(string $status): string {
    return match ($status) {
        'submitted', 'under_review' => 'Step 1 — New request',
        'awaiting_requirements', 'needs_revision' => 'Step 2 — Awaiting student requirements',
        'requirements_submitted' => 'Step 3 — Re-evaluation',
        'requirements_verified' => 'Step 4 — Awaiting payment',
        'payment_verified', 'processing' => 'Step 5 — Document processing',
        'ready_for_pickup', 'shipped', 'completed' => 'Step 6 — Document release',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}
