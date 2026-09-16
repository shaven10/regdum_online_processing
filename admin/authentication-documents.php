<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/document-rules.php';
require_once __DIR__ . '/../includes/ui.php';
requireRole('admin');

ensureAuthenticationDocumentTypesSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $input = [
            'code' => $_POST['code'] ?? '',
            'label' => $_POST['label'] ?? '',
            'sort_order' => $_POST['sort_order'] ?? 0,
            'is_active' => !empty($_POST['is_active']) ? 1 : 0,
        ];

        try {
            if ($action === 'create') {
                $newId = createAuthenticationDocumentType($input);
                auditLog('create_authentication_document_type', 'authentication_document_types', $newId);
                setFlash('success', 'Document to authenticate added.');
            } else {
                $id = (int) ($_POST['auth_document_id'] ?? 0);
                updateAuthenticationDocumentType($id, $input);
                auditLog('update_authentication_document_type', 'authentication_document_types', $id);
                setFlash('success', 'Document to authenticate updated.');
            }
        } catch (InvalidArgumentException $e) {
            setFlash('error', $e->getMessage());
        } catch (PDOException $e) {
            setFlash('error', str_contains($e->getMessage(), 'Duplicate')
                ? 'Document code already exists.'
                : 'Unable to save document.');
        }

        redirect(APP_URL . '/admin/authentication-documents.php');
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['auth_document_id'] ?? 0);
        try {
            $result = deleteAuthenticationDocumentType($id);
            if (!empty($result['deactivated'])) {
                auditLog('deactivate_authentication_document_type', 'authentication_document_types', $id);
                setFlash('warning', 'Document is used in existing requests and was deactivated instead of deleted.');
            } else {
                auditLog('delete_authentication_document_type', 'authentication_document_types', $id);
                setFlash('success', 'Document deleted.');
            }
        } catch (InvalidArgumentException $e) {
            setFlash('error', $e->getMessage());
        }
        redirect(APP_URL . '/admin/authentication-documents.php');
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['auth_document_id'] ?? 0);
        try {
            toggleAuthenticationDocumentType($id);
            auditLog('toggle_authentication_document_type', 'authentication_document_types', $id);
            setFlash('success', 'Document status updated.');
        } catch (InvalidArgumentException $e) {
            setFlash('error', $e->getMessage());
        }
        redirect(APP_URL . '/admin/authentication-documents.php');
    }
}

$documents = getAllAuthenticationDocumentTypes(false);
$pageTitle = 'Authentication Documents';
$activeNav = 'authentication-documents';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="settings-list-page">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Documents to Authenticate</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Manage selectable documents under Authentication / CTC (Certified True Copy).
                </p>
            </div>
            <button type="button" class="btn btn-primary btn-sm" data-open-admin-form="create">
                <i class="fas fa-plus"></i> Add Document
            </button>
        </div>
        <div class="card-body">
            <?php renderAdminCredentialSettingsNav('authentication-documents'); ?>

            <?php if (empty($documents)): ?>
                <div class="empty-state"><i class="fas fa-stamp"></i><p>No authentication documents configured.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-responsive document-types-table">
                        <thead>
                            <tr>
                                <th>Document</th>
                                <th>Code</th>
                                <th>Sort</th>
                                <th>Used In</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc): ?>
                                <tr>
                                    <td data-label="Document"><strong><?= e($doc['label']) ?></strong></td>
                                    <td data-label="Code"><code><?= e($doc['code']) ?></code></td>
                                    <td data-label="Sort"><?= (int) $doc['sort_order'] ?></td>
                                    <td data-label="Used In"><?= (int) ($doc['usage_count'] ?? 0) ?> request<?= (int) ($doc['usage_count'] ?? 0) === 1 ? '' : 's' ?></td>
                                    <td data-label="Status">
                                        <?= !empty($doc['is_active'])
                                            ? '<span class="badge badge-completed">Active</span>'
                                            : '<span class="badge badge-rejected">Inactive</span>' ?>
                                    </td>
                                    <td data-label="Actions" class="action-cell">
                                        <div class="action-cell-buttons">
                                            <button type="button" <?= adminSettingsIconBtnAttrs('edit') ?> data-admin-form-edit="<?= adminFormRecordAttr([
                                                'auth_document_id' => (int) $doc['id'],
                                                'label' => $doc['label'],
                                                'code' => $doc['code'],
                                                'sort_order' => (int) $doc['sort_order'],
                                                'is_active' => (int) $doc['is_active'],
                                            ]) ?>"><?= adminSettingsIconBtnContent('edit') ?></button>
                                            <?php $toggleAction = !empty($doc['is_active']) ? 'deactivate' : 'activate'; ?>
                                            <form method="POST">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="auth_document_id" value="<?= (int) $doc['id'] ?>">
                                                <button type="submit" <?= adminSettingsIconBtnAttrs($toggleAction) ?>><?= adminSettingsIconBtnContent($toggleAction) ?></button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('<?= (int) ($doc['usage_count'] ?? 0) > 0
                                                ? 'This document is used in existing requests and will be deactivated only. Continue?'
                                                : 'Delete this authentication document permanently?' ?>')">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="auth_document_id" value="<?= (int) $doc['id'] ?>">
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

<?php renderAdminFormModalOpen('Authentication Documents', 'Add Document'); ?>
<form method="POST" class="form-grid document-types-form" data-admin-form
    data-create-title="Add Document"
    data-update-title="Update Document"
    data-create-submit-label="Add Document"
    data-update-submit-label="Update Document"
    data-create-submit-icon="fa-plus"
    data-update-submit-icon="fa-save"
    data-id-field="auth_document_id">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="auth_document_id" value="">

    <div class="form-group">
        <label for="auth_doc_label">Document Name *</label>
        <input type="text" id="auth_doc_label" name="label" required maxlength="150" placeholder="e.g. Diploma">
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="auth_doc_code">Code *</label>
            <input type="text" id="auth_doc_code" name="code" required maxlength="50" placeholder="e.g. diploma"
                pattern="[a-z][a-z0-9_]{0,49}"
                title="Lowercase letters, numbers, or underscores; must start with a letter"
                data-admin-form-lock-on-edit="1">
            <small class="text-muted">Used internally. Locked after create so existing requests stay valid.</small>
        </div>
        <div class="form-group">
            <label for="auth_doc_sort_order">Sort Order</label>
            <input type="number" id="auth_doc_sort_order" name="sort_order" min="0" value="0">
        </div>
    </div>

    <div class="form-group">
        <label class="checkbox-label">
            <input type="checkbox" name="is_active" value="1" checked>
            Active (shown on Authentication / CTC requests)
        </label>
    </div>
</form>
<?php renderAdminFormModalClose(); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
