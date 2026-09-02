<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/enrollment-report.php';
requireRole('admin', 'registrar');

$academicYear = trim($_GET['academic_year'] ?? defaultEnrollmentReportAcademicYear());
$semester = trim($_GET['semester'] ?? defaultEnrollmentReportSemester());
$campusId = (int) ($_GET['campus_id'] ?? 0);
$asOf = trim($_GET['as_of'] ?? date('Y-m-d'));
$export = $_GET['export'] ?? '';

$yearOptions = schoolYearOptions();
$semesterOptions = semesterOptions();
$campuses = getAllCampuses();

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
    'as_of'         => $asOf,
];

$report = getEnrollmentByCourseReport($filters);
$applied = $report['filters'];
$queryBase = array_filter([
    'academic_year' => $applied['academic_year'],
    'semester'      => $applied['semester'],
    'campus_id'     => $applied['campus_id'] > 0 ? (string) $applied['campus_id'] : '',
    'as_of'         => $applied['as_of'] !== date('Y-m-d') ? $applied['as_of'] : '',
], static fn($v) => $v !== '' && $v !== null);

if ($export === 'csv') {
    $filename = 'enrolment_report_' . ($applied['academic_year'] ?: 'all') . '_' . ($applied['semester'] ?: 'all') . '.csv';
    exportCSV(enrollmentReportExportHeaders($report['year_columns']), enrollmentReportExportRows($report), $filename);
}

$printQuery = $queryBase;
$pageTitle = 'Enrollment by Course';
$activeNav = 'enrollment-report';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="payment-report-page enrollment-report-page">
    <div class="card no-print">
        <div class="card-header">
            <div>
                <h2>Enrollment by Course</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Enrolment by course and year level for active enrolled students, matching the official registrar template.
                </p>
            </div>
            <div class="payment-report-actions">
                <a href="enrollment-report-print.php?<?= e(http_build_query($printQuery)) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="fas fa-print"></i> Print
                </a>
                <a href="enrollment-report-print.php?<?= e(http_build_query(array_merge($printQuery, ['pdf' => '1']))) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
                <a href="?<?= e(http_build_query(array_merge($queryBase, ['export' => 'csv']))) ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar students-filter-bar">
                <div class="students-filter-fields" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
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
                    <a href="enrollment-report.php" class="btn btn-outline btn-sm">Reset</a>
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
                    · <?= number_format((int) $report['student_count']) ?> active student<?= (int) $report['student_count'] === 1 ? '' : 's' ?>
                    <?php if ($report['campus_name'] !== ''): ?>
                        · <?= e($report['campus_name']) ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="card-body">
            <?php if (!empty($report['summary'])): ?>
                <div class="enrollment-report-summary">
                    <?php foreach ($report['summary'] as $item): ?>
                        <div class="enrollment-report-summary-item">
                            <span><?= e($item['label']) ?></span>
                            <strong><?= e(number_format((int) $item['total'])) ?></strong>
                        </div>
                    <?php endforeach; ?>
                    <div class="enrollment-report-summary-item enrollment-report-summary-grand">
                        <span>GRAND TOTAL</span>
                        <strong><?= e(number_format((int) $report['grand_total'])) ?></strong>
                    </div>
                </div>
            <?php endif; ?>

            <div class="table-responsive students-table-wrap">
                <?php renderEnrollmentReportMatrix($report, 'screen'); ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
