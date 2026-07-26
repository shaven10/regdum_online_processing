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
        $normalizeCodes = static function ($codes): array {
            return array_values(array_filter(array_map(
                static fn($code): string => normalizeRequirementCode((string) $code),
                (array) $codes
            )));
        };

        $firstCodes = $normalizeCodes($_POST['req_codes_first'] ?? []);
        $secondCodes = $normalizeCodes($_POST['req_codes_second'] ?? []);
        $firstRequired = empty($_POST['no_requirements_first']);
        $secondRequired = empty($_POST['no_requirements_second']);

        if ($documentTypeId) {
            saveDocumentTypeRequirementDefaults(
                $documentTypeId,
                $firstCodes,
                $firstRequired,
                $secondCodes,
                $secondRequired
            );
            setFlash('success', 'First request and second copy requirement settings saved for this credential.');
            redirect(APP_URL . '/admin/requirement-settings.php');
        }
    }

    redirect(APP_URL . '/admin/requirement-settings.php');
}

$docTypes = $db->query('SELECT * FROM document_types ORDER BY name')->fetchAll();
$editDocType = null;
$editFirstCodes = [];
$editSecondCodes = [];
$editFirstRequired = 1;
$editSecondRequired = 1;

if ($editId) {
    foreach ($docTypes as $docType) {
        if ((int) $docType['id'] === $editId) {
            $editDocType = $docType;
            $editFirstRequired = documentTypeRequiresRequirements($editId, 'first_request') ? 1 : 0;
            $editSecondRequired = documentTypeRequiresRequirements($editId, 'second_copy') ? 1 : 0;
            $editFirstCodes = getSavedDocumentTypeRequirementDefaults($editId, 'first_request');
            $editSecondCodes = getSavedDocumentTypeRequirementDefaults($editId, 'second_copy');
            if ($editFirstCodes === [] && $editFirstRequired) {
                $editFirstCodes = getDocumentTypeRequirementDefaults($editId, 'first_request');
            }
            if ($editSecondCodes === [] && $editSecondRequired) {
                $editSecondCodes = getDocumentTypeRequirementDefaults($editId, 'second_copy');
            }
            break;
        }
    }
}

