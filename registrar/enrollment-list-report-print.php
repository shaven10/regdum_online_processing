<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/enrollment-report.php';
requireRole('admin', 'registrar');

$user = currentUser();
$academicYear = trim($_GET['academic_year'] ?? defaultEnrollmentReportAcademicYear());
$semester = trim($_GET['semester'] ?? defaultEnrollmentReportSemester());
$campusId = (int) ($_GET['campus_id'] ?? 0);
$courseId = (int) ($_GET['course_id'] ?? 0);
$asOf = trim($_GET['as_of'] ?? date('Y-m-d'));
$autoPdf = !empty($_GET['pdf']);

$report = getEnrollmentListReport([
    'academic_year' => $academicYear,
    'semester'      => $semester,
    'campus_id'     => $campusId,
    'course_id'     => $courseId,
    'as_of'         => $asOf,
]);
$applied = $report['filters'];
$heading = $report['heading'];

$backQuery = array_filter([
    'academic_year' => $applied['academic_year'],
    'semester'      => $applied['semester'],
    'campus_id'     => $applied['campus_id'] > 0 ? (string) $applied['campus_id'] : '',
    'course_id'     => $applied['course_id'] > 0 ? (string) $applied['course_id'] : '',
    'as_of'         => $applied['as_of'] !== date('Y-m-d') ? $applied['as_of'] : '',
], static fn($v) => $v !== '' && $v !== null);

