<?php

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/campuses.php';

requireRole('admin');



$db = getDB();

ensureCampusesSchema();



$editId = (int) ($_GET['edit'] ?? 0);

$editCampus = $editId ? getCampusById($editId) : null;



if ($editId && !$editCampus) {

    setFlash('error', 'Campus not found.');

    redirect(APP_URL . '/admin/campuses.php');

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

            $errors[] = 'Campus name is required.';

        }

        if ($data['code'] === '') {

            $errors[] = 'Campus code is required.';

        }



        if (empty($errors)) {

            try {

                if ($action === 'create') {

                    $db->prepare('INSERT INTO campuses (code, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?)')

                       ->execute([$data['code'], $data['name'], $data['description'] ?: null, $data['sort_order'], $data['is_active']]);

                    auditLog('create_campus', 'campuses', (int) $db->lastInsertId());

                    setFlash('success', 'Campus added successfully.');

                } else {

                    $id = (int) ($_POST['campus_id'] ?? 0);

                    $db->prepare('UPDATE campuses SET code = ?, name = ?, description = ?, sort_order = ?, is_active = ? WHERE id = ?')

                       ->execute([$data['code'], $data['name'], $data['description'] ?: null, $data['sort_order'], $data['is_active'], $id]);

                    auditLog('update_campus', 'campuses', $id);

                    setFlash('success', 'Campus updated successfully.');

                }

            } catch (PDOException $e) {

                setFlash('error', str_contains($e->getMessage(), 'Duplicate') ? 'Campus code already exists.' : 'Unable to save campus.');

            }

        } else {

            setFlash('error', implode(' ', $errors));

        }



        redirect(APP_URL . '/admin/campuses.php');

    }



    if ($action === 'delete') {

        $id = (int) ($_POST['campus_id'] ?? 0);

        $stmt = $db->prepare('SELECT COUNT(*) FROM student_profiles WHERE origin_campus_id = ?');

        $stmt->execute([$id]);

        $studentCount = (int) $stmt->fetchColumn();



        if ($studentCount > 0) {

            $db->prepare('UPDATE campuses SET is_active = 0 WHERE id = ?')->execute([$id]);

            setFlash('warning', 'Campus has linked students and was deactivated instead of deleted.');

        } else {

            $db->prepare('DELETE FROM campuses WHERE id = ?')->execute([$id]);

            setFlash('success', 'Campus deleted.');

        }

        redirect(APP_URL . '/admin/campuses.php');

    }



    if ($action === 'toggle') {

        $id = (int) ($_POST['campus_id'] ?? 0);

        $db->prepare('UPDATE campuses SET is_active = NOT is_active WHERE id = ?')->execute([$id]);

        setFlash('success', 'Campus status updated.');

        redirect(APP_URL . '/admin/campuses.php');

    }

}



$campuses = getAllCampuses();

$pageTitle = 'Campuses';

$activeNav = 'campuses';

require_once __DIR__ . '/../includes/header.php';

?>



<div class="settings-list-page">

    <div class="card">

        <div class="card-header">

            <h2>Campus List</h2>

            <button type="button" class="btn btn-primary btn-sm" data-open-admin-form="create">

                <i class="fas fa-plus"></i> Add Campus

            </button>

        </div>

        <div class="card-body">

            <?php if (empty($campuses)): ?>

                <div class="empty-state"><i class="fas fa-building"></i><p>No campuses configured.</p></div>

            <?php else: ?>

                <div class="table-responsive">

                <table class="data-table data-table-responsive document-types-table">

                    <thead>

                        <tr><th>Campus</th><th>Code</th><th>Students</th><th>Status</th><th>Actions</th></tr>

                    </thead>

                    <tbody>

                        <?php foreach ($campuses as $campus): ?>

                        <tr>

                            <td data-label="Campus"><strong><?= e($campus['name']) ?></strong></td>

                            <td data-label="Code"><code><?= e($campus['code']) ?></code></td>

                            <td data-label="Students"><?= (int) $campus['student_count'] ?></td>

                            <td data-label="Status"><?= $campus['is_active'] ? '<span class="badge badge-completed">Active</span>' : '<span class="badge badge-rejected">Inactive</span>' ?></td>

                            <td data-label="Actions" class="action-cell">

                                <div class="action-cell-buttons">

                                <button type="button" <?= adminSettingsIconBtnAttrs('edit') ?> data-admin-form-edit="<?= adminFormRecordAttr([

                                    'campus_id' => (int) $campus['id'],

                                    'name' => $campus['name'],

                                    'code' => $campus['code'],

                                    'description' => $campus['description'] ?? '',

                                    'sort_order' => (int) $campus['sort_order'],

                                    'is_active' => (int) $campus['is_active'],

                                ]) ?>"><?= adminSettingsIconBtnContent('edit') ?></button>

                                <?php $toggleAction = $campus['is_active'] ? 'deactivate' : 'activate'; ?>
                                <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="campus_id" value="<?= $campus['id'] ?>"><button type="submit" <?= adminSettingsIconBtnAttrs($toggleAction) ?>><?= adminSettingsIconBtnContent($toggleAction) ?></button></form>

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



<?php renderAdminFormModalOpen('Campuses', 'Add Campus'); ?>

<form method="POST" class="form-grid document-types-form" data-admin-form

    data-create-title="Add Campus"

    data-update-title="Update Campus"

    data-create-submit-label="Add Campus"

    data-update-submit-label="Update Campus"

    data-create-submit-icon="fa-plus"

    data-update-submit-icon="fa-save"

    data-id-field="campus_id">

    <?= csrfField() ?>

    <input type="hidden" name="action" value="create">

    <input type="hidden" name="campus_id" value="">



    <div class="form-group">

        <label for="campus_name">Campus Name *</label>

        <input type="text" id="campus_name" name="name" required maxlength="150">

    </div>



    <div class="form-row">

        <div class="form-group">

            <label for="campus_code">Campus Code *</label>

            <input type="text" id="campus_code" name="code" required maxlength="20" class="input-uppercase">

        </div>

        <div class="form-group">

            <label for="campus_sort_order">Sort Order</label>

            <input type="number" id="campus_sort_order" name="sort_order" min="0" value="0">

        </div>

    </div>



    <div class="form-group">

        <label for="campus_description">Description</label>

        <textarea id="campus_description" name="description" rows="2"></textarea>

    </div>



    <div class="form-group">

        <label class="checkbox-label">

            <input type="checkbox" name="is_active" value="1" data-default-checked>

            Active (shown in student origin campus dropdown)

        </label>

    </div>



    <?php renderAdminFormModalFooter('Add Campus', 'fa-plus'); ?>

</form>

<?php renderAdminFormModalClose(); ?>



<?php if ($editCampus): ?>

<script>window.__ADMIN_FORM_EDIT__ = <?= json_encode([

    'campus_id' => (int) $editCampus['id'],

    'name' => $editCampus['name'],

    'code' => $editCampus['code'],

    'description' => $editCampus['description'] ?? '',

    'sort_order' => (int) $editCampus['sort_order'],

    'is_active' => (int) $editCampus['is_active'],

], JSON_UNESCAPED_UNICODE) ?>;</script>

<?php endif; ?>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>

