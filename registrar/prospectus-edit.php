<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/grades-evaluation.php';
requireRole('admin', 'registrar');

ensureGradesEvaluationSchema();
$user = currentUser();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$prospectus = getProspectusById($id);
if (!$prospectus) {
    setFlash('error', 'Prospectus not found.');
    redirect(APP_URL . '/registrar/prospectuses.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete') {
        $result = deleteCourseProspectus($id);
        setFlash(!empty($result['ok']) ? 'success' : 'error', !empty($result['ok'])
            ? 'Prospectus deleted.'
            : ($result['error'] ?? 'Unable to delete prospectus.'));
        redirect(APP_URL . '/registrar/prospectuses.php');
    }

    $meta = saveCourseProspectus([
        'id'               => $id,
        'program_id'       => (int) ($prospectus['program_id'] ?? 0),
        'curriculum_year'  => trim((string) ($_POST['curriculum_year'] ?? $prospectus['curriculum_year'])),
        'title'            => trim((string) ($_POST['prospectus_title'] ?? '')),
        'is_active'        => !empty($_POST['is_active']) ? 1 : 0,
    ], (int) $user['id']);

    if (empty($meta['ok'])) {
        $errors[] = $meta['error'] ?? 'Unable to save prospectus.';
    } else {
        $posted = [];
        $ids = $_POST['subject_id'] ?? [];
        $years = $_POST['year_level'] ?? [];
        $sems = $_POST['semester'] ?? [];
        $codes = $_POST['course_code'] ?? [];
        $nos = $_POST['course_no'] ?? [];
        $titles = $_POST['title'] ?? [];
        $units = $_POST['units'] ?? [];
        $prereqs = $_POST['prereq'] ?? [];
        $count = max(count($ids), count($codes), count($titles));
        for ($i = 0; $i < $count; $i++) {
            $posted[] = [
                'id'          => (int) ($ids[$i] ?? 0),
                'year_level'  => (string) ($years[$i] ?? ''),
                'semester'    => (string) ($sems[$i] ?? ''),
                'course_code' => (string) ($codes[$i] ?? ''),
                'course_no'   => (string) ($nos[$i] ?? ''),
                'title'       => (string) ($titles[$i] ?? ''),
                'units'       => $units[$i] ?? 3,
                'prereq'      => (string) ($prereqs[$i] ?? ''),
            ];
        }
        $saved = saveProspectusSubjects($id, $posted);
        if (empty($saved['ok'])) {
            $errors[] = $saved['error'] ?? 'Unable to save subjects.';
        } else {
            setFlash('success', 'Prospectus saved with ' . (int) $saved['count'] . ' subject(s).');
            redirect(APP_URL . '/registrar/prospectus-edit.php?id=' . $id);
        }
    }
    $prospectus = getProspectusById($id);
}

$subjects = getProspectusSubjects($id);
$grouped = groupProspectusSubjects($subjects);
$yearOptions = schoolYearOptions();

