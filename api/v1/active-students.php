<?php
require_once __DIR__ . '/../../includes/external-api.php';

dispatchExternalApiGetRequest('active-students', static function (array $apiKey): array {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 50)));

    $result = queryActiveStudentsForExternalApi([
        'search' => trim((string) ($_GET['search'] ?? '')),
        'student_id' => trim((string) ($_GET['student_id'] ?? '')),
        'course_id' => (int) ($_GET['course_id'] ?? 0),
        'year_level' => trim((string) ($_GET['year_level'] ?? '')),
        'academic_year' => trim((string) ($_GET['academic_year'] ?? '')),
        'semester' => trim((string) ($_GET['semester'] ?? '')),
        'campus_id' => (int) ($_GET['campus_id'] ?? 0),
    ], $page, $perPage);

    return [
        'status_code' => 200,
        'result_count' => count($result['students']),
        'payload' => [
            'ok' => true,
            'data' => [
                'students' => array_map('formatExternalApiStudent', $result['students']),
                'pagination' => [
                    'page' => $result['page'],
                    'per_page' => $result['per_page'],
                    'total' => $result['total'],
                    'total_pages' => $result['total_pages'],
                ],
            ],
        ],
    ];
});