$preparedName = trim(fullName($user));
$roleLabel = ucwords(str_replace('_', ' ', (string) ($user['role_name'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrolment List — <?= e($heading['sy']) ?> <?= e($heading['semester']) ?></title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body.enrollment-report-print-page {
            background: #eef1f5;
            margin: 0;
            padding: 1rem;
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
            font-size: 12pt;
        }
        .enrollment-report-print-toolbar {
            width: 8.5in;
            max-width: 100%;
            margin: 0 auto 1rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: .75rem;
        }
        .enrollment-report-print-sheet {
            width: 8.5in;
            min-height: 11in;
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #cbd5e1;
            padding: 0.5in 0.45in 0.55in;
            box-sizing: border-box;
            position: relative;
        }
        .enrollment-report-letterhead {
            position: relative;
            margin-bottom: 0.16in;
            text-align: center;
            min-height: 0.85in;
        }
        .enrollment-report-letterhead-brand {
            position: relative;
            display: block;
            min-height: 0.85in;
        }
        .enrollment-report-letterhead img {
            position: absolute;
            left: 1.5in;
            top: 50%;
            transform: translateY(-50%);
            width: 0.72in;
            height: 0.72in;
            object-fit: contain;
        }
        .enrollment-report-letterhead-text {
            width: 100%;
            padding: 0;
            box-sizing: border-box;
            text-align: center;
        }
        .enrollment-report-letterhead p {
            margin: 0;
            line-height: 1.2;
            text-align: center;
        }
        .enrollment-report-letterhead .republic { font-size: 10pt; }
        .enrollment-report-letterhead h1 {
            margin: .08rem 0 0;
            font-size: 13pt;
            letter-spacing: .01em;
            text-transform: uppercase;
            text-align: center;
            line-height: 1.15;
        }
        .enrollment-report-letterhead .former,
        .enrollment-report-letterhead .address { font-size: 10pt; }
        .enrollment-report-letterhead h2 {
            margin: .28rem 0 0;
            font-size: 11pt;
            text-transform: uppercase;
            text-align: center;
            line-height: 1.2;
            padding: 0 .1in;
        }
        .enrollment-report-letterhead .term {
            margin-top: .08rem;
            font-weight: 700;
            font-size: 11pt;
            text-align: center;
        }
        .enrollment-report-letterhead .as-of {
            margin-top: .08rem;
            font-size: 10pt;
            text-align: center;
        }
        .enrollment-list-print-course + .enrollment-list-print-course {
            page-break-before: always;
            break-before: page;
            padding-top: .1in;
        }
        .enrollment-list-print-course-head {
            margin: .12in 0 .08in;
        }
        .enrollment-list-print-course-head h3 {
            margin: 0;
            font-size: 11pt;
            text-transform: uppercase;
        }
        .enrollment-list-print-course-head p {
            margin: .08rem 0 0;
            font-size: 9pt;
        }
        .enrollment-list-print-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 9pt;
        }
        .enrollment-list-print-table th,
        .enrollment-list-print-table td {
            border: 1px solid #111;
            padding: .1rem .12rem;
            vertical-align: middle;
        }
        .enrollment-list-print-table thead {
            display: table-header-group;
        }
        .enrollment-list-print-table thead th {
            background: #fff;
            font-weight: 700;
            font-size: 8.5pt;
            text-align: center;
        }
        .enrollment-list-print-table .col-no { width: 6%; text-align: center; }
        .enrollment-list-print-table .col-id { width: 13%; text-align: center; }
        .enrollment-list-print-table .col-last { width: 18%; text-align: left; }
        .enrollment-list-print-table .col-first { width: 18%; text-align: left; }
        .enrollment-list-print-table .col-middle { width: 16%; text-align: left; }
        .enrollment-list-print-table .col-sex { width: 6%; text-align: center; }
        .enrollment-list-print-table .col-year { width: 10%; text-align: center; }
        .enrollment-list-print-table .col-major { width: 13%; text-align: left; word-break: break-word; }
        .enrollment-report-signoff {
            display: flex;
            justify-content: space-between;
            gap: 1.5rem;
            margin-top: .45in;
            font-size: 12pt;
            page-break-inside: avoid;
        }
        .enrollment-report-signoff div { min-width: 2.6in; }
        .enrollment-report-signoff .caption { margin-bottom: .55in; }
        .enrollment-report-signoff .name {
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 1px solid #111;
            display: inline-block;
            min-width: 2.5in;
            padding-bottom: .15rem;
            font-size: 12pt;
        }
        .enrollment-report-signoff .title { margin-top: .15rem; font-size: 12pt; }
        .enrollment-list-print-empty {
            margin-top: .4in;
            text-align: center;
            font-size: 11pt;
        }
        @media print {
            @page {
                size: 8.5in 11in portrait;
                margin: 0.4in;
            }
            body.enrollment-report-print-page {
                background: #fff;
                padding: 0;
            }
            .no-print { display: none !important; }
            .enrollment-report-print-sheet {
                width: 8.5in;
                min-height: auto;
                border: 0;
                padding: 0;
                box-shadow: none;
            }
        }
    </style>
</head>
<body class="enrollment-report-print-page">
    <div class="enrollment-report-print-toolbar no-print">
        <a href="enrollment-list-report.php?<?= e(http_build_query($backQuery)) ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left"></i> Back to Report
        </a>
        <div class="payment-report-actions">
            <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
            <button type="button" class="btn btn-primary btn-sm" id="enrollmentListPdfBtn">
                <i class="fas fa-file-pdf"></i> Save as PDF
            </button>
        </div>
    </div>

    <div class="enrollment-report-print-sheet" id="enrollmentListPrintSheet">
        <header class="enrollment-report-letterhead">
            <div class="enrollment-report-letterhead-brand">
                <?= renderAppLogo('default') ?>
                <div class="enrollment-report-letterhead-text">
                    <p class="republic">Republic of the Philippines</p>
                    <h1>J.H. Cerilles State College</h1>
                    <p class="former">Dumingag Campus</p>
                    <p class="address">Dumingag, Zamboanga del Sur</p>
                </div>
            </div>
            <h2><?= e($heading['title']) ?> SY: <?= e($heading['sy']) ?></h2>
            <p class="term"><?= e($heading['semester']) ?></p>
            <p class="as-of">As of <?= e($heading['as_of']) ?></p>
            <?php if ($report['course_label'] !== ''): ?>
                <p class="as-of"><?= e($report['course_label']) ?></p>
            <?php endif; ?>
            <?php if ($report['campus_name'] !== ''): ?>
                <p class="as-of"><?= e($report['campus_name']) ?></p>
            <?php endif; ?>
        </header>

        <?php if (empty($report['groups'])): ?>
            <p class="enrollment-list-print-empty">No active enrolled students match these filters.</p>
        <?php else: ?>
            <?php foreach ($report['groups'] as $group): ?>
                <section class="enrollment-list-print-course">
                    <div class="enrollment-list-print-course-head">
                        <h3><?= e($group['label']) ?><?php if ($group['name'] !== '' && $group['name'] !== $group['label']): ?> — <?= e($group['name']) ?><?php endif; ?></h3>
                        <p>
                            <?= e($group['college']) ?>
                            · <?= (int) $group['total'] ?> student<?= (int) $group['total'] === 1 ? '' : 's' ?>
                            · M <?= (int) $group['male'] ?>
                            · F <?= (int) $group['female'] ?>
                        </p>
                    </div>
                    <table class="enrollment-list-print-table">
                        <thead>
                            <tr>
                                <th class="col-no">No.</th>
                                <th class="col-id">ID No.</th>
                                <th class="col-last">Last Name</th>
                                <th class="col-first">First Name</th>
                                <th class="col-middle">Middle Name</th>
                                <th class="col-sex">Sex</th>
                                <th class="col-year">Year</th>
                                <th class="col-major">Major</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group['students'] as $i => $student): ?>
                                <tr>
                                    <td class="col-no"><?= $i + 1 ?></td>
                                    <td class="col-id"><?= e($student['student_id'] ?: '—') ?></td>
                                    <td class="col-last"><?= e($student['display_last'] ?: '—') ?></td>
                                    <td class="col-first"><?= e($student['display_first'] ?: '—') ?></td>
                                    <td class="col-middle"><?= e($student['display_middle'] ?: '—') ?></td>
                                    <td class="col-sex"><?= e($student['sex_label'] ?? '—') ?></td>
                                    <td class="col-year"><?= e($student['year_level'] ?: '—') ?></td>
                                    <td class="col-major"><?= e($student['major'] ?: '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="enrollment-report-signoff">
            <div>
                <p class="caption">Prepared by:</p>
                <p class="name"><?= e($preparedName) ?></p>
                <p class="title"><?= e($roleLabel) ?></p>
            </div>
            <div>
                <p class="caption">Certified true and correct:</p>
                <p class="name">&nbsp;</p>
                <p class="title">Registrar III</p>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var btn = document.getElementById('enrollmentListPdfBtn');
        if (btn) {
            btn.addEventListener('click', function () { window.print(); });
        }
        <?php if ($autoPdf): ?>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 350);
        });
        <?php endif; ?>
    })();
    </script>
</body>
</html>
