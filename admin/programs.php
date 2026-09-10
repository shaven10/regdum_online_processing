<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/programs.php';

requireRole('admin');

$db = getDB();
ensureAcademicProgramsSchema();

$editId = (int) ($_GET['edit'] ?? 0);
$editProgram = $editId ? getAcademicProgramById($editId) : null;
$majorsProgramId = (int) ($_GET['program'] ?? 0);
$majorsProgram = $majorsProgramId ? getAcademicProgramById($majorsProgramId) : null;
$editMajorId = (int) ($_GET['edit_major'] ?? 0);
$editMajor = $editMajorId ? getAcademicMajorById($editMajorId) : null;

if ($editId && !$editProgram) {
    setFlash('error', 'Course/program not found.');
    redirect(APP_URL . '/admin/programs.php');
}

if ($majorsProgramId && !$majorsProgram) {
    setFlash('error', 'Course/program not found.');
    redirect(APP_URL . '/admin/programs.php');
}

if ($editMajor && (!$majorsProgram || (int) $editMajor['program_id'] !== (int) $majorsProgram['id'])) {
    setFlash('error', 'Major not found for this program.');
    redirect(APP_URL . '/admin/programs.php' . ($majorsProgramId ? '?program=' . $majorsProgramId : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $data = [
            'code'        => strtoupper(preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim($_POST['code'] ?? '')))),
            'name'        => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'sort_order'  => max(0, (int) ($_POST['sort_order'] ?? 0)),
            'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
        ];

        $errors = [];
        if ($data['name'] === '') {
            $errors[] = 'Program name is required.';
        }
        if ($data['code'] === '') {
            $errors[] = 'Program code is required (letters and numbers only).';
        }

        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    $db->prepare('INSERT INTO academic_programs (code, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?)')
                       ->execute([$data['code'], $data['name'], $data['description'] ?: null, $data['sort_order'], $data['is_active']]);
                    auditLog('create_academic_program', 'academic_programs', (int) $db->lastInsertId());
                    setFlash('success', 'Course/program added successfully.');
                } else {
                    $id = (int) ($_POST['program_id'] ?? 0);
                    $db->prepare('UPDATE academic_programs SET code = ?, name = ?, description = ?, sort_order = ?, is_active = ? WHERE id = ?')
                       ->execute([$data['code'], $data['name'], $data['description'] ?: null, $data['sort_order'], $data['is_active'], $id]);
                    $db->prepare('UPDATE student_profiles SET course = ? WHERE course_id = ?')
                       ->execute([$data['name'], $id]);
                    auditLog('update_academic_program', 'academic_programs', $id);
                    setFlash('success', 'Course/program updated successfully.');
                }
            } catch (PDOException $e) {
                setFlash('error', str_contains($e->getMessage(), 'Duplicate') ? 'Program code already exists.' : 'Unable to save course/program.');
            }
        } else {
            setFlash('error', implode(' ', $errors));
        }

        redirect(APP_URL . '/admin/programs.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['program_id'] ?? 0);
        $stmt = $db->prepare('SELECT COUNT(*) FROM student_profiles WHERE course_id = ?');
        $stmt->execute([$id]);
        $studentCount = (int) $stmt->fetchColumn();

        if ($studentCount > 0) {
            $db->prepare('UPDATE academic_programs SET is_active = 0 WHERE id = ?')->execute([$id]);
            auditLog('deactivate_academic_program', 'academic_programs', $id);
            setFlash('warning', 'Program has enrolled students and was deactivated instead of deleted.');
        } else {
            $db->prepare('DELETE FROM academic_majors WHERE program_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM academic_programs WHERE id = ?')->execute([$id]);
            auditLog('delete_academic_program', 'academic_programs', $id);
            setFlash('success', 'Course/program deleted.');
        }
        redirect(APP_URL . '/admin/programs.php');
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['program_id'] ?? 0);
        $db->prepare('UPDATE academic_programs SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
        setFlash('success', 'Course/program status updated.');
        redirect(APP_URL . '/admin/programs.php');
    }

    if (in_array($action, ['create_major', 'update_major'], true)) {
        $programId = (int) ($_POST['program_id'] ?? 0);
        $program = getAcademicProgramById($programId);
        if (!$program) {
            setFlash('error', 'Course/program not found.');
            redirect(APP_URL . '/admin/programs.php');
        }

        $data = [
            'code'        => strtoupper(preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim($_POST['code'] ?? '')))),
            'name'        => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'sort_order'  => max(0, (int) ($_POST['sort_order'] ?? 0)),
            'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
        ];

        $errors = [];
        if ($data['name'] === '') {
            $errors[] = 'Major name is required.';
        }
        if ($action === 'create_major' && $data['code'] === '') {
            $errors[] = 'Major code is required (letters and numbers only).';
        }

        if (empty($errors)) {
            try {
                if ($action === 'create_major') {
                    $db->prepare('INSERT INTO academic_majors (program_id, code, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)')
                       ->execute([$programId, $data['code'], $data['name'], $data['description'] ?: null, $data['sort_order'], $data['is_active']]);
                    auditLog('create_academic_major', 'academic_majors', (int) $db->lastInsertId());
                    setFlash('success', 'Major added successfully.');
                } else {
                    $majorId = (int) ($_POST['major_id'] ?? 0);
                    $existing = getAcademicMajorById($majorId);
                    if (!$existing || (int) $existing['program_id'] !== $programId) {
                        setFlash('error', 'Major not found.');
                        redirect(APP_URL . '/admin/programs.php?program=' . $programId);
                    }

                    $db->prepare('UPDATE academic_majors SET name = ?, description = ?, sort_order = ?, is_active = ? WHERE id = ? AND program_id = ?')
                       ->execute([$data['name'], $data['description'] ?: null, $data['sort_order'], $data['is_active'], $majorId, $programId]);
                    $db->prepare('UPDATE student_profiles SET major = ? WHERE major_id = ?')
                       ->execute([$data['name'], $majorId]);
                    auditLog('update_academic_major', 'academic_majors', $majorId);
                    setFlash('success', 'Major updated successfully.');
                }
            } catch (PDOException $e) {
                setFlash('error', str_contains($e->getMessage(), 'Duplicate') ? 'Major code already exists for this program.' : 'Unable to save major.');
            }
        } else {
            setFlash('error', implode(' ', $errors));
        }

        redirect(APP_URL . '/admin/programs.php?program=' . $programId);
    }

    if ($action === 'toggle_major') {
        $majorId = (int) ($_POST['major_id'] ?? 0);
        $major = getAcademicMajorById($majorId);
        if (!$major) {
            setFlash('error', 'Major not found.');
            redirect(APP_URL . '/admin/programs.php');
        }
        $db->prepare('UPDATE academic_majors SET is_active = NOT is_active WHERE id = ?')->execute([$majorId]);
        setFlash('success', 'Major status updated.');
        redirect(APP_URL . '/admin/programs.php?program=' . (int) $major['program_id']);
    }

    if ($action === 'delete_major') {
        $majorId = (int) ($_POST['major_id'] ?? 0);
        $major = getAcademicMajorById($majorId);
        if (!$major) {
            setFlash('error', 'Major not found.');
            redirect(APP_URL . '/admin/programs.php');
        }

        $studentCount = countStudentsUsingAcademicMajor($majorId);
        $programId = (int) $major['program_id'];

        if ($studentCount > 0) {
            $db->prepare('UPDATE academic_majors SET is_active = 0 WHERE id = ?')->execute([$majorId]);
            auditLog('deactivate_academic_major', 'academic_majors', $majorId);
            setFlash('warning', 'Major has enrolled students and was deactivated instead of deleted.');
        } else {
            $db->prepare('DELETE FROM academic_majors WHERE id = ?')->execute([$majorId]);
            auditLog('delete_academic_major', 'academic_majors', $majorId);
            setFlash('success', 'Major deleted.');
        }
        redirect(APP_URL . '/admin/programs.php?program=' . $programId);
    }
}

