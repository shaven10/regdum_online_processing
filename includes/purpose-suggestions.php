<?php

function ensureRequestPurposesSchema(): void {
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
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_purpose_document (purpose_id, document_type_id),
        FOREIGN KEY (purpose_id) REFERENCES request_purposes(id) ON DELETE CASCADE,
        FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
    )");

    migrateRequestPurposeColumnToVarchar();
    seedDefaultRequestPurposes();
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
    $insertSuggestion = $db->prepare('INSERT INTO request_purpose_document_suggestions (purpose_id, document_type_id, sort_order) VALUES (?, ?, ?)');

    foreach (defaultRequestPurposeDefinitions() as $definition) {
        $insertPurpose->execute([
            $definition['code'],
            $definition['label'],
            $definition['hint'],
            (int) $definition['sort_order'],
        ]);
        $purposeId = (int) $db->lastInsertId();

        $sortOrder = 0;
        foreach ($definition['documents'] as $documentCode) {
            $documentTypeId = $documentIdsByCode[$documentCode] ?? null;
            if (!$documentTypeId) {
                continue;
            }
            $sortOrder += 10;
            $insertSuggestion->execute([$purposeId, $documentTypeId, $sortOrder]);
        }
    }
}

function normalizePurposeCode(string $code): string {
    $code = strtolower(trim($code));
    $code = preg_replace('/[^a-z0-9_]+/', '_', $code);
    $code = preg_replace('/_+/', '_', $code);

    return trim($code, '_');
}

function getActiveRequestPurposeCodes(): array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $stmt = $db->query('SELECT code FROM request_purposes WHERE is_active = 1 ORDER BY sort_order, label');

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

function getRequestPurposeHint(string $code): string {
    ensureRequestPurposesSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT hint FROM request_purposes WHERE code = ? LIMIT 1');
    $stmt->execute([$code]);
    $hint = $stmt->fetchColumn();

    return $hint !== false ? (string) $hint : '';
}

function isValidActiveRequestPurposeCode(string $code): bool {
    ensureRequestPurposesSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM request_purposes WHERE code = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$code]);

    return (bool) $stmt->fetchColumn();
}

function getPurposeSuggestedDocumentCodesMap(): array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $purposes = $db->query('SELECT id, code FROM request_purposes ORDER BY sort_order, label')->fetchAll();
    $map = [];

    $stmt = $db->prepare('SELECT dt.code
        FROM request_purpose_document_suggestions s
        INNER JOIN document_types dt ON dt.id = s.document_type_id
        WHERE s.purpose_id = ?
        ORDER BY s.sort_order, dt.name');

    foreach ($purposes as $purpose) {
        $stmt->execute([(int) $purpose['id']]);
        $map[$purpose['code']] = array_column($stmt->fetchAll(), 'code');
    }

    return $map;
}

function getSuggestedDocumentTypeIdsForPurposeCode(string $purposeCode): array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT s.document_type_id
        FROM request_purpose_document_suggestions s
        INNER JOIN request_purposes p ON p.id = s.purpose_id
        WHERE p.code = ?
        ORDER BY s.sort_order');
    $stmt->execute([$purposeCode]);

    return array_map('intval', array_column($stmt->fetchAll(), 'document_type_id'));
}

function getSuggestedDocumentTypeIdsForPurposeId(int $purposeId): array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT document_type_id FROM request_purpose_document_suggestions WHERE purpose_id = ? ORDER BY sort_order');
    $stmt->execute([$purposeId]);

    return array_map('intval', array_column($stmt->fetchAll(), 'document_type_id'));
}

function getSuggestedDocumentNamesForPurposeId(int $purposeId): array {
    ensureRequestPurposesSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT dt.name
        FROM request_purpose_document_suggestions s
        INNER JOIN document_types dt ON dt.id = s.document_type_id
        WHERE s.purpose_id = ?
        ORDER BY s.sort_order, dt.name');
    $stmt->execute([$purposeId]);

    return array_column($stmt->fetchAll(), 'name');
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
        $purpose['suggested_document_type_ids'] = getSuggestedDocumentTypeIdsForPurposeId($purposeId);
        $purpose['suggested_document_names'] = getSuggestedDocumentNamesForPurposeId($purposeId);
        $purpose['request_count'] = countRequestsByPurposeCode($purpose['code']);
    }
    unset($purpose);

    return $purposes;
}

function saveRequestPurposeDocumentSuggestions(int $purposeId, array $documentTypeIds): void {
    ensureRequestPurposesSchema();
    $db = getDB();

    $validIds = [];
    foreach (array_unique(array_filter(array_map('intval', $documentTypeIds))) as $documentTypeId) {
        if ($documentTypeId > 0) {
            $validIds[] = $documentTypeId;
        }
    }

    $db->beginTransaction();
    try {
        $db->prepare('DELETE FROM request_purpose_document_suggestions WHERE purpose_id = ?')->execute([$purposeId]);
        $insert = $db->prepare('INSERT INTO request_purpose_document_suggestions (purpose_id, document_type_id, sort_order) VALUES (?, ?, ?)');

        $sortOrder = 0;
        foreach ($validIds as $documentTypeId) {
            $sortOrder += 10;
            $insert->execute([$purposeId, $documentTypeId, $sortOrder]);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
