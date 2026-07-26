<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/document-rules.php';
requireRole('admin');

ensureDocumentEnrollmentRulesSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    saveDocumentEnrollmentRulesFromPost($_POST['rules'] ?? []);
    setFlash('success', 'Credential release rules updated successfully.', [
        'title' => 'Rules Saved',
        'next_step' => 'Students will only see documents allowed for their enrollment status.',
    ]);
    redirect(APP_URL . '/admin/document-release-rules.php');
}

$matrix = getDocumentReleaseRulesMatrix();
$statuses = enrollmentStatusOptions();

$pageTitle = 'Credential Release Rules';
$activeNav = 'release-rules';

require_once __DIR__ . '/../includes/header.php';
?>

<?php renderAdminCredentialSettingsNav('release-rules'); ?>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-user-check"></i> Credential Release Rules</h2>
    </div>
    <div class="card-body">
        <p class="text-muted release-rules-intro">
            Control which credentials can be requested for each enrollment status and set the maximum number of copies allowed per document.
        </p>

        <form method="POST" class="form-grid">
            <?= csrfField() ?>

            <div class="table-responsive">
                <table class="data-table release-rules-table data-table-responsive">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <?php foreach ($statuses as $label): ?>
                                <th><?= e($label) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matrix as $row): ?>
                            <?php $doc = $row['document']; $docId = (int) $doc['id']; ?>
                            <tr>
                                <td data-label="Document">
                                    <strong><?= e($doc['name']) ?></strong>
                                    <small class="text-muted release-rules-code"><?= e($doc['code']) ?></small>
                                    <?php if (!(int) $doc['is_active']): ?>
                                        <span class="text-muted">(Inactive)</span>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($statuses as $status => $label): ?>
                                    <?php
                                    $rule = $row['rules'][$status];
                                    $allowed = (int) ($rule['is_allowed'] ?? 0);
                                    $maxCopies = max(1, min(99, (int) ($rule['max_copies'] ?? 1)));
                                    ?>
                                    <td data-label="<?= e($label) ?>">
                                        <div class="release-rule-cell">
                                            <label class="checkbox-label release-rule-allowed">
                                                <input type="checkbox"
                                                    name="rules[<?= $docId ?>][<?= e($status) ?>][allowed]"
                                                    value="1"
                                                    <?= $allowed ? 'checked' : '' ?>
                                                    onchange="toggleReleaseRuleCell(this)">
                                                <span>Allow</span>
                                            </label>
                                            <label class="release-rule-copies">
                                                <span>Max copies</span>
                                                <input type="number"
                                                    name="rules[<?= $docId ?>][<?= e($status) ?>][max_copies]"
                                                    min="1"
                                                    max="99"
                                                    value="<?= $maxCopies ?>"
                                                    <?= $allowed ? '' : 'disabled' ?>>
                                            </label>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Release Rules</button>
        </form>
    </div>
</div>

<script>
function toggleReleaseRuleCell(checkbox) {
    const cell = checkbox.closest('.release-rule-cell');
    const copiesInput = cell ? cell.querySelector('.release-rule-copies input') : null;
    if (copiesInput) {
        copiesInput.disabled = !checkbox.checked;
        if (checkbox.checked && (!copiesInput.value || parseInt(copiesInput.value, 10) < 1)) {
            copiesInput.value = '1';
        }
    }
}

document.querySelectorAll('.release-rule-allowed input[type="checkbox"]').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
        toggleReleaseRuleCell(checkbox);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
