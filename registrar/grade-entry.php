<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/grades-evaluation.php';
requireRole('admin', 'registrar');

ensureGradesEvaluationSchema();
$user = currentUser();

if (isset($_GET['suggest'])) {
    header('Content-Type: application/json; charset=utf-8');
    $rows = searchStudentsForGradesEvaluation((string) ($_GET['suggest'] ?? ''), 20);
    echo json_encode([
        'ok'       => true,
        'students' => array_map(static function (array $row): array {
            return [
                'id'         => (int) $row['id'],
                'student_id' => (string) ($row['student_id'] ?? ''),
                'name'       => studentRecordName($row),
                'course'     => (string) ($row['program_code'] ?: ($row['course'] ?: '')),
            ];
        }, $rows),
    ]);
    exit;
}

$search = trim((string) ($_GET['search'] ?? $_POST['search'] ?? ''));
$studentId = (int) ($_POST['student_user_id'] ?? $_GET['student_user_id'] ?? 0);
$student = $studentId > 0 ? loadStudentForGradesEvaluation($studentId) : null;
if ($student && !studentAllowsGradesEvaluation($student)) {
    setFlash('error', 'Grade entry is only available for enrolled (active) students. Graduated and inactive students are excluded.');
    redirect(APP_URL . '/registrar/grade-entry.php');
}
$searchResults = strlen($search) >= 2 ? searchStudentsForGradesEvaluation($search) : [];

$programId = (int) ($_POST['program_id'] ?? $_GET['program_id'] ?? 0);
$prospectusId = (int) ($_POST['prospectus_id'] ?? $_GET['prospectus_id'] ?? 0);
$pasteText = (string) ($_POST['paste_text'] ?? '');
$action = $_POST['action'] ?? '';

if ($student && $programId <= 0) {
    $programId = resolveStudentProgramId($student);
}

$programs = getAllAcademicPrograms();
$prospectus = null;
if ($prospectusId > 0) {
    $prospectus = getProspectusById($prospectusId);
    if ($prospectus && $programId <= 0) {
        $programId = (int) ($prospectus['program_id'] ?? 0);
    }
}
if ($programId > 0 && $prospectus && (int) ($prospectus['program_id'] ?? 0) !== $programId) {
    $prospectus = null;
    $prospectusId = 0;
}
if (!$prospectus && $programId > 0) {
    $prospectus = getActiveProspectusForProgram($programId);
    $prospectusId = (int) ($prospectus['id'] ?? 0);
}

$programProspectuses = $programId > 0 ? getProspectusesForProgram($programId) : [];
$parsed = null;
$subjects = $prospectus ? getProspectusSubjects((int) $prospectus['id']) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && in_array($action, ['preview_paste', 'review_paste', 'save_paste', 'apply_paste'], true)) {
    if (!$student) {
        setFlash('error', 'Select the student first, then paste the evaluation sheet.');
    } elseif (!$prospectus) {
        setFlash('error', 'Choose the student’s course so the prospectus can be loaded.');
    } elseif ($pasteText === '') {
        setFlash('error', 'Paste the evaluation sheet into the box, then review the subjects.');
    } else {
        $parsed = evaluatePastedSubjects($pasteText, $subjects, [
            'single_student' => true,
            'student'        => $student,
        ], postedSubjectMappings());
        if ($action === 'apply_paste' && (int) ($parsed['ok'] ?? 0) > 0) {
            $result = saveParsedGradeEntries($parsed, (int) $user['id'], [
                'prospectus_id' => (int) $prospectus['id'],
            ]);
            setFlash('success', 'Saved ' . (int) $result['saved'] . ' grade(s) after confirmation review.' . ($result['skipped'] > 0 ? ' Skipped ' . (int) $result['skipped'] . '.' : ''));
            redirect(APP_URL . '/registrar/grade-entry.php?' . http_build_query([
                'student_user_id' => (int) $student['id'],
                'program_id'      => $programId,
                'prospectus_id'   => (int) $prospectus['id'],
            ]));
        }
        if ($action === 'apply_paste' && (int) ($parsed['ok'] ?? 0) <= 0) {
            setFlash('error', $parsed['warnings'][0] ?? 'No matching grades were ready to apply. Review unmatched subjects first.');
        }
    }
}

