<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/enrollment-report.php';
requireRole('admin', 'registrar');

$academicYear = trim($_GET['academic_year'] ?? defaultEnrollmentReportAcademicYear());
$semester = trim($_GET['semester'] ?? defaultEnrollmentReportSemester());
$campusId = (int) ($_GET['campus_id'] ?? 0);
$courseId = (int) ($_GET['course_id'] ?? 0);
$asOf = trim($_GET['as_of'] ?? date('Y-m-d'));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = normalizeStudentRecordsPerPage((int) ($_GET['per_page'] ?? ITEMS_PER_PAGE));
$export = $_GET['export'] ?? '';

$yearOptions = schoolYearOptions();
$semesterOptions = semesterOptions();
$campuses = getAllCampuses();
$programs = getAllAcademicPrograms();

if ($academicYear !== '' && !isset($yearOptions[$academicYear])) {
    $academicYear = defaultEnrollmentReportAcademicYear();
}
if ($semester !== '' && !array_key_exists($semester, $semesterOptions)) {
    $semester = defaultEnrollmentReportSemester();
}

$filters = [
    'academic_year' => $academicYear,
    'semester'      => $semester,
    'campus_id'     => $campusId,
    'course_id'     => $courseId,
    'as_of'         => $asOf,
];

$report = getEnrollmentListReport($filters);
$applied = $report['filters'];
$queryBase = array_filter([
    'academic_year' => $applied['academic_year'],
    'semester'      => $applied['semester'],
    'campus_id'     => $applied['campus_id'] > 0 ? (string) $applied['campus_id'] : '',
    'course_id'     => $applied['course_id'] > 0 ? (string) $applied['course_id'] : '',
    'as_of'         => $applied['as_of'] !== date('Y-m-d') ? $applied['as_of'] : '',
], static fn($v) => $v !== '' && $v !== null);

if ($export === 'csv') {
    $filename = 'enrolment_list_' . ($applied['academic_year'] ?: 'all') . '_' . ($applied['semester'] ?: 'all') . '.csv';
    exportCSV(enrollmentListExportHeaders(), enrollmentListExportRows($report), $filename);
}

