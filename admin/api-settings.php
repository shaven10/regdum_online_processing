<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/external-api.php';
require_once __DIR__ . '/../includes/programs.php';
require_once __DIR__ . '/../includes/campuses.php';
requireRole('admin');

$user = currentUser();
ensureExternalApiSchema();
ensureAcademicProgramsSchema();
ensureCampusesSchema();

$errors = [];
$newKeyPlaintext = null;
$newKeyName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        $enabled = !empty($_POST['external_api_enabled']);
        $corsOrigins = trim((string) ($_POST['external_api_cors_origins'] ?? ''));
        $previousEnabled = isExternalApiEnabled();
        saveExternalApiSettings($enabled, $corsOrigins);
        auditLog('update_external_api_settings', 'app_settings', null,
            ['external_api_enabled' => $previousEnabled ? '1' : '0'],
            ['external_api_enabled' => $enabled ? '1' : '0']);
        setFlash('success', 'API settings saved.');
        redirect(APP_URL . '/admin/api-settings.php');
    }

    if ($action === 'create_key') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        try {
            $created = createExternalApiKey($name, $description, (int) ($user['id'] ?? 0));
            auditLog('create_api_key', 'api_keys', $created['id'], null, [
                'name' => $created['name'],
                'key_prefix' => $created['key_prefix'],
            ]);
            $newKeyPlaintext = $created['key'];
            $newKeyName = $created['name'];
            setFlash('success', 'API key created. Copy it now — it will not be shown again.');
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if ($action === 'toggle_key') {
        $keyId = (int) ($_POST['key_id'] ?? 0);
        $active = !empty($_POST['is_active']);
        $key = findExternalApiKeyById($keyId);
        if (!$key) {
            $errors[] = 'API key not found.';
        } else {
            setExternalApiKeyActive($keyId, $active);
            auditLog($active ? 'activate_api_key' : 'deactivate_api_key', 'api_keys', $keyId,
                ['is_active' => (int) ($key['is_active'] ?? 0)], ['is_active' => $active ? 1 : 0]);
            setFlash('success', $active ? 'API key activated.' : 'API key deactivated.');
            redirect(APP_URL . '/admin/api-settings.php');
        }
    }

    if ($action === 'delete_key') {
        $keyId = (int) ($_POST['key_id'] ?? 0);
        $key = findExternalApiKeyById($keyId);
        if (!$key) {
            $errors[] = 'API key not found.';
        } else {
            deleteExternalApiKey($keyId);
            auditLog('delete_api_key', 'api_keys', $keyId, ['name' => $key['name'] ?? ''], null);
            setFlash('success', 'API key deleted.');
            redirect(APP_URL . '/admin/api-settings.php');
        }
    }
}

$apiEnabled = isExternalApiEnabled();
$corsOrigins = getAppSetting('external_api_cors_origins', '');
$apiKeys = listExternalApiKeys();
$apiBaseUrl = externalApiBaseUrl();
$endpointUrl = externalApiActiveStudentsUrl();
$programs = getActiveAcademicPrograms();
$campuses = getActiveCampuses();
$yearLevels = yearLevelOptions();
$semesters = semesterOptions();
$logPage = max(1, (int) ($_GET['log_page'] ?? 1));
$logKeyFilter = (int) ($_GET['log_key'] ?? 0);
$logStatusFilter = trim((string) ($_GET['log_status'] ?? ''));
$logResult = listExternalApiRequestLogs([
    'api_key_id' => $logKeyFilter,
    'status' => $logStatusFilter,
], $logPage, 20);
$logStats = getExternalApiRequestLogStats();

