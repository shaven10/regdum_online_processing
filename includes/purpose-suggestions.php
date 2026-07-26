<?php

function ensureRequestPurposesSchema(): void {
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS request_purposes (
        id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        label VARCHAR(150) NOT NULL,
        hint TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS request_purpose_document_suggestions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        purpose_id TINYINT UNSIGNED NOT NULL,
        document_type_id TINYINT UNSIGNED NOT NULL,
        enrollment_status ENUM('enrolled','graduated','inactive') NOT NULL DEFAULT 'enrolled',
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_purpose_document_status (purpose_id, document_type_id, enrollment_status),
        FOREIGN KEY (purpose_id) REFERENCES request_purposes(id) ON DELETE CASCADE,
        FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS request_purpose_enrollment_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        purpose_id TINYINT UNSIGNED NOT NULL,
        enrollment_status ENUM('enrolled','graduated','inactive') NOT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        hint TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_purpose_enrollment (purpose_id, enrollment_status),
        FOREIGN KEY (purpose_id) REFERENCES request_purposes(id) ON DELETE CASCADE
    )");

    migrateRequestPurposeColumnToVarchar();
    migratePurposeSuggestionsEnrollmentStatus();
    seedDefaultRequestPurposes();
    ensurePurposeEnrollmentSettingsForAll();
}

function purposeEnrollmentStatusOptions(): array {
    if (function_exists('enrollmentStatusOptions')) {
        return enrollmentStatusOptions();
    }

    return [
        'graduated' => 'Graduated',
        'enrolled'  => 'Enrolled',
        'inactive'  => 'Inactive',
    ];
}

function purposeEnrollmentStatuses(): array {
    return array_keys(purposeEnrollmentStatusOptions());
}

function normalizePurposeEnrollmentStatus(?string $enrollmentStatus): string {
    $status = (string) ($enrollmentStatus ?? 'enrolled');
    return array_key_exists($status, purposeEnrollmentStatusOptions()) ? $status : 'enrolled';
}

function migrateRequestPurposeColumnToVarchar(): void {
    $db = getDB();
    $column = $db->query("SHOW COLUMNS FROM requests LIKE 'purpose'")->fetch();
    if (!$column) {
        return;
    }

    if (stripos((string) $column['Type'], 'enum') !== false) {
        $db->exec("ALTER TABLE requests MODIFY purpose VARCHAR(50) NOT NULL");
    }
}

