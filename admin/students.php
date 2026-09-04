<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/grades-evaluation.php';
requireRole('admin');

ensureAcademicProgramsSchema();
ensureCampusesSchema();
ensureEnrollmentStatuses();
ensureGradesEvaluationSchema();

$db = getDB();
$page = max(1, (int) ($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$enrollmentStatus = trim($_GET['enrollment_status'] ?? '');
$courseId = (int) ($_GET['course_id'] ?? 0);
$yearLevel = trim($_GET['year_level'] ?? '');
$accountStatus = trim($_GET['account'] ?? '');
$campusId = (int) ($_GET['campus_id'] ?? 0);
$sort = trim($_GET['sort'] ?? 'name');
$perPage = normalizeStudentRecordsPerPage((int) ($_GET['per_page'] ?? ITEMS_PER_PAGE));

$enrollmentOptions = enrollmentStatusOptions();
$yearOptions = yearLevelOptions();
if (!isset($yearOptions['5th Year'])) {
    $yearOptions['5th Year'] = '5th Year';
}
$programs = getAllAcademicPrograms();
$campuses = getAllCampuses();
$sortOptions = [
    'name'   => 'Name (A–Z)',
    'newest' => 'Newest first',
    'id'     => 'Student ID',
];

if (!array_key_exists($enrollmentStatus, $enrollmentOptions)) {
    $enrollmentStatus = '';
}
if (!array_key_exists($yearLevel, $yearOptions)) {
    $yearLevel = '';
}
if (!in_array($accountStatus, ['active', 'inactive'], true)) {
    $accountStatus = '';
}
if (!array_key_exists($sort, $sortOptions)) {
    $sort = 'name';
}

$validCourseIds = array_map(static fn($p) => (int) $p['id'], $programs);
if ($courseId > 0 && !in_array($courseId, $validCourseIds, true)) {
    $courseId = 0;
}
$validCampusIds = array_map(static fn($c) => (int) $c['id'], $campuses);
if ($campusId > 0 && !in_array($campusId, $validCampusIds, true)) {
    $campusId = 0;
}

$filters = array_filter([
    'search' => $search,
    'enrollment_status' => $enrollmentStatus,
    'course_id' => $courseId > 0 ? (string) $courseId : '',
    'year_level' => $yearLevel,
    'account' => $accountStatus,
    'campus_id' => $campusId > 0 ? (string) $campusId : '',
    'sort' => $sort !== 'name' ? $sort : '',
    'per_page' => $perPage !== ITEMS_PER_PAGE ? (string) $perPage : '',
]);
$listQuery = array_filter($filters + [
    'page' => $page > 1 ? (string) $page : '',
]);
$listUrl = APP_URL . '/admin/students.php' . ($listQuery ? '?' . http_build_query($listQuery) : '');
$filterQuery = http_build_query($filters);
$hasFilters = $search !== '' || $enrollmentStatus !== '' || $courseId > 0 || $yearLevel !== '' || $accountStatus !== '' || $campusId > 0;
handleStudentRecordsClearGradesPost($listUrl);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $studentId = (int) ($_POST['user_id'] ?? 0);
        $result = adminDeleteStudent($studentId);

        if (!empty($result['ok'])) {
            $requestsDeleted = (int) ($result['requests_deleted'] ?? 0);
            setFlash('success', 'Student account deleted permanently.', [
                'title' => 'Student Deleted',
                'context' => array_filter([
                    'Student' => $result['name'] ?? null,
                    'Requests removed' => $requestsDeleted > 0 ? (string) $requestsDeleted : null,
                ]),
            ]);
        } else {
            setFlash('error', $result['error'] ?? 'Unable to delete student account.', [
                'title' => 'Delete Failed',
            ]);
        }

        redirect($listUrl);
    }

    if ($action === 'batch_delete') {
        $userIds = normalizeAdminBatchRequestIds($_POST['user_ids'] ?? []);
        if (empty($userIds)) {
            setFlash('error', 'Select at least one student.', ['title' => 'No Students Selected']);
            redirect($listUrl);
        }

        $result = adminBatchDeleteStudents($userIds);
        $deleted = (int) ($result['deleted'] ?? 0);
        $failed = $result['failed'] ?? [];
        $requestsDeleted = (int) ($result['requests_deleted'] ?? 0);

        if ($deleted > 0) {
            setFlash('success', $deleted . ' student account' . ($deleted === 1 ? '' : 's') . ' deleted permanently.', [
                'title' => 'Students Deleted',
                'context' => array_filter([
                    'Deleted' => (string) $deleted,
                    'Requests removed' => $requestsDeleted > 0 ? (string) $requestsDeleted : null,
                    'Failed' => !empty($failed) ? (string) count($failed) : null,
                ]),
                'details' => !empty($failed) ? implode(' ', $failed) : null,
            ]);
        } else {
            setFlash('error', implode(' ', $failed ?: ['Unable to delete selected students.']), [
                'title' => 'Bulk Delete Failed',
            ]);
        }

        redirect($listUrl);
    }

    if ($action === 'delete_filtered' || $action === 'delete_all') {
        $confirm = strtoupper(trim((string) ($_POST['confirm_text'] ?? '')));
        if ($confirm !== 'DELETE') {
            setFlash('error', 'Type DELETE to confirm this action.', [
                'title' => 'Confirmation Required',
            ]);
            redirect($listUrl);
        }

        $deleteFilters = [];
        if ($action === 'delete_filtered') {
            $deleteFilters = [
                'search' => trim((string) ($_POST['search'] ?? '')),
                'enrollment_status' => trim((string) ($_POST['enrollment_status'] ?? '')),
                'course_id' => (int) ($_POST['course_id'] ?? 0),
                'year_level' => trim((string) ($_POST['year_level'] ?? '')),
                'account' => trim((string) ($_POST['account'] ?? '')),
                'campus_id' => (int) ($_POST['campus_id'] ?? 0),
            ];

            $hasDeleteFilters = $deleteFilters['search'] !== ''
                || $deleteFilters['enrollment_status'] !== ''
                || $deleteFilters['course_id'] > 0
                || $deleteFilters['year_level'] !== ''
                || $deleteFilters['account'] !== ''
                || $deleteFilters['campus_id'] > 0;

            if (!$hasDeleteFilters) {
                setFlash('error', 'Apply at least one filter before deleting filtered students.', [
                    'title' => 'No Filters Applied',
                ]);
                redirect($listUrl);
            }
        }

        $matched = countAdminStudents($deleteFilters);
        if ($matched <= 0) {
            setFlash('error', $action === 'delete_all'
                ? 'There are no student records to delete.'
                : 'No students match the current filters.', [
                'title' => 'Nothing to Delete',
            ]);
            redirect($action === 'delete_all' ? (APP_URL . '/admin/students.php') : $listUrl);
        }

        $result = adminDeleteStudentsMatchingFilters($deleteFilters);
        $deleted = (int) ($result['deleted'] ?? 0);
        $failed = $result['failed'] ?? [];
        $requestsDeleted = (int) ($result['requests_deleted'] ?? 0);

        auditLog($action === 'delete_all' ? 'delete_all_students' : 'delete_filtered_students', 'users', null, [
            'matched' => $matched,
            'filters' => $deleteFilters,
        ], [
            'deleted' => $deleted,
            'requests_deleted' => $requestsDeleted,
            'failed' => count($failed),
        ]);

        if ($deleted > 0) {
            setFlash('success', $deleted . ' student account' . ($deleted === 1 ? '' : 's') . ' deleted permanently.', [
                'title' => $action === 'delete_all' ? 'All Students Deleted' : 'Filtered Students Deleted',
                'context' => array_filter([
                    'Matched' => (string) $matched,
                    'Deleted' => (string) $deleted,
                    'Requests removed' => $requestsDeleted > 0 ? (string) $requestsDeleted : null,
                    'Failed' => !empty($failed) ? (string) count($failed) : null,
                ]),
                'details' => !empty($failed) ? implode(' ', array_slice($failed, 0, 5)) : null,
            ]);
        } else {
            setFlash('error', implode(' ', $failed ?: ['Unable to delete student records.']), [
                'title' => 'Delete Failed',
            ]);
        }

        redirect($action === 'delete_all' ? (APP_URL . '/admin/students.php') : $listUrl);
    }

    setFlash('error', 'Unknown action.', ['title' => 'Action Failed']);
    redirect($listUrl);
}

