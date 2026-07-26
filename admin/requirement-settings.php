<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
requireRole('admin');

ensureRequirementDefaultsSchema();

$db = getDB();
$checklist = registrarRequirementChecklist();

$autoApply = isAutoApplyRequirementsEnabled();

$editId = (int) ($_GET['document_type_id'] ?? 0);



if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {

    $action = $_POST['action'] ?? '';



    if ($action === 'save_global') {

        setAppSetting('auto_apply_requirement_defaults', !empty($_POST['auto_apply']) ? '1' : '0');

        setFlash('success', 'Global requirement settings updated.');

        redirect(APP_URL . '/admin/requirement-settings.php');

    }



    if ($action === 'save_document') {

        $documentTypeId = (int) ($_POST['document_type_id'] ?? 0);

        $codes = $_POST['req_codes'] ?? [];



        if ($documentTypeId) {

            $requirementsRequired = empty($_POST['no_requirements']);

            saveDocumentTypeRequirementDefaults($documentTypeId, $codes, $requirementsRequired);

            setFlash('success', $requirementsRequired
                ? 'Requirement defaults saved for this credential.'
                : 'Credential updated — no requirements will be required for this document.');

        }

    }



    redirect(APP_URL . '/admin/requirement-settings.php');

}



$docTypes = $db->query('SELECT * FROM document_types ORDER BY name')->fetchAll();

$editDocType = null;

$editCodes = [];



if ($editId) {

    foreach ($docTypes as $docType) {

        if ((int) $docType['id'] === $editId) {

            $editDocType = $docType;

            $editCodes = getDocumentTypeRequirementDefaults($editId);

            break;

        }

    }

}



$pageTitle = 'Requirement Settings';
$activeNav = 'requirements';
require_once __DIR__ . '/../includes/header.php';
?>

<?php renderAdminCredentialSettingsNav('requirements'); ?>

<div class="card">

    <div class="card-header"><h2>Global Settings</h2></div>

    <div class="card-body">

        <form method="POST" class="form-grid">

            <?= csrfField() ?>

            <input type="hidden" name="action" value="save_global">

            <label class="compliance-item">

                <input type="checkbox" name="auto_apply" value="1" <?= $autoApply ? 'checked' : '' ?>>

                <div>

                    <strong>Automatically assign requirements on request submission</strong>

                    <p>When enabled, the configured requirements for each credential type are assigned immediately after a student submits a request — no manual registrar confirmation needed for Step 2.</p>

                </div>

            </label>

            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Save Global Setting</button>

        </form>

    </div>

</div>



<div class="settings-list-page">

    <div class="card">

        <div class="card-header"><h2>Credential Types</h2></div>

        <div class="card-body">

            <p class="text-muted">Configure which requirements are automatically assigned per credential type.</p>

            <div class="table-responsive">

            <table class="data-table data-table-responsive">

                <thead>

                    <tr>

                        <th>Credential</th>

                        <th>Code</th>

                        <th>Requirements</th>

                        <th>Action</th>

                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($docTypes as $docType): ?>

                        <?php
                        $requiresRequirements = documentTypeRequiresRequirements((int) $docType['id']);
                        $codes = $requiresRequirements ? getDocumentTypeRequirementDefaults((int) $docType['id']) : [];
                        ?>

                        <tr>

                            <td data-label="Credential"><strong><?= e($docType['name']) ?></strong></td>

                            <td data-label="Code"><code><?= e($docType['code']) ?></code></td>

                            <td data-label="Requirements">
                                <?php if (!$requiresRequirements): ?>
                                    <span class="badge badge-completed">None required</span>
                                <?php else: ?>
                                    <?= count($codes) ?> item(s)
                                <?php endif; ?>
                            </td>

                            <td data-label="Action">

                                <button type="button" <?= adminSettingsIconBtnAttrs('configure', 'primary') ?> data-admin-form-edit="<?= adminFormRecordAttr([

                                    'document_type_id' => (int) $docType['id'],

                                    'name' => $docType['name'],

                                    'req_codes' => $codes,

                                    'requirements_required' => $requiresRequirements ? 1 : 0,

                                ]) ?>"><?= adminSettingsIconBtnContent('configure') ?></button>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

            </div>

        </div>

    </div>

</div>



<?php renderAdminFormModalOpen('Requirement Settings', 'Configure Requirements', 'adminFormModal', true); ?>

<form method="POST" class="form-grid" data-admin-form data-admin-form-type="requirements"

    data-create-title="Configure Requirements"

    data-update-title="Configure Requirements"

    data-create-action="save_document"

    data-update-action="save_document"

    data-create-submit-label="Save Requirements"

    data-update-submit-label="Save Requirements"

    data-id-field="document_type_id">

    <?= csrfField() ?>

    <input type="hidden" name="action" value="save_document">

    <input type="hidden" name="document_type_id" value="">



    <p class="text-muted" data-requirement-modal-intro>

        These requirements will be <?= $autoApply ? 'automatically assigned when a student requests' : 'pre-selected for the registrar when reviewing' ?>

        the selected credential.

    </p>

    <label class="compliance-item requirement-none-toggle">

        <input type="checkbox" name="no_requirements" value="1" id="requirementNoneToggle">

        <div>

            <strong>No requirements required</strong>

            <p>Students can proceed directly to payment for this credential without clearance, uploads, or other requirement steps.</p>

        </div>

    </label>

    <div class="compliance-checklist registrar-checklist" data-requirement-checklist>

        <?php foreach ($checklist as $code => $item): ?>

            <label class="compliance-item">

                <input type="checkbox" name="req_codes[]" value="<?= e($code) ?>">

                <div>

                    <strong><?= e($item['name']) ?></strong>

                    <?php if (!empty($item['optional'])): ?>

                        <span class="badge badge-review">Optional — 2nd request</span>

                    <?php endif; ?>

                    <?php if (!$item['requires_upload']): ?>

                        <span class="badge badge-submitted">Online clearance</span>

                    <?php endif; ?>

                    <p><?= e($item['description']) ?></p>

                </div>

            </label>

        <?php endforeach; ?>

    </div>



    <?php renderAdminFormModalFooter('Save Requirements', 'fa-save'); ?>

</form>

<?php renderAdminFormModalClose(); ?>

<?php if ($editDocType): ?>

<script>window.__ADMIN_FORM_EDIT__ = <?= json_encode([

    'document_type_id' => (int) $editDocType['id'],

    'name' => $editDocType['name'],

    'req_codes' => $editCodes,

    'requirements_required' => documentTypeRequiresRequirements((int) $editDocType['id']) ? 1 : 0,

], JSON_UNESCAPED_UNICODE) ?>;</script>

<?php endif; ?>

<script>
(function () {
    const form = document.querySelector('#adminFormModal [data-admin-form-type="requirements"]');
    const toggle = document.getElementById('requirementNoneToggle');
    const checklist = document.querySelector('[data-requirement-checklist]');

    if (!form || !toggle || !checklist) {
        return;
    }

    function syncRequirementNoneToggle() {
        const disabled = toggle.checked;
        checklist.hidden = disabled;
        if (disabled) {
            checklist.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                checkbox.checked = false;
            });
        }
    }

    form.addEventListener('adminformpopulated', function (event) {
        const record = event.detail.record || null;
        const requiresRequirements = !record || record.requirements_required === true || record.requirements_required === 1 || record.requirements_required === '1';
        toggle.checked = !requiresRequirements;
        syncRequirementNoneToggle();
    });

    toggle.addEventListener('change', syncRequirementNoneToggle);
})();
</script>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>

