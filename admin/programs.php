<?php

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/programs.php';

requireRole('admin');



$db = getDB();

ensureAcademicProgramsSchema();



$editId = (int) ($_GET['edit'] ?? 0);

$editProgram = $editId ? getAcademicProgramById($editId) : null;



if ($editId && !$editProgram) {

    setFlash('error', 'Course/program not found.');

    redirect(APP_URL . '/admin/programs.php');

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

}



$programs = getAllAcademicPrograms();

$pageTitle = 'Courses & Programs';

$activeNav = 'programs';

require_once __DIR__ . '/../includes/header.php';

?>



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



<?php require_once __DIR__ . '/../includes/footer.php'; ?>

