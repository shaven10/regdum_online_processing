<?php

function ensureDocumentEnrollmentRulesSchema(): void {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS document_type_enrollment_rules (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        document_type_id TINYINT UNSIGNED NOT NULL,
        enrollment_status ENUM('enrolled','graduated','inactive') NOT NULL,
        is_allowed TINYINT(1) NOT NULL DEFAULT 1,
        max_copies TINYINT UNSIGNED NOT NULL DEFAULT 10,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_doc_enrollment (document_type_id, enrollment_status),
        FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
    )");

    seedDocumentEnrollmentRules();
}

function ensureDocumentTypeFeeSchema(): void {
    $db = getDB();
    $exists = $db->query("SHOW COLUMNS FROM document_types LIKE 'requires_documentary_stamp'")->fetch();
    if (!$exists) {
        $db->exec('ALTER TABLE document_types ADD COLUMN requires_documentary_stamp TINYINT(1) NOT NULL DEFAULT 0');
    }

    $feePerSet = $db->query("SHOW COLUMNS FROM document_types LIKE 'fee_per_set'")->fetch();
    if (!$feePerSet) {
        $db->exec('ALTER TABLE document_types ADD COLUMN fee_per_set TINYINT(1) NOT NULL DEFAULT 0');
    }

    $db->exec("UPDATE document_types SET fee_per_set = 1 WHERE code = 'CTC'");
}

function documentTypeUsesFeePerSet(array $documentType): bool {
    return !empty($documentType['fee_per_set']);
}

function documentTypeFeeUnit(array $documentType): string {
    return documentTypeUsesFeePerSet($documentType) ? 'set' : 'copy';
}

function formatDocumentTypeUnitFee(array $documentType): string {
    return formatMoney((float) ($documentType['base_fee'] ?? 0)) . '/' . documentTypeFeeUnit($documentType);
}

function documentTypeFeeMetaText(array $documentType): string {
    if (documentTypeRequiresAuthDocumentType($documentType)) {
        return 'Fee per set × sets for each document to authenticate';
    }
    if (documentTypeUsesFeePerSet($documentType)) {
        return 'Flat fee per set';
    }

    return 'Fee per copy × copies requested';
}

function documentTypeQuantityLabel(array $documentType): string {
    return documentTypeUsesFeePerSet($documentType) ? 'Sets' : 'Copies';
}

function ensureRequestTermInfoSchema(): void {
    $db = getDB();

    $termFlag = $db->query("SHOW COLUMNS FROM document_types LIKE 'requires_term_info'")->fetch();
    if (!$termFlag) {
        $db->exec('ALTER TABLE document_types ADD COLUMN requires_term_info TINYINT(1) NOT NULL DEFAULT 0');
    }

    $schoolYearCol = $db->query("SHOW COLUMNS FROM requests LIKE 'request_school_year'")->fetch();
    if (!$schoolYearCol) {
        $db->exec('ALTER TABLE requests ADD COLUMN request_school_year VARCHAR(20) NULL AFTER purpose_other');
        $db->exec('ALTER TABLE requests ADD COLUMN request_semester VARCHAR(30) NULL AFTER request_school_year');
    }

    seedTermInfoDocumentTypes();
    ensureStatementOfAccountSchema();
}

function ensureStatementOfAccountSchema(): void {
    $db = getDB();

    $soaFlag = $db->query("SHOW COLUMNS FROM document_types LIKE 'requires_soa_info'")->fetch();
    if (!$soaFlag) {
        $db->exec('ALTER TABLE document_types ADD COLUMN requires_soa_info TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_term_info');
    }

    $scopeCol = $db->query("SHOW COLUMNS FROM requests LIKE 'request_soa_assessment_scope'")->fetch();
    if (!$scopeCol) {
        $db->exec('ALTER TABLE requests ADD COLUMN request_soa_assessment_scope VARCHAR(30) NULL AFTER request_semester');
        $db->exec('ALTER TABLE requests ADD COLUMN request_soa_remarks VARCHAR(255) NULL AFTER request_soa_assessment_scope');
    }

    seedStatementOfAccountDocumentType();
}

