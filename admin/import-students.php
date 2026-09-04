<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/student-import.php';
requireRole('admin');

ensureStudentImportProfileFields();
ensureAcademicProgramsSchema();
ensureCampusesSchema();

$currentAdmin = currentUser();
$currentAdminId = (int) ($currentAdmin['id'] ?? 0);

if (isset($_GET['progress'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $token = strtolower(trim((string) $_GET['progress']));
    $progress = readStudentImportProgress($token, $currentAdminId);
    if (!$progress) {
        echo json_encode(['ok' => false]);
        exit;
    }
    echo json_encode(['ok' => true] + $progress);
    exit;
}

$campuses = getActiveCampuses();
$yearOptions = schoolYearOptions();
$semesterChoices = semesterOptions();
$defaultYear = defaultImportAcademicYear();
$defaultSemester = defaultImportSemester();
$defaultCampusId = (int) (($campuses[0]['id'] ?? 0));

if (($_GET['download'] ?? '') === 'template') {
    $year = trim((string) ($_GET['academic_year'] ?? $defaultYear));
    if (!isset($yearOptions[$year])) {
        $year = $defaultYear;
    }
    $semester = trim((string) ($_GET['semester'] ?? $defaultSemester));
    if (!array_key_exists($semester, $semesterChoices)) {
        $semester = $defaultSemester;
    }

    $binary = buildEnrolmentReportTemplateBinary($year, $semester);
    downloadXlsxFile('enrolment-report-template.xlsx', $binary);
}

$importResult = $_SESSION['student_import_result'] ?? null;
if ($importResult) {
    unset($_SESSION['student_import_result']);
}

$errors = [];
$form = [
    'academic_year' => $defaultYear,
    'semester' => $defaultSemester,
    'origin_campus_id' => $defaultCampusId,
    'update_existing' => false,
];
$isAjaxImport = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax']);

if ($isAjaxImport && !verifyCsrf()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid request. Please refresh the page and try again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $form['academic_year'] = trim((string) ($_POST['academic_year'] ?? $defaultYear));
    $form['semester'] = trim((string) ($_POST['semester'] ?? $defaultSemester));
    $form['origin_campus_id'] = (int) ($_POST['origin_campus_id'] ?? 0);
    $form['update_existing'] = !empty($_POST['update_existing']);
    $progressToken = strtolower(trim((string) ($_POST['progress_token'] ?? '')));
    if (!isValidStudentImportProgressToken($progressToken)) {
        $progressToken = '';
    }

    if (!isset($yearOptions[$form['academic_year']])) {
        $errors[] = 'Select a valid academic year.';
    }
    if (!array_key_exists($form['semester'], $semesterChoices)) {
        $errors[] = 'Select a valid semester.';
    }
    if ($form['origin_campus_id'] > 0 && !getCampusById($form['origin_campus_id'])) {
        $errors[] = 'Select a valid origin campus.';
    }

    if ($errors === []) {
        if ($progressToken !== '') {
            writeStudentImportProgress($progressToken, $currentAdminId, [
                'status'  => 'reading',
                'percent' => 5,
                'message' => 'Uploading and reading the Enrolment Report…',
            ]);
            session_write_close();
        }

        try {
            @set_time_limit(0);
            ignore_user_abort(true);

            $result = importActiveStudentsFromUpload($_FILES['enrolment_file'] ?? [], [
                'academic_year'    => $form['academic_year'],
                'semester'         => $form['semester'],
                'origin_campus_id' => $form['origin_campus_id'],
                'update_existing'  => $form['update_existing'],
                'progress_token'   => $progressToken,
                'progress_user_id' => $currentAdminId,
            ]);

            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }

            $_SESSION['student_import_result'] = $result;

            $created = (int) $result['created'];
            $updated = (int) $result['updated'];
            $skipped = (int) $result['skipped'];
            $failed = (int) $result['failed'];

            $flashType = $failed > 0 && $created === 0 && $updated === 0 ? 'error' : ($failed > 0 ? 'warning' : 'success');
            $flashMessage = $created . ' student' . ($created === 1 ? '' : 's') . ' imported as enrolled.';
            if ($updated > 0) {
                $flashMessage = $created . ' created, ' . $updated . ' updated.';
            }

            setFlash($flashType, $flashMessage, [
                'title' => $failed > 0 ? 'Import Completed with Issues' : 'Students Imported',
                'context' => array_filter([
                    'Created' => (string) $created,
                    'Updated' => $updated > 0 ? (string) $updated : null,
                    'Skipped (already exists)' => $skipped > 0 ? (string) $skipped : null,
                    'Failed' => $failed > 0 ? (string) $failed : null,
                    'School year' => $result['academic_year'] ?? null,
                    'Semester' => semesterLabel($result['semester'] ?? null),
                ]),
                'details' => array_slice(array_merge($result['errors'] ?? [], $result['warnings'] ?? []), 0, 12),
                'next_step' => 'Imported students can sign in with their Student ID as the initial password, then complete remaining profile fields.',
                'action_url' => APP_URL . '/admin/students.php',
                'action_label' => 'View Students',
            ]);

            if ($isAjaxImport) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'percent' => 100,
                    'status' => 'complete',
                    'processed' => (int) ($result['total_rows'] ?? 0),
                    'total' => (int) ($result['total_rows'] ?? 0),
                    'created' => $created,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'failed' => $failed,
                ]);
                exit;
            }

            redirect(APP_URL . '/admin/import-students.php');
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
            if ($progressToken !== '') {
                writeStudentImportProgress($progressToken, $currentAdminId, [
                    'status'  => 'error',
                    'percent' => 100,
                    'error'   => $e->getMessage(),
                    'message' => $e->getMessage(),
                ]);
            }
        } catch (Throwable $e) {
            $errors[] = 'Unable to read or import the file. Please use the Enrolment Report .xlsx template.';
            if ($progressToken !== '') {
                writeStudentImportProgress($progressToken, $currentAdminId, [
                    'status'  => 'error',
                    'percent' => 100,
                    'error'   => 'Unable to read or import the file.',
                    'message' => 'Unable to read or import the file.',
                ]);
            }
        }

        if ($isAjaxImport && $errors !== []) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $errors[0]]);
            exit;
        }
    } elseif ($isAjaxImport) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $errors[0] ?? 'Unable to start the import.']);
        exit;
    }
}