$programs = getAllAcademicPrograms();
$majors = $majorsProgram ? getAcademicMajorsForProgram((int) $majorsProgram['id']) : [];
$pageTitle = $majorsProgram
    ? ('Majors — ' . $majorsProgram['name'])
    : 'Courses & Programs';
$activeNav = 'programs';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($majorsProgram): ?>
<div class="settings-list-page">
    <div class="card">
        <div class="card-header">
            <div>
                <a href="programs.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Courses & Programs</a>
                <h2 style="margin-top:.75rem">Majors for <?= e($majorsProgram['name']) ?></h2>
            </div>
            <button type="button" class="btn btn-primary btn-sm" data-open-admin-form="create">
                <i class="fas fa-plus"></i> Add Major
            </button>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Define majors available under <strong><?= e($majorsProgram['code']) ?></strong>
                (for example English and Mathematics under BSED). Programs without majors skip this step for students.
            </p>

            <?php if (empty($majors)): ?>
                <div class="empty-state"><i class="fas fa-book-open"></i><p>No majors configured for this program yet.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-responsive document-types-table">
                        <thead>
                            <tr>
                                <th>Major</th>
                                <th>Code</th>
                                <th>Students</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($majors as $major): ?>
                                <?php $majorStudentCount = countStudentsUsingAcademicMajor((int) $major['id']); ?>
                            <tr>
                                <td data-label="Major">
                                    <strong><?= e($major['name']) ?></strong>
                                    <?php if (!empty($major['description'])): ?>
                                        <br><small class="text-muted"><?= e($major['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Code"><code><?= e($major['code']) ?></code></td>
                                <td data-label="Students"><?= $majorStudentCount ?></td>
                                <td data-label="Order"><?= (int) $major['sort_order'] ?></td>
                                <td data-label="Status">
                                    <?= $major['is_active']
                                        ? '<span class="badge badge-completed">Active</span>'
                                        : '<span class="badge badge-rejected">Inactive</span>' ?>
                                </td>
                                <td data-label="Actions" class="action-cell">
                                    <div class="action-cell-buttons">
                                        <button type="button" <?= adminSettingsIconBtnAttrs('edit') ?> data-admin-form-edit="<?= adminFormRecordAttr([
                                            'major_id' => (int) $major['id'],
                                            'program_id' => (int) $major['program_id'],
                                            'code' => $major['code'],
                                            'name' => $major['name'],
                                            'description' => $major['description'] ?? '',
                                            'sort_order' => (int) $major['sort_order'],
                                            'is_active' => (int) $major['is_active'],
                                        ]) ?>"><?= adminSettingsIconBtnContent('edit') ?></button>
                                        <?php $toggleAction = $major['is_active'] ? 'deactivate' : 'activate'; ?>
                                        <form method="POST">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_major">
                                            <input type="hidden" name="major_id" value="<?= (int) $major['id'] ?>">
                                            <button type="submit" <?= adminSettingsIconBtnAttrs($toggleAction) ?>><?= adminSettingsIconBtnContent($toggleAction) ?></button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('<?= $majorStudentCount > 0 ? 'This major has students and will be deactivated only. Continue?' : 'Delete this major permanently?' ?>')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_major">
                                            <input type="hidden" name="major_id" value="<?= (int) $major['id'] ?>">
                                            <button type="submit" <?= adminSettingsIconBtnAttrs('delete', 'danger') ?>><?= adminSettingsIconBtnContent('delete') ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php renderAdminFormModalOpen('Program Majors', 'Add Major'); ?>
