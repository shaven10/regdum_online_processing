<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/assignment-offices.php';
requireRole('admin');

ensureDocumentTypeFeeSchema();
ensureDocumentAssignmentOfficeSchema();



$db = getDB();

$editId = (int) ($_GET['edit'] ?? 0);

$editDoc = null;



if ($editId) {

    $stmt = $db->prepare('SELECT * FROM document_types WHERE id = ?');

    $stmt->execute([$editId]);

    $editDoc = $stmt->fetch();

    if (!$editDoc) {

        setFlash('error', 'Document type not found.');

        redirect(APP_URL . '/admin/document-types.php');

    }

}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {

    $action = $_POST['action'] ?? '';



    if ($action === 'create' || $action === 'update') {

        $data = [

            'name'                       => trim($_POST['name'] ?? ''),

            'code'                       => strtoupper(preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim($_POST['code'] ?? '')))),

            'description'                => trim($_POST['description'] ?? ''),

            'base_fee'                   => max(0, (float) ($_POST['base_fee'] ?? 0)),
            'fee_per_set'                => !empty($_POST['fee_per_set']) ? 1 : 0,
            'requires_documentary_stamp' => !empty($_POST['requires_documentary_stamp']) ? 1 : 0,

            'processing_days'            => max(1, (int) ($_POST['processing_days'] ?? 3)),

            'requires_upload'            => !empty($_POST['requires_upload']) ? 1 : 0,

            'is_active'                  => !empty($_POST['is_active']) ? 1 : 0,

            'assignment_office'          => normalizeAssignmentOffice($_POST['assignment_office'] ?? 'registrar'),

        ];



        $errors = [];

        if ($data['name'] === '') {

            $errors[] = 'Document name is required.';

        }

        if ($data['code'] === '') {

            $errors[] = 'Document code is required (letters and numbers only).';

        }

        if ($action === 'update' && !(int) ($_POST['document_type_id'] ?? 0)) {

            $errors[] = 'Document record could not be identified. Please try again.';

        }



        if (empty($errors)) {

            try {

                if ($action === 'create') {

                    $db->prepare('INSERT INTO document_types (name, code, description, base_fee, per_copy_fee, processing_days, requires_upload, requires_documentary_stamp, fee_per_set, is_active, assignment_office) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)')

                       ->execute([

                           $data['name'], $data['code'], $data['description'] ?: null,

                           $data['base_fee'], $data['processing_days'],

                           $data['requires_upload'], $data['requires_documentary_stamp'], $data['fee_per_set'], $data['is_active'], $data['assignment_office'],

                       ]);

                    $newId = (int) $db->lastInsertId();

                    ensureRequirementDefaultsSchema();

                    seedDocumentTypeRequirementDefaults($newId, $data['code']);

                    seedDocumentEnrollmentRulesForType($newId, $data['code']);

                    auditLog('create_document_type', 'document_types', $newId);

                    setFlash('success', 'Document type added successfully.');

                } else {

                    $id = (int) ($_POST['document_type_id'] ?? 0);

                    $db->prepare('UPDATE document_types SET name = ?, code = ?, description = ?, base_fee = ?, per_copy_fee = 0, processing_days = ?, requires_upload = ?, requires_documentary_stamp = ?, fee_per_set = ?, is_active = ?, assignment_office = ? WHERE id = ?')

                       ->execute([

                           $data['name'], $data['code'], $data['description'] ?: null,

                           $data['base_fee'], $data['processing_days'],

                           $data['requires_upload'], $data['requires_documentary_stamp'], $data['fee_per_set'], $data['is_active'], $data['assignment_office'], $id,

                       ]);

                    auditLog('update_document_type', 'document_types', $id);

                    setFlash('success', 'Document type updated successfully.');

                }

            } catch (PDOException $e) {

                setFlash('error', str_contains($e->getMessage(), 'Duplicate') ? 'Document code already exists.' : 'Unable to save document type.');

            }

        } else {

            setFlash('error', implode(' ', $errors));

        }



        redirect(APP_URL . '/admin/document-types.php');

    }



    if ($action === 'delete') {

        $id = (int) ($_POST['document_type_id'] ?? 0);

        $count = $db->prepare('SELECT COUNT(*) FROM requests WHERE document_type_id = ?');

        $count->execute([$id]);

        $requestCount = (int) $count->fetchColumn();



        if ($requestCount > 0) {

            $db->prepare('UPDATE document_types SET is_active = 0 WHERE id = ?')->execute([$id]);

            auditLog('deactivate_document_type', 'document_types', $id);

            setFlash('warning', 'Document type has existing requests and was deactivated instead of deleted.');

        } else {

            $db->prepare('DELETE FROM document_type_requirement_defaults WHERE document_type_id = ?')->execute([$id]);

            $db->prepare('DELETE FROM document_types WHERE id = ?')->execute([$id]);

            auditLog('delete_document_type', 'document_types', $id);

            setFlash('success', 'Document type deleted.');

        }

        redirect(APP_URL . '/admin/document-types.php');

    }



    if ($action === 'toggle') {

        $id = (int) ($_POST['document_type_id'] ?? 0);

        $db->prepare('UPDATE document_types SET is_active = NOT is_active WHERE id = ?')->execute([$id]);

        setFlash('success', 'Document type status updated.');

        redirect(APP_URL . '/admin/document-types.php');

    }

}