$pageTitle = 'Import Active Students';
$activeNav = 'import-students';
require_once __DIR__ . '/../includes/header.php';

$templateColumns = enrolmentReportTemplateHeaders();
?>

<div class="import-students-page">
    <div class="card">
        <div class="card-header">
            <div>
                <h2><i class="fas fa-file-excel"></i> Import Active Students</h2>
                <p class="text-muted" style="margin:.35rem 0 0">Upload the registrar Enrolment Report Excel file to create enrolled student accounts.</p>
            </div>
            <div class="card-header-actions">
                <a href="students.php" class="btn btn-outline btn-sm"><i class="fas fa-users"></i> Student Records</a>
            </div>
        </div>
        <div class="card-body">
            <?php if ($errors !== []): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <p><?= e($error) ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="import-students-form" id="importStudentsForm">
                <?= csrfField() ?>

                <div class="import-dropzone">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <div>
                        <label for="enrolment_file">Enrolment Report file *</label>
                        <input type="file" id="enrolment_file" name="enrolment_file" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                        <p class="text-muted">Accepts the official Enrolment Report workbook (.xlsx). Title rows such as “First Semester, S.Y. 2026-2027” are read automatically.</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="academic_year">Academic Year</label>
                        <select id="academic_year" name="academic_year">
                            <?php foreach ($yearOptions as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $form['academic_year'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Used when the file title does not include S.Y.</small>
                    </div>
                    <div class="form-group">
                        <label for="semester">Semester</label>
                        <select id="semester" name="semester">
                            <?php foreach ($semesterChoices as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $form['semester'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Overridden by “First Semester” / “Second Semester” in the file title when present.</small>
                    </div>
                    <div class="form-group">
                        <label for="origin_campus_id">Origin Campus</label>
                        <select id="origin_campus_id" name="origin_campus_id">
                            <?php foreach ($campuses as $campus): ?>
                                <option value="<?= (int) $campus['id'] ?>" <?= $form['origin_campus_id'] === (int) $campus['id'] ? 'selected' : '' ?>>
                                    <?= e($campus['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <label class="checkbox-label import-update-option">
                    <input type="checkbox" name="update_existing" value="1" <?= $form['update_existing'] ? 'checked' : '' ?>>
                    <span>Update existing students matched by Student ID or email (names, course, year, and contact details). Passwords are not changed.</span>
                </label>

                <div class="import-progress" id="importProgressPanel" hidden>
                    <div class="import-progress-head">
                        <div>
                            <strong id="importProgressTitle">Importing students</strong>
                            <p class="import-progress-message" id="importProgressMessage">Preparing…</p>
                        </div>
                        <div class="import-progress-percent" id="importProgressPercent">0%</div>
                    </div>
                    <div class="import-progress-bar" id="importProgressBar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="Import progress">
                        <span id="importProgressFill"></span>
                    </div>
                    <p class="import-progress-counts" id="importProgressCounts"></p>
                </div>

                <div class="import-students-actions">
                    <button type="submit" class="btn btn-primary" id="importStudentsSubmit">
                        <i class="fas fa-file-import"></i> Import Students
                    </button>
                    <a class="btn btn-outline" id="templateDownloadLink" href="?download=template">
                        <i class="fas fa-download"></i> Download Blank Template
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if (is_array($importResult)): ?>
        <?php
        $created = (int) ($importResult['created'] ?? 0);
        $updated = (int) ($importResult['updated'] ?? 0);
        $skipped = (int) ($importResult['skipped'] ?? 0);
        $failed = (int) ($importResult['failed'] ?? 0);
        $issues = array_merge($importResult['errors'] ?? [], $importResult['warnings'] ?? []);
        ?>
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-clipboard-check"></i> Last Import Result</h2>
            </div>
            <div class="card-body">
                <div class="import-result-stats">
                    <div class="import-result-stat"><strong><?= $created ?></strong><span>Created</span></div>
                    <div class="import-result-stat"><strong><?= $updated ?></strong><span>Updated</span></div>
                    <div class="import-result-stat"><strong><?= $skipped ?></strong><span>Skipped</span></div>
                    <div class="import-result-stat"><strong><?= $failed ?></strong><span>Failed</span></div>
                </div>
                <p class="text-muted">
                    <?= e(semesterLabel($importResult['semester'] ?? null)) ?>
                    · S.Y. <?= e($importResult['academic_year'] ?? '—') ?>
                    · Initial password for new accounts is the Student ID.
                </p>
                <?php if ($issues !== []): ?>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($issues as $issue): ?>
                                    <tr>
                                        <td><?= e($issue) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-table"></i> Expected Enrolment Report Columns</h2>
        </div>
        <div class="card-body">
            <p class="text-muted">Use the registrar Enrolment Report as-is. Phone numbers and ID numbers stored in scientific notation are converted to full values during import.</p>
            <div class="import-column-grid">
                <?php foreach ($templateColumns as $column): ?>
                    <span class="import-column-chip"><?= e($column) ?></span>
                <?php endforeach; ?>
            </div>
            <ul class="import-help-list">
                <li>New accounts are created as <strong>Enrolled</strong> and can sign in with <strong>Student ID</strong> as the initial password.</li>
                <li>Year values <strong>I–IV</strong> are stored as 1st–4th Year. Course codes are matched to Courses &amp; Programs when possible.</li>
                <li>Rows already in the system are skipped unless “Update existing students” is checked.</li>
            </ul>
        </div>
    </div>
</div>

<script>
(function () {
    const year = document.getElementById('academic_year');
    const semester = document.getElementById('semester');
    const link = document.getElementById('templateDownloadLink');
    function syncTemplateLink() {
        if (!link || !year || !semester) return;
        link.href = '?download=template&academic_year=' + encodeURIComponent(year.value)
            + '&semester=' + encodeURIComponent(semester.value);
    }
    year?.addEventListener('change', syncTemplateLink);
    semester?.addEventListener('change', syncTemplateLink);
    syncTemplateLink();

    const form = document.getElementById('importStudentsForm');
    const panel = document.getElementById('importProgressPanel');
    const titleEl = document.getElementById('importProgressTitle');
    const messageEl = document.getElementById('importProgressMessage');
    const percentEl = document.getElementById('importProgressPercent');
    const fillEl = document.getElementById('importProgressFill');
    const barEl = document.getElementById('importProgressBar');
    const countsEl = document.getElementById('importProgressCounts');
    const submitBtn = document.getElementById('importStudentsSubmit');
    let pollTimer = null;
    let finished = false;
    let displayPercent = 0;

    function setProgress(percent, title, message, counts) {
        displayPercent = Math.max(displayPercent, Math.max(0, Math.min(100, Math.round(percent))));
        if (percentEl) percentEl.textContent = displayPercent + '%';
        if (fillEl) fillEl.style.width = displayPercent + '%';
        if (barEl) barEl.setAttribute('aria-valuenow', String(displayPercent));
        if (title && titleEl) titleEl.textContent = title;
        if (message && messageEl) messageEl.textContent = message;
        if (countsEl) countsEl.textContent = counts || '';
    }

    function formatCounts(data) {
        const processed = Number(data.processed || 0);
        const total = Number(data.total || 0);
        const parts = [];
        if (total > 0) {
            parts.push(processed + ' of ' + total + ' records');
        }
        const created = Number(data.created || 0);
        const updated = Number(data.updated || 0);
        const skipped = Number(data.skipped || 0);
        const failed = Number(data.failed || 0);
        if (created || updated || skipped || failed) {
            parts.push('Created ' + created + ' · Updated ' + updated + ' · Skipped ' + skipped + ' · Failed ' + failed);
        }
        return parts.join(' — ');
    }

    function statusTitle(status) {
        if (status === 'reading') return 'Reading file';
        if (status === 'complete') return 'Import complete';
        if (status === 'error') return 'Import failed';
        return 'Importing students';
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function failImport(message) {
        finished = true;
        stopPolling();
        if (panel) panel.classList.add('is-error');
        setProgress(100, 'Import failed', message || 'Unable to import the file.');
        if (submitBtn) {
            submitBtn.disabled = false;
            if (submitBtn.getAttribute('data-original-html')) {
                submitBtn.innerHTML = submitBtn.getAttribute('data-original-html');
            }
        }
        if (form) form.setAttribute('aria-busy', 'false');
    }

    function completeImport() {
        if (finished) return;
        finished = true;
        stopPolling();
        if (panel) panel.classList.add('is-complete');
        setProgress(100, 'Import complete', 'Finishing up…');
        window.setTimeout(function () {
            window.location.reload();
        }, 400);
    }

    function pollProgress(token) {
        fetch('import-students.php?progress=' + encodeURIComponent(token), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
        }).then(function (res) { return res.json(); }).then(function (data) {
            if (!data || !data.ok || finished) return;
            const percent = Number(data.percent || 0);
            setProgress(
                Math.max(displayPercent, percent),
                statusTitle(data.status),
                data.message || data.error || 'Importing students…',
                formatCounts(data)
            );
            if (data.status === 'complete') {
                if (panel) panel.classList.add('is-complete');
                setProgress(100, 'Import complete', data.message || 'Finishing up…', formatCounts(data));
            } else if (data.status === 'error') {
                failImport(data.error || data.message);
            }
        }).catch(function () {});
    }

    if (!form) return;

    form.addEventListener('submit', function (event) {
        if (!window.FormData || !window.XMLHttpRequest) return;

        const fileInput = document.getElementById('enrolment_file');
        if (!fileInput || !fileInput.files || !fileInput.files.length) return;

        event.preventDefault();
        finished = false;
        displayPercent = 0;
        stopPolling();

        const tokenBytes = new Uint8Array(16);
        if (window.crypto && crypto.getRandomValues) {
            crypto.getRandomValues(tokenBytes);
        } else {
            for (let i = 0; i < tokenBytes.length; i++) {
                tokenBytes[i] = Math.floor(Math.random() * 256);
            }
        }
        const token = Array.from(tokenBytes).map(function (b) {
            return b.toString(16).padStart(2, '0');
        }).join('');

        if (panel) {
            panel.hidden = false;
            panel.classList.remove('is-complete', 'is-error');
        }
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.setAttribute('data-original-html', submitBtn.innerHTML);
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing…';
        }
        form.setAttribute('aria-busy', 'true');
        setProgress(1, 'Uploading file', 'Uploading the Enrolment Report…');

        const body = new FormData(form);
        body.set('ajax', '1');
        body.set('progress_token', token);

        pollTimer = window.setInterval(function () {
            pollProgress(token);
        }, 400);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.getAttribute('action') || 'import-students.php');
        xhr.setRequestHeader('Accept', 'application/json');

        xhr.upload.addEventListener('progress', function (e) {
            if (!e.lengthComputable || finished) return;
            const uploadPercent = Math.round((e.loaded / e.total) * 8);
            setProgress(Math.max(1, uploadPercent), 'Uploading file', 'Uploading the Enrolment Report…');
        });

        xhr.addEventListener('load', function () {
            let payload = null;
            try {
                payload = JSON.parse(xhr.responseText || '{}');
            } catch (err) {
                payload = null;
            }

            if (xhr.status >= 200 && xhr.status < 300 && payload && payload.ok) {
                setProgress(100, 'Import complete', 'Finishing up…', formatCounts(payload));
                completeImport();
                return;
            }

            failImport((payload && payload.error) || 'Unable to import the file.');
        });

        xhr.addEventListener('error', function () {
            failImport('The import request was interrupted. Please try again.');
        });

        xhr.send(body);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
