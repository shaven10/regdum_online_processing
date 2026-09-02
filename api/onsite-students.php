<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/onsite-request.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !hasRole('registrar', 'admin')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$browse = !empty($_GET['browse']);
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(1, min(50, (int) ($_GET['per_page'] ?? ($browse ? 15 : 10))));
$courseId = (int) ($_GET['course_id'] ?? 0);
$yearLevel = trim((string) ($_GET['year_level'] ?? ''));
$enrollmentStatus = trim((string) ($_GET['enrollment_status'] ?? ''));

if (!$browse && strlen($search) < 2) {
    echo json_encode([
        'ok'          => true,
        'students'    => [],
        'total'       => 0,
        'page'        => 1,
        'per_page'    => $perPage,
        'total_pages' => 1,
        'message'     => 'Type at least 2 characters to search.',
    ]);
    exit;
}

if ($browse && !array_key_exists('enrollment_status', $_GET)) {
    $enrollmentStatus = 'enrolled';
}

$result = queryStudentsForOnsitePicker([
    'search'            => $search,
    'course_id'         => $courseId,
    'year_level'        => $yearLevel,
    'enrollment_status' => $enrollmentStatus,
    'active_only'       => true,
    'require_search'    => !$browse,
], $page, $perPage);

echo json_encode([
    'ok'          => true,
    'students'    => array_map('formatOnsitePickerStudent', $result['students']),
    'total'       => $result['total'],
    'page'        => $result['page'],
    'per_page'    => $result['per_page'],
    'total_pages' => $result['total_pages'],
]);