$documentTypes = $db->query('SELECT dt.*, (SELECT COUNT(*) FROM requests r WHERE r.document_type_id = dt.id) as request_count FROM document_types dt ORDER BY dt.name')->fetchAll();



$pageTitle = 'Document Types';

$activeNav = 'documents';

require_once __DIR__ . '/../includes/header.php';

?>



<?php renderAdminCredentialSettingsNav('documents'); ?>

<div class="settings-list-page">

    <div class="card">

        <div class="card-header">

            <h2>Request Documents</h2>

            <button type="button" class="btn btn-primary btn-sm" data-open-admin-form="create">

                <i class="fas fa-plus"></i> Add Document

            </button>

        </div>

        <div class="card-body">

            <?php if (empty($documentTypes)): ?>

                <div class="empty-state"><i class="fas fa-file-alt"></i><p>No document types configured.</p></div>

            <?php else: ?>

                <div class="table-responsive">

                <table class="data-table data-table-responsive document-types-table">

                    <thead>

                        <tr>

                            <th>Name</th>

                            <th>Code</th>

                            <th>Fees</th>

                            <th>Days</th>

                            <th>Requests</th>

                            <th>Status</th>

                            <th>Actions</th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($documentTypes as $doc): ?>

                        <tr>

                            <td data-label="Name">

                                <strong><?= e($doc['name']) ?></strong>

                                <?php if ($doc['requires_upload']): ?><br><small class="text-muted">Upload required</small><?php endif; ?>

                            </td>

                            <td data-label="Code"><code><?= e($doc['code']) ?></code></td>

                            <td data-label="Fees">

                                <?= formatDocumentTypeUnitFee($doc) ?>

                                <?php if (!empty($doc['requires_documentary_stamp'])): ?>

                                    <br><small class="text-muted">+ <?= formatMoney(documentStampFeeAmount()) ?> documentary stamp</small>

                                <?php endif; ?>

                            </td>

                            <td data-label="Days"><?= (int) $doc['processing_days'] ?></td>

                            <td data-label="Requests"><?= (int) $doc['request_count'] ?></td>

                            <td data-label="Status"><?= $doc['is_active'] ? '<span class="badge badge-completed">Active</span>' : '<span class="badge badge-rejected">Inactive</span>' ?></td>

                            <td data-label="Actions" class="action-cell">

                                <div class="action-cell-buttons">

                                <button type="button" <?= adminSettingsIconBtnAttrs('edit') ?> data-admin-form-edit="<?= adminFormRecordAttr([

                                    'document_type_id' => (int) $doc['id'],

                                    'name' => $doc['name'],

                                    'code' => $doc['code'],

                                    'description' => $doc['description'] ?? '',

                                    'base_fee' => $doc['base_fee'],

                                    'fee_per_set' => (int) ($doc['fee_per_set'] ?? 0),

                                    'requires_documentary_stamp' => (int) ($doc['requires_documentary_stamp'] ?? 0),

                                    'processing_days' => (int) $doc['processing_days'],

                                    'requires_upload' => (int) $doc['requires_upload'],

                                    'is_active' => (int) $doc['is_active'],

                                    'assignment_office' => normalizeAssignmentOffice($doc['assignment_office'] ?? 'registrar'),

                                ]) ?>"><?= adminSettingsIconBtnContent('edit') ?></button>

                                <form method="POST">

                                    <?= csrfField() ?>

                                    <input type="hidden" name="action" value="toggle">

                                    <input type="hidden" name="document_type_id" value="<?= $doc['id'] ?>">

                                    <?php $toggleAction = $doc['is_active'] ? 'deactivate' : 'activate'; ?>
                                    <button type="submit" <?= adminSettingsIconBtnAttrs($toggleAction) ?>><?= adminSettingsIconBtnContent($toggleAction) ?></button>

                                </form>

                                <form method="POST" onsubmit="return confirm('<?= $doc['request_count'] > 0 ? 'This document has requests and will be deactivated only. Continue?' : 'Delete this document type permanently?' ?>')">

                                    <?= csrfField() ?>

                                    <input type="hidden" name="action" value="delete">

                                    <input type="hidden" name="document_type_id" value="<?= $doc['id'] ?>">

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



