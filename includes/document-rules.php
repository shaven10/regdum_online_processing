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
    $db->exec("UPDATE document_types SET requires_term_info = 1 WHERE code IN ('COE', 'COGR')");

    $exists = $db->prepare('SELECT id FROM document_types WHERE code = ?');
    $exists->execute(['COGR']);
    if (!$exists->fetch()) {
        $db->exec("INSERT INTO document_types (name, code, description, base_fee, per_copy_fee, processing_days, requires_upload, requires_term_info)
            VALUES ('Certificate of Grades', 'COGR', 'Official certificate of grades for a specific school year and semester', 75.00, 25.00, 3, 0, 1)");
        seedDocumentEnrollmentRulesForType((int) $db->lastInsertId(), 'COGR');
    }
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

    migrateLegacyAuthenticationDocumentTypes();
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

function authenticationDocumentTypeOptions(): array {
    return [
        'gwa'            => 'GWA',
        'tor_employment' => 'TOR Employment',
        'diploma'        => 'Diploma',
        'gm'             => 'GM',
        'cav'            => 'CAV',
    ];
}

function authenticationDocumentTypeLabel(?string $type): string {
    return authenticationDocumentTypeOptions()[$type ?? ''] ?? '—';
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

    if ($code === 'COGR') {
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

function purposeSuggestedDocumentCodes(): array {
    return getPurposeSuggestedDocumentCodesMap();
}

function purposeSuggestionHint(string $purpose): string {
    return getRequestPurposeHint($purpose);
}

function getSuggestedDocumentIdsForPurpose(string $purpose, array $availableDocTypes): array {
    $suggestedIds = getSuggestedDocumentTypeIdsForPurposeCode($purpose);
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