function seedStatementOfAccountDocumentType(): void {
    $db = getDB();
    $db->exec("UPDATE document_types SET requires_term_info = 1, requires_soa_info = 1 WHERE code = 'SOA'");

    $exists = $db->prepare('SELECT id FROM document_types WHERE code = ?');
    $exists->execute(['SOA']);
    if (!$exists->fetch()) {
        $db->exec("INSERT INTO document_types (name, code, description, base_fee, per_copy_fee, processing_days, requires_upload, requires_term_info, requires_soa_info, is_active)
            VALUES ('Statement of Account', 'SOA', 'Official statement of account for a specific school year and semester', 75.00, 25.00, 3, 0, 1, 1, 1)");
        seedDocumentEnrollmentRulesForType((int) $db->lastInsertId(), 'SOA');
    }
}

function documentTypeRequiresSoaInfo(array $documentType): bool {
    return !empty($documentType['requires_soa_info']);
}

function soaAssessmentScopeOptions(): array {
    return [
        'full_account'        => 'Full Statement of Account',
        'tuition_fees'        => 'Tuition Fees Only',
        'miscellaneous_fees'  => 'Miscellaneous Fees Only',
        'outstanding_balance' => 'Outstanding Balance Summary',
    ];
}

function soaAssessmentScopeLabel(?string $scope): string {
    return soaAssessmentScopeOptions()[$scope ?? ''] ?? '—';
}

function validateRequestSoaFields(?string $assessmentScope, ?string $remarks = null): ?string {
    // Statement type and additional notes were removed from SOA data entry.
    return null;
}

function requestHasSoaInfo(?array $request): bool {
    return false;
}

function renderRequestSoaInfoHtml(?array $request): string {
    return '';
}

function seedTermInfoDocumentTypes(): void {
    $db = getDB();
    $db->exec("UPDATE document_types SET requires_term_info = 1
        WHERE code IN ('COE', 'COGR', 'COR')
           OR LOWER(name) LIKE '%certificate of registration%'");

    $exists = $db->prepare('SELECT id FROM document_types WHERE code = ?');
    $exists->execute(['COGR']);
    if (!$exists->fetch()) {
        $db->exec("INSERT INTO document_types (name, code, description, base_fee, per_copy_fee, processing_days, requires_upload, requires_term_info)
            VALUES ('Certificate of Grades', 'COGR', 'Official certificate of grades for a specific school year and semester', 75.00, 25.00, 3, 0, 1)");
        seedDocumentEnrollmentRulesForType((int) $db->lastInsertId(), 'COGR');
    }

    seedCertificateOfRegistrationDocumentType();
}

function seedCertificateOfRegistrationDocumentType(): void {
    $db = getDB();

    $rows = $db->query("SELECT id, code FROM document_types
        WHERE code = 'COR' OR LOWER(name) LIKE '%certificate of registration%'")->fetchAll();

    if (!$rows) {
        $db->exec("INSERT INTO document_types (name, code, description, base_fee, per_copy_fee, processing_days, requires_upload, requires_term_info)
            VALUES ('Certificate of Registration', 'COR', 'Official certificate of registration for a specific school year and semester', 50.00, 25.00, 2, 0, 1)");
        $newId = (int) $db->lastInsertId();
        seedDocumentEnrollmentRulesForType($newId, 'COR');
        if (function_exists('seedDocumentTypeRequirementDefaults')) {
            seedDocumentTypeRequirementDefaults($newId, 'COR');
        }
        $rows = [['id' => $newId, 'code' => 'COR']];
    }

    $db->exec("UPDATE document_types SET requires_term_info = 1
        WHERE code = 'COR' OR LOWER(name) LIKE '%certificate of registration%'");

    ensureDocumentEnrollmentRulesSchema();

    $preset = defaultRulePresetForDocumentCode('COR');
    $selectRule = $db->prepare('SELECT id FROM document_type_enrollment_rules WHERE document_type_id = ? AND enrollment_status = ?');
    $insertRule = $db->prepare('INSERT INTO document_type_enrollment_rules (document_type_id, enrollment_status, is_allowed, max_copies) VALUES (?, ?, ?, ?)');

    foreach ($rows as $row) {
        foreach (enrollmentStatusesForDocumentRules() as $status) {
            $selectRule->execute([(int) $row['id'], $status]);
            if ($selectRule->fetch()) {
                continue;
            }
            $rule = $preset[$status] ?? ['is_allowed' => 1, 'max_copies' => 5];
            $insertRule->execute([
                (int) $row['id'],
                $status,
                (int) $rule['is_allowed'],
                max(1, min(99, (int) $rule['max_copies'])),
            ]);
        }
    }

    $db->exec("UPDATE document_type_enrollment_rules r
        INNER JOIN document_types dt ON dt.id = r.document_type_id
        SET r.is_allowed = 1,
            r.max_copies = GREATEST(r.max_copies, 5)
        WHERE (dt.code = 'COR' OR LOWER(dt.name) LIKE '%certificate of registration%')
          AND r.enrollment_status IN ('enrolled', 'graduated')");
}

function documentTypeRequiresTermInfo(array $documentType): bool {
    return !empty($documentType['requires_term_info']);
}

function validateRequestTermFields(?string $schoolYear, ?string $semester): ?string {
    $schoolYear = trim((string) $schoolYear);
    $semester = trim((string) $semester);

    if ($schoolYear === '') {
        return 'School year is required for this document.';
    }
    if (!array_key_exists($schoolYear, schoolYearOptions())) {
        return 'Please select a valid school year.';
    }
    if ($semester === '') {
        return 'Semester is required for this document.';
    }
    if (!array_key_exists($semester, semesterOptions())) {
        return 'Please select a valid semester.';
    }

    return null;
}

function requestHasTermInfo(?array $request): bool {
    return !empty($request['request_school_year']) || !empty($request['request_semester']);
}

function renderRequestTermInfoHtml(?array $request): string {
    if (!requestHasTermInfo($request)) {
        return '';
    }

    return '<div class="detail-item"><label>School Year</label><span>' . e($request['request_school_year']) . '</span></div>'
        . '<div class="detail-item"><label>Semester</label><span>' . e(semesterLabel($request['request_semester'] ?? null)) . '</span></div>';
}

function ensureRequestAuthenticationTypeSchema(): void {
    $db = getDB();

    $authFlag = $db->query("SHOW COLUMNS FROM document_types LIKE 'requires_auth_document_type'")->fetch();
    if (!$authFlag) {
        $db->exec('ALTER TABLE document_types ADD COLUMN requires_auth_document_type TINYINT(1) NOT NULL DEFAULT 0');
    }

    $authTypeCol = $db->query("SHOW COLUMNS FROM requests LIKE 'authentication_document_type'")->fetch();
    if (!$authTypeCol) {
        $db->exec('ALTER TABLE requests ADD COLUMN authentication_document_type VARCHAR(50) NULL AFTER request_semester');
    }

    $db->exec("UPDATE document_types SET requires_auth_document_type = 1 WHERE code = 'CTC'");

    $db->exec("CREATE TABLE IF NOT EXISTS request_authentication_items (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        auth_document_type VARCHAR(50) NOT NULL,
        sets TINYINT UNSIGNED NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        UNIQUE KEY uk_request_auth_doc (request_id, auth_document_type)
    )");

    ensureAuthenticationDocumentTypesSchema();
    migrateLegacyAuthenticationDocumentTypes();
}

function ensureAuthenticationDocumentTypesSchema(): void {
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS authentication_document_types (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        label VARCHAR(150) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Align collations so comparisons with request_authentication_items do not fatal.
    try {
        $db->exec("ALTER TABLE authentication_document_types
            CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // Ignore if the table cannot be altered in this environment.
    }

    seedDefaultAuthenticationDocumentTypes();
}

function defaultAuthenticationDocumentTypes(): array {
    return [
        ['code' => 'gwa', 'label' => 'GWA', 'sort_order' => 10],
        ['code' => 'tor_employment', 'label' => 'TOR Employment', 'sort_order' => 20],
        ['code' => 'diploma', 'label' => 'Diploma', 'sort_order' => 30],
        ['code' => 'gm', 'label' => 'GM', 'sort_order' => 40],
        ['code' => 'cav', 'label' => 'CAV', 'sort_order' => 50],
    ];
}

function seedDefaultAuthenticationDocumentTypes(): void {
    $db = getDB();
    $count = (int) $db->query('SELECT COUNT(*) FROM authentication_document_types')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $insert = $db->prepare(
        'INSERT INTO authentication_document_types (code, label, sort_order, is_active) VALUES (?, ?, ?, 1)'
    );
    foreach (defaultAuthenticationDocumentTypes() as $row) {
        $insert->execute([$row['code'], $row['label'], $row['sort_order']]);
    }
}

function normalizeAuthenticationDocumentTypeCode(string $code): string {
    $code = strtolower(trim($code));
    $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
    $code = trim($code, '_');
    return substr($code, 0, 50);
}

/**
 * @return list<array<string,mixed>>
 */
function getAllAuthenticationDocumentTypes(bool $activeOnly = false): array {
    ensureAuthenticationDocumentTypesSchema();
    $db = getDB();
    $sql = 'SELECT adt.*,
            (SELECT COUNT(*) FROM request_authentication_items rai
                WHERE rai.auth_document_type COLLATE utf8mb4_unicode_ci = adt.code COLLATE utf8mb4_unicode_ci
            ) AS usage_count
        FROM authentication_document_types adt';
    if ($activeOnly) {
        $sql .= ' WHERE adt.is_active = 1';
    }
    $sql .= ' ORDER BY adt.sort_order ASC, adt.label ASC, adt.id ASC';

    return $db->query($sql)->fetchAll();
}

function getAuthenticationDocumentTypeById(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    ensureAuthenticationDocumentTypesSchema();
    $stmt = getDB()->prepare('SELECT * FROM authentication_document_types WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function authenticationDocumentTypeUsageCount(string $code): int {
    $code = trim($code);
    if ($code === '') {
        return 0;
    }
    $stmt = getDB()->prepare(
        'SELECT COUNT(*) FROM request_authentication_items
         WHERE auth_document_type COLLATE utf8mb4_unicode_ci = ? COLLATE utf8mb4_unicode_ci'
    );
    $stmt->execute([$code]);
    return (int) $stmt->fetchColumn();
}

/**
 * @return array{code:string,label:string,sort_order:int,is_active:int}
 */
function normalizeAuthenticationDocumentTypeInput(array $input): array {
    $label = trim((string) ($input['label'] ?? ''));
    $code = normalizeAuthenticationDocumentTypeCode((string) ($input['code'] ?? ''));
    if ($code === '' && $label !== '') {
        $code = normalizeAuthenticationDocumentTypeCode($label);
    }

    return [
        'code' => $code,
        'label' => $label,
        'sort_order' => max(0, (int) ($input['sort_order'] ?? 0)),
        'is_active' => !empty($input['is_active']) ? 1 : 0,
    ];
}

/**
 * @return list<string>
 */
function validateAuthenticationDocumentTypeInput(array $data, bool $requireCode = true): array {
    $errors = [];
    if ($data['label'] === '') {
        $errors[] = 'Document name is required.';
    }
    if ($requireCode && $data['code'] === '') {
        $errors[] = 'Document code is required.';
    } elseif ($data['code'] !== '' && !preg_match('/^[a-z][a-z0-9_]{0,49}$/', $data['code'])) {
        $errors[] = 'Code must start with a letter and use lowercase letters, numbers, or underscores only.';
    }

    return $errors;
}

function createAuthenticationDocumentType(array $input): int {
    $data = normalizeAuthenticationDocumentTypeInput($input);
    $errors = validateAuthenticationDocumentTypeInput($data, true);
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', $errors));
    }

    $db = getDB();
    $db->prepare(
        'INSERT INTO authentication_document_types (code, label, sort_order, is_active) VALUES (?, ?, ?, ?)'
    )->execute([$data['code'], $data['label'], $data['sort_order'], $data['is_active']]);

    return (int) $db->lastInsertId();
}

function updateAuthenticationDocumentType(int $id, array $input): void {
    $existing = getAuthenticationDocumentTypeById($id);
    if (!$existing) {
        throw new InvalidArgumentException('Document not found.');
    }

    $data = normalizeAuthenticationDocumentTypeInput($input);
    // Keep code stable once created so existing request rows stay valid.
    $data['code'] = (string) $existing['code'];
    $errors = validateAuthenticationDocumentTypeInput($data, false);
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', $errors));
    }

    getDB()->prepare(
        'UPDATE authentication_document_types SET label = ?, sort_order = ?, is_active = ? WHERE id = ?'
    )->execute([$data['label'], $data['sort_order'], $data['is_active'], $id]);
}

/**
 * @return array{deleted:bool,deactivated:bool}
 */
function deleteAuthenticationDocumentType(int $id): array {
    $existing = getAuthenticationDocumentTypeById($id);
    if (!$existing) {
        throw new InvalidArgumentException('Document not found.');
    }

    $usage = authenticationDocumentTypeUsageCount((string) $existing['code']);
    if ($usage > 0) {
        getDB()->prepare('UPDATE authentication_document_types SET is_active = 0 WHERE id = ?')->execute([$id]);
        return ['deleted' => false, 'deactivated' => true];
    }

    getDB()->prepare('DELETE FROM authentication_document_types WHERE id = ?')->execute([$id]);
    return ['deleted' => true, 'deactivated' => false];
}

function toggleAuthenticationDocumentType(int $id): void {
    if ($id <= 0) {
        throw new InvalidArgumentException('Document not found.');
    }
    getDB()->prepare('UPDATE authentication_document_types SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
}

function migrateLegacyAuthenticationDocumentTypes(): void {
    $db = getDB();
    $legacy = $db->query("SELECT id, authentication_document_type FROM requests
        WHERE authentication_document_type IS NOT NULL AND authentication_document_type != ''")->fetchAll();
    $exists = $db->prepare('SELECT id FROM request_authentication_items WHERE request_id = ? LIMIT 1');
    $insert = $db->prepare('INSERT INTO request_authentication_items (request_id, auth_document_type, sets) VALUES (?, ?, 1)');

    foreach ($legacy as $row) {
        $exists->execute([(int) $row['id']]);
        if ($exists->fetch()) {
            continue;
        }
        $insert->execute([(int) $row['id'], $row['authentication_document_type']]);
    }
}

/**
 * Active documents available for Authentication / CTC selection.
 *
 * @return array<string,string> code => label
 */
function authenticationDocumentTypeOptions(bool $activeOnly = true): array {
    ensureAuthenticationDocumentTypesSchema();

    $options = [];
    foreach (getAllAuthenticationDocumentTypes($activeOnly) as $row) {
        $code = trim((string) ($row['code'] ?? ''));
        $label = trim((string) ($row['label'] ?? ''));
        if ($code === '' || $label === '') {
            continue;
        }
        $options[$code] = $label;
    }

    return $options;
}

function authenticationDocumentTypeLabel(?string $type): string {
    $type = trim((string) $type);
    if ($type === '') {
        return '—';
    }

    $active = authenticationDocumentTypeOptions(true);
    if (isset($active[$type])) {
        return $active[$type];
    }

    $all = authenticationDocumentTypeOptions(false);
    if (isset($all[$type])) {
        return $all[$type];
    }

    return ucwords(str_replace('_', ' ', $type));
}

/**
 * Find or create an authentication document type from a free-text label.
 */
function ensureAuthenticationDocumentTypeFromLabel(string $label): ?string {
    $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
    if ($label === '') {
        return null;
    }
    if (mb_strlen($label) > 150) {
        $label = mb_substr($label, 0, 150);
    }

    ensureAuthenticationDocumentTypesSchema();
    $db = getDB();

    $stmt = $db->prepare(
        'SELECT code FROM authentication_document_types WHERE LOWER(label) = LOWER(?) LIMIT 1'
    );
    $stmt->execute([$label]);
    $byLabel = $stmt->fetchColumn();
    if ($byLabel) {
        $db->prepare('UPDATE authentication_document_types SET is_active = 1 WHERE code = ?')
           ->execute([(string) $byLabel]);
        return (string) $byLabel;
    }

    $baseCode = normalizeAuthenticationDocumentTypeCode($label);
    if ($baseCode === '' || !preg_match('/^[a-z]/', $baseCode)) {
        $baseCode = 'doc_' . substr(preg_replace('/[^a-z0-9]/', '', $baseCode) ?: 'custom', 0, 40);
    }
    $baseCode = substr($baseCode, 0, 45);
    $code = $baseCode;
    $suffix = 2;
    $exists = $db->prepare('SELECT id FROM authentication_document_types WHERE code = ? LIMIT 1');
    while (true) {
        $exists->execute([$code]);
        if (!$exists->fetch()) {
            break;
        }
        $code = substr($baseCode, 0, 45) . '_' . $suffix;
        $suffix++;
        if ($suffix > 99) {
            $code = 'doc_' . substr(sha1($label), 0, 12);
            break;
        }
    }

    createAuthenticationDocumentType([
        'code' => $code,
        'label' => $label,
        'sort_order' => 900 + $suffix,
        'is_active' => 1,
    ]);

    return $code;
}

/**
 * @param array<string,mixed> $postedCatalog checkbox/sets map keyed by auth code
 * @param list<mixed> $postedCustom custom rows with label + sets
 * @return array{catalog:list<array{type:string,sets:int}>,custom:list<array{label:string,sets:int}>}
 */
function collectPostedAuthenticationBundle(array $postedCatalog, array $postedCustom = []): array {
    $catalog = normalizeAuthenticationItems($postedCatalog);
    $custom = [];

    foreach ($postedCustom as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = trim(preg_replace('/\s+/', ' ', (string) ($row['label'] ?? '')) ?? '');
        $sets = max(0, (int) ($row['sets'] ?? 0));
        if ($label === '' || $sets < 1) {
            continue;
        }
        $custom[] = [
            'label' => mb_substr($label, 0, 150),
            'sets' => min(99, $sets),
        ];
    }

    return [
        'catalog' => $catalog,
        'custom' => $custom,
    ];
}

/**
 * @param array{catalog?:list<array{type:string,sets:int}>,custom?:list<array{label:string,sets:int}>} $bundle
 */
function validateAuthenticationItemsBundle(array $bundle): ?string {
    $catalog = $bundle['catalog'] ?? [];
    $custom = $bundle['custom'] ?? [];
    if ($catalog === [] && $custom === []) {
        return 'Select at least one document to authenticate and indicate the number of sets.';
    }

    foreach ($catalog as $item) {
        if (($item['sets'] ?? 0) < 1 || ($item['sets'] ?? 0) > 99) {
            return 'Each authenticated document must have between 1 and 99 sets.';
        }
    }
    foreach ($custom as $item) {
        if (trim((string) ($item['label'] ?? '')) === '') {
            return 'Enter a document name for each added authentication document.';
        }
        if (($item['sets'] ?? 0) < 1 || ($item['sets'] ?? 0) > 99) {
            return 'Each authenticated document must have between 1 and 99 sets.';
        }
    }

    return null;
}

/**
 * Materialize catalog + custom rows into persisted auth item rows (creates new catalog entries as needed).
 *
 * @param array{catalog?:list<array{type:string,sets:int}>,custom?:list<array{label:string,sets:int}>} $bundle
 * @return list<array{type:string,sets:int}>
 */
function materializeAuthenticationItems(array $bundle): array {
    $byType = [];
    foreach ($bundle['catalog'] ?? [] as $item) {
        $type = trim((string) ($item['type'] ?? ''));
        $sets = max(1, min(99, (int) ($item['sets'] ?? 1)));
        if ($type === '') {
            continue;
        }
        $byType[$type] = min(99, ($byType[$type] ?? 0) + $sets);
    }

    foreach ($bundle['custom'] ?? [] as $item) {
        $code = ensureAuthenticationDocumentTypeFromLabel((string) ($item['label'] ?? ''));
        if ($code === null) {
            continue;
        }
        $sets = max(1, min(99, (int) ($item['sets'] ?? 1)));
        $byType[$code] = min(99, ($byType[$code] ?? 0) + $sets);
    }

    $items = [];
    foreach ($byType as $type => $sets) {
        $items[] = [
            'type' => (string) $type,
            'sets' => (int) $sets,
        ];
    }

    return $items;
}

function documentTypeRequiresAuthDocumentType(array $documentType): bool {
    return !empty($documentType['requires_auth_document_type']);
}

function validateAuthenticationDocumentType(?string $type): ?string {
    $type = trim((string) $type);
    if ($type === '') {
        return 'Please select the document type to be authenticated.';
    }
    if (!array_key_exists($type, authenticationDocumentTypeOptions())) {
        return 'Please select a valid document type for authentication.';
    }

    return null;
}

function normalizeAuthenticationItems(array $postedItems): array {
    $options = authenticationDocumentTypeOptions();
    $items = [];

    foreach ($postedItems as $type => $sets) {
        $type = trim((string) $type);
        $sets = max(0, (int) $sets);
        if ($sets < 1 || !array_key_exists($type, $options)) {
            continue;
        }
        $items[] = [
            'type' => $type,
            'sets' => min(99, $sets),
        ];
    }

    return $items;
}

function validateAuthenticationItems(array $items): ?string {
    if (empty($items)) {
        return 'Select at least one document to authenticate and indicate the number of sets.';
    }

    foreach ($items as $item) {
        if ($item['sets'] < 1 || $item['sets'] > 99) {
            return 'Each authenticated document must have between 1 and 99 sets.';
        }
    }

    return null;
}

function totalAuthenticationSets(array $items): int {
    $total = 0;
    foreach ($items as $item) {
        $total += max(0, (int) ($item['sets'] ?? 0));
    }

    return $total;
}

function saveRequestAuthenticationItems(int $requestId, array $items, ?int $requestItemId = null): void {
    $db = getDB();
    if ($requestItemId) {
        $db->prepare('DELETE FROM request_authentication_items WHERE request_item_id = ?')->execute([$requestItemId]);
    } else {
        $db->prepare('DELETE FROM request_authentication_items WHERE request_id = ? AND request_item_id IS NULL')->execute([$requestId]);
    }
    $insert = $db->prepare('INSERT INTO request_authentication_items (request_id, request_item_id, auth_document_type, sets) VALUES (?, ?, ?, ?)');

    foreach ($items as $item) {
        $insert->execute([$requestId, $requestItemId, $item['type'], max(1, (int) $item['sets'])]);
    }
}

function getRequestAuthenticationItems(int $requestId, ?int $requestItemId = null): array {
    $db = getDB();
    if ($requestItemId) {
        $stmt = $db->prepare('SELECT auth_document_type, sets FROM request_authentication_items WHERE request_item_id = ? ORDER BY id');
        $stmt->execute([$requestItemId]);
        return $stmt->fetchAll();
    }

    $stmt = $db->prepare('SELECT auth_document_type, sets FROM request_authentication_items WHERE request_id = ? ORDER BY id');
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
}

function requestHasAuthenticationItems(?array $request): bool {
    if (!$request || empty($request['id'])) {
        return requestHasAuthenticationDocumentType($request);
    }

    return !empty(getRequestAuthenticationItems((int) $request['id'])) || requestHasAuthenticationDocumentType($request);
}

function requestHasAuthenticationDocumentType(?array $request): bool {
    return !empty($request['authentication_document_type']);
}

function renderRequestAuthenticationDocumentTypeHtml(?array $request): string {
    if (!requestHasAuthenticationDocumentType($request)) {
        return '';
    }

    return '<div class="detail-item"><label>Document to Authenticate</label><span>'
        . e(authenticationDocumentTypeLabel($request['authentication_document_type'] ?? null))
        . '</span></div>';
}

function renderRequestAuthenticationItemsHtml(?array $request): string {
    if (!$request || empty($request['id'])) {
        return renderRequestAuthenticationDocumentTypeHtml($request);
    }

    $items = getRequestAuthenticationItems((int) $request['id']);
    if (empty($items)) {
        return renderRequestAuthenticationDocumentTypeHtml($request);
    }

    $rows = '';
    foreach ($items as $item) {
        $rows .= '<li>' . e(authenticationDocumentTypeLabel($item['auth_document_type']))
            . ' — ' . (int) $item['sets'] . ' set' . ((int) $item['sets'] === 1 ? '' : 's') . '</li>';
    }

    return '<div class="detail-item full"><label>Documents to Authenticate</label><ul class="auth-items-list">' . $rows . '</ul></div>';
}

function documentStampFeeAmount(): float {
    return defined('DOCUMENT_STAMP_FEE') ? (float) DOCUMENT_STAMP_FEE : 30.0;
}

function enrollmentStatusesForDocumentRules(): array {
    return array_keys(enrollmentStatusOptions());
}

function defaultRulePresetForDocumentCode(string $code): array {
    $code = strtoupper($code);

    if ($code === 'COE') {
        return [
            'enrolled'  => ['is_allowed' => 1, 'max_copies' => 5],
            'graduated' => ['is_allowed' => 0, 'max_copies' => 1],
            'inactive'  => ['is_allowed' => 0, 'max_copies' => 1],
        ];
    }

    if (in_array($code, ['COGR', 'COR'], true)) {
        return [
            'enrolled'  => ['is_allowed' => 1, 'max_copies' => 5],
            'graduated' => ['is_allowed' => 1, 'max_copies' => 5],
            'inactive'  => ['is_allowed' => 0, 'max_copies' => 1],
        ];
    }

    if ($code === 'SOA') {
        return [
            'enrolled'  => ['is_allowed' => 1, 'max_copies' => 3],
            'graduated' => ['is_allowed' => 0, 'max_copies' => 1],
            'inactive'  => ['is_allowed' => 0, 'max_copies' => 1],
        ];
    }

    if (in_array($code, ['COG', 'DIPLOMA'], true)) {
        return [
            'enrolled'  => ['is_allowed' => 0, 'max_copies' => 1],
            'graduated' => ['is_allowed' => 1, 'max_copies' => 3],
            'inactive'  => ['is_allowed' => 0, 'max_copies' => 1],
        ];
    }

    return [
        'enrolled'  => ['is_allowed' => 1, 'max_copies' => 10],
        'graduated' => ['is_allowed' => 1, 'max_copies' => 10],
        'inactive'  => ['is_allowed' => 1, 'max_copies' => 5],
    ];
}

function seedDocumentEnrollmentRules(): void {
    $db = getDB();
    $documents = $db->query('SELECT id, code FROM document_types')->fetchAll();
    $statuses = enrollmentStatusesForDocumentRules();

    $select = $db->prepare('SELECT id FROM document_type_enrollment_rules WHERE document_type_id = ? AND enrollment_status = ?');
    $insert = $db->prepare('INSERT INTO document_type_enrollment_rules (document_type_id, enrollment_status, is_allowed, max_copies) VALUES (?, ?, ?, ?)');

    foreach ($documents as $document) {
        $preset = defaultRulePresetForDocumentCode($document['code']);
        foreach ($statuses as $status) {
            $select->execute([(int) $document['id'], $status]);
            if ($select->fetch()) {
                continue;
            }
            $rule = $preset[$status] ?? ['is_allowed' => 1, 'max_copies' => 10];
            $insert->execute([
                (int) $document['id'],
                $status,
                (int) $rule['is_allowed'],
                max(1, min(99, (int) $rule['max_copies'])),
            ]);
        }
    }
}

function seedDocumentEnrollmentRulesForType(int $documentTypeId, ?string $code = null): void {
    ensureDocumentEnrollmentRulesSchema();
    $db = getDB();

    if ($code === null) {
        $stmt = $db->prepare('SELECT code FROM document_types WHERE id = ?');
        $stmt->execute([$documentTypeId]);
        $code = (string) ($stmt->fetchColumn() ?: '');
    }

    $preset = defaultRulePresetForDocumentCode($code);
    $insert = $db->prepare('INSERT INTO document_type_enrollment_rules (document_type_id, enrollment_status, is_allowed, max_copies)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed), max_copies = VALUES(max_copies)');

    foreach (enrollmentStatusesForDocumentRules() as $status) {
        $rule = $preset[$status] ?? ['is_allowed' => 1, 'max_copies' => 10];
        $insert->execute([
            $documentTypeId,
            $status,
            (int) $rule['is_allowed'],
            max(1, min(99, (int) $rule['max_copies'])),
        ]);
    }
}

function getDocumentEnrollmentRule(int $documentTypeId, string $enrollmentStatus): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM document_type_enrollment_rules WHERE document_type_id = ? AND enrollment_status = ?');
    $stmt->execute([$documentTypeId, $enrollmentStatus]);
    return $stmt->fetch() ?: null;
}

function isDocumentAllowedForEnrollment(int $documentTypeId, string $enrollmentStatus): bool {
    $rule = getDocumentEnrollmentRule($documentTypeId, $enrollmentStatus);
    return $rule ? (bool) $rule['is_allowed'] : false;
}

function getMaxCopiesForDocument(int $documentTypeId, string $enrollmentStatus): int {
    $rule = getDocumentEnrollmentRule($documentTypeId, $enrollmentStatus);
    if (!$rule || !(int) $rule['is_allowed']) {
        return 0;
    }
    return max(1, min(99, (int) $rule['max_copies']));
}

function getStudentEnrollmentStatus(int $userId): string {
    $profile = getStudentProfile($userId);
    $status = $profile['enrollment_status'] ?? 'enrolled';
    return array_key_exists($status, enrollmentStatusOptions()) ? $status : 'enrolled';
}

function getAvailableDocumentTypesForEnrollment(string $enrollmentStatus): array {
    ensureDocumentEnrollmentRulesSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT dt.*, r.max_copies
        FROM document_types dt
        INNER JOIN document_type_enrollment_rules r ON r.document_type_id = dt.id
        WHERE dt.is_active = 1 AND r.enrollment_status = ? AND r.is_allowed = 1
        ORDER BY dt.name');
    $stmt->execute([$enrollmentStatus]);
    return $stmt->fetchAll();
}

function getFrequentRequestedDocumentTypesForEnrollment(string $enrollmentStatus, int $limit = 6): array {
    ensureDocumentEnrollmentRulesSchema();
    require_once __DIR__ . '/request-items.php';
    ensureRequestItemsSchema();

    $limit = max(1, min(12, $limit));
    $available = getAvailableDocumentTypesForEnrollment($enrollmentStatus);
    if ($available === []) {
        return [];
    }

    $availableIds = array_map(static fn($doc) => (int) $doc['id'], $available);
    $placeholders = implode(',', array_fill(0, count($availableIds), '?'));

    $db = getDB();
    $sql = "SELECT dt.id, dt.name, dt.code, COUNT(ri.id) AS request_count
        FROM document_types dt
        INNER JOIN request_items ri ON ri.document_type_id = dt.id
        INNER JOIN requests r ON r.id = ri.request_id
        WHERE dt.id IN ($placeholders)
          AND dt.is_active = 1
        GROUP BY dt.id, dt.name, dt.code
        ORDER BY request_count DESC, dt.name ASC
        LIMIT {$limit}";
    $stmt = $db->prepare($sql);
    $stmt->execute($availableIds);
    $ranked = $stmt->fetchAll();

    if ($ranked !== []) {
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'code' => (string) ($row['code'] ?? ''),
                'request_count' => (int) $row['request_count'],
            ];
        }, $ranked);
    }

    // Fallback when there is little/no request history yet.
    return array_map(static function (array $doc): array {
        return [
            'id' => (int) $doc['id'],
            'name' => (string) $doc['name'],
            'code' => (string) ($doc['code'] ?? ''),
            'request_count' => 0,
        ];
    }, array_slice($available, 0, $limit));
}

function getPubliclyAvailableDocumentTypes(): array {
    ensureDocumentEnrollmentRulesSchema();
    $db = getDB();
    return $db->query('SELECT DISTINCT dt.*
        FROM document_types dt
        INNER JOIN document_type_enrollment_rules r ON r.document_type_id = dt.id
        WHERE dt.is_active = 1 AND r.is_allowed = 1
        ORDER BY dt.name')->fetchAll();
}

function renderLandingDocumentCard(array $documentType): string {
    $processingDays = max(1, (int) ($documentType['processing_days'] ?? 1));
    $html = '<div class="doc-item">';
    $html .= '<h4>' . e($documentType['name']) . '</h4>';

    if (!empty($documentType['description'])) {
        $html .= '<p class="doc-item-desc">' . e($documentType['description']) . '</p>';
    }

    $html .= '<div class="doc-item-meta">';
    $html .= '<span class="doc-fee">' . e(formatDocumentTypeUnitFee($documentType)) . '</span>';
    $html .= '<span class="doc-days">' . $processingDays . ' day' . ($processingDays === 1 ? '' : 's') . '</span>';
    $html .= '</div></div>';

    return $html;
}

function getDocumentReleaseRulesMatrix(): array {
    ensureDocumentEnrollmentRulesSchema();
    $db = getDB();
    $documents = $db->query('SELECT * FROM document_types ORDER BY name')->fetchAll();
    $rules = $db->query('SELECT * FROM document_type_enrollment_rules')->fetchAll();
    $indexed = [];

    foreach ($rules as $rule) {
        $indexed[(int) $rule['document_type_id']][$rule['enrollment_status']] = $rule;
    }

    $matrix = [];
    foreach ($documents as $document) {
        $docId = (int) $document['id'];
        $row = [
            'document' => $document,
            'rules'    => [],
        ];
        foreach (enrollmentStatusesForDocumentRules() as $status) {
            $row['rules'][$status] = $indexed[$docId][$status] ?? [
                'is_allowed' => 0,
                'max_copies' => 1,
            ];
        }
        $matrix[] = $row;
    }

    return $matrix;
}

function saveDocumentEnrollmentRulesFromPost(array $postedRules): void {
    ensureDocumentEnrollmentRulesSchema();
    $db = getDB();
    $documents = $db->query('SELECT id FROM document_types')->fetchAll();
    $statuses = enrollmentStatusesForDocumentRules();

    $update = $db->prepare('INSERT INTO document_type_enrollment_rules (document_type_id, enrollment_status, is_allowed, max_copies)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed), max_copies = VALUES(max_copies)');

    foreach ($documents as $document) {
        $docId = (int) $document['id'];
        foreach ($statuses as $status) {
            $rule = $postedRules[$docId][$status] ?? [];
            $isAllowed = !empty($rule['allowed']) ? 1 : 0;
            $maxCopies = max(1, min(99, (int) ($rule['max_copies'] ?? 1)));
            $update->execute([$docId, $status, $isAllowed, $maxCopies]);
        }
    }

    auditLog('update_document_release_rules', 'document_type_enrollment_rules');
}

function validateStudentDocumentRequest(int $documentTypeId, string $enrollmentStatus, int $copies): ?string {
    if (!isDocumentAllowedForEnrollment($documentTypeId, $enrollmentStatus)) {
        return 'This document is not available for your enrollment status.';
    }

    $maxCopies = getMaxCopiesForDocument($documentTypeId, $enrollmentStatus);
    if ($copies < 1 || $copies > $maxCopies) {
        return 'Copy count must be between 1 and ' . $maxCopies . '.';
    }

    return null;
}

function validateActiveDocumentTypeIdsForEnrollment(array $docTypeIds, string $enrollmentStatus): array {
    $valid = [];
    foreach (array_unique(array_filter(array_map('intval', $docTypeIds))) as $docTypeId) {
        if (isDocumentAllowedForEnrollment($docTypeId, $enrollmentStatus)) {
            $valid[] = $docTypeId;
        }
    }
    return $valid;
}

function purposeSuggestedDocumentCodes(?string $enrollmentStatus = null): array {
    return getPurposeSuggestedDocumentCodesMap($enrollmentStatus);
}

function purposeSuggestionHint(string $purpose, ?string $enrollmentStatus = null): string {
    return getRequestPurposeHint($purpose, $enrollmentStatus);
}

function getSuggestedDocumentIdsForPurpose(
    string $purpose,
    array $availableDocTypes,
    ?string $enrollmentStatus = null
): array {
    $suggestedIds = getSuggestedDocumentTypeIdsForPurposeCode($purpose, $enrollmentStatus);
    if (empty($suggestedIds)) {
        return [];
    }

    $availableIds = array_map('intval', array_column($availableDocTypes, 'id'));
    $availableLookup = array_fill_keys($availableIds, true);
    $ids = [];

    foreach ($suggestedIds as $documentTypeId) {
        if (isset($availableLookup[$documentTypeId])) {
            $ids[] = $documentTypeId;
        }
    }

    return $ids;
}
