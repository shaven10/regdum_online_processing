<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/external-api.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !hasRole('admin')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$keyId = (int) ($_GET['id'] ?? 0);
$key = getExternalApiKeyForAdminView($keyId);
if (!$key) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'API key not found.']);
    exit;
}

auditLog('view_api_key', 'api_keys', $keyId, null, [
    'name' => $key['name'],
    'key_prefix' => $key['key_prefix'],
    'viewable' => $key['viewable'] ? 1 : 0,
]);

echo json_encode([
    'ok' => true,
    'key' => [
        'id' => $key['id'],
        'name' => $key['name'],
        'description' => $key['description'],
        'key_prefix' => $key['key_prefix'],
        'masked_key' => $key['masked_key'],
        'is_active' => $key['is_active'],
        'created_at' => formatDateTime($key['created_at']),
        'last_used_at' => $key['last_used_at'] !== '' ? formatDateTime($key['last_used_at']) : null,
        'viewable' => $key['viewable'],
        'key' => $key['viewable'] ? $key['key'] : null,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