<?php renderAdminFormModalOpen('Document Types', 'Add New Document'); ?>

<form method="POST" class="form-grid document-types-form" data-admin-form

    data-create-title="Add New Document"

    data-update-title="Update Document"

    data-create-submit-label="Add Document"

    data-update-submit-label="Update Document"

    data-create-submit-icon="fa-plus"

    data-update-submit-icon="fa-save"

    data-id-field="document_type_id">

    <?= csrfField() ?>

    <input type="hidden" name="action" value="create">

    <input type="hidden" name="document_type_id" value="">



    <div class="form-group">

        <label for="doc_name">Document Name *</label>

        <input type="text" id="doc_name" name="name" required maxlength="150" placeholder="e.g. Transcript of Records">

    </div>



    <div class="form-row">

        <div class="form-group">

            <label for="doc_code">Code *</label>

            <input type="text" id="doc_code" name="code" required maxlength="20" placeholder="e.g. TOR" class="input-uppercase">

        </div>

        <div class="form-group">

            <label for="doc_processing_days">Processing Days (Working Days)</label>

            <input type="number" id="doc_processing_days" name="processing_days" min="1" max="30" value="3">

            <small class="text-muted">Used to auto-calculate on-site release dates (weekends excluded).</small>

        </div>

    </div>



    <div class="form-group">

        <label for="doc_description">Description</label>

        <textarea id="doc_description" name="description" rows="2" placeholder="Brief description for students..."></textarea>

    </div>



    <div class="form-group">

        <label for="doc_base_fee">Base Fee (₱)</label>

        <input type="number" id="doc_base_fee" name="base_fee" min="0" step="0.01" value="0.00">

        <small class="text-muted" id="doc_fee_help">Total document fee = fee per copy × number of copies requested.</small>

    </div>



    <div class="form-group">

        <label class="checkbox-label">

            <input type="checkbox" name="fee_per_set" value="1" id="doc_fee_per_set">

            Charge per set (flat fee; quantity does not increase price)

        </label>

    </div>



    <div class="form-group">

        <label class="checkbox-label">

            <input type="checkbox" name="requires_documentary_stamp" value="1">

            Requires documentary stamp (+<?= formatMoney(documentStampFeeAmount()) ?>)

        </label>

    </div>



    <div class="form-group">

        <label class="checkbox-label">

            <input type="checkbox" name="requires_upload" value="1">

            Requires supporting document upload

        </label>

    </div>



    <div class="form-group">
        <label for="doc_assignment_office">Default Assignment Office</label>
        <select id="doc_assignment_office" name="assignment_office">
            <?php foreach (assignmentOfficeOptions() as $office => $label): ?>
                <option value="<?= e($office) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <small class="text-muted">Suggested office when registrar assigns this document. SOA defaults to Accounting; other offices remain available as assignees.</small>
    </div>

    <div class="form-group">

        <label class="checkbox-label">

            <input type="checkbox" name="is_active" value="1" data-default-checked>

            Active (available for student requests)

        </label>

    </div>



    <?php renderAdminFormModalFooter('Add Document', 'fa-plus'); ?>

</form>

<?php renderAdminFormModalClose(); ?>



<?php if ($editDoc): ?>

<script>window.__ADMIN_FORM_EDIT__ = <?= json_encode([

    'document_type_id' => (int) $editDoc['id'],

    'name' => $editDoc['name'],

    'code' => $editDoc['code'],

    'description' => $editDoc['description'] ?? '',

    'base_fee' => $editDoc['base_fee'],

    'fee_per_set' => (int) ($editDoc['fee_per_set'] ?? 0),

    'requires_documentary_stamp' => (int) ($editDoc['requires_documentary_stamp'] ?? 0),

    'processing_days' => (int) $editDoc['processing_days'],

    'requires_upload' => (int) $editDoc['requires_upload'],

    'is_active' => (int) $editDoc['is_active'],

    'assignment_office' => normalizeAssignmentOffice($editDoc['assignment_office'] ?? 'registrar'),

], JSON_UNESCAPED_UNICODE) ?>;</script>

<?php endif; ?>

<script>
(function () {
    const feePerSet = document.getElementById('doc_fee_per_set');
    const feeHelp = document.getElementById('doc_fee_help');
    if (!feePerSet || !feeHelp) return;

    function syncFeeHelp() {
        feeHelp.textContent = feePerSet.checked
            ? 'Total document fee = flat fee per set (quantity does not increase price).'
            : 'Total document fee = fee per copy × number of copies requested.';
    }

    feePerSet.addEventListener('change', syncFeeHelp);
    syncFeeHelp();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