function requirementSettingLabels(array $codes, array $checklist): array {
    $labels = [];
    foreach ($codes as $code) {
        $labels[] = $checklist[$code]['name'] ?? ucwords(str_replace('_', ' ', (string) $code));
    }
    return $labels;
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
                    <strong>Auto-confirm credentials with no requirements</strong>
                    <p>
                        When enabled, only credentials marked <strong>No requirements required</strong> for the selected request type
                        (first request or second copy) skip registrar confirmation and go straight to payment.
                        Credentials with configured requirements still need manual registrar confirmation — with those requirements pre-checked.
                    </p>
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
            <p class="text-muted">Set requirements for first request and second copy on each credential.</p>
            <div class="table-responsive">
            <table class="data-table data-table-responsive">
                <thead>
                    <tr>
                        <th>Credential</th>
                        <th>First Request</th>
                        <th>Second Copy</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($docTypes as $docType): ?>
                        <?php
                        $docTypeId = (int) $docType['id'];
                        $firstRequired = documentTypeRequiresRequirements($docTypeId, 'first_request');
                        $secondRequired = documentTypeRequiresRequirements($docTypeId, 'second_copy');
                        $firstSaved = getSavedDocumentTypeRequirementDefaults($docTypeId, 'first_request');
                        $secondSaved = getSavedDocumentTypeRequirementDefaults($docTypeId, 'second_copy');
                        $firstCodes = $firstRequired
                            ? ($firstSaved !== [] ? $firstSaved : getDocumentTypeRequirementDefaults($docTypeId, 'first_request'))
                            : [];
                        $secondCodes = $secondRequired
                            ? ($secondSaved !== [] ? $secondSaved : getDocumentTypeRequirementDefaults($docTypeId, 'second_copy'))
                            : [];
                        $firstLabels = requirementSettingLabels($firstCodes, $checklist);
                        $secondLabels = requirementSettingLabels($secondCodes, $checklist);
                        ?>
                        <tr>
                            <td data-label="Credential">
                                <strong><?= e($docType['name']) ?></strong><br>
                                <code><?= e($docType['code']) ?></code>
                            </td>
                            <td data-label="First Request">
                                <?php if (!$firstRequired): ?>
                                    <span class="badge badge-completed">None required</span>
                                <?php elseif (empty($firstLabels)): ?>
                                    <span class="text-muted">No items</span>
                                <?php else: ?>
                                    <div class="requirement-config-tags">
                                        <?php foreach ($firstLabels as $label): ?>
                                            <span class="badge badge-review"><?= e($label) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Second Copy">
                                <?php if (!$secondRequired): ?>
                                    <span class="badge badge-completed">None required</span>
                                <?php elseif (empty($secondLabels)): ?>
                                    <span class="text-muted">No items</span>
                                <?php else: ?>
                                    <div class="requirement-config-tags">
                                        <?php foreach ($secondLabels as $label): ?>
                                            <span class="badge badge-processing"><?= e($label) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Action">
                                <button type="button" <?= adminSettingsIconBtnAttrs('configure', 'primary') ?> data-admin-form-edit="<?= adminFormRecordAttr([
                                    'document_type_id' => $docTypeId,
                                    'name' => $docType['name'],
                                    'req_codes_first' => array_values(array_map('strval', $firstCodes)),
                                    'req_codes_second' => array_values(array_map('strval', $secondCodes)),
                                    'requirements_required_first' => $firstRequired ? 1 : 0,
                                    'requirements_required_second' => $secondRequired ? 1 : 0,
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
<form method="POST" class="form-grid settings-simple-form" data-admin-form data-admin-form-type="requirements"
    data-create-title="Configure Requirements"
    data-update-title="Configure Requirements"
    data-create-action="save_document"
    data-update-action="save_document"
    data-create-submit-label="Save"
    data-update-submit-label="Save"
    data-id-field="document_type_id">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_document">
    <input type="hidden" name="document_type_id" value="">

    <div class="settings-segment-tabs" role="tablist" aria-label="Request type">
        <button type="button" class="settings-segment-tab is-active" data-settings-tab="first" aria-selected="true">First Request</button>
        <button type="button" class="settings-segment-tab" data-settings-tab="second" aria-selected="false">Second Copy</button>
    </div>

    <?php foreach (['first' => 'first request', 'second' => 'second copy'] as $panelKey => $panelLabel): ?>
        <?php
        $isFirstPanel = $panelKey === 'first';
        $noneName = $isFirstPanel ? 'no_requirements_first' : 'no_requirements_second';
        $noneId = $isFirstPanel ? 'requirementNoneToggleFirst' : 'requirementNoneToggleSecond';
        $codesName = $isFirstPanel ? 'req_codes_first[]' : 'req_codes_second[]';
        ?>
        <section class="settings-segment-panel<?= $isFirstPanel ? ' is-active' : '' ?>"
            data-settings-panel="<?= e($panelKey) ?>"
            <?= $isFirstPanel ? '' : 'hidden' ?>>
            <label class="checkbox-label settings-simple-toggle">
                <input type="checkbox" name="<?= e($noneName) ?>" value="1" id="<?= e($noneId) ?>">
                <span>No requirements for <?= e($panelLabel) ?></span>
            </label>

            <div class="settings-simple-checklist" data-requirement-checklist="<?= e($panelKey) ?>">
                <?php foreach ($checklist as $code => $item): ?>
                    <label class="settings-simple-check">
                        <input type="checkbox" name="<?= e($codesName) ?>" value="<?= e($code) ?>">
                        <span><?= e($item['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php renderAdminFormModalFooter('Save', 'fa-save'); ?>
</form>
<?php renderAdminFormModalClose(); ?>

<?php if ($editDocType): ?>
<script>window.__ADMIN_FORM_EDIT__ = <?= json_encode([
    'document_type_id' => (int) $editDocType['id'],
    'name' => $editDocType['name'],
    'req_codes_first' => array_values(array_map('strval', $editFirstCodes)),
    'req_codes_second' => array_values(array_map('strval', $editSecondCodes)),
    'requirements_required_first' => $editFirstRequired,
    'requirements_required_second' => $editSecondRequired,
], JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>

<script>
(function () {
    const form = document.querySelector('#adminFormModal [data-admin-form-type="requirements"]');
    if (!form) return;

    function showMainTab(key) {
        form.querySelectorAll('[data-settings-tab]').forEach(function (tab) {
            const active = tab.getAttribute('data-settings-tab') === key;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        form.querySelectorAll('[data-settings-panel]').forEach(function (panel) {
            const active = panel.getAttribute('data-settings-panel') === key;
            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });
    }

    function syncPanel(kind, noneChecked, clearChecks) {
        const checklist = form.querySelector('[data-requirement-checklist="' + kind + '"]');
        if (!checklist) return;
        checklist.hidden = noneChecked;
        checklist.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
            checkbox.disabled = noneChecked;
            if (noneChecked && clearChecks) checkbox.checked = false;
        });
    }

    form.querySelectorAll('[data-settings-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            showMainTab(tab.getAttribute('data-settings-tab'));
        });
    });

    form.addEventListener('adminformpopulated', function (event) {
        const record = event.detail.record || {};
        const firstRequired = !(
            record.requirements_required_first === false
            || record.requirements_required_first === 0
            || record.requirements_required_first === '0'
        );
        const secondRequired = !(
            record.requirements_required_second === false
            || record.requirements_required_second === 0
            || record.requirements_required_second === '0'
        );

        const firstToggle = document.getElementById('requirementNoneToggleFirst');
        const secondToggle = document.getElementById('requirementNoneToggleSecond');
        if (firstToggle) firstToggle.checked = !firstRequired;
        if (secondToggle) secondToggle.checked = !secondRequired;

        syncPanel('first', !firstRequired, !firstRequired);
        syncPanel('second', !secondRequired, !secondRequired);
        showMainTab('first');
    });

    document.getElementById('requirementNoneToggleFirst')?.addEventListener('change', function () {
        syncPanel('first', this.checked, true);
    });
    document.getElementById('requirementNoneToggleSecond')?.addEventListener('change', function () {
        syncPanel('second', this.checked, true);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
