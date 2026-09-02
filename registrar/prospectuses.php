<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/grades-evaluation.php';
requireRole('admin', 'registrar');

ensureGradesEvaluationSchema();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $result = saveCourseProspectus([
            'program_id'       => (int) ($_POST['program_id'] ?? 0),
            'curriculum_year'  => trim((string) ($_POST['curriculum_year'] ?? '')),
            'title'            => trim((string) ($_POST['title'] ?? '')),
            'is_active'        => 1,
        ], (int) $user['id']);
        if (!empty($result['ok'])) {
            setFlash('success', 'Prospectus created. Add the subjects by year and semester.');
            redirect(APP_URL . '/registrar/prospectus-edit.php?id=' . (int) $result['id']);
        }
        setFlash('error', $result['error'] ?? 'Unable to create prospectus.');
        redirect(APP_URL . '/registrar/prospectuses.php');
    }

    if ($action === 'delete') {
        $result = deleteCourseProspectus((int) ($_POST['id'] ?? 0));
        setFlash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? 'Prospectus deleted.'
            : ($result['error'] ?? 'Unable to delete prospectus.'));
        redirect(APP_URL . '/registrar/prospectuses.php');
    }
}

$courses = listCourseProspectuses();
$programs = getAllAcademicPrograms();
$yearOptions = schoolYearOptions();

$pageTitle = 'Course Prospectus';
$activeNav = 'prospectus';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card grades-eval-page">
    <div class="card-header">
        <div>
            <h2>Course Prospectus</h2>
            <p class="text-muted" style="margin:.35rem 0 0">
                Set the official subjects by course, year, and semester. Student grade evaluation follows this prospectus.
            </p>
        </div>
        <div class="card-header-actions">
            <a href="grades-evaluation.php" class="btn btn-outline btn-sm"><i class="fas fa-clipboard-list"></i> Evaluate Student</a>
            <a href="grade-entry.php" class="btn btn-outline btn-sm"><i class="fas fa-paste"></i> Enter Grades</a>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" class="filter-bar onsite-student-search grades-prospectus-create">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <select name="program_id" required aria-label="Course">
                <option value="">Select course</option>
                <?php foreach ($programs as $program): ?>
                    <option value="<?= (int) $program['id'] ?>">
                        <?= e(($program['code'] ?? '') !== '' ? $program['code'] . ' — ' . $program['name'] : $program['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="curriculum_year" required aria-label="Curriculum year">
                <?php foreach ($yearOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="title" placeholder="Optional title (e.g. BSCRIM Prospectus)">
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> New prospectus</button>
        </form>

        <div class="table-responsive">
            <table class="data-table data-table-responsive">
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Curriculum year</th>
                        <th>Subjects</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $row): ?>
                        <tr>
                            <td data-label="Course">
                                <strong><?= e($row['program_code'] ?: '—') ?></strong>
                                <div class="text-muted"><?= e($row['program_name'] ?: '') ?></div>
                            </td>
                            <td data-label="Curriculum year">
                                <?= $row['prospectus_id'] ? e($row['curriculum_year'] ?: '—') : '<span class="text-muted">Not set</span>' ?>
                            </td>
                            <td data-label="Subjects">
                                <?= $row['prospectus_id'] ? (int) $row['subject_count'] : '—' ?>
                            </td>
                            <td data-label="Status">
                                <?php if (!$row['prospectus_id']): ?>
                                    <span class="text-muted">No prospectus</span>
                                <?php elseif ((int) $row['is_active'] === 1): ?>
                                    <span class="onsite-student-selected-pill">Active</span>
                                <?php else: ?>
                                    Inactive
                                <?php endif; ?>
                            </td>
                            <td data-label="Action">
                                <?php if ($row['prospectus_id']): ?>
                                    <a class="btn btn-sm btn-primary" href="prospectus-edit.php?id=<?= (int) $row['prospectus_id'] ?>">Edit subjects</a>
                                <?php else: ?>
                                    <span class="text-muted">Create one above</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