<form method="POST" class="form-grid document-types-form" data-admin-form
    data-create-title="Add Major"
    data-update-title="Update Major"
    data-create-submit-label="Add Major"
    data-update-submit-label="Update Major"
    data-create-submit-icon="fa-plus"
    data-update-submit-icon="fa-save"
    data-create-action="create_major"
    data-update-action="update_major"
    data-id-field="major_id">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create_major">
    <input type="hidden" name="major_id" value="">
    <input type="hidden" name="program_id" value="<?= (int) $majorsProgram['id'] ?>">

    <div class="form-group" id="majorCodeGroup">
        <label for="major_code">Major Code *</label>
        <input type="text" id="major_code" name="code" maxlength="30" placeholder="e.g. ENGLISH" class="input-uppercase">
        <small class="text-muted">Letters and numbers only. Cannot be changed after creation.</small>
    </div>

    <div class="form-group">
        <label for="major_name">Major Name *</label>
        <input type="text" id="major_name" name="name" required maxlength="150" placeholder="e.g. English">
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="major_sort_order">Sort Order</label>
            <input type="number" id="major_sort_order" name="sort_order" min="0" value="0">
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1" data-default-checked>
                Active (available when this program is selected)
            </label>
        </div>
    </div>

    <div class="form-group">
        <label for="major_description">Description</label>
        <textarea id="major_description" name="description" rows="2" placeholder="Optional major description..."></textarea>
    </div>

    <?php renderAdminFormModalFooter('Add Major', 'fa-plus'); ?>
