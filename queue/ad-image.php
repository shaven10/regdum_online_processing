<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';

ensureQueueSchema();

$id = (int) ($_GET['id'] ?? 0);
$stmt = getDB()->prepare('SELECT file_path FROM queue_display_ads WHERE id = ? AND is_active = 1');
$stmt->execute([$id]);
$relative = $stmt->fetchColumn();
$path = is_string($relative) ? queueDisplayAdAbsolutePath($relative) : null;

if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Advertisement not found.';
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    default => '',
};

if ($mime === '') {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
