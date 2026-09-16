<?php
/**
 * Auto-cancel stale online requests (CLI / Task Scheduler).
 *
 * Example:
 *   php C:\xampp\htdocs\regdum_online_processing\cron\auto-cancel-requests.php
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/student-requests.php';

ensureRequestStatuses();

$days = max(1, (int) ($argv[1] ?? 3));
$cancelled = autoCancelStaleOnlineRequests($days);

if (function_exists('setAppSetting')) {
    setAppSetting('auto_cancel_online_requests_last_run', (string) time());
}

echo 'Auto-cancelled ' . $cancelled . ' online request(s) older than ' . $days . " day(s).\n";
