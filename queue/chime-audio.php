<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';

ensureQueueSchema();

$id = (int) ($_GET['id'] ?? 0);
$upload = findQueueChimeUpload($id);
$path = $upload ? queueChimeAbsolutePath((string) ($upload['file_path'] ?? '')) : null;

if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Chime not found.';
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
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
header('Content-Disposition: inline');
readfile($path);
exit;