$where = ["r.name = 'student'"];
$params = [];

if ($search !== '') {
    $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($terms as $term) {
        $like = '%' . $term . '%';
        $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.middle_name LIKE ?
            OR u.email LIKE ? OR u.student_id LIKE ? OR u.phone LIKE ?
            OR sp.course LIKE ? OR CONCAT(u.last_name, " ", u.first_name) LIKE ?
            OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
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
if ($yearLevel !== '') {
    $where[] = 'sp.year_level = ?';
    $params[] = $yearLevel;
}
if ($accountStatus === 'active') {
    $where[] = 'u.is_active = 1';
} elseif ($accountStatus === 'inactive') {
    $where[] = 'u.is_active = 0';
}
if ($campusId > 0) {
    $where[] = 'sp.origin_campus_id = ?';
    $params[] = $campusId;
}

$whereClause = implode(' AND ', $where);
$orderBy = match ($sort) {
    'name' => 'u.last_name ASC, u.first_name ASC, u.middle_name ASC, u.id ASC',
    'id'   => 'u.student_id ASC, u.id DESC',
    default => 'u.created_at DESC, u.id DESC',
};

$countStmt = $db->prepare("SELECT COUNT(*) FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE $whereClause");
$countStmt->execute($params);
$totalStudents = (int) $countStmt->fetchColumn();
$pag = paginate($totalStudents, $page, $perPage);

$stmt = $db->prepare("SELECT u.*, sp.course, sp.course_id, sp.year_level, sp.enrollment_status, sp.origin_campus_id,
        (SELECT COUNT(*) FROM requests req WHERE req.user_id = u.id) AS request_count
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE $whereClause
    ORDER BY $orderBy
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$students = $stmt->fetchAll();

$from = $totalStudents > 0 ? $pag['offset'] + 1 : 0;
$to = min($pag['offset'] + $pag['per_page'], $totalStudents);
$viewId = (int) ($_GET['view'] ?? 0);
$viewStudent = $viewId > 0 ? loadStudentRecordForView($viewId) : null;
$viewCloseUrl = studentRecordsPageUrl($listQuery);

$pageTitle = 'Student Records';
$activeNav = 'students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card students-records-page">
    <div class="card-header">
        <div>
            <h2>Student Records</h2>
            <p class="text-muted" style="margin:.35rem 0 0">Delete permanently removes the account and all related credential requests.</p>
        </div>
        <div class="card-header-actions">
            <a href="<?= APP_URL ?>/registrar/grades-evaluation.php" class="btn btn-outline btn-sm"><i class="fas fa-clipboard-list"></i> Grades Evaluation</a>
            <a href="import-students.php" class="btn btn-primary btn-sm"><i class="fas fa-file-import"></i> Import Active Students</a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar students-filter-bar" id="studentsFilterForm">
            <div class="students-filter-search">
                <input type="text" name="search" placeholder="Search name, student ID, email, phone, or course..." value="<?= e($search) ?>">
            </div>
            <div class="students-filter-fields">
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
                            <?= e($program['code'] ? $program['code'] . ' — ' . $program['name'] : $program['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="year_level" aria-label="Year level">
                    <option value="">All years</option>
                    <?php foreach ($yearOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $yearLevel === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="account" aria-label="Account status">
                    <option value="">All accounts</option>
                    <option value="active" <?= $accountStatus === 'active' ? 'selected' : '' ?>>Active accounts</option>
                    <option value="inactive" <?= $accountStatus === 'inactive' ? 'selected' : '' ?>>Inactive accounts</option>
                </select>
                <select name="campus_id" aria-label="Campus">
                    <option value="">All campuses</option>
                    <?php foreach ($campuses as $campus): ?>
                        <option value="<?= (int) $campus['id'] ?>" <?= $campusId === (int) $campus['id'] ? 'selected' : '' ?>><?= e($campus['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="sort" aria-label="Sort records">
                    <?php foreach ($sortOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="students-filter-actions">
                <button type="submit" class="btn btn-outline btn-sm"><i class="fas fa-filter"></i> Filter</button>
                <?php if ($hasFilters || $sort !== 'name'): ?>
                    <a href="students.php" class="btn btn-outline btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="students-filter-meta">
            <span><?= $totalStudents ?> student<?= $totalStudents === 1 ? '' : 's' ?><?= $hasFilters ? ' match these filters' : '' ?></span>
            <?php if ($totalStudents > 0): ?>
                <span>Showing <?= $from ?>–<?= $to ?></span>
            <?php endif; ?>
            <?php
            $totalAllStudents = $hasFilters ? countAdminStudents([]) : $totalStudents;
            ?>
            <?php if ($totalAllStudents > 0 || $totalStudents > 0): ?>
                <div class="students-bulk-delete-actions">
                    <?php if ($hasFilters && $totalStudents > 0): ?>
                        <button type="button"
                            class="btn btn-outline btn-sm btn-danger-outline js-student-mass-delete"
                            data-action="delete_filtered"
                            data-count="<?= (int) $totalStudents ?>"
                            data-title="Delete Filtered Students?"
                            data-message="Permanently delete all <?= (int) $totalStudents ?> student<?= $totalStudents === 1 ? '' : 's' ?> matching the current filters? Related credential requests will also be removed. Type DELETE to confirm.">
                            <i class="fas fa-filter"></i> Delete Filtered (<?= (int) $totalStudents ?>)
                        </button>
                    <?php endif; ?>
                    <?php if ($totalAllStudents > 0): ?>
                        <button type="button"
                            class="btn btn-danger btn-sm js-student-mass-delete"
                            data-action="delete_all"
                            data-count="<?= (int) $totalAllStudents ?>"
                            data-title="Delete All Students?"
                            data-message="Permanently delete ALL <?= (int) $totalAllStudents ?> student account<?= $totalAllStudents === 1 ? '' : 's' ?>? This ignores filters and cannot be undone. Type DELETE to confirm.">
                            <i class="fas fa-trash"></i> Delete All Students
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <form method="POST" id="adminStudentsMassDeleteForm" class="hidden-form" hidden>
            <?= csrfField() ?>
            <input type="hidden" name="action" id="adminStudentsMassDeleteAction" value="">
            <input type="hidden" name="confirm_text" id="adminStudentsMassDeleteConfirm" value="">
            <input type="hidden" name="search" value="<?= e($search) ?>">
            <input type="hidden" name="enrollment_status" value="<?= e($enrollmentStatus) ?>">
            <input type="hidden" name="course_id" value="<?= $courseId > 0 ? (int) $courseId : '' ?>">
            <input type="hidden" name="year_level" value="<?= e($yearLevel) ?>">
            <input type="hidden" name="account" value="<?= e($accountStatus) ?>">
            <input type="hidden" name="campus_id" value="<?= $campusId > 0 ? (int) $campusId : '' ?>">
        </form>

        <?php if (empty($students)): ?>
            <div class="empty-state"><i class="fas fa-users"></i><p><?= $hasFilters ? 'No students match these filters.' : 'No student accounts found.' ?></p></div>
        <?php else: ?>
            <form method="POST" id="adminStudentsBatchForm" class="admin-students-batch-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="adminStudentsBatchAction" value="batch_delete">

                <div class="batch-action-bar" id="adminStudentsBatchActionBar" hidden>
                    <span class="batch-action-count"><strong id="adminStudentsBatchSelectedCount">0</strong> selected</span>
                    <div class="batch-action-buttons">
                        <button type="button" class="btn btn-danger btn-sm" id="adminStudentsBatchDeleteBtn">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                    </div>
                </div>
            </form>

            <div class="table-responsive students-table-wrap">
                <table class="data-table data-table-responsive students-records-table">
                    <thead>
                        <tr>
                            <th class="batch-select-col">
                                <label class="checkbox-label batch-select-all-label">
                                    <input type="checkbox" id="adminSelectAllStudents" form="adminStudentsBatchForm" aria-label="Select all students on this page">
                                </label>
                            </th>
                            <th>Student</th>
                            <th>Program</th>
                            <th>Enrollment</th>
                            <th class="students-col-account">Account</th>
                            <th class="students-col-requests">Requests</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                            <?php
                            $requestCount = (int) ($s['request_count'] ?? 0);
                            $displayName = studentRecordName($s);
                            $confirmMessage = $requestCount > 0
                                ? 'Delete this student permanently? This will also delete ' . $requestCount . ' credential request(s). This cannot be undone.'
                                : 'Delete this student account permanently? This cannot be undone.';
                            ?>
                            <tr<?= $viewId === (int) $s['id'] ? ' class="is-viewing"' : '' ?>>
                                <td class="batch-select-col" data-label="Select">
                                    <label class="checkbox-label">
                                        <input type="checkbox"
                                            class="admin-student-select"
                                            form="adminStudentsBatchForm"
                                            name="user_ids[]"
                                            value="<?= (int) $s['id'] ?>"
                                            data-request-count="<?= $requestCount ?>">
                                    </label>
                                </td>
                                <td data-label="Student">
                                    <div class="student-record-identity">
                                        <?= renderStudentRecordNameLink($s, studentRecordsPageUrl($listQuery, (int) $s['id'])) ?>
                                        <span class="student-record-id"><?= e($s['student_id'] ?: 'No student ID') ?></span>
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
                                <td class="students-col-account" data-label="Account">
                                    <?= !empty($s['is_active'])
                                        ? '<span class="badge badge-completed">Active</span>'
                                        : '<span class="badge badge-rejected">Inactive</span>' ?>
                                </td>
                                <td class="students-col-requests" data-label="Requests"><?= $requestCount ?></td>
                                <td data-label="Actions" class="action-cell">
                                    <div class="action-cell-buttons">
                                        <a href="<?= e(APP_URL . '/registrar/grades-evaluation.php?student_user_id=' . (int) $s['id']) ?>" <?= adminSettingsIconBtnAttrs('evaluate') ?>><?= adminSettingsIconBtnContent('evaluate') ?></a>
                                        <?= renderClearStudentGradesForm((int) $s['id'], studentRecordName($s), 'icon') ?>
                                        <form method="POST" class="student-delete-form"
                                            data-confirm-title="Delete Student?"
                                            data-confirm-message="<?= e($confirmMessage) ?>"
                                            data-confirm-name="<?= e($displayName) ?>"
                                            data-confirm-requests="<?= $requestCount ?>">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
                                            <button type="submit" <?= adminSettingsIconBtnAttrs('delete', 'danger') ?>><?= adminSettingsIconBtnContent('delete') ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= renderStudentRecordsPagination($pag, '?' . $filterQuery, $perPage) ?>
        <?php endif; ?>
    </div>
</div>

<?php renderStudentRecordViewModal($viewStudent, $viewCloseUrl); ?>

<script>
(function () {
    document.querySelectorAll('.students-filter-fields select, .students-per-page select').forEach(function (el) {
        el.addEventListener('change', function () {
            if (el.form) el.form.submit();
        });
    });
})();
</script>

<div class="confirm-modal" id="studentDeleteConfirmModal" aria-hidden="true">
    <div class="confirm-modal-overlay" data-close-confirm-modal></div>
    <div class="confirm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="studentDeleteConfirmTitle">
        <div class="confirm-modal-accent tone-error"></div>
        <button type="button" class="confirm-modal-close" data-close-confirm-modal aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
        <div class="confirm-modal-icon-wrap tone-error">
            <i class="fas fa-trash-alt"></i>
        </div>
        <span class="confirm-modal-eyebrow">Confirm Deletion</span>
        <h2 class="confirm-modal-title" id="studentDeleteConfirmTitle">Delete Student?</h2>
        <p class="confirm-modal-message" id="studentDeleteConfirmMessage">This action cannot be undone.</p>
        <dl class="confirm-modal-context" id="studentDeleteConfirmContext" hidden></dl>
        <div class="confirm-modal-typed" id="studentDeleteConfirmTyped" hidden>
            <label for="studentDeleteConfirmInput">Type <strong>DELETE</strong> to confirm</label>
            <input type="text" id="studentDeleteConfirmInput" autocomplete="off" spellcheck="false" placeholder="DELETE">
        </div>
        <div class="confirm-modal-actions">
            <button type="button" class="btn btn-outline" data-close-confirm-modal>Cancel</button>
            <button type="button" class="btn btn-danger" id="studentDeleteConfirmBtn">
                <i class="fas fa-trash-alt"></i> Delete Permanently
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    const batchForm = document.getElementById('adminStudentsBatchForm');
    const batchBar = document.getElementById('adminStudentsBatchActionBar');
    const countEl = document.getElementById('adminStudentsBatchSelectedCount');
    const selectAll = document.getElementById('adminSelectAllStudents');
    const deleteBtn = document.getElementById('adminStudentsBatchDeleteBtn');
    const massForm = document.getElementById('adminStudentsMassDeleteForm');
    const massActionInput = document.getElementById('adminStudentsMassDeleteAction');
    const massConfirmInput = document.getElementById('adminStudentsMassDeleteConfirm');
    const modal = document.getElementById('studentDeleteConfirmModal');
    const titleEl = document.getElementById('studentDeleteConfirmTitle');
    const messageEl = document.getElementById('studentDeleteConfirmMessage');
    const contextEl = document.getElementById('studentDeleteConfirmContext');
    const typedWrap = document.getElementById('studentDeleteConfirmTyped');
    const typedInput = document.getElementById('studentDeleteConfirmInput');
    const confirmBtn = document.getElementById('studentDeleteConfirmBtn');
    let pendingConfirm = null;
    let requireTypedDelete = false;

    const rowChecks = function () {
        return Array.from(document.querySelectorAll('.admin-student-select'));
    };

    function selectedChecks() {
        return rowChecks().filter(function (cb) { return cb.checked; });
    }

    function syncBatchBar() {
        const selected = selectedChecks();
        const count = selected.length;
        if (countEl) countEl.textContent = String(count);
        if (batchBar) batchBar.hidden = count === 0;
        if (selectAll) {
            const all = rowChecks();
            selectAll.checked = all.length > 0 && count === all.length;
            selectAll.indeterminate = count > 0 && count < all.length;
        }
    }

    function syncTypedConfirmState() {
        if (!confirmBtn) return;
        if (!requireTypedDelete) {
            confirmBtn.disabled = false;
            return;
        }
        const value = (typedInput && typedInput.value ? typedInput.value : '').trim().toUpperCase();
        confirmBtn.disabled = value !== 'DELETE';
    }

    function closeConfirmModal() {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        pendingConfirm = null;
        requireTypedDelete = false;
        if (typedWrap) typedWrap.hidden = true;
        if (typedInput) typedInput.value = '';
        syncTypedConfirmState();
    }

    function openConfirmModal(options) {
        if (!modal) return;
        pendingConfirm = options.onConfirm || null;
        requireTypedDelete = !!options.requireTypedDelete;
        if (titleEl) titleEl.textContent = options.title || 'Confirm Deletion';
        if (messageEl) messageEl.textContent = options.message || 'This action cannot be undone.';
        if (contextEl) {
            contextEl.innerHTML = '';
            const context = options.context || {};
            const keys = Object.keys(context);
            if (keys.length) {
                keys.forEach(function (key) {
                    const dt = document.createElement('dt');
                    dt.textContent = key;
                    const dd = document.createElement('dd');
                    dd.textContent = String(context[key]);
                    contextEl.appendChild(dt);
                    contextEl.appendChild(dd);
                });
                contextEl.hidden = false;
            } else {
                contextEl.hidden = true;
            }
        }
        if (typedWrap) typedWrap.hidden = !requireTypedDelete;
        if (typedInput) typedInput.value = '';
        syncTypedConfirmState();
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (requireTypedDelete && typedInput) {
            typedInput.focus();
        } else if (confirmBtn) {
            confirmBtn.focus();
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rowChecks().forEach(function (cb) { cb.checked = selectAll.checked; });
            syncBatchBar();
        });
    }

    rowChecks().forEach(function (cb) {
        cb.addEventListener('change', syncBatchBar);
    });

    document.querySelectorAll('.student-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const requests = parseInt(form.getAttribute('data-confirm-requests') || '0', 10) || 0;
            const context = {
                Student: form.getAttribute('data-confirm-name') || '—'
            };
            if (requests > 0) {
                context['Requests to remove'] = String(requests);
            }
            openConfirmModal({
                title: form.getAttribute('data-confirm-title') || 'Delete Student?',
                message: form.getAttribute('data-confirm-message') || 'This action cannot be undone.',
                context: context,
                onConfirm: function () {
                    form.submit();
                }
            });
        });
    });

    if (deleteBtn && batchForm) {
        deleteBtn.addEventListener('click', function () {
            const selected = selectedChecks();
            if (!selected.length) {
                return;
            }

            let requestTotal = 0;
            selected.forEach(function (cb) {
                requestTotal += parseInt(cb.getAttribute('data-request-count') || '0', 10) || 0;
            });

            const count = selected.length;
            const message = count === 1
                ? 'Delete the selected student permanently? This cannot be undone.'
                : 'Delete ' + count + ' selected students permanently? This cannot be undone.';

            const context = {
                Selected: String(count)
            };
            if (requestTotal > 0) {
                context['Requests to remove'] = String(requestTotal);
            }

            openConfirmModal({
                title: count === 1 ? 'Delete Student?' : 'Delete Selected Students?',
                message: message + (requestTotal > 0 ? ' Related credential requests will also be permanently removed.' : ''),
                context: context,
                onConfirm: function () {
                    document.getElementById('adminStudentsBatchAction').value = 'batch_delete';
                    batchForm.submit();
                }
            });
        });
    }

    document.querySelectorAll('.js-student-mass-delete').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!massForm || !massActionInput || !massConfirmInput) {
                return;
            }

            const action = button.getAttribute('data-action') || '';
            const count = parseInt(button.getAttribute('data-count') || '0', 10) || 0;
            if (!action || count <= 0) {
                return;
            }

            openConfirmModal({
                title: button.getAttribute('data-title') || 'Delete Students?',
                message: button.getAttribute('data-message') || 'This action cannot be undone.',
                context: {
                    Records: String(count),
                    Scope: action === 'delete_all' ? 'All students' : 'Current filters'
                },
                requireTypedDelete: true,
                onConfirm: function (typed) {
                    massActionInput.value = action;
                    massConfirmInput.value = typed || 'DELETE';
                    massForm.submit();
                }
            });
        });
    });

    if (typedInput) {
        typedInput.addEventListener('input', syncTypedConfirmState);
        typedInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (confirmBtn && !confirmBtn.disabled) {
                    confirmBtn.click();
                }
            }
        });
    }

    if (modal) {
        modal.querySelectorAll('[data-close-confirm-modal]').forEach(function (el) {
            el.addEventListener('click', closeConfirmModal);
        });
    }

    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            let typed = '';
            if (requireTypedDelete) {
                typed = (typedInput && typedInput.value ? typedInput.value : '').trim().toUpperCase();
                if (typed !== 'DELETE') {
                    return;
                }
            }
            const action = pendingConfirm;
            closeConfirmModal();
            if (typeof action === 'function') {
                action(typed);
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) {
            closeConfirmModal();
        }
    });

    syncBatchBar();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
