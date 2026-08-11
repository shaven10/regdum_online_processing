<?php

const ACCOUNTING_SOA_CODE = 'SOA';

function ensureAccountingRole(): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM roles WHERE name = 'accounting'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $db->exec("INSERT INTO roles (name, description) VALUES ('accounting', 'Accounting Office — SOA document assignment only')");
    }
}

function ensureDefaultAccountingUser(): void {
    $db = getDB();
    ensureAccountingRole();

    $stmt = $db->prepare("SELECT id FROM users WHERE email = 'accounting@regdum.edu.ph'");
    $stmt->execute();
    if ($stmt->fetch()) {
        return;
    }

    $roleId = $db->query("SELECT id FROM roles WHERE name = 'accounting'")->fetchColumn();
    if (!$roleId) {
        return;
    }

    $hash = password_hash('Accounting@123', PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (?, ?, ?, ?, ?, 1, 1)')
       ->execute([$roleId, 'accounting@regdum.edu.ph', $hash, 'SOA', 'Accounting']);
}

function ensureAccountingModule(): void {
    ensureAccountingRole();
    ensureDefaultAccountingUser();

    require_once __DIR__ . '/assignment-offices.php';
    ensureDocumentAssignmentOfficeSchema();

    // Preferred office for Statement of Account is Accounting.
    $db = getDB();
    $db->prepare("UPDATE document_types SET assignment_office = 'accounting' WHERE UPPER(code) = 'SOA'")
       ->execute();
}

/**
 * Keep only SOA document assignments for Accounting processors.
 *
 * @param list<array<string,mixed>> $items
 * @return list<array<string,mixed>>
 */
function filterAccountingSoaAssignments(array $items): array {
    return array_values(array_filter($items, static function (array $item): bool {
        $code = strtoupper(trim((string) ($item['document_code'] ?? $item['code'] ?? '')));
        return $code === ACCOUNTING_SOA_CODE;
    }));
}

function isSoaDocumentAssignment(array $item): bool {
    $code = strtoupper(trim((string) ($item['document_code'] ?? $item['code'] ?? '')));
    return $code === ACCOUNTING_SOA_CODE;
}
