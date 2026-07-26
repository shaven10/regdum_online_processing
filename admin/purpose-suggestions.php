<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/purpose-suggestions.php';
require_once __DIR__ . '/../includes/student.php';
require_once __DIR__ . '/../includes/document-rules.php';

requireRole('admin');

$db = getDB();
ensureRequestPurposesSchema();
ensureDocumentEnrollmentRulesSchema();

$enrollmentStatuses = enrollmentStatusOptions();
$allowedDocIdsByStatus = [];
foreach (array_keys($enrollmentStatuses) as $status) {
    $allowedDocIdsByStatus[$status] = array_fill_keys(
        array_map('intval', array_column(getAvailableDocumentTypesForEnrollment($status), 'id')),
        true
    );
}
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
        $focusStatusRaw = trim((string) ($_POST['focus_status'] ?? ''));
        $focusStatus = $focusStatusRaw !== '' && array_key_exists($focusStatusRaw, $enrollmentStatuses)
            ? $focusStatusRaw
            : null;
        $isFocusedUpdate = $isUpdate && $focusStatus !== null;

        $data = [
            'label'      => trim($_POST['label'] ?? ''),
            'sort_order' => max(0, (int) ($_POST['sort_order'] ?? 0)),
            'is_active'  => !empty($_POST['is_active']) ? 1 : 0,
        ];

        $settingsByStatus = [];
        $suggestionsByStatus = [];
        $statusesToSave = $isFocusedUpdate ? [$focusStatus] : array_keys($enrollmentStatuses);
        $firstHint = '';

        foreach ($statusesToSave as $status) {
            $hint = trim((string) ($_POST['hint_' . $status] ?? ''));
            if ($firstHint === '' && $hint !== '') {
                $firstHint = $hint;
            }
            $settingsByStatus[$status] = [
                'is_enabled' => !empty($_POST['enabled_' . $status]) ? 1 : 0,
                'hint' => $hint,
            ];
            if (!empty($_POST['no_suggestions_' . $status])) {
                $suggestionsByStatus[$status] = [];
            } else {
                $allowedLookup = $allowedDocIdsByStatus[$status] ?? [];
                $suggestionsByStatus[$status] = array_values(array_unique(array_filter(array_map(
                    static function ($id) use ($allowedLookup): int {
                        $documentTypeId = (int) $id;
                        return ($documentTypeId > 0 && isset($allowedLookup[$documentTypeId])) ? $documentTypeId : 0;
                    },
                    $_POST['suggested_docs_' . $status] ?? []
                ))));
            }
        }

        $errors = [];
        if (!$isFocusedUpdate && $data['label'] === '') {
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

                    if (!$isFocusedUpdate) {
                        $db->prepare('UPDATE request_purposes SET label = ?, hint = ?, sort_order = ?, is_active = ? WHERE id = ?')
                           ->execute([
                               $data['label'],
                               $firstHint !== '' ? $firstHint : ($existing['hint'] ?? null),
                               $data['sort_order'],
                               $data['is_active'],
                               $purposeId,
                           ]);
                    } elseif ($firstHint !== '') {
                        $db->prepare('UPDATE request_purposes SET hint = ? WHERE id = ?')
                           ->execute([$firstHint, $purposeId]);
                    }

                    saveRequestPurposeEnrollmentSettings($purposeId, $settingsByStatus);
                    saveRequestPurposeSuggestionsByEnrollment($purposeId, $suggestionsByStatus);
                    auditLog('update_request_purpose', 'request_purposes', $purposeId, null, [
                        'focus_status' => $focusStatus,
                        'settings' => $settingsByStatus,
                        'suggestions' => $suggestionsByStatus,
                    ]);
                    $statusLabel = $focusStatus ? ($enrollmentStatuses[$focusStatus] ?? $focusStatus) : 'all classifications';
                    setFlash('success', $isFocusedUpdate
                        ? 'Purpose settings updated for ' . $statusLabel . ' requestors.'
                        : 'Purpose settings updated for Graduated, Enrolled, and Inactive requestors.');
                } else {
                    $db->prepare('INSERT INTO request_purposes (code, label, hint, sort_order, is_active) VALUES (?, ?, ?, ?, ?)')
                       ->execute([
                           $postedCode,
                           $data['label'],
                           $firstHint !== '' ? $firstHint : null,
                           $data['sort_order'],
                           $data['is_active'],
                       ]);

                    $purposeId = (int) $db->lastInsertId();
                    saveRequestPurposeEnrollmentSettings($purposeId, $settingsByStatus);
                    saveRequestPurposeSuggestionsByEnrollment($purposeId, $suggestionsByStatus);
                    auditLog('create_request_purpose', 'request_purposes', $purposeId);
                    setFlash('success', 'Purpose added with per-classification suggestion settings.');
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
$documentTypes = $db->query('SELECT id, name, code, is_active FROM document_types WHERE is_active = 1 ORDER BY name')->fetchAll();

// Keep list badges aligned with release rules for each classification.
foreach ($purposes as &$purposeRow) {
    foreach (array_keys($enrollmentStatuses) as $status) {
        $allowedLookup = $allowedDocIdsByStatus[$status] ?? [];
        $ids = array_map('intval', $purposeRow['suggestions_by_status'][$status] ?? []);
        $names = $purposeRow['suggested_names_by_status'][$status] ?? [];
        $filteredIds = [];
        $filteredNames = [];
        foreach ($ids as $index => $documentTypeId) {
            if (!isset($allowedLookup[$documentTypeId])) {
                continue;
            }
            $filteredIds[] = $documentTypeId;
            $filteredNames[] = $names[$index] ?? '';
        }
        $purposeRow['suggestions_by_status'][$status] = $filteredIds;
        $purposeRow['suggested_names_by_status'][$status] = array_values(array_filter(
            $filteredNames,
            static fn($name): bool => $name !== ''
        ));
    }
}
unset($purposeRow);

$editSettings = $editPurpose ? getPurposeEnrollmentSettingsMap((int) $editPurpose['id']) : [];
$editSuggestions = [];
if ($editPurpose) {
    foreach (array_keys($enrollmentStatuses) as $status) {
        $editSuggestions[$status] = getSuggestedDocumentTypeIdsForPurposeId((int) $editPurpose['id'], $status);
    }
}

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
            <p class="text-muted">Use Edit in Actions to update purpose details and suggested documents for Graduated, Enrolled, and Inactive requestors.</p>

            <?php if (empty($purposes)): ?>
                <div class="empty-state"><i class="fas fa-bullseye"></i><p>No purposes configured.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                <table class="data-table data-table-responsive document-types-table">
                    <thead>
                        <tr>
                            <th>Purpose</th>
                            <th>Graduated</th>
                            <th>Enrolled</th>
                            <th>Inactive</th>
                            <th>Requests</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($purposes as $purpose): ?>
                        <?php
                        $settings = $purpose['enrollment_settings'] ?? [];
                        $namesByStatus = $purpose['suggested_names_by_status'] ?? [];
                        $editPayload = [
                            'purpose_id' => (int) $purpose['id'],
                            'code' => $purpose['code'],
                            'label' => $purpose['label'],
                            'sort_order' => (int) $purpose['sort_order'],
                            'is_active' => (int) $purpose['is_active'],
                        ];
                        foreach (array_keys($enrollmentStatuses) as $status) {
                            $docs = array_map('strval', $purpose['suggestions_by_status'][$status] ?? []);
                            $editPayload['enabled_' . $status] = (int) ($settings[$status]['is_enabled'] ?? 0);
                            $editPayload['hint_' . $status] = (string) ($settings[$status]['hint'] ?? '');
                            $editPayload['suggested_docs_' . $status] = $docs;
                            $editPayload['no_suggestions_' . $status] = $docs === [] ? 1 : 0;
                        }
                        ?>
                        <tr>
                            <td data-label="Purpose">
                                <strong><?= e($purpose['label']) ?></strong><br>
                                <code><?= e($purpose['code']) ?></code>
                            </td>
                            <?php foreach ($enrollmentStatuses as $status => $statusLabel): ?>
                                <td data-label="<?= e($statusLabel) ?>">
                                    <?php if (empty($settings[$status]['is_enabled'])): ?>
                                        <span class="badge badge-rejected">Hidden</span>
                                    <?php elseif (empty($namesByStatus[$status])): ?>
                                        <span class="badge badge-completed">No suggestions</span>
                                    <?php else: ?>
                                        <div class="requirement-config-tags">
                                            <?php foreach ($namesByStatus[$status] as $name): ?>
                                                <span class="badge badge-review"><?= e($name) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td data-label="Requests"><?= (int) $purpose['request_count'] ?></td>
                            <td data-label="Status">
                                <?= $purpose['is_active'] ? '<span class="badge badge-completed">Active</span>' : '<span class="badge badge-rejected">Inactive</span>' ?>
                            </td>
                            <td data-label="Actions" class="action-cell">
                                <div class="action-cell-buttons">
                                <button type="button" <?= adminSettingsIconBtnAttrs('edit') ?> data-admin-form-edit="<?= adminFormRecordAttr($editPayload) ?>"><?= adminSettingsIconBtnContent('edit') ?></button>
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

<?php
$statusKeys = array_keys($enrollmentStatuses);
$defaultStatusTab = $statusKeys[0] ?? 'graduated';
?>
<?php renderAdminFormModalOpen('Purpose & Suggested Documents', 'Add Purpose', 'adminFormModal', true); ?>
<form method="POST" class="form-grid settings-simple-form document-types-form" data-admin-form
    data-create-title="Add Purpose"
    data-update-title="Update Purpose"
    data-create-submit-label="Save"
    data-update-submit-label="Save"
    data-create-submit-icon="fa-save"
    data-update-submit-icon="fa-save"
    data-id-field="purpose_id">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="purpose_id" value="">
    <input type="hidden" name="focus_status" id="purposeFocusStatus" value="">

    <div id="purposeMetaFields" data-purpose-meta>
        <div class="form-group" id="purposeCodeGroup">
            <label for="purpose_code">Code *</label>
            <input type="text" id="purpose_code" name="code" maxlength="50" placeholder="employment">
        </div>

        <div class="form-row settings-simple-meta-row">
            <div class="form-group">
                <label for="purpose_label">Label *</label>
                <input type="text" id="purpose_label" name="label" maxlength="150" placeholder="Employment">
            </div>
            <div class="form-group">
                <label for="purpose_sort_order">Sort</label>
                <input type="number" id="purpose_sort_order" name="sort_order" min="0" value="0">
            </div>
            <div class="form-group settings-simple-meta-check">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_active" value="1" data-default-checked>
                    <span>Active</span>
                </label>
            </div>
        </div>
    </div>

    <div class="settings-segment-tabs" role="tablist" aria-label="Requestor classification" data-purpose-tabs>
        <?php foreach ($enrollmentStatuses as $status => $statusLabel): ?>
            <button type="button"
                class="settings-segment-tab<?= $status === $defaultStatusTab ? ' is-active' : '' ?>"
                data-settings-tab="<?= e($status) ?>"
                aria-selected="<?= $status === $defaultStatusTab ? 'true' : 'false' ?>"><?= e($statusLabel) ?></button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($enrollmentStatuses as $status => $statusLabel): ?>
        <section class="settings-segment-panel<?= $status === $defaultStatusTab ? ' is-active' : '' ?>"
            data-settings-panel="<?= e($status) ?>"
            <?= $status === $defaultStatusTab ? '' : 'hidden' ?>>
            <label class="checkbox-label settings-simple-toggle">
                <input type="checkbox" name="enabled_<?= e($status) ?>" value="1" data-default-checked data-purpose-enabled="<?= e($status) ?>">
                <span>Show this purpose</span>
            </label>

            <div data-purpose-fields="<?= e($status) ?>">
                <div class="form-group">
                    <label for="purpose_hint_<?= e($status) ?>">Hint</label>
                    <input type="text" id="purpose_hint_<?= e($status) ?>" name="hint_<?= e($status) ?>"
                        placeholder="Tip shown when selected">
                </div>

                <label class="checkbox-label settings-simple-toggle">
                    <input type="checkbox" name="no_suggestions_<?= e($status) ?>" value="1" data-no-suggestions="<?= e($status) ?>">
                    <span>No suggestions</span>
                </label>

                <div class="form-group" data-suggestions-list="<?= e($status) ?>">
                    <label>Suggested documents</label>
                    <p class="text-muted">Only credentials allowed for <?= e(strtolower($statusLabel)) ?> requestors can be selected.</p>
                    <div class="settings-simple-checklist" data-purpose-docs="<?= e($status) ?>">
                        <?php foreach ($documentTypes as $documentType): ?>
                            <?php
                            $documentTypeId = (int) $documentType['id'];
                            $isAllowed = isset($allowedDocIdsByStatus[$status][$documentTypeId]);
                            ?>
                            <label class="settings-simple-check<?= $isAllowed ? '' : ' is-disabled' ?>">
                                <input type="checkbox"
                                    name="suggested_docs_<?= e($status) ?>[]"
                                    value="<?= $documentTypeId ?>"
                                    <?= $isAllowed ? '' : 'disabled data-release-blocked="1"' ?>>
                                <span>
                                    <?= e($documentType['name']) ?>
                                    <?php if (!$isAllowed): ?>
                                        <span class="badge badge-rejected">Not allowed</span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

    <?php renderAdminFormModalFooter('Save', 'fa-save'); ?>
</form>
<?php renderAdminFormModalClose(); ?>

<?php if ($editPurpose): ?>
<?php
$editPayload = [
    'purpose_id' => (int) $editPurpose['id'],
    'code' => $editPurpose['code'],
    'label' => $editPurpose['label'],
    'sort_order' => (int) $editPurpose['sort_order'],
    'is_active' => (int) $editPurpose['is_active'],
];
foreach (array_keys($enrollmentStatuses) as $status) {
    $allowedLookup = $allowedDocIdsByStatus[$status] ?? [];
    $docs = array_values(array_map('strval', array_filter(
        array_map('intval', $editSuggestions[$status] ?? []),
        static fn(int $id): bool => isset($allowedLookup[$id])
    )));
    $editPayload['enabled_' . $status] = (int) ($editSettings[$status]['is_enabled'] ?? 0);
    $editPayload['hint_' . $status] = (string) ($editSettings[$status]['hint'] ?? '');
    $editPayload['suggested_docs_' . $status] = $docs;
    $editPayload['no_suggestions_' . $status] = $docs === [] ? 1 : 0;
}
?>
<script>window.__ADMIN_FORM_EDIT__ = <?= json_encode($editPayload, JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>

<script>
(function () {
    const form = document.querySelector('#adminFormModal [data-admin-form]');
    const codeGroup = document.getElementById('purposeCodeGroup');
    const codeInput = document.getElementById('purpose_code');
    const metaFields = document.getElementById('purposeMetaFields');
    const tablist = form ? form.querySelector('[data-purpose-tabs]') : null;
    if (!form || !codeGroup || !codeInput) return;

    const defaultStatus = <?= json_encode($defaultStatusTab) ?>;
    const focusStatusInput = document.getElementById('purposeFocusStatus');
    const labelInput = document.getElementById('purpose_label');

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

    function isReleaseBlocked(input) {
        return !!(input && input.getAttribute('data-release-blocked') === '1');
    }

    function syncNoSuggestions(status, noneChecked, clearChecks) {
        const list = form.querySelector('[data-suggestions-list="' + status + '"]');
        const noneToggle = form.querySelector('[data-no-suggestions="' + status + '"]');
        if (noneToggle) noneToggle.checked = !!noneChecked;
        if (!list) return;
        list.hidden = !!noneChecked;
        list.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
            if (isReleaseBlocked(checkbox)) {
                checkbox.disabled = true;
                checkbox.checked = false;
                return;
            }
            checkbox.disabled = !!noneChecked;
            if (noneChecked && clearChecks) checkbox.checked = false;
        });
    }

    function syncStatusPanel(status, enabled) {
        const fields = form.querySelector('[data-purpose-fields="' + status + '"]');
        if (!fields) return;
        fields.style.display = enabled ? '' : 'none';
        fields.querySelectorAll('input').forEach(function (input) {
            if (input.getAttribute('data-no-suggestions')) {
                input.disabled = !enabled;
                return;
            }
            if (input.name.indexOf('suggested_docs_') === 0) {
                return;
            }
            input.disabled = !enabled;
        });

        if (!enabled) {
            syncNoSuggestions(status, false, false);
            const list = form.querySelector('[data-suggestions-list="' + status + '"]');
            if (list) {
                list.querySelectorAll('input').forEach(function (input) {
                    input.disabled = true;
                });
            }
            return;
        }

        const noneToggle = form.querySelector('[data-no-suggestions="' + status + '"]');
        syncNoSuggestions(status, !!(noneToggle && noneToggle.checked), false);
    }

    function setUpdateMode(isFocusedUpdate, focusStatus) {
        if (metaFields) metaFields.hidden = !!isFocusedUpdate;
        if (tablist) tablist.hidden = !!isFocusedUpdate;
        codeInput.required = !isFocusedUpdate;
        codeInput.readOnly = isFocusedUpdate;
        if (labelInput) labelInput.required = !isFocusedUpdate;
        codeGroup.hidden = isFocusedUpdate;
        if (focusStatusInput) {
            focusStatusInput.value = isFocusedUpdate ? (focusStatus || '') : '';
        }

        if (focusStatus) {
            showMainTab(focusStatus);
        }
    }

    form.querySelectorAll('[data-settings-tab]').forEach(function (tab) {
        tab.addEventListener('click', function () {
            showMainTab(tab.getAttribute('data-settings-tab'));
        });
    });

    form.addEventListener('adminformpopulated', function (event) {
        const isUpdate = event.detail.mode === 'update';
        const record = event.detail.record || {};
        const focusStatus = record.focus_status || defaultStatus;
        const isFocusedUpdate = isUpdate && !!record.focus_status;

        setUpdateMode(isFocusedUpdate, focusStatus);

        form.querySelectorAll('[data-no-suggestions]').forEach(function (toggle) {
            const status = toggle.getAttribute('data-no-suggestions');
            const key = 'no_suggestions_' + status;
            const docs = Array.isArray(record['suggested_docs_' + status]) ? record['suggested_docs_' + status] : [];
            const none = record[key] === 1 || record[key] === '1' || record[key] === true || (isUpdate && docs.length === 0);
            syncNoSuggestions(status, none, none);
        });

        form.querySelectorAll('[data-settings-panel] input').forEach(function (input) {
            if (isReleaseBlocked(input)) {
                input.disabled = true;
                input.checked = false;
                return;
            }
            input.disabled = false;
        });

        form.querySelectorAll('[data-purpose-enabled]').forEach(function (toggle) {
            syncStatusPanel(toggle.getAttribute('data-purpose-enabled'), toggle.checked);
        });

        if (isFocusedUpdate) {
            form.querySelectorAll('[data-settings-panel]').forEach(function (panel) {
                const active = panel.getAttribute('data-settings-panel') === focusStatus;
                panel.querySelectorAll('input').forEach(function (input) {
                    if (!active) {
                        input.disabled = true;
                    }
                });
            });
            const activeToggle = form.querySelector('[data-purpose-enabled="' + focusStatus + '"]');
            if (activeToggle) {
                syncStatusPanel(focusStatus, activeToggle.checked);
            }
        }
    });

    form.querySelectorAll('[data-purpose-enabled]').forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            const status = toggle.getAttribute('data-purpose-enabled');
            syncStatusPanel(status, toggle.checked);
            if (!toggle.checked) {
                const fields = form.querySelector('[data-purpose-fields="' + status + '"]');
                if (fields) {
                    fields.querySelectorAll('input[type="checkbox"]').forEach(function (checkbox) {
                        if (checkbox.getAttribute('data-no-suggestions')) return;
                        checkbox.checked = false;
                    });
                }
                syncNoSuggestions(status, false, false);
            }
        });
    });

    form.querySelectorAll('[data-no-suggestions]').forEach(function (toggle) {
        toggle.addEventListener('change', function () {
            syncNoSuggestions(toggle.getAttribute('data-no-suggestions'), toggle.checked, true);
        });
    });

    form.addEventListener('submit', function () {
        const focusStatus = focusStatusInput ? focusStatusInput.value : '';
        if (!focusStatus) return;

        const enabledToggle = form.querySelector('[data-purpose-enabled="' + focusStatus + '"]');
        if (enabledToggle) enabledToggle.disabled = false;

        const noneToggle = form.querySelector('[data-no-suggestions="' + focusStatus + '"]');
        if (noneToggle) noneToggle.disabled = false;

        const fields = form.querySelector('[data-purpose-fields="' + focusStatus + '"]');
        if (fields && enabledToggle && enabledToggle.checked) {
            fields.querySelectorAll('input').forEach(function (input) {
                if (isReleaseBlocked(input)) {
                    input.disabled = true;
                    input.checked = false;
                    return;
                }
                if (input.name.indexOf('suggested_docs_') === 0 && noneToggle && noneToggle.checked) {
                    input.disabled = true;
                    return;
                }
                input.disabled = false;
            });
        }
    });

    document.querySelectorAll('[data-open-admin-form="create"]').forEach(function (button) {
        button.addEventListener('click', function () {
            setUpdateMode(false, defaultStatus);
            form.querySelectorAll('[data-settings-panel] input').forEach(function (input) {
                if (isReleaseBlocked(input)) {
                    input.disabled = true;
                    input.checked = false;
                    return;
                }
                input.disabled = false;
            });
            form.querySelectorAll('[data-no-suggestions]').forEach(function (toggle) {
                syncNoSuggestions(toggle.getAttribute('data-no-suggestions'), false, false);
            });
            form.querySelectorAll('[data-purpose-enabled]').forEach(function (toggle) {
                syncStatusPanel(toggle.getAttribute('data-purpose-enabled'), toggle.checked);
            });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
