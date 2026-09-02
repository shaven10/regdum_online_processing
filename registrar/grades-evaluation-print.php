<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/grades-evaluation.php';
requireRole('admin', 'registrar');

ensureGradesEvaluationSchema();
$user = currentUser();
$studentId = (int) ($_GET['student_user_id'] ?? 0);
$prospectusId = (int) ($_GET['prospectus_id'] ?? 0);
$autoPdf = !empty($_GET['pdf']);

$bundle = loadGradesEvaluationBundle($studentId, $prospectusId);
$student = $bundle['student'];
$prospectus = $bundle['prospectus'];
$evaluation = $bundle['evaluation'];
if ($prospectus) {
    $prospectusId = (int) $prospectus['id'];
}

if (!empty($_GET['export']) && $_GET['export'] === 'csv' && $student && $evaluation) {
    exportGradesEvaluationCsv($student, $evaluation);
}

$preparedName = trim(fullName($user));
$roleLabel = ucwords(str_replace('_', ' ', (string) ($user['role_name'] ?? '')));
$backQuery = array_filter([
    'student_user_id' => $studentId > 0 ? (string) $studentId : '',
    'prospectus_id'   => $prospectusId > 0 ? (string) $prospectusId : '',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grades Evaluation<?= $student ? ' — ' . e(studentRecordName($student)) : '' ?></title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body.enrollment-report-print-page { background:#eef1f5; margin:0; padding:1rem; font-family:Arial,Helvetica,sans-serif; color:#111; }
        .enrollment-report-print-toolbar { width:8.5in; max-width:100%; margin:0 auto 1rem; display:flex; justify-content:space-between; gap:.75rem; }
        .enrollment-report-print-sheet { width:8.5in; max-width:100%; margin:0 auto; background:#fff; border:1px solid #cbd5e1; padding:.5in .45in; box-sizing:border-box; }
        .enrollment-report-letterhead { position:relative; margin-bottom:.16in; text-align:center; min-height:.85in; }
        .enrollment-report-letterhead-brand { position:relative; display:block; min-height:.85in; }
        .enrollment-report-letterhead img { position:absolute; left:1.5in; top:50%; transform:translateY(-50%); width:.72in; height:.72in; object-fit:contain; }
        .enrollment-report-letterhead p { margin:0; line-height:1.2; }
        .enrollment-report-letterhead h1 { margin:.08rem 0 0; font-size:13pt; text-transform:uppercase; }
        .enrollment-report-letterhead h2 { margin:.28rem 0 0; font-size:11pt; text-transform:uppercase; }
        .grades-print-meta { margin:.15in 0; font-size:10pt; }
        .grades-print-table { width:100%; border-collapse:collapse; font-size:8.5pt; margin-bottom:.12in; }
        .grades-print-table th, .grades-print-table td { border:1px solid #111; padding:.08rem .12rem; }
        .grades-print-table thead th { text-align:center; }
        .grades-print-year { background:#f3f4f6; font-weight:700; text-align:left; }
        .grades-print-summary { width:3.2in; margin:.2in 0 0 auto; border-collapse:collapse; font-size:10pt; }
        .grades-print-summary td { border:1px solid #111; padding:.2rem .35rem; }
        .grades-print-table tr.is-failed,
        .grades-print-table tr.is-void { background:#fee2e2; }
        .grades-print-table tr.is-failed td,
        .grades-print-table tr.is-void td { color:#991b1b; }
        .enrollment-report-signoff { display:flex; justify-content:space-between; gap:1.5rem; margin-top:.4in; font-size:11pt; }
        .enrollment-report-signoff .caption { margin-bottom:.5in; }
        .enrollment-report-signoff .name { font-weight:700; text-transform:uppercase; border-bottom:1px solid #111; display:inline-block; min-width:2.5in; padding-bottom:.15rem; }
        @media print {
            @page { size: 8.5in 11in portrait; margin: 0.4in; }
            body.enrollment-report-print-page { background:#fff; padding:0; }
            .no-print { display:none !important; }
            .enrollment-report-print-sheet { width:8.5in; border:0; padding:0; }
        }
    </style>
</head>
<body class="enrollment-report-print-page">
    <div class="enrollment-report-print-toolbar no-print">
        <a href="grades-evaluation.php?<?= e(http_build_query($backQuery)) ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <?php if ($student && $evaluation && $prospectus): ?>
                <a href="grades-evaluation-print.php?<?= e(http_build_query($backQuery + ['export' => 'csv'])) ?>" class="btn btn-outline btn-sm"><i class="fas fa-file-csv"></i> Export CSV</a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>
    <div class="enrollment-report-print-sheet">
        <header class="enrollment-report-letterhead">
            <div class="enrollment-report-letterhead-brand">
                <?= renderAppLogo('default') ?>
                <div>
                    <p>Republic of the Philippines</p>
                    <h1>J.H. Cerilles State College</h1>
                    <p>Dumingag Campus</p>
                    <p>Dumingag, Zamboanga del Sur</p>
                </div>
            </div>
            <h2>Grades Evaluation</h2>
        </header>

        <?php if (!$student || !$evaluation): ?>
            <p>Student or prospectus not found.</p>
        <?php else: ?>
            <?php $summary = $evaluation['summary']; ?>
            <div class="grades-print-meta">
                <p><strong>Student:</strong> <?= e(studentRecordName($student)) ?> &nbsp; <strong>ID No.:</strong> <?= e($student['student_id'] ?: '—') ?></p>
                <p><strong>Course:</strong> <?= e($prospectus['program_code'] . ' — ' . $prospectus['program_name']) ?></p>
                <p>
                    <strong>Curriculum year:</strong> <?= e($prospectus['curriculum_year']) ?>
                    &nbsp; <strong>Year level:</strong> <?= e($student['year_level'] ?: '—') ?>
                    <?php
                    $termParts = [];
                    if (!empty($student['current_semester'])) {
                        $termParts[] = semesterLabel($student['current_semester']);
                    }
                    if (!empty($student['current_academic_year'])) {
                        $termParts[] = $student['current_academic_year'];
                    }
                    ?>
                    <?php if ($termParts !== []): ?>
                        &nbsp; <strong>Semester:</strong> <?= e(implode(' ', $termParts)) ?>
                    <?php endif; ?>
                </p>
            </div>

            <?php foreach ($evaluation['grouped'] as $yearBlock): ?>
                <?php
                $hasSubjects = false;
                foreach ($yearBlock['semesters'] as $semBlock) {
                    if (!empty($semBlock['subjects'])) { $hasSubjects = true; break; }
                }
                if (!$hasSubjects) continue;
                ?>
                <?php foreach ($yearBlock['semesters'] as $semBlock): ?>
                    <?php if (empty($semBlock['subjects'])) continue; ?>
                    <table class="grades-print-table">
                        <thead>
                            <tr><th colspan="9" class="grades-print-year"><?= e($yearBlock['label']) ?> — <?= e($semBlock['label']) ?></th></tr>
                            <tr>
                                <th>Course</th><th>No.</th><th>Descriptive Title</th><th>Units</th><th>Pre-req.</th><th>Grade</th><th>Remarks</th><th>Semester</th><th>SY taken</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($semBlock['subjects'] as $subject): ?>
                                <?php
                                $isTaken = !empty($subject['_is_taken']);
                                $isVoid = $isTaken && !empty($subject['_is_void']);
                                $voidReason = (string) ($subject['_void_reason'] ?? '');
                                $rowClass = gradesEvaluationRowClass($subject);
                                $displayGrade = $isTaken ? ($isVoid ? '—' : formatStudentGrade($subject['_grade'] ?? '')) : '';
                                $displayRemark = $isVoid ? 'Void' : ($isTaken ? gradeRemarkLabel($subject['_remarks'] ?? '') : '');
                                ?>
                                <tr class="<?= e($rowClass) ?>">
                                    <td><?= e($subject['course_code']) ?></td>
                                    <td><?= e($subject['course_no']) ?></td>
                                    <td><?= e($subject['title']) ?></td>
                                    <td style="text-align:center"><?= e(rtrim(rtrim(number_format((float) $subject['units'], 1), '0'), '.')) ?></td>
                                    <td><?= e($subject['prereq'] ?: '—') ?><?php if ($isVoid): ?><br><small><?= e(gradesEvaluationVoidReasonLabel($voidReason)) ?></small><?php endif; ?></td>
                                    <td style="text-align:center"><?= e($displayGrade !== '' ? $displayGrade : '—') ?></td>
                                    <td><?= e($displayRemark !== '' ? $displayRemark : '—') ?></td>
                                    <td><?= e($isTaken ? (prospectusSemesterOptions()[gradeTakenSemester($subject)] ?? '—') : '—') ?></td>
                                    <td style="text-align:center"><?= e($isTaken ? ($subject['_school_year'] ?: '—') : '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <table class="grades-print-summary">
                <tr><td>Units earned</td><td><?= number_format((float) $summary['units_earned'], 1) ?> / <?= number_format((float) $summary['units_total'], 1) ?></td></tr>
                <tr><td>Passed / Failed</td><td><?= (int) $summary['passed'] ?> / <?= (int) $summary['failed'] ?></td></tr>
                <tr><td>GWA</td><td><?= $summary['gwa'] !== null ? number_format((float) $summary['gwa'], 2) : '—' ?></td></tr>
            </table>

            <div class="enrollment-report-signoff">
                <div>
                    <p class="caption">Prepared by:</p>
                    <p class="name"><?= e($preparedName) ?></p>
                    <p><?= e($roleLabel) ?></p>
                </div>
                <div>
                    <p class="caption">Certified true and correct:</p>
                    <p class="name">&nbsp;</p>
                    <p>Registrar III</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($autoPdf): ?>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
    <?php endif; ?>
</body>
</html>
