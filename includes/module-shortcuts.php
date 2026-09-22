<?php

/**
 * Keyboard shortcut settings for registrar and cashier module navigation.
 */

/**
 * @return list<array{key:string,label:string,url:string,icon:string}>
 */
function moduleShortcutCatalog(string $role): array {
    $base = rtrim(APP_URL, '/');

    if ($role === 'cashier') {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => $base . '/cashier/dashboard.php', 'icon' => 'fa-tachometer-alt'],
            ['key' => 'payments', 'label' => 'Verify Payments', 'url' => $base . '/cashier/payments.php', 'icon' => 'fa-credit-card'],
            ['key' => 'pending', 'label' => 'Pending Payments', 'url' => $base . '/cashier/payments.php?status=pending', 'icon' => 'fa-clock'],
            ['key' => 'documents', 'label' => 'Assigned Documents', 'url' => $base . '/cashier/documents.php', 'icon' => 'fa-file-invoice'],
            ['key' => 'reports', 'label' => 'Transaction Reports', 'url' => $base . '/cashier/reports.php', 'icon' => 'fa-receipt'],
            ['key' => 'bank-settings', 'label' => 'Bank Settings', 'url' => $base . '/cashier/bank-settings.php', 'icon' => 'fa-university'],
            ['key' => 'or-settings', 'label' => 'OR / Print Settings', 'url' => $base . '/cashier/or-settings.php', 'icon' => 'fa-cog'],
        ];
    }

    if ($role === 'registrar') {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => $base . '/registrar/dashboard.php', 'icon' => 'fa-tachometer-alt'],
            ['key' => 'onsite-request', 'label' => 'Onsite Request', 'url' => $base . '/registrar/new-onsite-request.php', 'icon' => 'fa-store'],
            ['key' => 'queue-window', 'label' => 'Queue Window', 'url' => $base . '/queue/window.php', 'icon' => 'fa-list-ol'],
            ['key' => 'queue-monitor', 'label' => 'Queue Monitor', 'url' => $base . '/queue/monitor.php', 'icon' => 'fa-tv'],
            ['key' => 'students', 'label' => 'Student Records', 'url' => $base . '/registrar/students.php', 'icon' => 'fa-user-graduate'],
            ['key' => 'grades-evaluation', 'label' => 'Grades Evaluation', 'url' => $base . '/registrar/grades-evaluation.php', 'icon' => 'fa-clipboard-list'],
            ['key' => 'grade-entry', 'label' => 'Enter Grades', 'url' => $base . '/registrar/grade-entry.php', 'icon' => 'fa-paste'],
            ['key' => 'prospectus', 'label' => 'Course Prospectus', 'url' => $base . '/registrar/prospectuses.php', 'icon' => 'fa-book'],
            ['key' => 'reports', 'label' => 'All Requests', 'url' => $base . '/registrar/reports.php', 'icon' => 'fa-list-alt'],
            ['key' => 'document-statistics', 'label' => 'Documents & Assignments', 'url' => $base . '/registrar/document-statistics-report.php', 'icon' => 'fa-chart-pie'],
            ['key' => 'enrollment-report', 'label' => 'Enrollment by Course', 'url' => $base . '/registrar/enrollment-report.php', 'icon' => 'fa-table'],
            ['key' => 'enrollment-list', 'label' => 'Enrollment List', 'url' => $base . '/registrar/enrollment-list-report.php', 'icon' => 'fa-user-graduate'],
            ['key' => 'compliance', 'label' => 'Request Review', 'url' => $base . '/registrar/compliance.php', 'icon' => 'fa-clipboard-check'],
            ['key' => 'assignments', 'label' => 'Staff Assignment', 'url' => $base . '/registrar/assignments.php', 'icon' => 'fa-user-tag'],
            ['key' => 'my-assignments', 'label' => 'My Assignments', 'url' => $base . '/registrar/documents.php', 'icon' => 'fa-tasks'],
            ['key' => 'attachments', 'label' => 'Attachments', 'url' => $base . '/registrar/attachments.php', 'icon' => 'fa-paperclip'],
            ['key' => 'shortcut-settings', 'label' => 'Shortcut Settings', 'url' => $base . '/registrar/shortcut-settings.php', 'icon' => 'fa-keyboard'],
        ];
    }

    return [];
}

