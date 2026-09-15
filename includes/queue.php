<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/compliance.php';

function ensureQueueSchema(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db = getDB();
    ensureRequirementDefaultsSchema();

    $db->exec("CREATE TABLE IF NOT EXISTS queue_windows (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        window_number SMALLINT UNSIGNED NOT NULL,
        name VARCHAR(80) NOT NULL,
        assigned_user_id INT UNSIGNED NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_queue_windows_number (window_number),
        KEY idx_queue_windows_user (assigned_user_id),
        KEY idx_queue_windows_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS queue_daily_counters (
        queue_date DATE NOT NULL PRIMARY KEY,
        last_number INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS queue_tickets (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        queue_date DATE NOT NULL,
        ticket_number INT UNSIGNED NOT NULL,
        ticket_code VARCHAR(20) NOT NULL,
        service_type VARCHAR(40) NOT NULL DEFAULT 'document_processing',
        status VARCHAR(20) NOT NULL DEFAULT 'waiting',
        window_id INT UNSIGNED NULL,
        request_id INT UNSIGNED NULL,
        requestor_name VARCHAR(160) NULL,
        student_id VARCHAR(50) NULL,
        request_number VARCHAR(40) NULL,
        notes VARCHAR(255) NULL,
        called_at DATETIME NULL,
        served_at DATETIME NULL,
        completed_at DATETIME NULL,
        called_by INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_queue_tickets_day_number (queue_date, ticket_number),
        KEY idx_queue_tickets_day_status (queue_date, status),
        KEY idx_queue_tickets_window (window_id, status),
        KEY idx_queue_tickets_code (ticket_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaults = queueDefaultSettings();
    foreach ($defaults as $key => $value) {
        if (getAppSetting($key, '') === '') {
            setAppSetting($key, $value);
        }
    }

    syncQueueWindows((int) getQueueSettings()['window_count']);
}

/**
 * @return array<string,string>
 */
function queueDefaultSettings(): array {
    return [
        'queue_enabled' => '1',
        'queue_window_count' => '3',
        'queue_prefix' => '',
        'queue_pad' => '3',
        'queue_require_name' => '0',
        'queue_sound_enabled' => '1',
    ];
}

/**
 * @return array{
 *   enabled:bool,
 *   window_count:int,
 *   prefix:string,
 *   pad:int,
 *   require_name:bool,
 *   sound_enabled:bool
 * }
 */
function getQueueSettings(): array {
    ensureQueueSchema();
    $pad = max(2, min(4, (int) getAppSetting('queue_pad', '3')));
    $count = max(1, min(12, (int) getAppSetting('queue_window_count', '3')));

    return [
        'enabled' => getAppSetting('queue_enabled', '1') === '1',
        'window_count' => $count,
        'prefix' => strtoupper(trim(getAppSetting('queue_prefix', ''))),
        'pad' => $pad,
        'require_name' => getAppSetting('queue_require_name', '0') === '1',
        'sound_enabled' => getAppSetting('queue_sound_enabled', '1') === '1',
    ];
}

function isQueueEnabled(): bool {
    return getQueueSettings()['enabled'];
}

function queueToday(): string {
    return date('Y-m-d');
}

/**
 * @return array<string,string>
 */
function queueServiceTypes(): array {
    return [
        'document_processing' => 'Document Processing',
        'new_request' => 'New Document Request',
        'claim' => 'Claim / Pickup',
        'follow_up' => 'Follow-up / Inquiry',
    ];
}

function queueServiceLabel(?string $type): string {
    $types = queueServiceTypes();
    $key = (string) $type;
    return $types[$key] ?? ($key !== '' ? ucwords(str_replace('_', ' ', $key)) : 'Document Processing');
}

function formatQueueTicketCode(int $number, ?string $prefix = null): string {
    $settings = getQueueSettings();
    $prefix = $prefix !== null ? strtoupper(trim($prefix)) : $settings['prefix'];
    $padded = str_pad((string) $number, $settings['pad'], '0', STR_PAD_LEFT);
    return $prefix !== '' ? $prefix . '-' . $padded : $padded;
}

function queueStatusLabel(?string $status): string {
    return match ((string) $status) {
        'waiting' => 'Waiting',
        'serving' => 'Now Serving',
        'completed' => 'Completed',
        'skipped' => 'Skipped',
        'cancelled' => 'Cancelled',
        default => ucwords(str_replace('_', ' ', (string) $status)),
    };
}

function queueStatusBadge(?string $status): string {
    $status = (string) $status;
    $class = match ($status) {
        'waiting' => 'badge-review',
        'serving' => 'badge-processing',
        'completed' => 'badge-completed',
        'skipped' => 'badge-submitted',
        'cancelled' => 'badge-rejected',
        default => 'badge-submitted',
    };
    return '<span class="badge ' . $class . '">' . e(queueStatusLabel($status)) . '</span>';
}

function syncQueueWindows(int $count): void {
    ensureQueueSchema();
    $count = max(1, min(12, $count));
    $db = getDB();

    $existing = $db->query('SELECT id, window_number FROM queue_windows')->fetchAll();
    $byNumber = [];
    foreach ($existing as $row) {
        $byNumber[(int) $row['window_number']] = (int) $row['id'];
    }

    $insert = $db->prepare('INSERT INTO queue_windows (window_number, name, is_active) VALUES (?, ?, 1)');
    $activate = $db->prepare('UPDATE queue_windows SET is_active = 1, updated_at = NOW() WHERE id = ?');
    for ($number = 1; $number <= $count; $number++) {
        if (!isset($byNumber[$number])) {
            $insert->execute([$number, 'Window ' . $number]);
        } else {
            $activate->execute([$byNumber[$number]]);
        }
    }

    $deactivate = $db->prepare('UPDATE queue_windows
        SET is_active = 0, assigned_user_id = NULL, updated_at = NOW()
        WHERE window_number > ?');
    $deactivate->execute([$count]);
}

/**
 * @return list<array<string,mixed>>
 */
function getQueueWindows(bool $activeOnly = true): array {
    ensureQueueSchema();
    $sql = "SELECT w.*,
                u.first_name as staff_first, u.last_name as staff_last, u.email as staff_email,
                r.name as staff_role
            FROM queue_windows w
            LEFT JOIN users u ON u.id = w.assigned_user_id
            LEFT JOIN roles r ON r.id = u.role_id";
    if ($activeOnly) {
        $sql .= ' WHERE w.is_active = 1';
    }
    $sql .= ' ORDER BY w.window_number ASC';

    $rows = getDB()->query($sql)->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['window_number'] = (int) $row['window_number'];
        $row['assigned_user_id'] = $row['assigned_user_id'] !== null ? (int) $row['assigned_user_id'] : null;
        $row['is_active'] = (int) $row['is_active'] === 1;
        $row['staff_name'] = trim(($row['staff_first'] ?? '') . ' ' . ($row['staff_last'] ?? ''));
        $row['staff_label'] = $row['staff_name'] !== ''
            ? $row['staff_name'] . (!empty($row['staff_role']) ? ' (' . ucfirst((string) $row['staff_role']) . ')' : '')
            : '';
    }
    unset($row);

    return $rows;
}

function getQueueWindow(int $windowId): ?array {
    foreach (getQueueWindows(false) as $window) {
        if ((int) $window['id'] === $windowId) {
            return $window;
        }
    }
    return null;
}

function getQueueWindowForUser(int $userId): ?array {
    if ($userId <= 0) {
        return null;
    }
    foreach (getQueueWindows(true) as $window) {
        if ((int) ($window['assigned_user_id'] ?? 0) === $userId) {
            return $window;
        }
    }
    return null;
}

/**
 * @return list<array{id:int,name:string,email:string,role:string,label:string}>
 */
function getQueueAssignableStaff(): array {
    $stmt = getDB()->query("SELECT u.id, u.first_name, u.last_name, u.email, r.name as role_name
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.is_active = 1 AND r.name IN ('registrar','staff')
        ORDER BY r.name ASC, u.last_name ASC, u.first_name ASC");

    $staff = [];
    foreach ($stmt->fetchAll() as $row) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $role = (string) ($row['role_name'] ?? '');
        $staff[] = [
            'id' => (int) $row['id'],
            'name' => $name,
            'email' => (string) ($row['email'] ?? ''),
            'role' => $role,
            'label' => $name . ' — ' . ($role === 'staff' ? 'Registrar Staff' : 'Registrar'),
        ];
    }

    return $staff;
}

/**
 * @param array<string,mixed> $input
 * @return list<string>
 */
function saveQueueSettingsFromPost(array $input): array {
    ensureQueueSchema();
    $errors = [];

    $enabled = !empty($input['queue_enabled']) ? '1' : '0';
    $count = (int) ($input['queue_window_count'] ?? 3);
    if ($count < 1 || $count > 12) {
        $errors[] = 'Number of windows must be between 1 and 12.';
        $count = max(1, min(12, $count));
    }

    $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($input['queue_prefix'] ?? '')) ?? '');
    if (strlen($prefix) > 4) {
        $errors[] = 'Ticket prefix can be up to 4 letters or numbers.';
        $prefix = substr($prefix, 0, 4);
    }

    $pad = (int) ($input['queue_pad'] ?? 3);
    if (!in_array($pad, [2, 3, 4], true)) {
        $pad = 3;
    }

    $requireName = !empty($input['queue_require_name']) ? '1' : '0';
    $sound = !empty($input['queue_sound_enabled']) ? '1' : '0';

    setAppSetting('queue_enabled', $enabled);
    setAppSetting('queue_window_count', (string) $count);
    setAppSetting('queue_prefix', $prefix);
    setAppSetting('queue_pad', (string) $pad);
    setAppSetting('queue_require_name', $requireName);
    setAppSetting('queue_sound_enabled', $sound);

    syncQueueWindows($count);

    $names = $input['window_name'] ?? [];
    $users = $input['window_user'] ?? [];
    if (!is_array($names)) {
        $names = [];
    }
    if (!is_array($users)) {
        $users = [];
    }

    $assignmentErrors = saveQueueWindowAssignments($names, $users);
    return array_merge($errors, $assignmentErrors);
}

/**
 * @param array<int|string,mixed> $names
 * @param array<int|string,mixed> $users
 * @return list<string>
 */
function saveQueueWindowAssignments(array $names, array $users): array {
    $errors = [];
    $db = getDB();
    $windows = getQueueWindows(true);
    $seenUsers = [];
    $validStaffIds = array_map(static fn(array $row): int => $row['id'], getQueueAssignableStaff());

    $update = $db->prepare('UPDATE queue_windows SET name = ?, assigned_user_id = ?, updated_at = NOW() WHERE id = ? AND is_active = 1');

    foreach ($windows as $window) {
        $id = (int) $window['id'];
        $name = trim((string) ($names[$id] ?? $window['name'] ?? ('Window ' . $window['window_number'])));
        if ($name === '') {
            $name = 'Window ' . $window['window_number'];
        }
        if (strlen($name) > 80) {
            $name = substr($name, 0, 80);
        }

        $userId = (int) ($users[$id] ?? 0);
        if ($userId > 0 && !in_array($userId, $validStaffIds, true)) {
            $errors[] = $name . ' must be assigned to an active registrar or registrar staff account.';
            $userId = 0;
        }
        if ($userId > 0 && isset($seenUsers[$userId])) {
            $errors[] = 'Each registrar staff account can be assigned to only one window.';
            $userId = 0;
        }
        if ($userId > 0) {
            $seenUsers[$userId] = $id;
        }

        $update->execute([$name, $userId > 0 ? $userId : null, $id]);
    }

    return $errors;
}

/**
 * @return array{id:int,request_number:string,status:string,first_name:string,last_name:string,student_id:?string}|null
 */
function lookupRequestForQueue(string $requestNumber = '', string $studentId = ''): ?array {
    $requestNumber = strtoupper(trim($requestNumber));
    $studentId = trim($studentId);
    $db = getDB();

    if ($requestNumber !== '') {
        $stmt = $db->prepare('SELECT r.id, r.request_number, r.status, u.first_name, u.last_name, u.student_id
            FROM requests r
            JOIN users u ON u.id = r.user_id
            WHERE r.request_number = ?
            LIMIT 1');
        $stmt->execute([$requestNumber]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }

    if ($studentId !== '') {
        $stmt = $db->prepare('SELECT r.id, r.request_number, r.status, u.first_name, u.last_name, u.student_id
            FROM requests r
            JOIN users u ON u.id = r.user_id
            WHERE u.student_id = ?
            ORDER BY r.created_at DESC
            LIMIT 1');
        $stmt->execute([$studentId]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
    }

    return null;
}

/**
 * @param array<string,mixed> $input
 * @return array{ok:bool,error?:string,ticket?:array}
 */
function issueQueueTicket(array $input): array {
    ensureQueueSchema();
    if (!isQueueEnabled()) {
        return ['ok' => false, 'error' => 'The queuing system is currently closed.'];
    }

    $service = (string) ($input['service_type'] ?? 'document_processing');
    if (!array_key_exists($service, queueServiceTypes())) {
        $service = 'document_processing';
    }

    $name = trim((string) ($input['requestor_name'] ?? ''));
    $studentId = trim((string) ($input['student_id'] ?? ''));
    $requestNumber = strtoupper(trim((string) ($input['request_number'] ?? '')));
    $settings = getQueueSettings();

    if ($settings['require_name'] && $name === '') {
        return ['ok' => false, 'error' => 'Please enter the requestor name.'];
    }

    $linked = lookupRequestForQueue($requestNumber, $studentId);
    if ($name === '' && $linked) {
        $name = trim(($linked['first_name'] ?? '') . ' ' . ($linked['last_name'] ?? ''));
    }
    if ($studentId === '' && $linked) {
        $studentId = (string) ($linked['student_id'] ?? '');
    }
    if ($requestNumber === '' && $linked) {
        $requestNumber = (string) ($linked['request_number'] ?? '');
    }

    $today = queueToday();
    $db = getDB();

    if ($studentId !== '') {
        $dup = $db->prepare("SELECT id, ticket_code FROM queue_tickets
            WHERE queue_date = ? AND student_id = ? AND status IN ('waiting','serving')
            LIMIT 1");
        $dup->execute([$today, $studentId]);
        $existing = $dup->fetch();
        if ($existing) {
            return [
                'ok' => false,
                'error' => 'This requestor already has priority number ' . $existing['ticket_code'] . ' in the queue today.',
                'ticket' => findQueueTicket((int) $existing['id']),
            ];
        }
    }

    try {
        $db->beginTransaction();
        $db->prepare('INSERT INTO queue_daily_counters (queue_date, last_number) VALUES (?, 0)
            ON DUPLICATE KEY UPDATE queue_date = queue_date')
            ->execute([$today]);

        $lock = $db->prepare('SELECT last_number FROM queue_daily_counters WHERE queue_date = ? FOR UPDATE');
        $lock->execute([$today]);
        $last = (int) $lock->fetchColumn();
        $next = $last + 1;
        $code = formatQueueTicketCode($next);

        $db->prepare('UPDATE queue_daily_counters SET last_number = ? WHERE queue_date = ?')
            ->execute([$next, $today]);

        $db->prepare('INSERT INTO queue_tickets
            (queue_date, ticket_number, ticket_code, service_type, status, request_id, requestor_name, student_id, request_number)
            VALUES (?, ?, ?, ?, \'waiting\', ?, ?, ?, ?)')
            ->execute([
                $today,
                $next,
                $code,
                $service,
                $linked ? (int) $linked['id'] : null,
                $name !== '' ? $name : null,
                $studentId !== '' ? $studentId : null,
                $requestNumber !== '' ? $requestNumber : null,
            ]);

        $ticketId = (int) $db->lastInsertId();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'Unable to issue a priority number. Please try again.'];
    }

    $ticket = findQueueTicket($ticketId);
    if (!$ticket) {
        return ['ok' => false, 'error' => 'Priority number was issued but could not be loaded.'];
    }

    return ['ok' => true, 'ticket' => $ticket];
}

function findQueueTicket(int $ticketId): ?array {
    if ($ticketId <= 0) {
        return null;
    }

    $stmt = getDB()->prepare("SELECT t.*,
            w.window_number, w.name as window_name,
            u.first_name as staff_first, u.last_name as staff_last
        FROM queue_tickets t
        LEFT JOIN queue_windows w ON w.id = t.window_id
        LEFT JOIN users u ON u.id = t.called_by
        WHERE t.id = ?
        LIMIT 1");
    $stmt->execute([$ticketId]);
    $row = $stmt->fetch();
    return $row ? decorateQueueTicket($row) : null;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function decorateQueueTicket(array $row): array {
    $row['id'] = (int) ($row['id'] ?? 0);
    $row['ticket_number'] = (int) ($row['ticket_number'] ?? 0);
    $row['window_id'] = $row['window_id'] !== null ? (int) $row['window_id'] : null;
    $row['request_id'] = $row['request_id'] !== null ? (int) $row['request_id'] : null;
    $row['service_label'] = queueServiceLabel($row['service_type'] ?? null);
    $row['status_label'] = queueStatusLabel($row['status'] ?? null);
    $row['staff_name'] = trim(($row['staff_first'] ?? '') . ' ' . ($row['staff_last'] ?? ''));
    $row['window_label'] = trim((string) ($row['window_name'] ?? ''));
    if ($row['window_label'] === '' && !empty($row['window_number'])) {
        $row['window_label'] = 'Window ' . (int) $row['window_number'];
    }
    return $row;
}

/**
 * @return list<array<string,mixed>>
 */
function getWaitingQueueTickets(int $limit = 30): array {
    ensureQueueSchema();
    $limit = max(1, min(100, $limit));
    $stmt = getDB()->prepare("SELECT t.*, w.window_number, w.name as window_name
        FROM queue_tickets t
        LEFT JOIN queue_windows w ON w.id = t.window_id
        WHERE t.queue_date = ? AND t.status = 'waiting'
        ORDER BY t.ticket_number ASC
        LIMIT {$limit}");
    $stmt->execute([queueToday()]);
    return array_map('decorateQueueTicket', $stmt->fetchAll());
}

function getServingQueueTicketForWindow(int $windowId): ?array {
    $stmt = getDB()->prepare("SELECT t.*, w.window_number, w.name as window_name,
            u.first_name as staff_first, u.last_name as staff_last
        FROM queue_tickets t
        LEFT JOIN queue_windows w ON w.id = t.window_id
        LEFT JOIN users u ON u.id = t.called_by
        WHERE t.queue_date = ? AND t.window_id = ? AND t.status = 'serving'
        ORDER BY t.called_at DESC, t.id DESC
        LIMIT 1");
    $stmt->execute([queueToday(), $windowId]);
    $row = $stmt->fetch();
    return $row ? decorateQueueTicket($row) : null;
}

function waitingQueueCount(): int {
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM queue_tickets WHERE queue_date = ? AND status = 'waiting'");
    $stmt->execute([queueToday()]);
    return (int) $stmt->fetchColumn();
}

/**
 * @return array<string,mixed>
 */
function getQueueDisplayState(): array {
    ensureQueueSchema();
    $settings = getQueueSettings();
    $windows = [];
    $lastCalled = null;

    foreach (getQueueWindows(true) as $window) {
        $current = getServingQueueTicketForWindow((int) $window['id']);
        $windows[] = [
            'id' => (int) $window['id'],
            'number' => (int) $window['window_number'],
            'name' => (string) $window['name'],
            'staff' => (string) ($window['staff_name'] ?? ''),
            'ticket_code' => $current['ticket_code'] ?? null,
            'ticket_id' => $current['id'] ?? null,
            'requestor_name' => $current['requestor_name'] ?? null,
            'service_label' => $current['service_label'] ?? null,
            'status' => $current ? 'serving' : 'idle',
        ];
        if ($current && ($lastCalled === null || (string) ($current['called_at'] ?? '') > (string) ($lastCalled['called_at'] ?? ''))) {
            $lastCalled = $current;
        }
    }

    $waiting = getWaitingQueueTickets(12);

    return [
        'enabled' => $settings['enabled'],
        'sound_enabled' => $settings['sound_enabled'],
        'date' => queueToday(),
        'date_label' => date('F j, Y'),
        'time_label' => date('g:i A'),
        'windows' => $windows,
        'waiting' => array_map(static fn(array $ticket): array => [
            'id' => (int) $ticket['id'],
            'ticket_code' => (string) $ticket['ticket_code'],
            'service_label' => (string) $ticket['service_label'],
            'requestor_name' => (string) ($ticket['requestor_name'] ?? ''),
        ], $waiting),
        'waiting_count' => waitingQueueCount(),
        'last_called' => $lastCalled ? [
            'id' => (int) $lastCalled['id'],
            'ticket_code' => (string) $lastCalled['ticket_code'],
            'window' => (string) ($lastCalled['window_label'] ?? ''),
            'called_at' => (string) ($lastCalled['called_at'] ?? ''),
        ] : null,
        'office' => APP_NAME,
        'tagline' => APP_TAGLINE,
    ];
}

/**
 * @return array<string,mixed>
 */
function getQueueWindowConsoleState(int $windowId): array {
    $window = getQueueWindow($windowId);
    $current = $window ? getServingQueueTicketForWindow($windowId) : null;
    $waiting = getWaitingQueueTickets(25);

    return [
        'window' => $window,
        'current' => $current,
        'waiting' => $waiting,
        'waiting_count' => waitingQueueCount(),
        'settings' => getQueueSettings(),
        'display' => getQueueDisplayState(),
    ];
}

/**
 * @return array{ok:bool,error?:string,ticket?:array}
 */
function callNextQueueTicket(int $windowId, int $userId): array {
    $waiting = getWaitingQueueTickets(1);
    if ($waiting === []) {
        return ['ok' => false, 'error' => 'There is no one waiting in the queue.'];
    }

    return serveQueueTicket((int) $waiting[0]['id'], $windowId, $userId);
}

/**
 * @return array{ok:bool,error?:string,ticket?:array}
 */
function serveQueueTicket(int $ticketId, int $windowId, int $userId): array {
    ensureQueueSchema();
    $window = getQueueWindow($windowId);
    if (!$window || empty($window['is_active'])) {
        return ['ok' => false, 'error' => 'This window is not active.'];
    }
    if ((int) ($window['assigned_user_id'] ?? 0) !== $userId && !hasRole('admin')) {
        return ['ok' => false, 'error' => 'This window is assigned to another staff account.'];
    }

    $current = getServingQueueTicketForWindow($windowId);
    if ($current) {
        return ['ok' => false, 'error' => 'Complete or skip the current number first (' . $current['ticket_code'] . ').'];
    }

    $ticket = findQueueTicket($ticketId);
    if (!$ticket || $ticket['queue_date'] !== queueToday()) {
        return ['ok' => false, 'error' => 'That priority number was not found today.'];
    }
    if (($ticket['status'] ?? '') !== 'waiting') {
        return ['ok' => false, 'error' => 'That number is no longer waiting.'];
    }

    $stmt = getDB()->prepare("UPDATE queue_tickets
        SET status = 'serving', window_id = ?, called_by = ?, called_at = NOW(), served_at = NOW()
        WHERE id = ? AND status = 'waiting'");
    $stmt->execute([$windowId, $userId, $ticketId]);
    if ($stmt->rowCount() < 1) {
        return ['ok' => false, 'error' => 'That number was already called at another window.'];
    }

    $updated = findQueueTicket($ticketId);
    auditLog('queue_call_ticket', 'queue_tickets', $ticketId, $ticket, $updated);

    return ['ok' => true, 'ticket' => $updated ?: $ticket];
}

/**
 * @return array{ok:bool,error?:string,ticket?:array}
 */
function recallQueueTicket(int $windowId, int $userId): array {
    $current = getServingQueueTicketForWindow($windowId);
    if (!$current) {
        return ['ok' => false, 'error' => 'No current number to recall at this window.'];
    }
    if ((int) ($current['window_id'] ?? 0) !== $windowId) {
        return ['ok' => false, 'error' => 'That number belongs to another window.'];
    }

    getDB()->prepare('UPDATE queue_tickets SET called_at = NOW(), called_by = ? WHERE id = ?')
        ->execute([$userId, (int) $current['id']]);

    $updated = findQueueTicket((int) $current['id']);
    auditLog('queue_recall_ticket', 'queue_tickets', (int) $current['id'], $current, $updated);

    return ['ok' => true, 'ticket' => $updated ?: $current];
}

/**
 * @return array{ok:bool,error?:string,ticket?:array}
 */
function completeQueueTicket(int $windowId, int $userId): array {
    return finishQueueTicketAtWindow($windowId, $userId, 'completed');
}

/**
 * @return array{ok:bool,error?:string,ticket?:array}
 */
function skipQueueTicket(int $windowId, int $userId): array {
    return finishQueueTicketAtWindow($windowId, $userId, 'skipped');
}

/**
 * @return array{ok:bool,error?:string,ticket?:array}
 */
function finishQueueTicketAtWindow(int $windowId, int $userId, string $status): array {
    if (!in_array($status, ['completed', 'skipped'], true)) {
        return ['ok' => false, 'error' => 'Invalid queue action.'];
    }

    $current = getServingQueueTicketForWindow($windowId);
    if (!$current) {
        return ['ok' => false, 'error' => 'No current number at this window.'];
    }

    $window = getQueueWindow($windowId);
    if ($window && (int) ($window['assigned_user_id'] ?? 0) !== $userId && !hasRole('admin')) {
        return ['ok' => false, 'error' => 'This window is assigned to another staff account.'];
    }

    getDB()->prepare("UPDATE queue_tickets
        SET status = ?, completed_at = NOW(), called_by = COALESCE(called_by, ?)
        WHERE id = ? AND window_id = ? AND status = 'serving'")
        ->execute([$status, $userId, (int) $current['id'], $windowId]);

    $updated = findQueueTicket((int) $current['id']);
    auditLog(
        $status === 'completed' ? 'queue_complete_ticket' : 'queue_skip_ticket',
        'queue_tickets',
        (int) $current['id'],
        $current,
        $updated
    );

    return ['ok' => true, 'ticket' => $updated ?: $current];
}

function cancelRemainingWaitingTickets(): int {
    ensureQueueSchema();
    $stmt = getDB()->prepare("UPDATE queue_tickets
        SET status = 'cancelled', completed_at = NOW()
        WHERE queue_date = ? AND status = 'waiting'");
    $stmt->execute([queueToday()]);
    return $stmt->rowCount();
}

function queueKioskUrl(): string {
    return rtrim(APP_URL, '/') . '/queue/get-number.php';
}

function queueDisplayUrl(): string {
    return rtrim(APP_URL, '/') . '/queue/display.php';
}

function queueWindowUrl(): string {
    return rtrim(APP_URL, '/') . '/queue/window.php';
}

function queueMonitorUrl(): string {
    return rtrim(APP_URL, '/') . '/queue/monitor.php';
}

function queueStatusUrl(): string {
    return rtrim(APP_URL, '/') . '/queue/status.php';
}