$pageTitle = 'Enter Grades';
$activeNav = 'grade-entry';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card grades-eval-page">
    <div class="card-header">
        <div>
            <h2>Enter Grades</h2>
            <p class="text-muted" style="margin:.35rem 0 0">
                Search and click the student name, then paste the Course # / Grade / Re-Ex sheet in the box below.
            </p>
        </div>
        <div class="card-header-actions">
            <a href="grades-evaluation.php<?= $student ? '?student_user_id=' . (int) $student['id'] : '' ?>" class="btn btn-outline btn-sm"><i class="fas fa-user-check"></i> Evaluate Student</a>
            <a href="prospectuses.php" class="btn btn-outline btn-sm"><i class="fas fa-book"></i> Course Prospectus</a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar grade-entry-search" id="gradeStudentSearch">
            <input type="search" id="gradeStudentQuery" name="search" value="<?= e($search) ?>" placeholder="Type student name or ID number…" autocomplete="off" <?= $student ? '' : 'autofocus' ?>>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
            <?php if ($student || $search !== ''): ?>
                <a href="grade-entry.php" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>
        <div id="gradeStudentSuggest" class="table-wrap onsite-student-results" hidden></div>

        <?php if (strlen($search) >= 2 && empty($searchResults) && !$student): ?>
            <div class="alert alert-warning">No matching students found.</div>
        <?php elseif (!empty($searchResults) && !$student): ?>
            <div class="table-wrap onsite-student-results" id="gradeStudentResults">
                <table class="data-table data-table-responsive">
                    <thead>
                        <tr>
                            <th>ID No.</th>
                            <th>Name</th>
                            <th>Course</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($searchResults as $row): ?>
                            <tr class="is-clickable">
                                <td data-label="ID No.">
                                    <a href="?student_user_id=<?= (int) $row['id'] ?>"><strong><?= e($row['student_id'] ?: '—') ?></strong></a>
                                </td>
                                <td data-label="Name">
                                    <a href="?student_user_id=<?= (int) $row['id'] ?>"><?= e(studentRecordName($row)) ?></a>
                                </td>
                                <td data-label="Course">
                                    <a href="?student_user_id=<?= (int) $row['id'] ?>"><?= e($row['program_code'] ?: ($row['course'] ?: '—')) ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (!$student): ?>
            <p class="text-muted">Click a student name to open the paste box. Do not paste the grade sheet into the search field.</p>
        <?php endif; ?>

        <?php if ($student): ?>
            <div class="grades-eval-student">
                <div>
                    <h3><?= e(studentRecordName($student)) ?></h3>
                    <p class="text-muted" style="margin:.25rem 0 0">
                        <?= e($student['student_id'] ?: 'No student ID') ?>
                        · <?= e($student['program_code'] ?: ($student['course'] ?: 'No course')) ?>
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
                    </p>
                </div>
                <a href="grade-entry.php" class="btn btn-outline btn-sm">Change student</a>
            </div>
        <?php endif; ?>

        <form method="POST" id="gradeEntryForm" class="grades-paste-panel">
            <?= csrfField() ?>
            <input type="hidden" name="student_user_id" value="<?= $student ? (int) $student['id'] : 0 ?>">
            <div class="form-row" style="margin-bottom:.75rem">
                <div class="form-group">
                    <label for="program_id">Course prospectus</label>
                    <select id="program_id" name="program_id">
                        <option value="">Select course</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= (int) $program['id'] ?>" <?= $programId === (int) $program['id'] ? 'selected' : '' ?>>
                                <?= e(($program['code'] ?? '') !== '' ? $program['code'] . ' — ' . $program['name'] : $program['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (count($programProspectuses) > 1): ?>
                    <div class="form-group">
                        <label for="prospectus_id">Curriculum year</label>
                        <select id="prospectus_id" name="prospectus_id" <?= $student ? '' : 'disabled' ?>>
                            <?php foreach ($programProspectuses as $option): ?>
                                <option value="<?= (int) $option['id'] ?>" <?= (int) $option['id'] === $prospectusId ? 'selected' : '' ?>>
                                    <?= e($option['curriculum_year']) ?><?= !empty($option['is_active']) ? ' (active)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif ($prospectusId > 0): ?>
                    <input type="hidden" name="prospectus_id" value="<?= $prospectusId ?>">
                <?php endif; ?>
            </div>

            <?php if ($student && $programId > 0 && !$prospectus): ?>
                <div class="alert alert-warning">No prospectus is set for this course. <a href="prospectuses.php">Create the prospectus</a> first.</div>
            <?php endif; ?>

            <label for="paste_text">Paste evaluation sheet</label>
            <p class="text-muted" style="margin:.2rem 0 .55rem">
                Copy <strong>Course #, Course Description, Preq, Lec, Lab, Units, Grade, Re-Ex</strong> from Excel, including semester rows.
                Grade <strong>0</strong> is treated as not taken. <strong>INC</strong> with a Re-Ex rating uses the Re-Ex.
                Review unmatched subjects against the prospectus before applying grades.
            </p>
            <textarea id="paste_text" name="paste_text" rows="14" placeholder="Course #    Course Description    Preq    Lec    Lab    Units    Grade    Re-Ex"><?= e($pasteText) ?></textarea>
            <div class="form-actions" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:.75rem">
                <button type="submit" name="action" value="review_paste" class="btn btn-primary" <?= $student && $prospectus ? '' : 'disabled' ?>>
                    <i class="fas fa-code-compare"></i> Review subjects
                </button>
            </div>
        </form>

        <?php if ($parsed): ?>
            <?php
            $reviewHidden = [
                'student_user_id' => (string) ($student ? (int) $student['id'] : 0),
                'program_id'      => (string) $programId,
                'paste_text'      => $pasteText,
            ];
            if ($prospectusId > 0) {
                $reviewHidden['prospectus_id'] = (string) $prospectusId;
            }
            renderGradesPasteReview($parsed, $subjects, $reviewHidden);
            ?>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const input = document.getElementById('gradeStudentQuery');
    const box = document.getElementById('gradeStudentSuggest');
    const results = document.getElementById('gradeStudentResults');
    if (!input || !box) return;

    const endpoint = <?= json_encode(APP_URL . '/registrar/grade-entry.php') ?>;
    let timer = null;

    function render(students) {
        if (!students.length) {
            box.hidden = true;
            box.innerHTML = '';
            return;
        }
        if (results) results.hidden = true;
        const rows = students.map(function (row) {
            const href = '?student_user_id=' + encodeURIComponent(row.id);
            return '<tr class="is-clickable"><td><a href="' + href + '"><strong>' + escapeHtml(row.student_id || '—') + '</strong></a></td>'
                + '<td><a href="' + href + '">' + escapeHtml(row.name) + '</a></td>'
                + '<td><a href="' + href + '">' + escapeHtml(row.course || '—') + '</a></td></tr>';
        }).join('');
        box.innerHTML = '<table class="data-table data-table-responsive"><thead><tr><th>ID No.</th><th>Name</th><th>Course</th></tr></thead><tbody>'
            + rows + '</tbody></table>';
        box.hidden = false;
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    input.addEventListener('input', function () {
        const q = input.value.trim();
        window.clearTimeout(timer);
        if (q.length < 2) {
            box.hidden = true;
            return;
        }
        timer = window.setTimeout(function () {
            fetch(endpoint + '?suggest=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (data) { render(data.students || []); })
                .catch(function () { box.hidden = true; });
        }, 200);
    });

    const pasteBox = document.getElementById('paste_text');
    const storageKey = 'gradeEntryPaste';
    if (pasteBox) {
        if (!pasteBox.value) {
            const saved = sessionStorage.getItem(storageKey);
            if (saved) pasteBox.value = saved;
        }
        pasteBox.addEventListener('input', function () {
            sessionStorage.setItem(storageKey, pasteBox.value);
        });
        document.getElementById('gradeEntryForm')?.addEventListener('submit', function (event) {
            const action = event.submitter && event.submitter.value;
            if (action === 'apply_paste' || action === 'save_paste') {
                sessionStorage.removeItem(storageKey);
            }
        });
        document.querySelector('.grades-compare-form')?.addEventListener('submit', function (event) {
            const action = event.submitter && event.submitter.value;
            if (action === 'apply_paste') {
                sessionStorage.removeItem(storageKey);
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
