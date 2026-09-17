<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/grades-evaluation.php';
require_once __DIR__ . '/../includes/student-view.php';
requireRole('admin', 'registrar');

ensureGradesEvaluationSchema();
$user = currentUser();

$search = trim((string) ($_GET['search'] ?? ''));
$studentId = (int) ($_GET['student_user_id'] ?? $_POST['student_user_id'] ?? 0);
$prospectusId = (int) ($_GET['prospectus_id'] ?? $_POST['prospectus_id'] ?? 0);
$student = $studentId > 0 ? loadStudentForGradesEvaluation($studentId) : null;
if ($student && !studentAllowsGradesEvaluation($student)) {
    setFlash('error', 'Grades evaluation is only available for enrolled (active) students. Graduated and inactive students are excluded.');
    redirect(APP_URL . '/registrar/grades-evaluation.php');
}
$searchResults = strlen($search) >= 2 ? searchStudentsForGradesEvaluation($search) : [];

$prospectus = null;
$programProspectuses = [];
if ($student) {
    $programId = resolveStudentProgramId($student);
    if ($programId > 0) {
        $programProspectuses = getProspectusesForProgram($programId);
        if ($prospectusId > 0) {
            $prospectus = getProspectusById($prospectusId);
            if (!$prospectus || (int) $prospectus['program_id'] !== $programId) {
                $prospectus = null;
                $prospectusId = 0;
            }
        }
        if (!$prospectus) {
            $prospectus = getActiveProspectusForProgram($programId);
            $prospectusId = (int) ($prospectus['id'] ?? 0);
        }
    }
}

$gradesEvalRedirect = APP_URL . '/registrar/grades-evaluation.php';
if ($studentId > 0) {
    $redirectQuery = ['student_user_id' => $studentId];
    if ($prospectusId > 0) {
        $redirectQuery['prospectus_id'] = $prospectusId;
    }
    $gradesEvalRedirect .= '?' . http_build_query($redirectQuery);
}
handleStudentRecordsClearGradesPost($gradesEvalRedirect);

$pasteReview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && $student && $prospectus) {
    $action = (string) ($_POST['action'] ?? '');
    if (in_array($action, ['review_paste', 'apply_paste', 'paste_grades'], true)) {
        $pasteText = (string) ($_POST['paste_text'] ?? '');
        $subjects = getProspectusSubjects((int) $prospectus['id']);
        if ($pasteText === '') {
            setFlash('error', 'Paste the evaluation sheet first, then review the subjects.');
        } else {
            $parsedPaste = evaluatePastedSubjects($pasteText, $subjects, [
                'single_student' => true,
                'student'        => $student,
            ], postedSubjectMappings());
            if ($action === 'apply_paste') {
                if ((int) ($parsedPaste['ok'] ?? 0) > 0) {
                    $result = saveParsedGradeEntries($parsedPaste, (int) $user['id'], [
                        'prospectus_id' => (int) $prospectus['id'],
                        'school_year'   => trim((string) ($_POST['school_year'] ?? '')),
                    ]);
                    setFlash('success', 'Applied ' . (int) $result['saved'] . ' matching grade(s) after confirmation review.');
                    redirect(APP_URL . '/registrar/grades-evaluation.php?student_user_id=' . (int) $student['id'] . '&prospectus_id=' . (int) $prospectus['id']);
                }
                setFlash('error', $parsedPaste['warnings'][0] ?? 'No matching grades were ready to apply. Review unmatched subjects first.');
            }
            $pasteReview = $parsedPaste;
        }
    } else {
        $grades = [];
        foreach (($_POST['grade'] ?? []) as $subjectId => $grade) {
            $grades[(int) $subjectId] = [
                'grade'       => $grade,
                'remarks'     => $_POST['remarks'][$subjectId] ?? '',
                'school_year' => $_POST['taken_sy'][$subjectId] ?? '',
                'semester'    => $_POST['taken_sem'][$subjectId] ?? '',
            ];
        }
        $result = saveStudentSubjectGrades((int) $student['id'], (int) $prospectus['id'], $grades, (int) $user['id']);
        if (!empty($result['ok'])) {
            setFlash('success', 'Grades evaluation saved.');
            redirect(APP_URL . '/registrar/grades-evaluation.php?student_user_id=' . (int) $student['id'] . '&prospectus_id=' . (int) $prospectus['id']);
        }
        setFlash('error', $result['error'] ?? 'Unable to save grades.');
    }
}

$evaluation = null;
if ($student && $prospectus) {
    $subjects = getProspectusSubjects((int) $prospectus['id']);
    $gradeMap = getStudentSubjectGrades((int) $student['id'], (int) $prospectus['id']);
    $evaluation = buildStudentGradesEvaluation($student, $prospectus, $subjects, $gradeMap);
}