$printQuery = $queryBase;
$listQuery = array_filter($queryBase + [
    'per_page' => $perPage !== ITEMS_PER_PAGE ? (string) $perPage : '',
], static fn($v) => $v !== '' && $v !== null);
$totalStudents = (int) $report['student_count'];
$pag = paginate($totalStudents, $page, $perPage);
$pageGroups = paginateEnrollmentListGroups($report['groups'], (int) $pag['offset'], (int) $pag['per_page']);
$from = $totalStudents > 0 ? $pag['offset'] + 1 : 0;
$to = min($pag['offset'] + $pag['per_page'], $totalStudents);
$pageTitle = 'Enrollment List';
$activeNav = 'enrollment-list';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="payment-report-page enrollment-report-page">
    <div class="card no-print">
        <div class="card-header">
            <div>
                <h2>Enrollment List per Course</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Active enrolled students grouped by course, listed alphabetically by name.
                </p>
            </div>
            <div class="payment-report-actions">
                <a href="enrollment-list-report-print.php?<?= e(http_build_query($printQuery)) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="fas fa-print"></i> Print
                </a>
                <a href="enrollment-list-report-print.php?<?= e(http_build_query(array_merge($printQuery, ['pdf' => '1']))) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
                <a href="?<?= e(http_build_query(array_merge($queryBase, ['export' => 'csv']))) ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar students-filter-bar" id="enrollmentListFilterForm">
                <div class="students-filter-fields" style="grid-template-columns: repeat(5, minmax(0, 1fr));">
                    <select name="academic_year" aria-label="School year">
                        <option value="">All school years</option>
                        <?php foreach ($yearOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $applied['academic_year'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="semester" aria-label="Semester">
                        <option value="">All terms</option>
                        <?php foreach ($semesterOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $applied['semester'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="course_id" aria-label="Course">
                        <option value="">All courses</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= (int) $program['id'] ?>" <?= $applied['course_id'] === (int) $program['id'] ? 'selected' : '' ?>>
                                <?= e(($program['code'] ?? '') !== '' ? $program['code'] . ' — ' . $program['name'] : $program['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="campus_id" aria-label="Campus">
                        <option value="">All campuses</option>
                        <?php foreach ($campuses as $campus): ?>
                            <option value="<?= (int) $campus['id'] ?>" <?= $applied['campus_id'] === (int) $campus['id'] ? 'selected' : '' ?>><?= e($campus['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="date" name="as_of" value="<?= e($applied['as_of']) ?>" aria-label="As of date" title="Printed as-of date">
                </div>
                <div class="students-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply</button>
                    <a href="enrollment-list-report.php" class="btn btn-outline btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2><?= e($report['heading']['title']) ?> SY: <?= e($report['heading']['sy']) ?></h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    <?= e($report['heading']['semester']) ?>
                    · As of <?= e($report['heading']['as_of']) ?>
                    · <?= number_format($totalStudents) ?> student<?= $totalStudents === 1 ? '' : 's' ?>
                    <?php if ($totalStudents > 0): ?>
                        · Showing <?= number_format($from) ?>–<?= number_format($to) ?>
                    <?php endif; ?>
                    <?php if ($report['course_label'] !== ''): ?>
                        · <?= e($report['course_label']) ?>
                    <?php endif; ?>
                    <?php if ($report['campus_name'] !== ''): ?>
                        · <?= e($report['campus_name']) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($report['groups'])): ?>
                <div class="empty-state"><i class="fas fa-user-slash"></i><p>No active enrolled students match these filters.</p></div>
            <?php else: ?>
                <?php foreach ($pageGroups as $group): ?>
                    <section class="enrollment-list-course">
                        <div class="enrollment-list-course-head">
                            <div>
                                <h3><?= e($group['label']) ?><?php if ($group['name'] !== '' && $group['name'] !== $group['label']): ?> — <?= e($group['name']) ?><?php endif; ?></h3>
                                <p class="text-muted"><?= e($group['college']) ?></p>
                            </div>
                            <p class="enrollment-list-course-meta">
                                <?= (int) $group['total'] ?> student<?= (int) $group['total'] === 1 ? '' : 's' ?>
                                · M <?= (int) $group['male'] ?>
                                · F <?= (int) $group['female'] ?>
                            </p>
                        </div>
                        <div class="table-responsive students-table-wrap">
                            <table class="data-table enrollment-list-table">
                                <thead>
                                    <tr>
                                        <th>No.</th>
                                        <th>ID No.</th>
                                        <th>Last Name</th>
                                        <th>First Name</th>
                                        <th>Middle Name</th>
                                        <th>Sex</th>
                                        <th>Year</th>
                                        <th>Major</th>
                                        <th>Email</th>
                                        <th>Mobile #</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group['students'] as $student): ?>
                                        <tr>
                                            <td><?= (int) ($student['row_number'] ?? 0) ?></td>
                                            <td><?= e($student['student_id'] ?: '—') ?></td>
                                            <td><?= e($student['display_last'] ?: '—') ?></td>
                                            <td><?= e($student['display_first'] ?: '—') ?></td>
                                            <td><?= e($student['display_middle'] ?: '—') ?></td>
                                            <td><?= e($student['sex_label'] ?? '—') ?></td>
                                            <td><?= e($student['year_level'] ?: '—') ?></td>
                                            <td><?= e($student['major'] ?: '—') ?></td>
                                            <td><?= e($student['email'] ?: '—') ?></td>
                                            <td><?= e($student['phone'] ?: '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>
                <?= renderStudentRecordsPagination($pag, '?' . http_build_query($listQuery), $perPage, 'enrollmentListFilterForm') ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.students-per-page select').forEach(function (el) {
    el.addEventListener('change', function () {
        if (el.form) el.form.submit();
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
