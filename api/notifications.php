<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$user = currentUser();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = [];
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    if ($payload === []) {
        $payload = $_POST;
    }

    $csrf = (string) ($payload['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $action = (string) ($payload['action'] ?? '');
    if ($action === 'mark_read') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id > 0) {
            markNotificationRead($userId, $id);
        }
    } elseif ($action === 'mark_all_read') {
        markAllNotificationsRead($userId);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'count' => getUnreadNotificationCount($userId),
    ]);
    exit;
}

$afterId = max(0, (int) ($_GET['after_id'] ?? 0));
$limit = max(1, min(10, (int) ($_GET['limit'] ?? 5)));

$items = getLatestUnreadNotifications($userId, $limit, $afterId);
$count = getUnreadNotificationCount($userId);

echo json_encode([
    'ok' => true,
    'count' => $count,
    'items' => array_map(static function (array $item): array {
        return [
            'id' => (int) $item['id'],
            'title' => $item['title'],
            'message' => $item['message'],
            'type' => $item['type'],
            'link' => $item['link'],
            'created_at' => $item['created_at'],
        ];
    }, $items),
]);