if (($_GET['export'] ?? '') === 'csv' && $student && $evaluation) {
    exportGradesEvaluationCsv($student, $evaluation);
}

$yearOptions = schoolYearOptions();
$pageTitle = 'Grades Evaluation';
$activeNav = 'grades-evaluation';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card grades-eval-page">
    <div class="card-header">
        <div>
            <h2>Grades Evaluation</h2>
            <p class="text-muted" style="margin:.35rem 0 0">
                Evaluate a student against the official prospectus for their course. Subjects follow the year and semester layout of that prospectus.
            </p>
        </div>
        <div class="card-header-actions">
            <a href="grade-entry.php<?= $student ? '?student_user_id=' . (int) $student['id'] : '' ?>" class="btn btn-outline btn-sm"><i class="fas fa-paste"></i> Enter Grades</a>
            <a href="prospectuses.php" class="btn btn-outline btn-sm"><i class="fas fa-book"></i> Course Prospectus</a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar onsite-student-search">
            <input type="search" name="search" value="<?= e($search) ?>" placeholder="Search student ID or name…" minlength="2" <?= $student ? '' : 'autofocus' ?>>
            <?php if ($studentId > 0): ?>
                <input type="hidden" name="student_user_id" value="<?= $studentId ?>">
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
            <?php if ($student || $search !== ''): ?>
                <a href="grades-evaluation.php" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (strlen($search) >= 2 && empty($searchResults)): ?>
            <div class="alert alert-warning">No matching students found.</div>
        <?php elseif (!empty($searchResults) && !$student): ?>
            <div class="table-wrap onsite-student-results">
                <table class="data-table data-table-responsive">
                    <thead>
                        <tr>
                            <th>ID No.</th>
                            <th>Name</th>
                            <th>Course</th>
                            <th>Year</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchResults as $row): ?>
                            <tr>
                                <td data-label="ID No."><strong><?= e($row['student_id'] ?: '—') ?></strong></td>
                                <td data-label="Name"><?= e(studentRecordName($row)) ?></td>
                                <td data-label="Course"><?= e($row['program_code'] ?: ($row['course'] ?: '—')) ?></td>
                                <td data-label="Year"><?= e($row['year_level'] ?: '—') ?></td>
                                <td data-label="Action">
                                    <a class="btn btn-sm btn-primary" href="?student_user_id=<?= (int) $row['id'] ?>">Evaluate</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (!$student): ?>
            <p class="text-muted">Search for an active student to open their grades evaluation against the course prospectus.</p>
        <?php endif; ?>

        <?php if ($student): ?>
            <div class="grades-eval-student">
                <div>
                    <h3><?= e(studentRecordName($student)) ?></h3>
                    <p class="text-muted" style="margin:.25rem 0 0">
                        <?= e($student['student_id'] ?: 'No student ID') ?>
                        · <?= e($student['program_code'] ?: ($student['course'] ?: 'No course')) ?>
                        <?php if (!empty($student['program_name']) && ($student['program_code'] ?? '') !== ''): ?>
                            — <?= e($student['program_name']) ?>
                        <?php endif; ?>
                        · <?= e($student['year_level'] ?: 'No year level') ?>
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
                            · <?= e(implode(' ', $termParts)) ?>
                        <?php endif; ?>
                        · <?= e(enrollmentStatusLabel($student['enrollment_status'] ?? null)) ?>
                    </p>
                </div>
            </div>

            <?php if ($programId <= 0): ?>
                <div class="alert alert-warning">This student has no course on file. Update the student record before evaluating grades.</div>
            <?php elseif (!$prospectus): ?>
                <div class="alert alert-warning">
                    No prospectus is set for <?= e($student['program_code'] ?: 'this course') ?>.
                    <a href="prospectuses.php">Set the course prospectus</a> first.
                </div>
            <?php else: ?>
                <?php if (count($programProspectuses) > 1): ?>
                    <form method="GET" class="filter-bar" style="margin-bottom:1rem">
                        <input type="hidden" name="student_user_id" value="<?= (int) $student['id'] ?>">
                        <label class="students-per-page">Curriculum
                            <select name="prospectus_id" onchange="this.form.submit()" aria-label="Prospectus">
                                <?php foreach ($programProspectuses as $option): ?>
                                    <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === (int) $prospectus['id'] ? 'selected' : '' ?>>
                                        <?= e($option['curriculum_year']) ?><?= !empty($option['is_active']) ? ' (active)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </form>
                <?php endif; ?>

                <?php $summary = $evaluation['summary']; ?>
                <div class="enrollment-report-summary grades-eval-summary">
                    <div class="enrollment-report-summary-item"><span>Subjects</span><strong><?= (int) $summary['subjects'] ?></strong></div>
                    <div class="enrollment-report-summary-item"><span>Passed</span><strong><?= (int) $summary['passed'] ?></strong></div>
                    <div class="enrollment-report-summary-item"><span>Failed</span><strong><?= (int) $summary['failed'] ?></strong></div>
                    <div class="enrollment-report-summary-item"><span>Void</span><strong><?= (int) ($summary['void'] ?? 0) ?></strong></div>
                    <div class="enrollment-report-summary-item"><span>No grade</span><strong><?= (int) $summary['no_grade'] ?></strong></div>
                    <div class="enrollment-report-summary-item"><span>Units earned</span><strong><?= number_format((float) $summary['units_earned'], 1) ?> / <?= number_format((float) $summary['units_total'], 1) ?></strong></div>
                    <div class="enrollment-report-summary-item enrollment-report-summary-grand"><span>GWA</span><strong><?= $summary['gwa'] !== null ? number_format((float) $summary['gwa'], 2) : '—' ?></strong></div>
                </div>
                <p class="text-muted grades-eval-scale">Grading: 1.00–3.00 Passed · 5.00 Failed · INC Incomplete · DRP Dropped. Red rows indicate failed grades or void subjects (prerequisite not taken, failed, or taken out of order).</p>

                <div class="payment-report-actions grades-eval-export-actions">
                    <span class="grades-eval-export-label">Print / Export</span>
                    <a class="btn btn-outline btn-sm" target="_blank"
                       href="<?= e(gradesEvaluationPrintUrl((int) $student['id'], (int) $prospectus['id'])) ?>">
                        <i class="fas fa-print"></i> Print
                    </a>
                    <a class="btn btn-outline btn-sm" target="_blank"
                       href="<?= e(gradesEvaluationPrintUrl((int) $student['id'], (int) $prospectus['id'], ['pdf' => '1'])) ?>">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </a>
                    <a class="btn btn-outline btn-sm"
                       href="?<?= e(http_build_query(['student_user_id' => (int) $student['id'], 'prospectus_id' => (int) $prospectus['id'], 'export' => 'csv'])) ?>">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                    <?= renderClearStudentGradesFormSm(
                        (int) $student['id'],
                        studentRecordName($student),
                        isset($student['enrollment_status']) ? (string) $student['enrollment_status'] : null
                    ) ?>
                </div>

                <form method="POST" class="grades-paste-panel">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="review_paste">
                    <input type="hidden" name="student_user_id" value="<?= (int) $student['id'] ?>">
                    <input type="hidden" name="prospectus_id" value="<?= (int) $prospectus['id'] ?>">
                    <label for="paste_text">Paste evaluation sheet</label>
                    <p class="text-muted" style="margin:.2rem 0 .55rem">
                        Copy from Excel using <strong>Course #, Course Description, Preq, Lec, Lab, Units, Grade, Re-Ex</strong>.
                        Keep semester rows such as <strong>First Semester - S.Y. 2026-2027</strong>.
                        Grade <strong>0</strong> is treated as not taken. <strong>INC</strong> with a Re-Ex rating uses the Re-Ex.
                        Pasted subjects are compared with the prospectus before grades are applied.
                    </p>
                    <textarea id="paste_text" name="paste_text" rows="10" placeholder="Course #	Course Description	Preq	Lec	Lab	Units	Grade	Re-Ex&#10;First Semester - S.Y. 2026-2027&#10;Crim Pract 1	Internship (On the Job Training 1)	-	3	0	3	0	&#10;CDI 4	Traffic Mgt. & Accident Investigation with Driving	CDI 1	3	0	3	INC	2.5"><?= e((string) ($_POST['paste_text'] ?? '')) ?></textarea>
                    <div class="form-actions" style="margin-top:.75rem">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-code-compare"></i> Review subjects</button>
                    </div>
                </form>

                <?php if ($pasteReview): ?>
                    <?php
                    renderGradesPasteReview($pasteReview, $subjects, [
                        'student_user_id' => (string) (int) $student['id'],
                        'prospectus_id'   => (string) (int) $prospectus['id'],
                        'paste_text'      => (string) ($_POST['paste_text'] ?? ''),
                    ]);
                    ?>
                <?php endif; ?>

                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="student_user_id" value="<?= (int) $student['id'] ?>">
                    <input type="hidden" name="prospectus_id" value="<?= (int) $prospectus['id'] ?>">

                    <?php foreach ($evaluation['grouped'] as $yearKey => $yearBlock): ?>
                        <?php
                        $hasSubjects = false;
                        foreach ($yearBlock['semesters'] as $semBlock) {
                            if (!empty($semBlock['subjects'])) {
                                $hasSubjects = true;
                                break;
                            }
                        }
                        if (!$hasSubjects) {
                            continue;
                        }
                        ?>
                        <section class="prospectus-year-block">
                            <h3><?= e($yearBlock['label']) ?></h3>
                            <?php foreach ($yearBlock['semesters'] as $semBlock): ?>
                                <?php if (empty($semBlock['subjects'])) continue; ?>
                                <div class="prospectus-sem-block">
                                    <div class="prospectus-sem-head">
                                        <h4><?= e($semBlock['label']) ?></h4>
                                        <span class="text-muted"><?= number_format((float) $semBlock['units'], 1) ?> units</span>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="data-table grades-eval-table">
                                            <thead>
                                                <tr>
                                                    <th>Course</th>
                                                    <th>No.</th>
                                                    <th>Descriptive Title</th>
                                                    <th>Units</th>
                                                    <th>Pre-req.</th>
                                                    <th>Grade</th>
                                                    <th>Remarks</th>
                                                    <th>Semester</th>
                                                    <th>SY taken</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($semBlock['subjects'] as $subject): ?>
                                                    <?php
                                                    $sid = (int) $subject['id'];
                                                    $isTaken = !empty($subject['_is_taken']);
                                                    $remark = $isTaken ? (string) ($subject['_remarks'] ?? '') : '';
                                                    $isVoid = $isTaken && !empty($subject['_is_void']);
                                                    $voidReason = (string) ($subject['_void_reason'] ?? '');
                                                    $rowClass = gradesEvaluationRowClass($subject);
                                                    $storedGrade = $isTaken ? formatStudentGrade($subject['_grade'] ?? '') : '';
                                                    $takenSemester = $isTaken ? gradeTakenSemester($subject) : '';
                                                    $takenSy = $isTaken ? (string) ($subject['_school_year'] ?? '') : '';
                                                    ?>
                                                    <tr class="<?= e($rowClass) ?>">
                                                        <td><?= e($subject['course_code']) ?></td>
                                                        <td><?= e($subject['course_no']) ?></td>
                                                        <td><?= e($subject['title']) ?></td>
                                                        <td><?= e(rtrim(rtrim(number_format((float) $subject['units'], 1), '0'), '.')) ?></td>
                                                        <td>
                                                            <?= e($subject['prereq'] ?: '—') ?>
                                                            <?php if ($isVoid): ?>
                                                                <small class="grades-eval-void-note"><?= e(gradesEvaluationVoidReasonLabel($voidReason)) ?></small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <input type="text" name="grade[<?= $sid ?>]" value="<?= e($storedGrade) ?>" inputmode="decimal" aria-label="Grade for <?= e(prospectusSubjectCode($subject)) ?>"<?= $isVoid ? ' class="is-void-grade"' : '' ?>>
                                                        </td>
                                                        <td>
                                                            <?php if ($isVoid): ?>
                                                                <span class="grades-eval-void-label">Void</span>
                                                                <input type="hidden" name="remarks[<?= $sid ?>]" value="<?= e($remark) ?>">
                                                            <?php else: ?>
                                                                <select name="remarks[<?= $sid ?>]" aria-label="Remarks for <?= e(prospectusSubjectCode($subject)) ?>">
                                                                    <?php foreach (gradeRemarkOptions() as $value => $label): ?>
                                                                        <option value="<?= e($value) ?>" <?= $remark === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <select name="taken_sem[<?= $sid ?>]" aria-label="Semester taken">
                                                                <option value="">—</option>
                                                                <?php foreach (prospectusSemesterOptions() as $value => $label): ?>
                                                                    <option value="<?= e($value) ?>" <?= $takenSemester === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </td>
                                                        <td>
                                                            <select name="taken_sy[<?= $sid ?>]" aria-label="School year taken">
                                                                <option value="">—</option>
                                                                <?php foreach ($yearOptions as $value => $label): ?>
                                                                    <option value="<?= e($value) ?>" <?= $takenSy === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </section>
                    <?php endforeach; ?>

                    <div class="form-actions" style="margin-top:1.25rem">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save evaluation</button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($student && $prospectus && $evaluation): ?>
<script>
(function () {
    document.querySelectorAll('.grades-eval-table input[name^="grade["]').forEach(function (input) {
        input.addEventListener('blur', function () {
            var raw = String(input.value || '').trim().replace(',', '.');
            if (raw === '' || !/^\d+(\.\d+)?$/.test(raw)) {
                return;
            }
            var value = parseFloat(raw);
            if (!(value > 0)) {
                input.value = '';
                return;
            }
            input.value = value.toFixed(2);
        });
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