</form>
<?php renderAdminFormModalClose(); ?>

<script>
(function () {
    const form = document.querySelector('#adminFormModal [data-admin-form]');
    const codeGroup = document.getElementById('majorCodeGroup');
    const codeInput = document.getElementById('major_code');
    if (!form || !codeGroup || !codeInput) return;

    form.addEventListener('adminformpopulated', function (event) {
        const isUpdate = event.detail.mode === 'update';
        codeInput.required = !isUpdate;
        codeInput.readOnly = isUpdate;
        codeGroup.hidden = isUpdate;
    });

    document.querySelectorAll('[data-open-admin-form="create"]').forEach(function (button) {
        button.addEventListener('click', function () {
            codeInput.required = true;
            codeInput.readOnly = false;
            codeGroup.hidden = false;
        });
    });
})();
</script>

<?php if ($editMajor): ?>
<script>window.__ADMIN_FORM_EDIT__ = <?= json_encode([
    'major_id' => (int) $editMajor['id'],
    'program_id' => (int) $editMajor['program_id'],
    'code' => $editMajor['code'],
    'name' => $editMajor['name'],
    'description' => $editMajor['description'] ?? '',
    'sort_order' => (int) $editMajor['sort_order'],
    'is_active' => (int) $editMajor['is_active'],
], JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>

<?php else: ?>

<div class="settings-list-page">
    <div class="card">
        <div class="card-header">
            <h2>Course / Program List</h2>
            <button type="button" class="btn btn-primary btn-sm" data-open-admin-form="create">
                <i class="fas fa-plus"></i> Add Program
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($programs)): ?>
                <div class="empty-state"><i class="fas fa-graduation-cap"></i><p>No courses or programs configured.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                <table class="data-table data-table-responsive document-types-table">
                    <thead>
                        <tr>
                            <th>Program</th>
                            <th>Code</th>
                            <th>Majors</th>
                            <th>Students</th>
                            <th>Order</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programs as $program): ?>
                        <tr>
                            <td data-label="Program">
                                <strong><?= e($program['name']) ?></strong>
                                <?php if ($program['description']): ?><br><small class="text-muted"><?= e($program['description']) ?></small><?php endif; ?>
                            </td>
                            <td data-label="Code"><code><?= e($program['code']) ?></code></td>
                            <td data-label="Majors">
                                <?php if ((int) $program['major_count'] > 0): ?>
                                    <strong><?= (int) $program['major_count'] ?></strong>
                                    major<?= (int) $program['major_count'] === 1 ? '' : 's' ?>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                                <br>
                                <a href="programs.php?program=<?= (int) $program['id'] ?>" class="btn btn-outline btn-sm" style="margin-top:.35rem">
                                    <i class="fas fa-book-open"></i> Manage Majors
                                </a>
                            </td>
                            <td data-label="Students"><?= (int) $program['student_count'] ?></td>
                            <td data-label="Order"><?= (int) $program['sort_order'] ?></td>
                            <td data-label="Status"><?= $program['is_active'] ? '<span class="badge badge-completed">Active</span>' : '<span class="badge badge-rejected">Inactive</span>' ?></td>
                            <td data-label="Actions" class="action-cell">
                                <div class="action-cell-buttons">
                                <button type="button" <?= adminSettingsIconBtnAttrs('edit') ?> data-admin-form-edit="<?= adminFormRecordAttr([
                                    'program_id' => (int) $program['id'],
                                    'name' => $program['name'],
                                    'code' => $program['code'],
                                    'description' => $program['description'] ?? '',
                                    'sort_order' => (int) $program['sort_order'],
                                    'is_active' => (int) $program['is_active'],
                                ]) ?>"><?= adminSettingsIconBtnContent('edit') ?></button>
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="program_id" value="<?= $program['id'] ?>">
                                    <?php $toggleAction = $program['is_active'] ? 'deactivate' : 'activate'; ?>
                                    <button type="submit" <?= adminSettingsIconBtnAttrs($toggleAction) ?>><?= adminSettingsIconBtnContent($toggleAction) ?></button>
                                </form>
                                <form method="POST" onsubmit="return confirm('<?= $program['student_count'] > 0 ? 'This program has students and will be deactivated only. Continue?' : 'Delete this program permanently?' ?>')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="program_id" value="<?= $program['id'] ?>">
                                    <button type="submit" <?= adminSettingsIconBtnAttrs('delete', 'danger') ?>><?= adminSettingsIconBtnContent('delete') ?></button>
                                </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php renderAdminFormModalOpen('Courses & Programs', 'Add Course / Program'); ?>
