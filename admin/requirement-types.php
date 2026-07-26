<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';

requireRole('admin');

ensureRequirementDefinitionsSchema();

$db = getDB();
$editId = (int) ($_GET['edit'] ?? 0);
$editRequirement = $editId ? getRequirementDefinitionById($editId) : null;
$parentCode = normalizeRequirementCode((string) ($_GET['parent'] ?? ''));
$parentRequirement = $parentCode !== '' ? getRequirementDefinitionByCode($parentCode) : null;

if ($editId && !$editRequirement) {
    setFlash('error', 'Requirement not found.');
    redirect(APP_URL . '/admin/requirement-types.php');
}

if ($parentCode !== '' && !$parentRequirement) {
    setFlash('error', 'Parent requirement not found.');
    redirect(APP_URL . '/admin/requirement-types.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $isUpdate = $action === 'update';
        $requirementId = (int) ($_POST['requirement_id'] ?? 0);
        $postedCode = normalizeRequirementCode((string) ($_POST['code'] ?? ''));
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'requires_upload' => !empty($_POST['requires_upload']) ? 1 : 0,
            'is_optional' => !empty($_POST['is_optional']) ? 1 : 0,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'sort_order' => max(0, (int) ($_POST['sort_order'] ?? 0)),
        ];

        $errors = [];
        if ($data['name'] === '') {
            $errors[] = 'Requirement name is required.';
        }

        if (!$isUpdate) {
            if ($postedCode === '') {
                $errors[] = 'Requirement code is required.';
            } elseif (!preg_match('/^[a-z][a-z0-9_]{1,48}$/', $postedCode)) {
                $errors[] = 'Requirement code must start with a letter and use lowercase letters, numbers, or underscores only.';
            }
        }

        if ($isUpdate && !$requirementId) {
            $errors[] = 'Requirement record not found.';
        }

        if (empty($errors)) {
            try {
                if ($isUpdate) {
                    $existing = getRequirementDefinitionById($requirementId);
                    if (!$existing) {
                        setFlash('error', 'Requirement not found.');
                        redirect(APP_URL . '/admin/requirement-types.php');
                    }

                    $db->prepare('UPDATE requirement_definitions
                        SET name = ?, description = ?, requires_upload = ?, is_optional = ?, is_active = ?, sort_order = ?
                        WHERE id = ?')
                       ->execute([
                           $data['name'],
                           $data['description'] ?: null,
                           $data['requires_upload'],
                           $data['is_optional'],
                           $data['is_active'],
                           $data['sort_order'],
                           $requirementId,
                       ]);

                    auditLog('update_requirement_definition', 'requirement_definitions', $requirementId);
                    setFlash('success', 'Requirement updated successfully.');
                } else {
                    $db->prepare('INSERT INTO requirement_definitions
                        (code, name, description, requires_upload, is_optional, is_system, is_active, sort_order)
                        VALUES (?, ?, ?, ?, ?, 0, ?, ?)')
                       ->execute([
                           $postedCode,
                           $data['name'],
                           $data['description'] ?: null,
                           $data['requires_upload'],
                           $data['is_optional'],
                           $data['is_active'],
                           $data['sort_order'],
                       ]);

                    $requirementId = (int) $db->lastInsertId();
                    auditLog('create_requirement_definition', 'requirement_definitions', $requirementId);
                    setFlash('success', 'Requirement added. Enable it for document types under Requirement Settings.');
                }
            } catch (PDOException $e) {
                setFlash('error', str_contains($e->getMessage(), 'Duplicate')
                    ? 'Requirement code already exists.'
                    : 'Unable to save requirement.');
            }
        } else {
            setFlash('error', implode(' ', $errors));
        }

        redirect(APP_URL . '/admin/requirement-types.php');
    }

    if ($action === 'toggle') {
        $requirementId = (int) ($_POST['requirement_id'] ?? 0);
        $requirement = getRequirementDefinitionById($requirementId);
        if (!$requirement) {
            setFlash('error', 'Requirement not found.');
        } else {
            $db->prepare('UPDATE requirement_definitions SET is_active = NOT is_active WHERE id = ?')
               ->execute([$requirementId]);
            setFlash('success', 'Requirement status updated.');
        }
        redirect(APP_URL . '/admin/requirement-types.php');
    }

    if ($action === 'delete') {
        $requirementId = (int) ($_POST['requirement_id'] ?? 0);
        $requirement = getRequirementDefinitionById($requirementId);

        if (!$requirement) {
            setFlash('error', 'Requirement not found.');
            redirect(APP_URL . '/admin/requirement-types.php');
        }

        if (!empty($requirement['is_system'])) {
            setFlash('error', 'System requirements cannot be deleted. Deactivate them instead if needed.');
            redirect(APP_URL . '/admin/requirement-types.php');
        }

        $usageCount = countDocumentTypesUsingRequirementCode($requirement['code'])
            + countAssignedRequestsUsingRequirementCode($requirement['code']);

        if ($usageCount > 0) {
            $db->prepare('UPDATE requirement_definitions SET is_active = 0 WHERE id = ?')->execute([$requirementId]);
            setFlash('warning', 'Requirement is in use and was deactivated instead of deleted.');
        } else {
            $db->prepare('DELETE FROM requirement_subcategories WHERE requirement_code = ?')->execute([$requirement['code']]);
            $db->prepare('DELETE FROM requirement_definitions WHERE id = ?')->execute([$requirementId]);
            auditLog('delete_requirement_definition', 'requirement_definitions', $requirementId);
            setFlash('success', 'Requirement deleted.');
        }

        redirect(APP_URL . '/admin/requirement-types.php');
    }

    if (in_array($action, ['create_subcategory', 'update_subcategory'], true)) {
        $isUpdate = $action === 'update_subcategory';
        $subId = (int) ($_POST['subcategory_id'] ?? 0);
        $reqCode = normalizeRequirementCode((string) ($_POST['requirement_code'] ?? ''));
        $postedCode = normalizeRequirementCode((string) ($_POST['code'] ?? ''));
        $parent = getRequirementDefinitionByCode($reqCode);

        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
            'sort_order' => max(0, (int) ($_POST['sort_order'] ?? 0)),
        ];

        $errors = [];
        if (!$parent) {
            $errors[] = 'Parent requirement not found.';
        }
        if ($data['name'] === '') {
            $errors[] = 'Subcategory name is required.';
        }
        if (!$isUpdate) {
            if ($postedCode === '') {
                $errors[] = 'Subcategory code is required.';
            } elseif (!preg_match('/^[a-z][a-z0-9_]{1,48}$/', $postedCode)) {
                $errors[] = 'Subcategory code must start with a letter and use lowercase letters, numbers, or underscores only.';
            }
        }

        if (empty($errors)) {
            try {
                if ($isUpdate) {
                    $existing = getRequirementSubcategoryById($subId);
                    if (!$existing || $existing['requirement_code'] !== $reqCode) {
                        setFlash('error', 'Subcategory not found.');
                        redirect(APP_URL . '/admin/requirement-types.php?parent=' . urlencode($reqCode));
                    }
                    $db->prepare('UPDATE requirement_subcategories
                        SET name = ?, description = ?, is_active = ?, sort_order = ?
                        WHERE id = ?')
                       ->execute([
                           $data['name'],
                           $data['description'] ?: null,
                           $data['is_active'],
                           $data['sort_order'],
                           $subId,
                       ]);
                    auditLog('update_requirement_subcategory', 'requirement_subcategories', $subId);
                    setFlash('success', 'Subcategory updated.');
                } else {
                    $db->prepare('INSERT INTO requirement_subcategories
                        (requirement_code, code, name, description, is_active, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?)')
                       ->execute([
                           $reqCode,
                           $postedCode,
                           $data['name'],
                           $data['description'] ?: null,
                           $data['is_active'],
                           $data['sort_order'],
                       ]);
                    auditLog('create_requirement_subcategory', 'requirement_subcategories', (int) $db->lastInsertId());
                    setFlash('success', 'Subcategory document added.');
                }
            } catch (PDOException $e) {
                setFlash('error', str_contains($e->getMessage(), 'Duplicate')
                    ? 'Subcategory code already exists under this requirement.'
                    : 'Unable to save subcategory.');
            }
        } else {
            setFlash('error', implode(' ', $errors));
        }

        redirect(APP_URL . '/admin/requirement-types.php?parent=' . urlencode($reqCode));
    }

    if ($action === 'toggle_subcategory') {
        $subId = (int) ($_POST['subcategory_id'] ?? 0);
        $sub = getRequirementSubcategoryById($subId);
        if (!$sub) {
            setFlash('error', 'Subcategory not found.');
            redirect(APP_URL . '/admin/requirement-types.php');
        }
        $db->prepare('UPDATE requirement_subcategories SET is_active = NOT is_active WHERE id = ?')->execute([$subId]);
        setFlash('success', 'Subcategory status updated.');
        redirect(APP_URL . '/admin/requirement-types.php?parent=' . urlencode($sub['requirement_code']));
    }

    if ($action === 'delete_subcategory') {
        $subId = (int) ($_POST['subcategory_id'] ?? 0);
        $sub = getRequirementSubcategoryById($subId);
        if (!$sub) {
            setFlash('error', 'Subcategory not found.');
            redirect(APP_URL . '/admin/requirement-types.php');
        }
        $db->prepare('DELETE FROM requirement_subcategories WHERE id = ?')->execute([$subId]);
        auditLog('delete_requirement_subcategory', 'requirement_subcategories', $subId);
        setFlash('success', 'Subcategory deleted.');
        redirect(APP_URL . '/admin/requirement-types.php?parent=' . urlencode($sub['requirement_code']));
    }
}

$requirements = getAllRequirementDefinitions();
foreach ($requirements as &$requirement) {
    $requirement['document_usage'] = countDocumentTypesUsingRequirementCode($requirement['code']);
    $requirement['request_usage'] = countAssignedRequestsUsingRequirementCode($requirement['code']);
    $requirement['subcategory_count'] = countRequirementSubcategories($requirement['code'], false);
}
unset($requirement);

$subcategories = $parentRequirement ? getRequirementSubcategories($parentRequirement['code'], false) : [];

$pageTitle = $parentRequirement
    ? ('Subcategories — ' . $parentRequirement['name'])
    : 'Requirement Types';
$activeNav = 'requirement-types';
require_once __DIR__ . '/../includes/header.php';
?>

<?php renderAdminCredentialSettingsNav('requirement-types'); ?>

<?php if ($parentRequirement): ?>
<div class="settings-list-page">
    <div class="card">
        <div class="card-header">
            <div>
                <a href="requirement-types.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Requirement Types</a>
                <h2 style="margin-top:.75rem">Subcategories for <?= e($parentRequirement['name']) ?></h2>
            </div>
            <button type="button" class="btn btn-primary btn-sm" data-open-admin-form="create">
                <i class="fas fa-plus"></i> Add Subcategory Document
            </button>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Students must upload each active subcategory document when
                <strong><?= e($parentRequirement['name']) ?></strong> is assigned.
                Example items: HS Card, Live Birth PSA Photocopy, F137A.
            </p>

            <?php if (empty($subcategories)): ?>
                <div class="empty-state"><i class="fas fa-folder-open"></i><p>No subcategory documents yet.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <th>Document</th>
                                <th>Code</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subcategories as $sub): ?>
                            <tr>
                                <td data-label="Document">
                                    <strong><?= e($sub['name']) ?></strong>
                                    <?php if (!empty($sub['description'])): ?>
                                        <br><small class="text-muted"><?= e($sub['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Code"><code><?= e($sub['code']) ?></code></td>
                                <td data-label="Order"><?= (int) $sub['sort_order'] ?></td>
                                <td data-label="Status">
                                    <?= $sub['is_active']
                                        ? '<span class="badge badge-completed">Active</span>'
                                        : '<span class="badge badge-rejected">Inactive</span>' ?>
                                </td>
                                <td data-label="Actions" class="action-cell">
                                    <div class="action-cell-buttons">
                                        <button type="button" <?= adminSettingsIconBtnAttrs('edit') ?> data-admin-form-edit="<?= adminFormRecordAttr([
                                            'subcategory_id' => (int) $sub['id'],
                                            'requirement_code' => $sub['requirement_code'],
                                            'code' => $sub['code'],
                                            'name' => $sub['name'],
                                            'description' => $sub['description'] ?? '',
                                            'is_active' => (int) $sub['is_active'],
                                            'sort_order' => (int) $sub['sort_order'],
                                        ]) ?>"><?= adminSettingsIconBtnContent('edit') ?></button>
                                        <?php $toggleAction = $sub['is_active'] ? 'deactivate' : 'activate'; ?>
                                        <form method="POST">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle_subcategory">
                                            <input type="hidden" name="subcategory_id" value="<?= (int) $sub['id'] ?>">
                                            <button type="submit" <?= adminSettingsIconBtnAttrs($toggleAction) ?>><?= adminSettingsIconBtnContent($toggleAction) ?></button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Delete this subcategory document?');">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_subcategory">
                                            <input type="hidden" name="subcategory_id" value="<?= (int) $sub['id'] ?>">
                                            <button type="submit" <?= adminSettingsIconBtnAttrs('delete') ?>><?= adminSettingsIconBtnContent('delete') ?></button>
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

<?php renderAdminFormModalOpen('Subcategory Documents', 'Add Subcategory Document'); ?>
<form method="POST" class="form-grid document-types-form" data-admin-form
    data-create-title="Add Subcategory Document"
    data-update-title="Update Subcategory Document"
    data-create-submit-label="Add Subcategory"
    data-update-submit-label="Save Changes"
    data-create-submit-icon="fa-plus"
    data-update-submit-icon="fa-save"
    data-create-action="create_subcategory"
    data-update-action="update_subcategory"
    data-id-field="subcategory_id">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create_subcategory">
    <input type="hidden" name="subcategory_id" value="">
    <input type="hidden" name="requirement_code" value="<?= e($parentRequirement['code']) ?>">

    <div class="form-group" id="subcategoryCodeGroup">
        <label for="subcategory_code">Document Code *</label>
        <input type="text" id="subcategory_code" name="code" maxlength="50" placeholder="e.g. hs_card">
        <small class="text-muted">Lowercase identifier. Cannot be changed after creation.</small>
    </div>

    <div class="form-group">
        <label for="subcategory_name">Document Name *</label>
        <input type="text" id="subcategory_name" name="name" required maxlength="255" placeholder="e.g. HS Card">
    </div>

    <div class="form-group">
        <label for="subcategory_description">Description</label>
        <textarea id="subcategory_description" name="description" rows="3" placeholder="Guidance shown when students upload this document."></textarea>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="subcategory_sort_order">Sort Order</label>
            <input type="number" id="subcategory_sort_order" name="sort_order" min="0" value="0">
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1" data-default-checked>
                Active (required when parent requirement is assigned)
            </label>
        </div>
    </div>

    <?php renderAdminFormModalFooter('Add Subcategory', 'fa-plus'); ?>
</form>
<?php renderAdminFormModalClose(); ?>

<script>
(function () {
    const form = document.querySelector('#adminFormModal [data-admin-form]');
    const codeGroup = document.getElementById('subcategoryCodeGroup');
    const codeInput = document.getElementById('subcategory_code');
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

<?php else: ?>

<div class="settings-list-page">
    <div class="card">
        <div class="card-header">
            <h2>Requirement Types</h2>
            <button type="button" class="btn btn-primary btn-sm" data-open-admin-form="create">
                <i class="fas fa-plus"></i> Add Requirement
            </button>
        </div>
        <div class="card-body">
            <p class="text-muted">
                Create requirements and optional subcategory documents (for example under Other Enrollment Requirements).
                Then enable the parent requirement for credentials in
                <a href="<?= APP_URL ?>/admin/requirement-settings.php">Requirement Settings</a>.
            </p>

            <?php if (empty($requirements)): ?>
                <div class="empty-state"><i class="fas fa-list-check"></i><p>No requirements configured.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-responsive document-types-table">
                        <thead>
                            <tr>
                                <th>Requirement</th>
                                <th>Code</th>
                                <th>Subcategories</th>
                                <th>Upload</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requirements as $requirement): ?>
                            <tr>
                                <td data-label="Requirement">
                                    <strong><?= e($requirement['name']) ?></strong>
                                    <?php if (!empty($requirement['is_system'])): ?>
                                        <span class="badge badge-submitted">System</span>
                                    <?php endif; ?>
                                    <?php if (!empty($requirement['is_optional'])): ?>
                                        <span class="badge badge-review">Optional</span>
                                    <?php endif; ?>
                                    <?php if (!empty($requirement['description'])): ?>
                                        <br><small class="text-muted"><?= e($requirement['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Code"><code><?= e($requirement['code']) ?></code></td>
                                <td data-label="Subcategories">
                                    <?php if ((int) $requirement['subcategory_count'] > 0): ?>
                                        <strong><?= (int) $requirement['subcategory_count'] ?></strong> document<?= (int) $requirement['subcategory_count'] === 1 ? '' : 's' ?>
                                    <?php else: ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                    <br>
                                    <a href="requirement-types.php?parent=<?= urlencode($requirement['code']) ?>" class="btn btn-outline btn-sm" style="margin-top:.35rem">
                                        <i class="fas fa-folder-tree"></i> Manage Subcategories
                                    </a>
                                </td>
                                <td data-label="Upload">
                                    <?= !empty($requirement['requires_upload']) || (int) $requirement['subcategory_count'] > 0
                                        ? '<span class="badge badge-completed">Required</span>'
                                        : '<span class="badge badge-submitted">None</span>' ?>
                                </td>
                                <td data-label="Status">
                                    <?= $requirement['is_active']
                                        ? '<span class="badge badge-completed">Active</span>'
                                        : '<span class="badge badge-rejected">Inactive</span>' ?>
                                </td>
                                <td data-label="Actions" class="action-cell">
                                    <div class="action-cell-buttons">
                                        <button type="button" <?= adminSettingsIconBtnAttrs('edit') ?> data-admin-form-edit="<?= adminFormRecordAttr([
                                            'requirement_id' => (int) $requirement['id'],
                                            'code' => $requirement['code'],
                                            'name' => $requirement['name'],
                                            'description' => $requirement['description'] ?? '',
                                            'requires_upload' => (int) $requirement['requires_upload'],
                                            'is_optional' => (int) $requirement['is_optional'],
                                            'is_active' => (int) $requirement['is_active'],
                                            'sort_order' => (int) $requirement['sort_order'],
                                            'is_system' => (int) $requirement['is_system'],
                                        ]) ?>"><?= adminSettingsIconBtnContent('edit') ?></button>
                                        <?php $toggleAction = $requirement['is_active'] ? 'deactivate' : 'activate'; ?>
                                        <form method="POST">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="requirement_id" value="<?= (int) $requirement['id'] ?>">
                                            <button type="submit" <?= adminSettingsIconBtnAttrs($toggleAction) ?>><?= adminSettingsIconBtnContent($toggleAction) ?></button>
                                        </form>
                                        <?php if (empty($requirement['is_system']) && (int) $requirement['document_usage'] === 0 && (int) $requirement['request_usage'] === 0): ?>
                                            <form method="POST" onsubmit="return confirm('Delete this requirement?');">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="requirement_id" value="<?= (int) $requirement['id'] ?>">
                                                <button type="submit" <?= adminSettingsIconBtnAttrs('delete') ?>><?= adminSettingsIconBtnContent('delete') ?></button>
                                            </form>
                                        <?php endif; ?>
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

<?php renderAdminFormModalOpen('Requirement Types', 'Add Requirement'); ?>
<form method="POST" class="form-grid document-types-form" data-admin-form
    data-create-title="Add Requirement"
    data-update-title="Update Requirement"
    data-create-submit-label="Add Requirement"
    data-update-submit-label="Save Changes"
    data-create-submit-icon="fa-plus"
    data-update-submit-icon="fa-save"
    data-id-field="requirement_id">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="requirement_id" value="">

    <div class="form-group" id="requirementCodeGroup">
        <label for="requirement_code">Requirement Code *</label>
        <input type="text" id="requirement_code" name="code" maxlength="50" placeholder="e.g. good_moral_certificate">
        <small class="text-muted">Lowercase identifier used in settings and requests. Cannot be changed after creation.</small>
    </div>

    <div class="form-group">
        <label for="requirement_name">Requirement Name *</label>
        <input type="text" id="requirement_name" name="name" required maxlength="255" placeholder="e.g. Good Moral Certificate">
    </div>

    <div class="form-group">
        <label for="requirement_description">Description</label>
        <textarea id="requirement_description" name="description" rows="3" placeholder="Guidance shown to students and registrar staff."></textarea>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="requirement_sort_order">Sort Order</label>
            <input type="number" id="requirement_sort_order" name="sort_order" min="0" value="0">
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <label class="checkbox-label">
                <input type="checkbox" name="requires_upload" value="1" data-default-checked>
                Requires student file upload
            </label>
            <label class="checkbox-label">
                <input type="checkbox" name="is_optional" value="1">
                Mark as optional (informational)
            </label>
            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1" data-default-checked>
                Active (available in Requirement Settings)
            </label>
        </div>
    </div>

    <?php renderAdminFormModalFooter('Add Requirement', 'fa-plus'); ?>
</form>
<?php renderAdminFormModalClose(); ?>

<?php if ($editRequirement): ?>
<script>window.__ADMIN_FORM_EDIT__ = <?= json_encode([
    'requirement_id' => (int) $editRequirement['id'],
    'code' => $editRequirement['code'],
    'name' => $editRequirement['name'],
    'description' => $editRequirement['description'] ?? '',
    'requires_upload' => (int) $editRequirement['requires_upload'],
    'is_optional' => (int) $editRequirement['is_optional'],
    'is_active' => (int) $editRequirement['is_active'],
    'sort_order' => (int) $editRequirement['sort_order'],
    'is_system' => (int) $editRequirement['is_system'],
], JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>

<script>
(function () {
    const form = document.querySelector('#adminFormModal [data-admin-form]');
    const codeGroup = document.getElementById('requirementCodeGroup');
    const codeInput = document.getElementById('requirement_code');

    if (!form || !codeGroup || !codeInput) {
        return;
    }

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

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