function moduleShortcutSettingKey(int $userId): string {
    return 'module_shortcuts_user_' . max(0, $userId);
}

/**
 * Normalize a keyboard shortcut string (e.g. Alt+P, Ctrl+Shift+1).
 */
function normalizeModuleShortcut(?string $shortcut): string {
    $shortcut = trim((string) $shortcut);
    if ($shortcut === '') {
        return '';
    }

    $parts = preg_split('/\s*\+\s*/', $shortcut) ?: [];
    $mods = [];
    $key = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $lower = strtolower($part);
        if (in_array($lower, ['ctrl', 'control'], true)) {
            $mods['Ctrl'] = 'Ctrl';
            continue;
        }
        if ($lower === 'alt') {
            $mods['Alt'] = 'Alt';
            continue;
        }
        if ($lower === 'shift') {
            $mods['Shift'] = 'Shift';
            continue;
        }
        if (in_array($lower, ['meta', 'cmd', 'command', 'win'], true)) {
            $mods['Meta'] = 'Meta';
            continue;
        }
        if (preg_match('/^f([1-9]|1[0-2])$/i', $part, $m)) {
            $key = 'F' . $m[1];
            continue;
        }
        if (preg_match('/^[a-z0-9]$/i', $part)) {
            $key = strtoupper($part);
            continue;
        }
        if (preg_match('/^(Escape|Esc|Enter|Tab|Space|ArrowUp|ArrowDown|ArrowLeft|ArrowRight|Home|End|PageUp|PageDown|Backspace|Delete)$/i', $part, $m)) {
            $label = ucfirst(strtolower($m[1]));
            if (strcasecmp($label, 'Esc') === 0) {
                $label = 'Escape';
            }
            $key = $label;
            continue;
        }
        return '';
    }

    if ($key === '') {
        return '';
    }

    // Require at least one modifier for letter/number keys to avoid typing conflicts.
    if (preg_match('/^[A-Z0-9]$/', $key) && $mods === []) {
        return '';
    }

    $ordered = [];
    foreach (['Ctrl', 'Alt', 'Shift', 'Meta'] as $mod) {
        if (isset($mods[$mod])) {
            $ordered[] = $mod;
        }
    }
    $ordered[] = $key;
    return implode('+', $ordered);
}

/**
 * @return array<string,string> moduleKey => shortcut
 */
function getModuleShortcuts(int $userId, string $role): array {
    if (!function_exists('getAppSetting')) {
        require_once __DIR__ . '/compliance.php';
    }

    $catalog = moduleShortcutCatalog($role);
    $allowed = [];
    foreach ($catalog as $item) {
        $allowed[$item['key']] = '';
    }

    if ($userId <= 0 || $allowed === []) {
        return $allowed;
    }

    $raw = getAppSetting(moduleShortcutSettingKey($userId), '');
    if ($raw === '') {
        return $allowed;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $allowed;
    }

    $used = [];
    foreach ($decoded as $moduleKey => $shortcut) {
        $moduleKey = (string) $moduleKey;
        if (!array_key_exists($moduleKey, $allowed)) {
            continue;
        }
        $normalized = normalizeModuleShortcut(is_string($shortcut) ? $shortcut : '');
        if ($normalized === '') {
            continue;
        }
        if (isset($used[$normalized])) {
            continue;
        }
        $allowed[$moduleKey] = $normalized;
        $used[$normalized] = $moduleKey;
    }

    return $allowed;
}

/**
 * @param array<string,string> $shortcuts
 * @return array{ok:bool,shortcuts:array<string,string>,errors:list<string>}
 */