<form method="POST" class="form-grid document-types-form" data-admin-form
    data-create-title="Add Course / Program"
    data-update-title="Update Program"
    data-create-submit-label="Add Program"
    data-update-submit-label="Update Program"
    data-create-submit-icon="fa-plus"
    data-update-submit-icon="fa-save"
    data-id-field="program_id">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="program_id" value="">

    <div class="form-group">
        <label for="program_name">Program Name *</label>
        <input type="text" id="program_name" name="name" required maxlength="150" placeholder="e.g. BS Information Technology">
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="program_code">Program Code *</label>
            <input type="text" id="program_code" name="code" required maxlength="20" placeholder="e.g. BSIT" class="input-uppercase">
        </div>
        <div class="form-group">
            <label for="program_sort_order">Sort Order</label>
            <input type="number" id="program_sort_order" name="sort_order" min="0" value="0">
            <small class="text-muted">Lower numbers appear first in the student dropdown.</small>
        </div>
    </div>

    <div class="form-group">
        <label for="program_description">Description</label>
        <textarea id="program_description" name="description" rows="2" placeholder="Optional program description..."></textarea>
    </div>

    <div class="form-group">
        <label class="checkbox-label">
            <input type="checkbox" name="is_active" value="1" data-default-checked>
            Active (available in student profile dropdown)
        </label>
    </div>

    <?php renderAdminFormModalFooter('Add Program', 'fa-plus'); ?>
</form>
<?php renderAdminFormModalClose(); ?>

<?php if ($editProgram): ?>
<script>window.__ADMIN_FORM_EDIT__ = <?= json_encode([
    'program_id' => (int) $editProgram['id'],
    'name' => $editProgram['name'],
    'code' => $editProgram['code'],
    'description' => $editProgram['description'] ?? '',
    'sort_order' => (int) $editProgram['sort_order'],
    'is_active' => (int) $editProgram['is_active'],
], JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