$pageTitle = 'Edit Prospectus';
$activeNav = 'prospectus';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card grades-eval-page">
    <div class="card-header">
        <div>
            <h2><?= e($prospectus['program_code'] ?? '') ?> Prospectus</h2>
            <p class="text-muted" style="margin:.35rem 0 0"><?= e($prospectus['program_name'] ?? '') ?></p>
        </div>
        <div class="card-header-actions">
            <a href="prospectuses.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> All courses</a>
            <a href="grade-entry.php" class="btn btn-outline btn-sm"><i class="fas fa-paste"></i> Enter Grades</a>
            <a href="grades-evaluation.php" class="btn btn-outline btn-sm"><i class="fas fa-clipboard-list"></i> Evaluate Student</a>
        </div>
    </div>
    <div class="card-body">
        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="POST" id="prospectusForm">
            <?= csrfField() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="save">

            <div class="form-row">
                <div class="form-group">
                    <label for="curriculum_year">Curriculum year *</label>
                    <select id="curriculum_year" name="curriculum_year" required>
                        <?php foreach ($yearOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= ($prospectus['curriculum_year'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" id="title" name="prospectus_title" value="<?= e($prospectus['title'] ?? '') ?>" placeholder="Course prospectus title">
                </div>
                <div class="form-group">
                    <label class="checkbox-label" style="margin-top:1.7rem">
                        <input type="checkbox" name="is_active" value="1" <?= !empty($prospectus['is_active']) ? 'checked' : '' ?>>
                        Active prospectus for this course
                    </label>
                </div>
            </div>

            <p class="text-muted">Add subjects under each year and semester. Grade evaluation for students in this course uses this list.</p>

            <?php foreach ($grouped as $yearKey => $yearBlock): ?>
                <section class="prospectus-year-block">
                    <h3><?= e($yearBlock['label']) ?></h3>
                    <?php foreach ($yearBlock['semesters'] as $semKey => $semBlock): ?>
                        <div class="prospectus-sem-block">
                            <div class="prospectus-sem-head">
                                <h4><?= e($semBlock['label']) ?></h4>
                                <span class="text-muted"><?= number_format((float) $semBlock['units'], 1) ?> units</span>
                            </div>
                            <div class="table-responsive">
                                <table class="data-table prospectus-edit-table">
                                    <thead>
                                        <tr>
                                            <th>Course</th>
                                            <th>No.</th>
                                            <th>Descriptive Title</th>
                                            <th>Units</th>
                                            <th>Pre-req.</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody data-prospectus-body="<?= e($yearKey) ?>|<?= e($semKey) ?>">
                                        <?php foreach ($semBlock['subjects'] as $subject): ?>
                                            <tr>
                                                <td>
                                                    <input type="hidden" name="subject_id[]" value="<?= (int) $subject['id'] ?>">
                                                    <input type="hidden" name="year_level[]" value="<?= e($yearKey) ?>">
                                                    <input type="hidden" name="semester[]" value="<?= e($semKey) ?>">
                                                    <input type="text" name="course_code[]" value="<?= e($subject['course_code']) ?>" required>
                                                </td>
                                                <td><input type="text" name="course_no[]" value="<?= e($subject['course_no']) ?>"></td>
                                                <td><input type="text" name="title[]" value="<?= e($subject['title']) ?>" required></td>
                                                <td><input type="number" name="units[]" min="0.5" step="0.5" value="<?= e((string) $subject['units']) ?>"></td>
                                                <td><input type="text" name="prereq[]" value="<?= e($subject['prereq'] ?? '') ?>"></td>
                                                <td><button type="button" class="btn btn-outline btn-sm" data-remove-prospectus-row>&times;</button></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-outline btn-sm" data-add-prospectus-row data-year="<?= e($yearKey) ?>" data-semester="<?= e($semKey) ?>">
                                <i class="fas fa-plus"></i> Add subject
                            </button>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endforeach; ?>

            <div class="form-actions" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1.25rem">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save prospectus</button>
                <button type="submit" name="action" value="delete" class="btn btn-outline" onclick="return confirm('Delete this prospectus and its subjects?')">
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>

<template id="prospectusRowTemplate">
    <tr>
        <td>
            <input type="hidden" name="subject_id[]" value="0">
            <input type="hidden" name="year_level[]" value="">
            <input type="hidden" name="semester[]" value="">
            <input type="text" name="course_code[]" placeholder="CRIM" required>
        </td>
        <td><input type="text" name="course_no[]" placeholder="1"></td>
        <td><input type="text" name="title[]" placeholder="Descriptive title" required></td>
        <td><input type="number" name="units[]" min="0.5" step="0.5" value="3"></td>
        <td><input type="text" name="prereq[]" placeholder="Optional"></td>
        <td><button type="button" class="btn btn-outline btn-sm" data-remove-prospectus-row>&times;</button></td>
    </tr>
</template>

<script>
(function () {
    const template = document.getElementById('prospectusRowTemplate');
    document.querySelectorAll('[data-add-prospectus-row]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const year = btn.getAttribute('data-year');
            const semester = btn.getAttribute('data-semester');
            const body = document.querySelector('[data-prospectus-body="' + year + '|' + semester + '"]');
            if (!body || !template) {
                return;
            }
            const row = template.content.cloneNode(true);
            row.querySelector('[name="year_level[]"]').value = year;
            row.querySelector('[name="semester[]"]').value = semester;
            body.appendChild(row);
        });
    });
    document.getElementById('prospectusForm')?.addEventListener('click', function (event) {
        const btn = event.target.closest('[data-remove-prospectus-row]');
        if (!btn) {
            return;
        }
        btn.closest('tr')?.remove();
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
