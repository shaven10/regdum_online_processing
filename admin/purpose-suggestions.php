<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/purpose-suggestions.php';

requireRole('admin');

$db = getDB();
ensureRequestPurposesSchema();

$editId = (int) ($_GET['edit'] ?? 0);
$editPurpose = $editId ? getRequestPurposeById($editId) : null;

if ($editId && !$editPurpose) {
    setFlash('error', 'Purpose not found.');
    redirect(APP_URL . '/admin/purpose-suggestions.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $isUpdate = $action === 'update';
        $purposeId = (int) ($_POST['purpose_id'] ?? 0);
        $postedCode = normalizePurposeCode((string) ($_POST['code'] ?? ''));
        $data = [
            'label'      => trim($_POST['label'] ?? ''),
            'hint'       => trim($_POST['hint'] ?? ''),
            'sort_order' => max(0, (int) ($_POST['sort_order'] ?? 0)),
            'is_active'  => !empty($_POST['is_active']) ? 1 : 0,
        ];
        $suggestedDocumentTypeIds = array_values(array_unique(array_filter(array_map('intval', $_POST['suggested_document_type_ids'] ?? []))));

        $errors = [];
        if ($data['label'] === '') {
            $errors[] = 'Purpose label is required.';
        }

        if (!$isUpdate && $postedCode === '') {
            $errors[] = 'Purpose code is required.';
        }

        if ($isUpdate && !$purposeId) {
            $errors[] = 'Purpose record not found.';
        }

        if (!$isUpdate && $postedCode !== '' && !preg_match('/^[a-z][a-z0-9_]{1,48}$/', $postedCode)) {
            $errors[] = 'Purpose code must start with a letter and use lowercase letters, numbers, or underscores only.';
        }

        if (empty($errors)) {
            try {
                if ($isUpdate) {
                    $existing = getRequestPurposeById($purposeId);
                    if (!$existing) {
                        setFlash('error', 'Purpose not found.');
                        redirect(APP_URL . '/admin/purpose-suggestions.php');
                    }

                    $db->prepare('UPDATE request_purposes SET label = ?, hint = ?, sort_order = ?, is_active = ? WHERE id = ?')
                       ->execute([
                           $data['label'],
                           $data['hint'] ?: null,
                           $data['sort_order'],
                           $data['is_active'],
                           $purposeId,
                       ]);

                    saveRequestPurposeDocumentSuggestions($purposeId, $suggestedDocumentTypeIds);
                    auditLog('update_request_purpose', 'request_purposes', $purposeId);
                    setFlash('success', 'Purpose and suggested documents updated successfully.');
                } else {
                    $db->prepare('INSERT INTO request_purposes (code, label, hint, sort_order, is_active) VALUES (?, ?, ?, ?, ?)')
                       ->execute([
                           $postedCode,
                           $data['label'],
                           $data['hint'] ?: null,
                           $data['sort_order'],
                           $data['is_active'],
                       ]);

                    $purposeId = (int) $db->lastInsertId();
                    saveRequestPurposeDocumentSuggestions($purposeId, $suggestedDocumentTypeIds);
                    auditLog('create_request_purpose', 'request_purposes', $purposeId);
                    setFlash('success', 'Purpose added successfully.');
                }
            } catch (PDOException $e) {
                setFlash('error', str_contains($e->getMessage(), 'Duplicate') ? 'Purpose code already exists.' : 'Unable to save purpose.');
            }
        } else {
            setFlash('error', implode(' ', $errors));
        }

        redirect(APP_URL . '/admin/purpose-suggestions.php');
    }

    if ($action === 'delete') {
        $purposeId = (int) ($_POST['purpose_id'] ?? 0);
        $purpose = getRequestPurposeById($purposeId);

        if (!$purpose) {
            setFlash('error', 'Purpose not found.');
            redirect(APP_URL . '/admin/purpose-suggestions.php');
        }

        $requestCount = countRequestsByPurposeCode($purpose['code']);
        if ($requestCount > 0) {
            $db->prepare('UPDATE request_purposes SET is_active = 0 WHERE id = ?')->execute([$purposeId]);
            setFlash('warning', 'Purpose has linked requests and was deactivated instead of deleted.');
        } else {
            $db->prepare('DELETE FROM request_purposes WHERE id = ?')->execute([$purposeId]);
            auditLog('delete_request_purpose', 'request_purposes', $purposeId);
            setFlash('success', 'Purpose deleted.');
        }

        redirect(APP_URL . '/admin/purpose-suggestions.php');
    }

    if ($action === 'toggle') {
        $purposeId = (int) ($_POST['purpose_id'] ?? 0);
        $db->prepare('UPDATE request_purposes SET is_active = NOT is_active WHERE id = ?')->execute([$purposeId]);
        setFlash('success', 'Purpose status updated.');
        redirect(APP_URL . '/admin/purpose-suggestions.php');
    }
}

