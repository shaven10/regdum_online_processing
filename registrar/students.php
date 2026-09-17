<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('registrar');

ensureAcademicProgramsSchema();
ensureEnrollmentStatuses();

require_once __DIR__ . '/../includes/grades-evaluation.php';
ensureGradesEvaluationSchema();

$db = getDB();
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = normalizeStudentRecordsPerPage((int) ($_GET['per_page'] ?? ITEMS_PER_PAGE));
$search = trim($_GET['search'] ?? '');
$enrollmentStatus = trim($_GET['enrollment_status'] ?? '');
$courseId = (int) ($_GET['course_id'] ?? 0);

$enrollmentOptions = enrollmentStatusOptions();
$programs = getActiveAcademicPrograms();
if (!array_key_exists($enrollmentStatus, $enrollmentOptions)) {
    $enrollmentStatus = '';
}

$where = ['u.role_id = 1'];
$params = [];
if ($search !== '') {
    $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($terms as $term) {
        $like = '%' . $term . '%';
        $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.middle_name LIKE ?
            OR u.student_id LIKE ? OR u.email LIKE ? OR sp.course LIKE ?
            OR CONCAT(u.last_name, " ", u.first_name) LIKE ?
            OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
    }
}
if ($enrollmentStatus !== '') {
    $where[] = 'sp.enrollment_status = ?';
    $params[] = $enrollmentStatus;
}
if ($courseId > 0) {
    $where[] = 'sp.course_id = ?';
    $params[] = $courseId;
}

$stmt = $db->prepare('SELECT COUNT(*) FROM users u LEFT JOIN student_profiles sp ON u.id = sp.user_id WHERE ' . implode(' AND ', $where));
$stmt->execute($params);
$totalStudents = (int) $stmt->fetchColumn();
$pag = paginate($totalStudents, $page, $perPage);

$stmt = $db->prepare('SELECT u.*, sp.course, sp.year_level, sp.enrollment_status
    FROM users u LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE ' . implode(' AND ', $where) . ' ORDER BY u.last_name, u.first_name, u.middle_name, u.id
    LIMIT ' . (int) $pag['per_page'] . ' OFFSET ' . (int) $pag['offset']);
$stmt->execute($params);
$students = $stmt->fetchAll();
$hasFilters = $search !== '' || $enrollmentStatus !== '' || $courseId > 0;
$filterQuery = http_build_query(array_filter([
    'search' => $search,
    'enrollment_status' => $enrollmentStatus,
    'course_id' => $courseId > 0 ? (string) $courseId : '',
    'per_page' => $perPage !== ITEMS_PER_PAGE ? (string) $perPage : '',
]));
$from = $totalStudents > 0 ? $pag['offset'] + 1 : 0;
$to = min($pag['offset'] + $pag['per_page'], $totalStudents);
$listQuery = array_filter([
    'search' => $search,
    'enrollment_status' => $enrollmentStatus,
    'course_id' => $courseId > 0 ? (string) $courseId : '',
    'per_page' => $perPage !== ITEMS_PER_PAGE ? (string) $perPage : '',
    'page' => $page > 1 ? (string) $page : '',
]);
$viewId = (int) ($_GET['view'] ?? 0);
$viewStudent = $viewId > 0 ? loadStudentRecordForView($viewId) : null;
$viewCloseUrl = studentRecordsPageUrl($listQuery);
$listUrl = 'students.php' . ($listQuery ? '?' . http_build_query($listQuery) : '');
handleStudentRecordsClearGradesPost($listUrl);

$pageTitle = 'Student Records';
$activeNav = 'students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card students-records-page">
    <div class="card-header">
        <h2>Student Records</h2>
        <div class="card-header-actions">
            <a href="grades-evaluation.php" class="btn btn-outline btn-sm"><i class="fas fa-clipboard-list"></i> Grades Evaluation</a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar students-filter-bar" id="studentsFilterForm">
            <div class="students-filter-search">
                <input type="text" name="search" placeholder="Search by name, student ID, email, or course..." value="<?= e($search) ?>" autofocus>
            </div>
            <div class="students-filter-fields students-filter-fields-compact">
                <select name="enrollment_status" aria-label="Enrollment status">
                    <option value="">All enrollment</option>
                    <?php foreach ($enrollmentOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $enrollmentStatus === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="course_id" aria-label="Course">
                    <option value="">All courses</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?= (int) $program['id'] ?>" <?= $courseId === (int) $program['id'] ? 'selected' : '' ?>>
                            <?= e(($program['code'] ?? '') !== '' ? $program['code'] . ' — ' . $program['name'] : $program['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="students-filter-actions">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
                <?php if ($hasFilters): ?>
                    <a href="students.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($totalStudents > 0): ?>
            <div class="students-filter-meta">
                <span><?= $totalStudents ?> student<?= $totalStudents === 1 ? '' : 's' ?></span>
                <span>Showing <?= $from ?>–<?= $to ?></span>
            </div>
        <?php endif; ?>

        <?php if ($hasFilters && empty($students)): ?>
            <div class="empty-state"><i class="fas fa-user-slash"></i><p>No students match these filters.</p></div>
        <?php elseif (!empty($students)): ?>
            <div class="table-responsive students-table-wrap">
                <table class="data-table data-table-responsive students-records-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Program</th>
                            <th>Enrollment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                            <tr<?= $viewId === (int) $s['id'] ? ' class="is-viewing"' : '' ?>>
                                <td data-label="Student">
                                    <div class="student-record-identity">
                                        <?= renderStudentRecordNameLink($s, studentRecordsPageUrl($listQuery, (int) $s['id'])) ?>
                                        <span class="student-record-id"><?= e($s['student_id'] ?: 'No student ID') ?></span>
                                        <div class="student-record-grade-actions">
                                            <?= renderEvaluateGradesActionHtml($s, 'link') ?>
                                            <?= renderClearStudentGradesForm(
                                                (int) $s['id'],
                                                studentRecordName($s),
                                                'link',
                                                isset($s['enrollment_status']) ? (string) $s['enrollment_status'] : null
                                            ) ?>
                                        </div>
                                        <span class="student-record-email" title="<?= e($s['email']) ?>"><?= e($s['email']) ?></span>
                                    </div>
                                </td>
                                <td data-label="Program">
                                    <div class="student-record-program">
                                        <span><?= e($s['course'] ?: '—') ?></span>
                                        <?php if (!empty($s['year_level'])): ?>
                                            <small><?= e($s['year_level']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Enrollment"><?= e(enrollmentStatusLabel($s['enrollment_status'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= renderStudentRecordsPagination($pag, '?' . $filterQuery, $perPage) ?>
        <?php else: ?>
            <p class="text-muted">Search student records to verify requester identity during compliance review.</p>
        <?php endif; ?>
    </div>
</div>

<?php renderStudentRecordViewModal($viewStudent, $viewCloseUrl); ?>

<script>
document.querySelectorAll('.students-filter-fields select, .students-per-page select').forEach(function (el) {
    el.addEventListener('change', function () {
        if (el.form) el.form.submit();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
