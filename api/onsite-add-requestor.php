<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/onsite-request.php';
require_once __DIR__ . '/../includes/campuses.php';
require_once __DIR__ . '/../includes/programs.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !hasRole('registrar', 'admin')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$csrf = (string) ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token. Refresh the page and try again.']);
    exit;
}

ensureOnsiteRequestSchema();
ensureEnrollmentStatuses();
ensureAcademicProgramsSchema();
ensureCampusesSchema();

$batchStatus = trim((string) ($_POST['enrollment_status'] ?? ''));
$result = createOnsiteAlumniRequestorForBatch($_POST, $batchStatus);

if (empty($result['ok'])) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => $result['error'] ?? 'Unable to add requestor.',
        'errors' => $result['errors'] ?? [],
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'created' => !empty($result['created']),
    'student' => $result['student'],
    'message' => !empty($result['created'])
        ? 'Requestor created and ready to add to the batch.'
        : 'Existing requestor updated and ready to add to the batch.',
]);