$purposes = getAllRequestPurposesWithSuggestions();
$documentTypes = $db->query('SELECT id, name, code, is_active FROM document_types ORDER BY name')->fetchAll();
$editSuggestedDocumentTypeIds = $editPurpose ? getSuggestedDocumentTypeIdsForPurposeId((int) $editPurpose['id']) : [];

$pageTitle = 'Purpose & Suggested Documents';
$activeNav = 'purpose-suggestions';
require_once __DIR__ . '/../includes/header.php';
?>

<?php renderAdminCredentialSettingsNav('purpose-suggestions'); ?>

<div class="settings-list-page">
    <div class="card">
        <div class="card-header">
            <h2>Purpose & Suggested Documents</h2>
            <button type="button" class="btn btn-primary btn-sm" data-open-admin-form="create">
                <i class="fas fa-plus"></i> Add Purpose
            </button>
        </div>
        <div class="card-body">
            <p class="text-muted">Manage request purposes shown to students and the documents suggested for each purpose on the new request form.</p>

            <?php if (empty($purposes)): ?>
                <div class="empty-state"><i class="fas fa-bullseye"></i><p>No purposes configured.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                <table class="data-table data-table-responsive document-types-table">
                    <thead>
                        <tr>
                            <th>Purpose</th>
                            <th>Code</th>
                            <th>Suggested Documents</th>
                            <th>Requests</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($purposes as $purpose): ?>
                        <tr>
                            <td data-label="Purpose">
                                <strong><?= e($purpose['label']) ?></strong>
                                <?php if (!empty($purpose['hint'])): ?>
                                    <br><small class="text-muted"><?= e($purpose['hint']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Code"><code><?= e($purpose['code']) ?></code></td>
                            <td data-label="Suggested Documents">
                                <?php if (!empty($purpose['suggested_document_names'])): ?>
                                    <?= e(implode(', ', $purpose['suggested_document_names'])) ?>
                                <?php else: ?>
                                    <span class="text-muted">None</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Requests"><?= (int) $purpose['request_count'] ?></td>
                            <td data-label="Status">
                                <?= $purpose['is_active'] ? '<span class="badge badge-completed">Active</span>' : '<span class="badge badge-rejected">Inactive</span>' ?>
                            </td>
                            <td data-label="Actions" class="action-cell">
                                <div class="action-cell-buttons">
                                <button type="button" <?= adminSettingsIconBtnAttrs('edit') ?> data-admin-form-edit="<?= adminFormRecordAttr([
                                    'purpose_id' => (int) $purpose['id'],
                                    'code' => $purpose['code'],
                                    'label' => $purpose['label'],
                                    'hint' => $purpose['hint'] ?? '',
                                    'sort_order' => (int) $purpose['sort_order'],
                                    'is_active' => (int) $purpose['is_active'],
                                    'suggested_document_type_ids' => $purpose['suggested_document_type_ids'],
                                ]) ?>"><?= adminSettingsIconBtnContent('edit') ?></button>
                                <?php $toggleAction = $purpose['is_active'] ? 'deactivate' : 'activate'; ?>
                                <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="purpose_id" value="<?= (int) $purpose['id'] ?>"><button type="submit" <?= adminSettingsIconBtnAttrs($toggleAction) ?>><?= adminSettingsIconBtnContent($toggleAction) ?></button></form>
                                <?php if ((int) $purpose['request_count'] === 0): ?>
                                <form method="POST" onsubmit="return confirm('Delete this purpose?');"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="purpose_id" value="<?= (int) $purpose['id'] ?>"><button type="submit" <?= adminSettingsIconBtnAttrs('delete') ?>><?= adminSettingsIconBtnContent('delete') ?></button></form>
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

