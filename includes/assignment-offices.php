<?php

function assignmentOfficeOptions(): array {
    return [
        'registrar' => 'Registrar Office',
        'cashier' => 'Cashier',
        'guidance' => 'Guidance Office',
    ];
}

function normalizeAssignmentOffice(?string $office): string {
    $office = strtolower(trim((string) $office));
    return array_key_exists($office, assignmentOfficeOptions()) ? $office : 'registrar';
}

function assignmentOfficeLabel(?string $office): string {
    $office = normalizeAssignmentOffice($office);
    return assignmentOfficeOptions()[$office] ?? 'Registrar Office';
}

function ensureDocumentAssignmentOfficeSchema(): void {
    $db = getDB();
    $col = $db->query("SHOW COLUMNS FROM document_types LIKE 'assignment_office'")->fetch();
    if (!$col) {
        $db->exec("ALTER TABLE document_types
            ADD COLUMN assignment_office VARCHAR(30) NOT NULL DEFAULT 'registrar' AFTER requirements_required");

        $defaults = [
            'SOA' => 'cashier',
            'GMC' => 'guidance',
        ];
        $update = $db->prepare('UPDATE document_types SET assignment_office = ? WHERE code = ?');
        foreach ($defaults as $code => $office) {
            $update->execute([$office, $code]);
        }
    }
}

function defaultAssignmentOfficeForDocumentCode(?string $code): string {
    $code = strtoupper(trim((string) $code));
    return match ($code) {
        'SOA' => 'cashier',
        'GMC' => 'guidance',
        default => 'registrar',
    };
}

function getDocumentAssignmentOffice(?int $documentTypeId = null, ?string $documentCode = null): string {
    ensureDocumentAssignmentOfficeSchema();
    $db = getDB();

    if ($documentTypeId) {
        $stmt = $db->prepare('SELECT code, assignment_office FROM document_types WHERE id = ?');
        $stmt->execute([$documentTypeId]);
        $row = $stmt->fetch();
        if ($row) {
            return normalizeAssignmentOffice($row['assignment_office'] ?? defaultAssignmentOfficeForDocumentCode($row['code'] ?? ''));
        }
    }

    return defaultAssignmentOfficeForDocumentCode($documentCode);
}

/**
 * Users who can process assigned documents, grouped for assignment UI.
 *
 * @return list<array{id:int,first_name:string,last_name:string,email:string,office:string,office_label:string,role:string}>
 */
function getAssignableProcessors(): array {
    ensureDocumentAssignmentOfficeSchema();
    require_once __DIR__ . '/clearance.php';
    ensureClearanceSchema();

    $db = getDB();
    $processors = [];

    $registrars = $db->query("SELECT u.id, u.first_name, u.last_name, u.email, r.name as role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.name = 'registrar' AND u.is_active = 1
        ORDER BY u.last_name, u.first_name")->fetchAll();
    foreach ($registrars as $row) {
        $processors[] = [
            'id' => (int) $row['id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email'],
            'office' => 'registrar',
            'office_label' => 'Registrar',
            'role' => 'registrar',
        ];
    }

    $staff = $db->query("SELECT u.id, u.first_name, u.last_name, u.email, r.name as role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.name = 'staff' AND u.is_active = 1
        ORDER BY u.last_name, u.first_name")->fetchAll();
    foreach ($staff as $row) {
        $processors[] = [
            'id' => (int) $row['id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email'],
            'office' => 'registrar',
            'office_label' => 'Registrar Staff',
            'role' => 'staff',
        ];
    }

    $cashiers = $db->query("SELECT u.id, u.first_name, u.last_name, u.email, r.name as role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE r.name = 'cashier' AND u.is_active = 1
        ORDER BY u.last_name, u.first_name")->fetchAll();
    foreach ($cashiers as $row) {
        $processors[] = [
            'id' => (int) $row['id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email'],
            'office' => 'cashier',
            'office_label' => 'Cashier',
            'role' => 'cashier',
        ];
    }

    $guidance = $db->query("SELECT u.id, u.first_name, u.last_name, u.email, r.name as role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN clearance_departments cd ON cd.id = u.clearance_department_id
        WHERE r.name = 'clearance_officer' AND u.is_active = 1
          AND (cd.code = 'guidance' OR u.email LIKE 'guidance@%')
        ORDER BY u.last_name, u.first_name")->fetchAll();
    foreach ($guidance as $row) {
        $processors[] = [
            'id' => (int) $row['id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email'],
            'office' => 'guidance',
            'office_label' => 'Guidance Office',
            'role' => 'clearance_officer',
        ];
    }

    return $processors;
}

/** @return array<string, list<array<string,mixed>>> */
function groupAssignableProcessorsByOffice(array $processors): array {
    $groups = [];
    foreach (assignmentOfficeOptions() as $office => $label) {
        $groups[$office] = [
            'label' => $label,
            'users' => [],
        ];
    }

    foreach ($processors as $user) {
        $office = normalizeAssignmentOffice($user['office'] ?? 'registrar');
        if (!isset($groups[$office])) {
            $groups[$office] = [
                'label' => assignmentOfficeLabel($office),
                'users' => [],
            ];
        }
        $groups[$office]['users'][] = $user;
    }

    return array_filter($groups, static fn(array $group): bool => !empty($group['users']));
}

function assignmentProcessUrlForUser(int $userId, int $itemId): string {
    $db = getDB();
    $stmt = $db->prepare('SELECT r.name as role_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.id = ?');
    $stmt->execute([$userId]);
    $role = (string) ($stmt->fetchColumn() ?: 'staff');

    return match ($role) {
        'cashier' => APP_URL . '/cashier/process-document.php?item_id=' . $itemId,
        'clearance_officer' => APP_URL . '/clearance/process-document.php?item_id=' . $itemId,
        'registrar' => APP_URL . '/registrar/process-document.php?item_id=' . $itemId,
        default => APP_URL . '/staff/process-request.php?item_id=' . $itemId,
    };
}

function renderAssigneeSelectHtml(
    string $name,
    array $processors,
    ?string $preferredOffice = null,
    bool $required = true,
    string $id = ''
): string {
    $groups = groupAssignableProcessorsByOffice($processors);
    $preferredOffice = $preferredOffice ? normalizeAssignmentOffice($preferredOffice) : null;

    $html = '<select name="' . e($name) . '"'
        . ($id !== '' ? ' id="' . e($id) . '"' : '')
        . ($required ? ' required' : '')
        . ' class="assignee-select">';
    $html .= '<option value="">— Select assignee —</option>';

    foreach ($groups as $office => $group) {
        $html .= '<optgroup label="' . e($group['label']) . '">';
        foreach ($group['users'] as $user) {
            $selected = ($preferredOffice && $office === $preferredOffice && count($group['users']) === 1) ? ' selected' : '';
            $roleLabel = trim((string) ($user['office_label'] ?? $group['label']));
            $label = $user['first_name'] . ' ' . $user['last_name'] . ' (' . $roleLabel . ')';
            $html .= '<option value="' . (int) $user['id'] . '"' . $selected . '>' . e($label) . '</option>';
        }
        $html .= '</optgroup>';
    }

    $html .= '</select>';
    return $html;
}