function saveModuleShortcuts(int $userId, string $role, array $shortcuts): array {
    if (!function_exists('setAppSetting')) {
        require_once __DIR__ . '/compliance.php';
    }

    $catalog = moduleShortcutCatalog($role);
    $clean = [];
    $errors = [];
    $used = [];

    foreach ($catalog as $item) {
        $moduleKey = $item['key'];
        $raw = isset($shortcuts[$moduleKey]) ? (string) $shortcuts[$moduleKey] : '';
        $normalized = normalizeModuleShortcut($raw);
        if ($raw !== '' && $normalized === '') {
            $errors[] = 'Invalid shortcut for ' . $item['label'] . '. Use Alt/Ctrl/Shift plus a key (e.g. Alt+P).';
            continue;
        }
        if ($normalized === '') {
            continue;
        }
        if (isset($used[$normalized])) {
            $errors[] = 'Shortcut ' . $normalized . ' is assigned more than once.';
            continue;
        }
        $clean[$moduleKey] = $normalized;
        $used[$normalized] = $moduleKey;
    }

    if ($errors !== []) {
        return ['ok' => false, 'shortcuts' => $clean, 'errors' => $errors];
    }

    if ($userId > 0) {
        setAppSetting(moduleShortcutSettingKey($userId), json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    return ['ok' => true, 'shortcuts' => getModuleShortcuts($userId, $role), 'errors' => []];
}

/**
 * Payload for client-side shortcut navigation.
 *
 * @return list<array{key:string,label:string,url:string,shortcut:string}>
 */
function moduleShortcutRuntimeMap(int $userId, string $role): array {
    $shortcuts = getModuleShortcuts($userId, $role);
    $map = [];
    foreach (moduleShortcutCatalog($role) as $item) {
        $combo = $shortcuts[$item['key']] ?? '';
        if ($combo === '') {
            continue;
        }
        $map[] = [
            'key' => $item['key'],
            'label' => $item['label'],
            'url' => $item['url'],
            'shortcut' => $combo,
        ];
    }
    return $map;
}

/**
 * Render shortcut assignment fields for a settings form.
 *
 * @param array<string,string> $shortcuts
 */
function renderModuleShortcutSettingsFields(string $role, array $shortcuts): void {
    $catalog = moduleShortcutCatalog($role);
    if ($catalog === []) {
        echo '<p class="text-muted">No modules available for shortcut settings.</p>';
        return;
    }
    ?>
    <div class="shortcut-settings-list">
        <?php foreach ($catalog as $item):
            $value = $shortcuts[$item['key']] ?? '';
            ?>
            <div class="shortcut-settings-row">
                <div class="shortcut-settings-module">
                    <i class="fas <?= e($item['icon']) ?>"></i>
                    <span><?= e($item['label']) ?></span>
                </div>
                <div class="shortcut-settings-input">
                    <input type="text"
                        name="shortcuts[<?= e($item['key']) ?>]"
                        value="<?= e($value) ?>"
                        class="shortcut-capture-input"
                        data-shortcut-capture
                        placeholder="Click, then press keys"
                        autocomplete="off"
                        spellcheck="false"
                        readonly>
                    <button type="button" class="btn btn-outline btn-sm" data-shortcut-clear title="Clear shortcut">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="text-muted shortcut-settings-hint">
        Click a field and press a key combo (e.g. <kbd>Alt</kbd>+<kbd>P</kbd>). Letter and number shortcuts need Alt, Ctrl, or Shift.
        Shortcuts are ignored while typing in form fields.
    </p>
    <?php
}

function claimSlipAfterVerifySettingKey(int $userId): string {
    return 'claim_slip_after_verify_user_' . max(0, $userId);
}

function isClaimSlipAfterVerifyEnabled(?int $userId = null): bool {
    if (!function_exists('getAppSetting')) {
        require_once __DIR__ . '/compliance.php';
    }
    if ($userId === null) {
        $user = function_exists('currentUser') ? currentUser() : null;
        $userId = (int) ($user['id'] ?? 0);
    }
    if ($userId <= 0) {
        return true;
    }
    return getAppSetting(claimSlipAfterVerifySettingKey($userId), '1') === '1';
}

function saveClaimSlipAfterVerifyEnabled(int $userId, bool $enabled): bool {
    if (!function_exists('setAppSetting')) {
        require_once __DIR__ . '/compliance.php';
    }
    if ($userId <= 0) {
        return $enabled;
    }
    setAppSetting(claimSlipAfterVerifySettingKey($userId), $enabled ? '1' : '0');
    return $enabled;
}
