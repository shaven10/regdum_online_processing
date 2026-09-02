<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/enrollment-report.php';
requireRole('admin', 'registrar');

$user = currentUser();
$academicYear = trim($_GET['academic_year'] ?? defaultEnrollmentReportAcademicYear());
$semester = trim($_GET['semester'] ?? defaultEnrollmentReportSemester());
$campusId = (int) ($_GET['campus_id'] ?? 0);
$asOf = trim($_GET['as_of'] ?? date('Y-m-d'));
$autoPdf = !empty($_GET['pdf']);

$report = getEnrollmentByCourseReport([
    'academic_year' => $academicYear,
    'semester'      => $semester,
    'campus_id'     => $campusId,
    'as_of'         => $asOf,
]);
$applied = $report['filters'];
$heading = $report['heading'];

$backQuery = array_filter([
    'academic_year' => $applied['academic_year'],
    'semester'      => $applied['semester'],
    'campus_id'     => $applied['campus_id'] > 0 ? (string) $applied['campus_id'] : '',
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
    <title>Enrolment Report — <?= e($heading['sy']) ?> <?= e($heading['semester']) ?></title>
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
        .enrollment-report-letterhead .republic {
            font-size: 10pt;
        }
        .enrollment-report-letterhead h1 {
            margin: .08rem 0 0;
            font-size: 13pt;
            letter-spacing: .01em;
            text-transform: uppercase;
            text-align: center;
            line-height: 1.15;
        }
        .enrollment-report-letterhead .former,
        .enrollment-report-letterhead .address {
            font-size: 10pt;
        }
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
        .enrollment-report-print-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 9pt;
        }
        .enrollment-report-print-table th,
        .enrollment-report-print-table td {
            border: 1px solid #111;
            padding: .12rem .06rem;
            text-align: center;
            vertical-align: middle;
            overflow: hidden;
        }
        .enrollment-report-print-table .enrollment-report-course-col {
            text-align: left;
            width: 22%;
            white-space: normal;
            word-break: break-word;
            padding-left: .18rem;
        }
        .enrollment-report-print-table .enrollment-report-mf-col {
            width: 6.2%;
        }
        .enrollment-report-print-table .enrollment-report-total-col {
            width: 8%;
        }
        .enrollment-report-print-table .enrollment-report-grand-col {
            width: 9.5%;
            white-space: normal;
            line-height: 1.1;
            overflow-wrap: anywhere;
        }
        .enrollment-report-print-table thead th {
            background: #fff;
            font-weight: 700;
            font-size: 8.5pt;
            line-height: 1.15;
            white-space: normal;
        }
        .enrollment-report-print-table thead .enrollment-report-grand-col {
            font-size: 8pt;
        }
        .enrollment-report-college-header td,
        .enrollment-report-section-banner td {
            text-align: left;
            font-weight: 700;
            background: #f3f4f6;
            font-size: 11pt;
        }
        .enrollment-report-section-total td {
            font-weight: 700;
        }
        .enrollment-report-major .enrollment-report-course-col {
            padding-left: .7rem;
        }
        .enrollment-report-print-summary {
            margin-top: .35in;
            width: 2.8in;
            margin-left: auto;
            border: 1px solid #111;
            border-collapse: collapse;
            font-size: 12pt;
        }
        .enrollment-report-print-summary td {
            border: 1px solid #111;
            padding: .28rem .4rem;
        }
        .enrollment-report-print-summary .label { text-align: left; }
        .enrollment-report-print-summary .num { text-align: right; font-weight: 700; }
        .enrollment-report-print-summary .grand td { font-weight: 800; }
        .enrollment-report-signoff {
            display: flex;
            justify-content: space-between;
            gap: 1.5rem;
            margin-top: .45in;
            font-size: 12pt;
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
        <a href="enrollment-report.php?<?= e(http_build_query($backQuery)) ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left"></i> Back to Report
        </a>
        <div class="payment-report-actions">
            <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
            <button type="button" class="btn btn-primary btn-sm" id="enrollmentReportPdfBtn">
                <i class="fas fa-file-pdf"></i> Save as PDF
            </button>
        </div>
    </div>

    <div class="enrollment-report-print-sheet" id="enrollmentReportPrintSheet">
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
            <?php if ($report['campus_name'] !== ''): ?>
                <p class="as-of"><?= e($report['campus_name']) ?></p>
            <?php endif; ?>
        </header>

        <?php renderEnrollmentReportMatrix($report, 'print'); ?>

        <?php if (!empty($report['summary'])): ?>
            <table class="enrollment-report-print-summary">
                <tbody>
                    <?php foreach ($report['summary'] as $item): ?>
                        <tr>
                            <td class="label"><?= e($item['label']) ?></td>
                            <td class="num"><?= e(number_format((int) $item['total'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="grand">
                        <td class="label">GRAND TOTAL</td>
                        <td class="num"><?= e(number_format((int) $report['grand_total'])) ?></td>
                    </tr>
                </tbody>
            </table>
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
        var btn = document.getElementById('enrollmentReportPdfBtn');
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