<?php renderAdminFormModalOpen('Purpose & Suggested Documents', 'Add Purpose'); ?>
<form method="POST" class="form-grid document-types-form" data-admin-form
    data-create-title="Add Purpose"
    data-update-title="Update Purpose"
    data-create-submit-label="Add Purpose"
    data-update-submit-label="Save Changes"
    data-create-submit-icon="fa-plus"
    data-update-submit-icon="fa-save"
    data-id-field="purpose_id">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="purpose_id" value="">

    <div class="form-group" id="purposeCodeGroup">
        <label for="purpose_code">Purpose Code *</label>
        <input type="text" id="purpose_code" name="code" maxlength="50" placeholder="e.g. employment">
        <small class="text-muted">Lowercase identifier stored on requests. Cannot be changed after creation.</small>
    </div>

    <div class="form-group">
        <label for="purpose_label">Purpose Label *</label>
        <input type="text" id="purpose_label" name="label" required maxlength="150" placeholder="e.g. Employment">
    </div>

    <div class="form-group">
        <label for="purpose_hint">Suggestion Hint</label>
        <textarea id="purpose_hint" name="hint" rows="3" placeholder="Short guidance shown to students when this purpose is selected."></textarea>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="purpose_sort_order">Sort Order</label>
            <input type="number" id="purpose_sort_order" name="sort_order" min="0" value="0">
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <label class="checkbox-label">
                <input type="checkbox" name="is_active" value="1" data-default-checked>
                Active (shown on student request form)
            </label>
        </div>
    </div>

    <div class="form-group">
        <label>Suggested Documents</label>
        <p class="text-muted">Select the documents commonly requested for this purpose. Only documents available to the student’s enrollment status will appear on the form.</p>
        <div class="compliance-checklist registrar-checklist purpose-suggestion-admin-list">
            <?php foreach ($documentTypes as $documentType): ?>
                <label class="compliance-item">
                    <input type="checkbox"
                        name="suggested_document_type_ids[]"
                        value="<?= (int) $documentType['id'] ?>"
                        <?= !$documentType['is_active'] ? 'disabled' : '' ?>>
                    <div>
                        <strong><?= e($documentType['name']) ?></strong>
                        <span class="badge badge-submitted"><?= e($documentType['code']) ?></span>
                        <?php if (!$documentType['is_active']): ?>
                            <span class="badge badge-rejected">Inactive</span>
                        <?php endif; ?>
                    </div>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <?php renderAdminFormModalFooter('Add Purpose', 'fa-plus'); ?>
</form>
<?php renderAdminFormModalClose(); ?>

<?php if ($editPurpose): ?>
<script>window.__ADMIN_FORM_EDIT__ = <?= json_encode([
    'purpose_id' => (int) $editPurpose['id'],
    'code' => $editPurpose['code'],
    'label' => $editPurpose['label'],
    'hint' => $editPurpose['hint'] ?? '',
    'sort_order' => (int) $editPurpose['sort_order'],
    'is_active' => (int) $editPurpose['is_active'],
    'suggested_document_type_ids' => array_map('strval', $editSuggestedDocumentTypeIds),
], JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>

<script>
(function () {
    const form = document.querySelector('#adminFormModal [data-admin-form]');
    const codeGroup = document.getElementById('purposeCodeGroup');
    const codeInput = document.getElementById('purpose_code');

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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