function migratePurposeSuggestionsEnrollmentStatus(): void {
    $db = getDB();

    $copyCol = $db->query("SHOW COLUMNS FROM request_purpose_document_suggestions LIKE 'enrollment_status'")->fetch();
    if (!$copyCol) {
        $db->exec("ALTER TABLE request_purpose_document_suggestions
            ADD COLUMN enrollment_status ENUM('enrolled','graduated','inactive') NOT NULL DEFAULT 'enrolled'
            AFTER document_type_id");
    }

    $indexes = $db->query('SHOW INDEX FROM request_purpose_document_suggestions')->fetchAll();
    $hasLegacy = false;
    $hasNew = false;
    $hasPurposeIndex = false;

    foreach ($indexes as $index) {
        $name = (string) ($index['Key_name'] ?? '');
        if ($name === 'uk_purpose_document') {
            $hasLegacy = true;
        }
        if ($name === 'uk_purpose_document_status') {
            $hasNew = true;
        }
        if ($name === 'idx_purpose_suggestions_purpose' || (
            $name !== 'PRIMARY'
            && (int) ($index['Seq_in_index'] ?? 0) === 1
            && ($index['Column_name'] ?? '') === 'purpose_id'
            && (int) ($index['Non_unique'] ?? 1) === 1
        )) {
            $hasPurposeIndex = true;
        }
    }

    if (!$hasPurposeIndex) {
        try {
            $db->exec('ALTER TABLE request_purpose_document_suggestions
                ADD INDEX idx_purpose_suggestions_purpose (purpose_id)');
        } catch (Throwable $e) {
            // Index may already exist.
        }
    }

    if ($hasLegacy) {
        try {
            $db->exec('ALTER TABLE request_purpose_document_suggestions DROP INDEX uk_purpose_document');
        } catch (Throwable $e) {
            // Keep going.
        }
    }

    if (!$hasNew) {
        try {
            $db->exec('ALTER TABLE request_purpose_document_suggestions
                ADD UNIQUE KEY uk_purpose_document_status (purpose_id, document_type_id, enrollment_status)');
        } catch (Throwable $e) {
            // Index may already exist.
        }
    }

    // Expand legacy (status-agnostic) rows into all enrollment classifications.
    $purposeIds = $db->query('SELECT DISTINCT purpose_id FROM request_purpose_document_suggestions')->fetchAll(PDO::FETCH_COLUMN);
    $countForStatus = $db->prepare('SELECT COUNT(*) FROM request_purpose_document_suggestions
        WHERE purpose_id = ? AND enrollment_status = ?');
    $copyFrom = $db->prepare('INSERT IGNORE INTO request_purpose_document_suggestions
        (purpose_id, document_type_id, enrollment_status, sort_order)
        SELECT purpose_id, document_type_id, ?, sort_order
        FROM request_purpose_document_suggestions
        WHERE purpose_id = ? AND enrollment_status = ?');

    foreach ($purposeIds as $purposeId) {
        $purposeId = (int) $purposeId;
        $sourceStatus = null;
        foreach (['enrolled', 'graduated', 'inactive'] as $status) {
            $countForStatus->execute([$purposeId, $status]);
            if ((int) $countForStatus->fetchColumn() > 0) {
                $sourceStatus = $status;
                break;
            }
        }
        if ($sourceStatus === null) {
            continue;
        }

        foreach (purposeEnrollmentStatuses() as $status) {
            $countForStatus->execute([$purposeId, $status]);
            if ((int) $countForStatus->fetchColumn() === 0) {
                $copyFrom->execute([$status, $purposeId, $sourceStatus]);
            }
        }
    }
}

function ensurePurposeEnrollmentSettingsForAll(): void {
    $db = getDB();
    $purposes = $db->query('SELECT id, hint, is_active FROM request_purposes')->fetchAll();
    $insert = $db->prepare('INSERT IGNORE INTO request_purpose_enrollment_settings
        (purpose_id, enrollment_status, is_enabled, hint)
        VALUES (?, ?, ?, ?)');

    foreach ($purposes as $purpose) {
        foreach (purposeEnrollmentStatuses() as $status) {
            $insert->execute([
                (int) $purpose['id'],
                $status,
                (int) $purpose['is_active'] ? 1 : 0,
                $purpose['hint'] ?: null,
            ]);
        }
    }
}

function defaultRequestPurposeDefinitions(): array {
    return [
        [
            'code' => 'employment',
            'label' => 'Employment',
            'hint' => 'Employers often ask for your transcript, graduation proof, diploma, or good moral certificate.',
            'sort_order' => 10,
            'documents' => ['TOR', 'COG', 'DIPLOMA', 'GMC'],
        ],
        [
            'code' => 'scholarship',
            'label' => 'Scholarship',
            'hint' => 'Scholarship applications usually require your transcript, grades, enrollment proof, statement of account, or good moral certificate.',
            'sort_order' => 20,
            'documents' => ['TOR', 'COGR', 'COE', 'GMC', 'SOA'],
        ],
        [
            'code' => 'transfer',
            'label' => 'Transfer',
            'hint' => 'School transfers typically need your transcript, grades, and enrollment certificate.',
            'sort_order' => 30,
            'documents' => ['TOR', 'COGR', 'COE'],
        ],
        [
            'code' => 'further_studies',
            'label' => 'Further Studies',
            'hint' => 'Graduate or further studies applications commonly require transcript, diploma, graduation proof, and grades.',
            'sort_order' => 40,
            'documents' => ['TOR', 'DIPLOMA', 'COG', 'COGR'],
        ],
        [
            'code' => 'personal',
            'label' => 'Personal',
            'hint' => 'Common personal requests include transcript, grades, enrollment certificate, or statement of account.',
            'sort_order' => 50,
            'documents' => ['TOR', 'COGR', 'COE', 'SOA'],
        ],
        [
            'code' => 'legal',
            'label' => 'Legal',
            'hint' => 'Legal purposes often need authenticated copies of your transcript, diploma, or certified true copies.',
            'sort_order' => 60,
            'documents' => ['TOR', 'DIPLOMA', 'CTC'],
        ],
        [
            'code' => 'other',
            'label' => 'Other',
            'hint' => 'Select the documents that match your specific need below.',
            'sort_order' => 70,
            'documents' => [],
        ],
    ];
}

function seedDefaultRequestPurposes(): void {
    $db = getDB();
    $count = (int) $db->query('SELECT COUNT(*) FROM request_purposes')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $documentIdsByCode = [];
    foreach ($db->query('SELECT id, code FROM document_types')->fetchAll() as $row) {
        $documentIdsByCode[$row['code']] = (int) $row['id'];
    }

    $insertPurpose = $db->prepare('INSERT INTO request_purposes (code, label, hint, sort_order, is_active) VALUES (?, ?, ?, ?, 1)');
    $insertSuggestion = $db->prepare('INSERT INTO request_purpose_document_suggestions
        (purpose_id, document_type_id, enrollment_status, sort_order) VALUES (?, ?, ?, ?)');
    $insertSetting = $db->prepare('INSERT INTO request_purpose_enrollment_settings
        (purpose_id, enrollment_status, is_enabled, hint) VALUES (?, ?, 1, ?)');

    foreach (defaultRequestPurposeDefinitions() as $definition) {
        $insertPurpose->execute([
            $definition['code'],
            $definition['label'],
            $definition['hint'],
            (int) $definition['sort_order'],
        ]);
        $purposeId = (int) $db->lastInsertId();

        foreach (purposeEnrollmentStatuses() as $status) {
            $insertSetting->execute([$purposeId, $status, $definition['hint']]);

            $sortOrder = 0;
            foreach ($definition['documents'] as $documentCode) {
                $documentTypeId = $documentIdsByCode[$documentCode] ?? null;
                if (!$documentTypeId) {
                    continue;
                }
                $sortOrder += 10;
                $insertSuggestion->execute([$purposeId, $documentTypeId, $status, $sortOrder]);
            }
        }
    }
}

function normalizePurposeCode(string $code): string {
    $code = strtolower(trim($code));
    $code = preg_replace('/[^a-z0-9_]+/', '_', $code);
    $code = preg_replace('/_+/', '_', $code);

    return trim($code, '_');
}

function getActiveRequestPurposeCodes(?string $enrollmentStatus = null): array {
    ensureRequestPurposesSchema();
    $db = getDB();

    if ($enrollmentStatus === null) {
        $stmt = $db->query('SELECT code FROM request_purposes WHERE is_active = 1 ORDER BY sort_order, label');
        return array_column($stmt->fetchAll(), 'code');
    }

    $status = normalizePurposeEnrollmentStatus($enrollmentStatus);
    $stmt = $db->prepare('SELECT p.code
        FROM request_purposes p
        INNER JOIN request_purpose_enrollment_settings s
            ON s.purpose_id = p.id AND s.enrollment_status = ?
        WHERE p.is_active = 1 AND s.is_enabled = 1
        ORDER BY p.sort_order, p.label');
    $stmt->execute([$status]);

    return array_column($stmt->fetchAll(), 'code');
}

function getRequestPurposeLabel(string $code): ?string {
    ensureRequestPurposesSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT label FROM request_purposes WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    $label = $stmt->fetchColumn();

    return $label !== false ? (string) $label : null;
}

function getRequestPurposeHint(string $code, ?string $enrollmentStatus = null): string {
    ensureRequestPurposesSchema();
    $db = getDB();

    if ($enrollmentStatus !== null) {
        $status = normalizePurposeEnrollmentStatus($enrollmentStatus);
        $stmt = $db->prepare('SELECT COALESCE(NULLIF(TRIM(s.hint), \'\'), p.hint) AS hint
            FROM request_purposes p
            LEFT JOIN request_purpose_enrollment_settings s
                ON s.purpose_id = p.id AND s.enrollment_status = ?
            WHERE p.code = ?
            LIMIT 1');
        $stmt->execute([$status, $code]);
        $hint = $stmt->fetchColumn();
        return $hint !== false ? (string) $hint : '';
    }

    $stmt = $db->prepare('SELECT hint FROM request_purposes WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    $hint = $stmt->fetchColumn();

    return $hint !== false ? (string) $hint : '';
}

function isValidActiveRequestPurposeCode(string $code, ?string $enrollmentStatus = null): bool {
    ensureRequestPurposesSchema();
    $db = getDB();

    if ($enrollmentStatus === null) {
        $stmt = $db->prepare('SELECT id FROM request_purposes WHERE code = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$code]);
        return (bool) $stmt->fetchColumn();
    }

    $status = normalizePurposeEnrollmentStatus($enrollmentStatus);
    $stmt = $db->prepare('SELECT p.id
        FROM request_purposes p
        INNER JOIN request_purpose_enrollment_settings s
            ON s.purpose_id = p.id AND s.enrollment_status = ?
        WHERE p.code = ? AND p.is_active = 1 AND s.is_enabled = 1
        LIMIT 1');
    $stmt->execute([$status, $code]);

    return (bool) $stmt->fetchColumn();
}

function getPurposeSuggestedDocumentCodesMap(?string $enrollmentStatus = null): array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $purposes = $db->query('SELECT id, code FROM request_purposes ORDER BY sort_order, label')->fetchAll();
    $map = [];
    $status = $enrollmentStatus !== null ? normalizePurposeEnrollmentStatus($enrollmentStatus) : null;

    if ($status === null) {
        $stmt = $db->prepare('SELECT dt.code
            FROM request_purpose_document_suggestions s
            INNER JOIN document_types dt ON dt.id = s.document_type_id
            WHERE s.purpose_id = ?
            GROUP BY dt.code, dt.name
            ORDER BY MIN(s.sort_order), dt.name');
    } else {
        $stmt = $db->prepare('SELECT dt.code
            FROM request_purpose_document_suggestions s
            INNER JOIN document_types dt ON dt.id = s.document_type_id
            WHERE s.purpose_id = ? AND s.enrollment_status = ?
            ORDER BY s.sort_order, dt.name');
    }

    foreach ($purposes as $purpose) {
        if ($status === null) {
            $stmt->execute([(int) $purpose['id']]);
        } else {
            $stmt->execute([(int) $purpose['id'], $status]);
        }
        $map[$purpose['code']] = array_column($stmt->fetchAll(), 'code');
    }

    return $map;
}

function getSuggestedDocumentTypeIdsForPurposeCode(string $purposeCode, ?string $enrollmentStatus = null): array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $status = $enrollmentStatus !== null ? normalizePurposeEnrollmentStatus($enrollmentStatus) : null;

    if ($status === null) {
        $stmt = $db->prepare('SELECT s.document_type_id
            FROM request_purpose_document_suggestions s
            INNER JOIN request_purposes p ON p.id = s.purpose_id
            WHERE p.code = ?
            GROUP BY s.document_type_id
            ORDER BY MIN(s.sort_order)');
        $stmt->execute([$purposeCode]);
    } else {
        $stmt = $db->prepare('SELECT s.document_type_id
            FROM request_purpose_document_suggestions s
            INNER JOIN request_purposes p ON p.id = s.purpose_id
            WHERE p.code = ? AND s.enrollment_status = ?
            ORDER BY s.sort_order');
        $stmt->execute([$purposeCode, $status]);
    }

    return array_map('intval', array_column($stmt->fetchAll(), 'document_type_id'));
}

function getSuggestedDocumentTypeIdsForPurposeId(int $purposeId, ?string $enrollmentStatus = null): array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $status = $enrollmentStatus !== null ? normalizePurposeEnrollmentStatus($enrollmentStatus) : null;

    if ($status === null) {
        $stmt = $db->prepare('SELECT document_type_id
            FROM request_purpose_document_suggestions
            WHERE purpose_id = ?
            GROUP BY document_type_id
            ORDER BY MIN(sort_order)');
        $stmt->execute([$purposeId]);
    } else {
        $stmt = $db->prepare('SELECT document_type_id
            FROM request_purpose_document_suggestions
            WHERE purpose_id = ? AND enrollment_status = ?
            ORDER BY sort_order');
        $stmt->execute([$purposeId, $status]);
    }

    return array_map('intval', array_column($stmt->fetchAll(), 'document_type_id'));
}

function getSuggestedDocumentNamesForPurposeId(int $purposeId, ?string $enrollmentStatus = null): array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $status = $enrollmentStatus !== null ? normalizePurposeEnrollmentStatus($enrollmentStatus) : null;

    if ($status === null) {
        $stmt = $db->prepare('SELECT dt.name
            FROM request_purpose_document_suggestions s
            INNER JOIN document_types dt ON dt.id = s.document_type_id
            WHERE s.purpose_id = ?
            GROUP BY dt.id, dt.name
            ORDER BY MIN(s.sort_order), dt.name');
        $stmt->execute([$purposeId]);
    } else {
        $stmt = $db->prepare('SELECT dt.name
            FROM request_purpose_document_suggestions s
            INNER JOIN document_types dt ON dt.id = s.document_type_id
            WHERE s.purpose_id = ? AND s.enrollment_status = ?
            ORDER BY s.sort_order, dt.name');
        $stmt->execute([$purposeId, $status]);
    }

    return array_column($stmt->fetchAll(), 'name');
}

function getPurposeEnrollmentSettingsMap(int $purposeId): array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $purpose = getRequestPurposeById($purposeId);
    $fallbackHint = $purpose['hint'] ?? '';
    $fallbackEnabled = $purpose ? (int) $purpose['is_active'] : 1;

    $map = [];
    foreach (purposeEnrollmentStatuses() as $status) {
        $map[$status] = [
            'is_enabled' => $fallbackEnabled,
            'hint' => (string) $fallbackHint,
        ];
    }

    $stmt = $db->prepare('SELECT enrollment_status, is_enabled, hint
        FROM request_purpose_enrollment_settings
        WHERE purpose_id = ?');
    $stmt->execute([$purposeId]);

    foreach ($stmt->fetchAll() as $row) {
        $status = normalizePurposeEnrollmentStatus($row['enrollment_status'] ?? null);
        $map[$status] = [
            'is_enabled' => (int) $row['is_enabled'],
            'hint' => (string) ($row['hint'] ?? ''),
        ];
    }

    return $map;
}

function countRequestsByPurposeCode(string $code): int {
    $db = getDB();
    $stmt = $db->prepare('SELECT COUNT(*) FROM requests WHERE purpose = ?');
    $stmt->execute([$code]);

    return (int) $stmt->fetchColumn();
}

function getRequestPurposeById(int $purposeId): ?array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM request_purposes WHERE id = ?');
    $stmt->execute([$purposeId]);
    $purpose = $stmt->fetch();

    return $purpose ?: null;
}

function getAllRequestPurposesWithSuggestions(): array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $purposes = $db->query('SELECT * FROM request_purposes ORDER BY sort_order, label')->fetchAll();

    foreach ($purposes as &$purpose) {
        $purposeId = (int) $purpose['id'];
        $purpose['enrollment_settings'] = getPurposeEnrollmentSettingsMap($purposeId);
        $purpose['suggestions_by_status'] = [];
        $purpose['suggested_names_by_status'] = [];

        foreach (purposeEnrollmentStatuses() as $status) {
            $purpose['suggestions_by_status'][$status] = getSuggestedDocumentTypeIdsForPurposeId($purposeId, $status);
            $purpose['suggested_names_by_status'][$status] = getSuggestedDocumentNamesForPurposeId($purposeId, $status);
        }

        // Backward-compatible aggregates.
        $purpose['suggested_document_type_ids'] = getSuggestedDocumentTypeIdsForPurposeId($purposeId, 'enrolled');
        $purpose['suggested_document_names'] = getSuggestedDocumentNamesForPurposeId($purposeId, 'enrolled');
        $purpose['request_count'] = countRequestsByPurposeCode($purpose['code']);
    }
    unset($purpose);

    return $purposes;
}

function saveRequestPurposeDocumentSuggestions(
    int $purposeId,
    array $documentTypeIds,
    ?string $enrollmentStatus = null
): void {
    ensureRequestPurposesSchema();
    $db = getDB();

    $validIds = [];
    foreach (array_unique(array_filter(array_map('intval', $documentTypeIds))) as $documentTypeId) {
        if ($documentTypeId > 0) {
            $validIds[] = $documentTypeId;
        }
    }

    $statuses = $enrollmentStatus === null
        ? purposeEnrollmentStatuses()
        : [normalizePurposeEnrollmentStatus($enrollmentStatus)];

    $db->beginTransaction();
    try {
        $delete = $db->prepare('DELETE FROM request_purpose_document_suggestions
            WHERE purpose_id = ? AND enrollment_status = ?');
        $insert = $db->prepare('INSERT INTO request_purpose_document_suggestions
            (purpose_id, document_type_id, enrollment_status, sort_order) VALUES (?, ?, ?, ?)');

        foreach ($statuses as $status) {
            $delete->execute([$purposeId, $status]);
            $sortOrder = 0;
            foreach ($validIds as $documentTypeId) {
                $sortOrder += 10;
                $insert->execute([$purposeId, $documentTypeId, $status, $sortOrder]);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function saveRequestPurposeEnrollmentSettings(int $purposeId, array $settingsByStatus): void {
    foreach (purposeEnrollmentStatuses() as $status) {
        if (!array_key_exists($status, $settingsByStatus)) {
            continue;
        }
        saveRequestPurposeEnrollmentSettingForStatus($purposeId, $status, $settingsByStatus[$status]);
    }
}

function saveRequestPurposeEnrollmentSettingForStatus(int $purposeId, string $enrollmentStatus, array $setting): void {
    ensureRequestPurposesSchema();
    $db = getDB();
    $status = normalizePurposeEnrollmentStatus($enrollmentStatus);
    $purpose = getRequestPurposeById($purposeId);
    $fallbackHint = $purpose['hint'] ?? null;
    $enabled = !empty($setting['is_enabled']) ? 1 : 0;
    $hint = trim((string) ($setting['hint'] ?? ''));

    $db->prepare('INSERT INTO request_purpose_enrollment_settings
        (purpose_id, enrollment_status, is_enabled, hint)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            is_enabled = VALUES(is_enabled),
            hint = VALUES(hint),
            updated_at = NOW()')
       ->execute([
           $purposeId,
           $status,
           $enabled,
           $hint !== '' ? $hint : $fallbackHint,
       ]);
}

function saveRequestPurposeSuggestionsByEnrollment(int $purposeId, array $suggestionsByStatus): void {
    foreach (purposeEnrollmentStatuses() as $status) {
        if (!array_key_exists($status, $suggestionsByStatus)) {
            continue;
        }
        saveRequestPurposeDocumentSuggestions($purposeId, (array) $suggestionsByStatus[$status], $status);
    }
}
