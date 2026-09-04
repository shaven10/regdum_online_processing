<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/academic-term.php';
requireRole('admin');

ensureAcademicTermSettings();

$errors = [];
$currentYear = getActiveSchoolYear();
$currentSemester = getActiveSemester();
$yearOptions = buildSchoolYearOptions($currentYear);
$semesterOptions = semesterOptions();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $schoolYear = trim((string) ($_POST['active_school_year'] ?? ''));
    $semester = trim((string) ($_POST['active_semester'] ?? ''));

    try {
        $previous = [
            'active_school_year' => $currentYear,
            'active_semester' => $currentSemester,
        ];
        saveActiveAcademicTerm($schoolYear, $semester);
        auditLog('update_active_academic_term', 'app_settings', null, $previous, [
            'active_school_year' => $schoolYear,
            'active_semester' => $semester,
        ]);
        setFlash('success', 'Active school year and semester updated.', [
            'title' => 'Academic Term Saved',
            'context' => [
                'School Year' => $schoolYear,
                'Semester' => semesterLabel($semester),
            ],
        ]);
        redirect(APP_URL . '/admin/academic-term-settings.php');
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
        $currentYear = $schoolYear !== '' ? $schoolYear : $currentYear;
        $currentSemester = $semester !== '' ? $semester : $currentSemester;
        $yearOptions = buildSchoolYearOptions($currentYear);
    }
}

$pageTitle = 'Academic Term';
$activeNav = 'academic-term';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2><i class="fas fa-calendar-alt"></i> Active Academic Term</h2>
            <p class="text-muted" style="margin:.35rem 0 0">
                Set the active school year and semester used as the default for student import, enrollment reports, and related modules.
            </p>
        </div>
    </div>
    <div class="card-body">
        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="academic-term-current">
            <span class="academic-term-current-label">Currently active</span>
            <strong><?= e(activeAcademicTermSummary()) ?></strong>
        </div>

        <form method="POST" class="form-grid academic-term-form">
            <?= csrfField() ?>

            <div class="form-group">
                <label for="active_school_year">Active School Year</label>
                <select id="active_school_year" name="active_school_year" required>
                    <?php foreach ($yearOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $currentYear === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="active_semester">Active Semester</label>
                <select id="active_semester" name="active_semester" required>
                    <?php foreach ($semesterOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $currentSemester === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-actions span-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Academic Term
                </button>
            </div>
        </form>

        <div class="academic-term-note text-muted">
            <p>This setting becomes the default for:</p>
            <ul>
                <li>Active student Excel import</li>
                <li>Enrollment reports (course summary and enrollment list)</li>
                <li>Other modules that need a current school year / semester default</li>
            </ul>
            <p>Individual student profile terms are not changed automatically.</p>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