$pageTitle = 'External API';
$activeNav = 'api-settings';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-plug"></i> External API Settings</h2></div>
    <div class="card-body">
        <p class="text-muted">
            Allow trusted external web applications to read active enrolled student records using API keys.
            Only students with an active account and <code>enrolled</code> status are returned.
        </p>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($newKeyPlaintext !== null): ?>
            <div class="alert alert-success">
                <p><strong>New API key for “<?= e($newKeyName) ?>”</strong></p>
                <p>Copy this key now. It cannot be retrieved later.</p>
                <div class="api-key-reveal">
                    <code id="newApiKeyValue"><?= e($newKeyPlaintext) ?></code>
                    <button type="button" class="btn btn-outline btn-sm" id="copyNewApiKey">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" class="form-grid">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_settings">

            <div class="form-group span-2">
                <label class="checkbox-label">
                    <input type="checkbox" name="external_api_enabled" value="1" <?= $apiEnabled ? 'checked' : '' ?>>
                    Enable external API access
                </label>
            </div>

            <div class="form-group span-2">
                <label for="external_api_cors_origins">Allowed CORS origins</label>
                <textarea id="external_api_cors_origins" name="external_api_cors_origins" rows="4"
                    placeholder="https://example.com&#10;https://portal.school.edu.ph&#10;Or use * to allow all origins"><?= e($corsOrigins) ?></textarea>
                <p class="text-muted">One origin per line or comma-separated. Use <code>*</code> only if you understand the security implications.</p>
            </div>

            <div class="form-actions span-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Settings
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-key"></i> API Keys</h2></div>
    <div class="card-body">
        <form method="POST" class="form-grid api-key-create-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_key">

            <div class="form-group">
                <label for="api_key_name">Key name</label>
                <input type="text" id="api_key_name" name="name" required maxlength="120"
                    placeholder="e.g. Campus Portal">
            </div>

            <div class="form-group">
                <label for="api_key_description">Description (optional)</label>
                <input type="text" id="api_key_description" name="description" maxlength="255"
                    placeholder="External app or integration">
            </div>

            <div class="form-actions span-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Generate API Key
                </button>
            </div>
        </form>

        <?php if ($apiKeys === []): ?>
            <p class="text-muted">No API keys yet. Generate one for each external application.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Key prefix</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Last used</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($apiKeys as $key): ?>
                            <tr>
                                <td>
                                    <strong><?= e($key['name'] ?? '') ?></strong>
                                    <?php if (!empty($key['description'])): ?>
                                        <br><span class="text-muted"><?= e($key['description']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= e($key['key_prefix'] ?? '') ?>…</code></td>
                                <td>
                                    <?php if (!empty($key['is_active'])): ?>
                                        <span class="badge badge-completed">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-rejected">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(formatDateTime($key['created_at'] ?? '')) ?></td>
                                <td><?= !empty($key['last_used_at']) ? e(formatDateTime($key['last_used_at'])) : '—' ?></td>
                                <td class="table-actions">
                                    <form method="POST" class="inline-form">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_key">
                                        <input type="hidden" name="key_id" value="<?= (int) ($key['id'] ?? 0) ?>">
                                        <?php if (!empty($key['is_active'])): ?>
                                            <input type="hidden" name="is_active" value="0">
                                            <button type="submit" class="btn btn-outline btn-sm">Deactivate</button>
                                        <?php else: ?>
                                            <input type="hidden" name="is_active" value="1">
                                            <button type="submit" class="btn btn-outline btn-sm">Activate</button>
                                        <?php endif; ?>
                                    </form>
                                    <form method="POST" class="inline-form"
                                        onsubmit="return confirm('Delete this API key? External apps using it will stop working.');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_key">
                                        <input type="hidden" name="key_id" value="<?= (int) ($key['id'] ?? 0) ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card" id="api-logs">
    <div class="card-header">
        <h2><i class="fas fa-list-alt"></i> API Connection &amp; Request Logs</h2>
    </div>
    <div class="card-body">
        <div class="api-log-stats">
            <div><strong><?= number_format($logStats['total']) ?></strong><span>Total requests</span></div>
            <div><strong><?= number_format($logStats['last_24h']) ?></strong><span>Last 24 hours</span></div>
            <div><strong><?= number_format($logStats['success_count']) ?></strong><span>Successful</span></div>
            <div><strong><?= number_format($logStats['error_count']) ?></strong><span>Failed</span></div>
        </div>

        <form method="GET" class="form-grid api-log-filters">
            <div class="form-group">
                <label for="log_key">API key</label>
                <select id="log_key" name="log_key">
                    <option value="">All keys</option>
                    <?php foreach ($apiKeys as $key): ?>
                        <option value="<?= (int) ($key['id'] ?? 0) ?>" <?= $logKeyFilter === (int) ($key['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= e($key['name'] ?? '') ?> (<?= e($key['key_prefix'] ?? '') ?>…)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="log_status">Result</label>
                <select id="log_status" name="log_status">
                    <option value="">All results</option>
                    <option value="success" <?= $logStatusFilter === 'success' ? 'selected' : '' ?>>Successful</option>
                    <option value="error" <?= $logStatusFilter === 'error' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Filter</button>
                <a href="<?= APP_URL ?>/admin/api-settings.php#api-logs" class="btn btn-outline">Reset</a>
            </div>
        </form>

        <?php if ($logResult['logs'] === []): ?>
            <p class="text-muted">No API requests logged yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>API key</th>
                            <th>Endpoint</th>
                            <th>Status</th>
                            <th>Results</th>
                            <th>Time</th>
                            <th>IP address</th>
                            <th>Query</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logResult['logs'] as $log): ?>
                            <tr>
                                <td><?= e(formatDateTime($log['created_at'] ?? '')) ?></td>
                                <td>
                                    <?php if (!empty($log['api_key_name'])): ?>
                                        <?= e($log['api_key_name']) ?>
                                        <br><code><?= e($log['key_prefix'] ?? '') ?>…</code>
                                    <?php else: ?>
                                        <span class="text-muted">Unknown / unauthenticated</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= e($log['endpoint'] ?? '') ?></code></td>
                                <td>
                                    <?php if (!empty($log['response_ok'])): ?>
                                        <span class="badge badge-completed"><?= (int) ($log['status_code'] ?? 0) ?> OK</span>
                                    <?php else: ?>
                                        <span class="badge badge-rejected"><?= (int) ($log['status_code'] ?? 0) ?> Error</span>
                                        <?php if (!empty($log['error_message'])): ?>
                                            <br><span class="text-muted"><?= e($log['error_message']) ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= isset($log['result_count']) ? (int) $log['result_count'] : '—' ?></td>
                                <td><?= isset($log['response_time_ms']) ? (int) $log['response_time_ms'] . ' ms' : '—' ?></td>
                                <td><?= e($log['ip_address'] ?? '—') ?></td>
                                <td>
                                    <?php if (!empty($log['query_string'])): ?>
                                        <code><?= e($log['query_string']) ?></code>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($logResult['total_pages'] > 1): ?>
                <nav class="pagination" aria-label="API log pagination">
                    <p class="pagination-status">Page <?= (int) $logResult['page'] ?> of <?= (int) $logResult['total_pages'] ?></p>
                    <ul>
                        <?php
                        $logPageQuery = static function (int $targetPage) use ($logKeyFilter, $logStatusFilter): string {
                            return http_build_query(array_filter([
                                'log_key' => $logKeyFilter > 0 ? $logKeyFilter : null,
                                'log_status' => $logStatusFilter !== '' ? $logStatusFilter : null,
                                'log_page' => $targetPage > 1 ? $targetPage : null,
                            ]));
                        };
                        $logPageBase = APP_URL . '/admin/api-settings.php';
                        ?>
                        <?php if ($logResult['page'] > 1): ?>
                            <li class="pagination-nav">
                                <a href="<?= e($logPageBase . '?' . $logPageQuery($logResult['page'] - 1)) ?>#api-logs">Prev</a>
                            </li>
                        <?php endif; ?>
                        <?php if ($logResult['page'] < $logResult['total_pages']): ?>
                            <li class="pagination-nav">
                                <a href="<?= e($logPageBase . '?' . $logPageQuery($logResult['page'] + 1)) ?>#api-logs">Next</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card" id="api-docs">
    <div class="card-header card-header-actions">
        <h2><i class="fas fa-book"></i> API Documentation</h2>
        <a href="<?= APP_URL ?>/admin/api-docs-download.php" class="btn btn-outline btn-sm">
            <i class="fas fa-file-alt"></i> Download Markdown
        </a>
        <a href="<?= APP_URL ?>/admin/api-docs-download.php?format=pdf" class="btn btn-outline btn-sm">
            <i class="fas fa-file-pdf"></i> Download PDF
        </a>
    </div>
    <div class="card-body api-docs">
        <h3>Overview</h3>
        <p>
            The Active Students API returns enrolled students with active accounts.
            Authentication is required on every request via an API key issued from this page.
        </p>

        <h3>Base URL</h3>
        <pre><code><?= e($apiBaseUrl) ?></code></pre>

        <h3>Authentication</h3>
        <p>Send your API key using one of these methods:</p>
        <ul>
            <li><code>X-API-Key: YOUR_API_KEY</code> header (recommended)</li>
            <li><code>Authorization: Bearer YOUR_API_KEY</code> header</li>
        </ul>

        <h3>Endpoint: List Active Students</h3>
        <p><code>GET <?= e($endpointUrl) ?></code></p>

        <h4>Query parameters</h4>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Parameter</th>
                        <th>Type</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td><code>page</code></td><td>integer</td><td>Page number (default: 1)</td></tr>
                    <tr><td><code>per_page</code></td><td>integer</td><td>Results per page, max 100 (default: 50)</td></tr>
                    <tr><td><code>search</code></td><td>string</td><td>Search by name, student ID, email, or course</td></tr>
                    <tr><td><code>student_id</code></td><td>string</td><td>Exact student ID match</td></tr>
                    <tr><td><code>course_id</code></td><td>integer</td><td>Filter by academic program ID</td></tr>
                    <tr><td><code>year_level</code></td><td>string</td><td>Filter by year level</td></tr>
                    <tr><td><code>academic_year</code></td><td>string</td><td>Filter by current academic year</td></tr>
                    <tr><td><code>semester</code></td><td>string</td><td>Filter by current semester</td></tr>
                    <tr><td><code>campus_id</code></td><td>integer</td><td>Filter by origin campus ID</td></tr>
                </tbody>
            </table>
        </div>

        <h4>Filter reference values</h4>
        <div class="api-docs-grid">
            <div>
                <strong>Year levels</strong>
                <ul>
                    <?php foreach ($yearLevels as $value => $label): ?>
                        <li><code><?= e($value) ?></code> — <?= e($label) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <strong>Semesters</strong>
                <ul>
                    <?php foreach ($semesters as $value => $label): ?>
                        <li><code><?= e($value) ?></code> — <?= e($label) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <strong>Programs (course_id)</strong>
                <ul>
                    <?php foreach ($programs as $program): ?>
                        <li><code><?= (int) ($program['id'] ?? 0) ?></code> — <?= e($program['name'] ?? '') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <strong>Campuses (campus_id)</strong>
                <ul>
                    <?php foreach ($campuses as $campus): ?>
                        <li><code><?= (int) ($campus['id'] ?? 0) ?></code> — <?= e($campus['name'] ?? '') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <h4>Example request</h4>
        <pre><code>curl -H "X-API-Key: YOUR_API_KEY" \
  "<?= e($endpointUrl) ?>?page=1&amp;per_page=25&amp;search=del%20a%20cruz"</code></pre>

        <h4>Example success response</h4>
        <pre><code>{
  "ok": true,
  "data": {
    "students": [
      {
        "id": 42,
        "student_id": "2024-00123",
        "first_name": "Juan",
        "last_name": "Dela Cruz",
        "middle_name": "Santos",
        "full_name": "Dela Cruz, Juan Santos",
        "email": "juan@example.edu.ph",
        "phone": "09171234567",
        "course": "Bachelor of Science in Information Technology",
        "course_id": 3,
        "program_code": "BSIT",
        "year_level": "2nd Year",
        "section": "A",
        "major": "",
        "sex": "M",
        "enrollment_status": "enrolled",
        "current_academic_year": "2025-2026",
        "current_semester": "1st_semester",
        "origin_campus_id": 1,
        "origin_campus": "Main Campus",
        "is_active": true
      }
    ],
    "pagination": {
      "page": 1,
      "per_page": 25,
      "total": 1,
      "total_pages": 1
    }
  }
}</code></pre>

        <h4>Error responses</h4>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>HTTP status</th>
                        <th>When</th>
                        <th>Example</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>401</td>
                        <td>Missing or invalid API key</td>
                        <td><code>{"ok": false, "error": "Invalid or missing API key."}</code></td>
                    </tr>
                    <tr>
                        <td>503</td>
                        <td>API disabled in settings</td>
                        <td><code>{"ok": false, "error": "External API is disabled."}</code></td>
                    </tr>
                    <tr>
                        <td>405</td>
                        <td>Non-GET request</td>
                        <td><code>{"ok": false, "error": "Method not allowed. Use GET."}</code></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h4>JavaScript fetch example</h4>
        <pre><code>const response = await fetch('<?= e($endpointUrl) ?>?page=1&amp;per_page=50', {
  headers: {
    'X-API-Key': 'YOUR_API_KEY'
  }
});
const payload = await response.json();
if (payload.ok) {
  console.log(payload.data.students);
}</code></pre>

        <p class="text-muted">
            Sensitive fields such as passwords and uploaded ID documents are never included in API responses.
        </p>
    </div>
</div>

<script>
(function () {
    const copyBtn = document.getElementById('copyNewApiKey');
    const keyEl = document.getElementById('newApiKeyValue');
    if (!copyBtn || !keyEl) {
        return;
    }
    copyBtn.addEventListener('click', function () {
        navigator.clipboard.writeText(keyEl.textContent || '').then(function () {
            copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied';
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
