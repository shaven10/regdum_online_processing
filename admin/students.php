<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ui.php';
requireRole('admin');

$db = getDB();
$page = max(1, (int) ($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');

$listQuery = array_filter([
    'search' => $search,
    'page' => $page > 1 ? (string) $page : '',
]);
$listUrl = APP_URL . '/admin/students.php' . ($listQuery ? '?' . http_build_query($listQuery) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $studentId = (int) ($_POST['user_id'] ?? 0);
        $result = adminDeleteStudent($studentId);

        if (!empty($result['ok'])) {
            $requestsDeleted = (int) ($result['requests_deleted'] ?? 0);
            setFlash('success', 'Student account deleted permanently.', [
                'title' => 'Student Deleted',
                'context' => array_filter([
                    'Student' => $result['name'] ?? null,
                    'Requests removed' => $requestsDeleted > 0 ? (string) $requestsDeleted : null,
                ]),
            ]);
        } else {
            setFlash('error', $result['error'] ?? 'Unable to delete student account.', [
                'title' => 'Delete Failed',
            ]);
        }

        redirect($listUrl);
    }

    if ($action === 'batch_delete') {
        $userIds = normalizeAdminBatchRequestIds($_POST['user_ids'] ?? []);
        if (empty($userIds)) {
            setFlash('error', 'Select at least one student.', ['title' => 'No Students Selected']);
            redirect($listUrl);
        }

        $result = adminBatchDeleteStudents($userIds);
        $deleted = (int) ($result['deleted'] ?? 0);
        $failed = $result['failed'] ?? [];
        $requestsDeleted = (int) ($result['requests_deleted'] ?? 0);

        if ($deleted > 0) {
            setFlash('success', $deleted . ' student account' . ($deleted === 1 ? '' : 's') . ' deleted permanently.', [
                'title' => 'Students Deleted',
                'context' => array_filter([
                    'Deleted' => (string) $deleted,
                    'Requests removed' => $requestsDeleted > 0 ? (string) $requestsDeleted : null,
                    'Failed' => !empty($failed) ? (string) count($failed) : null,
                ]),
                'details' => !empty($failed) ? implode(' ', $failed) : null,
            ]);
        } else {
            setFlash('error', implode(' ', $failed ?: ['Unable to delete selected students.']), [
                'title' => 'Bulk Delete Failed',
            ]);
        }

        redirect($listUrl);
    }

    setFlash('error', 'Unknown action.', ['title' => 'Action Failed']);
    redirect($listUrl);
}

$where = ["r.name = 'student'"];
$params = [];
if ($search) {
    $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE $whereClause");
$countStmt->execute($params);
$pag = paginate((int) $countStmt->fetchColumn(), $page);

$stmt = $db->prepare("SELECT u.*, sp.course, sp.year_level, sp.enrollment_status,
        (SELECT COUNT(*) FROM requests req WHERE req.user_id = u.id) AS request_count
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE $whereClause
    ORDER BY u.created_at DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$students = $stmt->fetchAll();

$pageTitle = 'Student Records';
$activeNav = 'students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2>Student Records</h2>
            <p class="text-muted" style="margin:.35rem 0 0">Delete permanently removes the account and all related credential requests.</p>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search by name, email, or student ID..." value="<?= e($search) ?>">
            <button type="submit" class="btn btn-outline btn-sm">Search</button>
            <?php if ($search): ?>
                <a href="students.php" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($students)): ?>
            <div class="empty-state"><i class="fas fa-users"></i><p>No student accounts found.</p></div>
        <?php else: ?>
            <form method="POST" id="adminStudentsBatchForm" class="admin-students-batch-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="adminStudentsBatchAction" value="batch_delete">

                <div class="batch-action-bar" id="adminStudentsBatchActionBar" hidden>
                    <span class="batch-action-count"><strong id="adminStudentsBatchSelectedCount">0</strong> selected</span>
                    <div class="batch-action-buttons">
                        <button type="button" class="btn btn-danger btn-sm" id="adminStudentsBatchDeleteBtn">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                    </div>
                </div>
            </form>

            <div class="table-responsive">
                <table class="data-table data-table-responsive">
                    <thead>
                        <tr>
                            <th class="batch-select-col">
                                <label class="checkbox-label batch-select-all-label">
                                    <input type="checkbox" id="adminSelectAllStudents" form="adminStudentsBatchForm" aria-label="Select all students on this page">
                                </label>
                            </th>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Course</th>
                            <th>Status</th>
                            <th>Account</th>
                            <th>Requests</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                            <?php
                            $requestCount = (int) ($s['request_count'] ?? 0);
                            $confirmMessage = $requestCount > 0
                                ? 'Delete this student permanently? This will also delete ' . $requestCount . ' credential request(s). This cannot be undone.'
                                : 'Delete this student account permanently? This cannot be undone.';
                            ?>
                            <tr>
                                <td class="batch-select-col" data-label="Select">
                                    <label class="checkbox-label">
                                        <input type="checkbox"
                                            class="admin-student-select"
                                            form="adminStudentsBatchForm"
                                            name="user_ids[]"
                                            value="<?= (int) $s['id'] ?>"
                                            data-request-count="<?= $requestCount ?>">
                                    </label>
                                </td>
                                <td data-label="Student ID"><?= e($s['student_id'] ?? '—') ?></td>
                                <td data-label="Name"><strong><?= e($s['first_name'] . ' ' . $s['last_name']) ?></strong></td>
                                <td data-label="Email"><?= e($s['email']) ?></td>
                                <td data-label="Course"><?= e($s['course'] ?? '—') ?></td>
                                <td data-label="Status"><?= e(enrollmentStatusLabel($s['enrollment_status'] ?? null)) ?></td>
                                <td data-label="Account">
                                    <?= !empty($s['is_active'])
                                        ? '<span class="badge badge-completed">Active</span>'
                                        : '<span class="badge badge-rejected">Inactive</span>' ?>
                                </td>
                                <td data-label="Requests"><?= $requestCount ?></td>
                                <td data-label="Registered"><?= formatDate($s['created_at']) ?></td>
                                <td data-label="Actions" class="action-cell">
                                    <div class="action-cell-buttons">
                                        <form method="POST" class="student-delete-form"
                                            data-confirm-title="Delete Student?"
                                            data-confirm-message="<?= e($confirmMessage) ?>"
                                            data-confirm-name="<?= e($s['first_name'] . ' ' . $s['last_name']) ?>"
                                            data-confirm-requests="<?= $requestCount ?>">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
                                            <button type="submit" <?= adminSettingsIconBtnAttrs('delete', 'danger') ?>><?= adminSettingsIconBtnContent('delete') ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= paginationLinks($pag, '?' . http_build_query(array_filter(['search' => $search]))) ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($students)): ?>
<div class="confirm-modal" id="studentDeleteConfirmModal" aria-hidden="true">
    <div class="confirm-modal-overlay" data-close-confirm-modal></div>
    <div class="confirm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="studentDeleteConfirmTitle">
        <div class="confirm-modal-accent tone-error"></div>
        <button type="button" class="confirm-modal-close" data-close-confirm-modal aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
        <div class="confirm-modal-icon-wrap tone-error">
            <i class="fas fa-trash-alt"></i>
        </div>
        <span class="confirm-modal-eyebrow">Confirm Deletion</span>
        <h2 class="confirm-modal-title" id="studentDeleteConfirmTitle">Delete Student?</h2>
        <p class="confirm-modal-message" id="studentDeleteConfirmMessage">This action cannot be undone.</p>
        <dl class="confirm-modal-context" id="studentDeleteConfirmContext" hidden></dl>
        <div class="confirm-modal-actions">
            <button type="button" class="btn btn-outline" data-close-confirm-modal>Cancel</button>
            <button type="button" class="btn btn-danger" id="studentDeleteConfirmBtn">
                <i class="fas fa-trash-alt"></i> Delete Permanently
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    const batchForm = document.getElementById('adminStudentsBatchForm');
    const batchBar = document.getElementById('adminStudentsBatchActionBar');
    const countEl = document.getElementById('adminStudentsBatchSelectedCount');
    const selectAll = document.getElementById('adminSelectAllStudents');
    const deleteBtn = document.getElementById('adminStudentsBatchDeleteBtn');
    const modal = document.getElementById('studentDeleteConfirmModal');
    const titleEl = document.getElementById('studentDeleteConfirmTitle');
    const messageEl = document.getElementById('studentDeleteConfirmMessage');
    const contextEl = document.getElementById('studentDeleteConfirmContext');
    const confirmBtn = document.getElementById('studentDeleteConfirmBtn');
    let pendingConfirm = null;

    const rowChecks = function () {
        return Array.from(document.querySelectorAll('.admin-student-select'));
    };

    function selectedChecks() {
        return rowChecks().filter(function (cb) { return cb.checked; });
    }

    function syncBatchBar() {
        const selected = selectedChecks();
        const count = selected.length;
        if (countEl) countEl.textContent = String(count);
        if (batchBar) batchBar.hidden = count === 0;
        if (selectAll) {
            const all = rowChecks();
            selectAll.checked = all.length > 0 && count === all.length;
            selectAll.indeterminate = count > 0 && count < all.length;
        }
    }

    function closeConfirmModal() {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        pendingConfirm = null;
    }

    function openConfirmModal(options) {
        if (!modal) return;
        pendingConfirm = options.onConfirm || null;
        if (titleEl) titleEl.textContent = options.title || 'Confirm Deletion';
        if (messageEl) messageEl.textContent = options.message || 'This action cannot be undone.';
        if (contextEl) {
            contextEl.innerHTML = '';
            const context = options.context || {};
            const keys = Object.keys(context);
            if (keys.length) {
                keys.forEach(function (key) {
                    const dt = document.createElement('dt');
                    dt.textContent = key;
                    const dd = document.createElement('dd');
                    dd.textContent = String(context[key]);
                    contextEl.appendChild(dt);
                    contextEl.appendChild(dd);
                });
                contextEl.hidden = false;
            } else {
                contextEl.hidden = true;
            }
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (confirmBtn) confirmBtn.focus();
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rowChecks().forEach(function (cb) { cb.checked = selectAll.checked; });
            syncBatchBar();
        });
    }

    rowChecks().forEach(function (cb) {
        cb.addEventListener('change', syncBatchBar);
    });

    document.querySelectorAll('.student-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const requests = parseInt(form.getAttribute('data-confirm-requests') || '0', 10) || 0;
            const context = {
                Student: form.getAttribute('data-confirm-name') || '—'
            };
            if (requests > 0) {
                context['Requests to remove'] = String(requests);
            }
            openConfirmModal({
                title: form.getAttribute('data-confirm-title') || 'Delete Student?',
                message: form.getAttribute('data-confirm-message') || 'This action cannot be undone.',
                context: context,
                onConfirm: function () {
                    form.submit();
                }
            });
        });
    });

    if (deleteBtn && batchForm) {
        deleteBtn.addEventListener('click', function () {
            const selected = selectedChecks();
            if (!selected.length) {
                return;
            }

            let requestTotal = 0;
            selected.forEach(function (cb) {
                requestTotal += parseInt(cb.getAttribute('data-request-count') || '0', 10) || 0;
            });

            const count = selected.length;
            const message = count === 1
                ? 'Delete the selected student permanently? This cannot be undone.'
                : 'Delete ' + count + ' selected students permanently? This cannot be undone.';

            const context = {
                Selected: String(count)
            };
            if (requestTotal > 0) {
                context['Requests to remove'] = String(requestTotal);
            }

            openConfirmModal({
                title: count === 1 ? 'Delete Student?' : 'Delete Selected Students?',
                message: message + (requestTotal > 0 ? ' Related credential requests will also be permanently removed.' : ''),
                context: context,
                onConfirm: function () {
                    document.getElementById('adminStudentsBatchAction').value = 'batch_delete';
                    batchForm.submit();
                }
            });
        });
    }

    if (modal) {
        modal.querySelectorAll('[data-close-confirm-modal]').forEach(function (el) {
            el.addEventListener('click', closeConfirmModal);
        });
    }

    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            const action = pendingConfirm;
            closeConfirmModal();
            if (typeof action === 'function') {
                action();
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('is-open')) {
            closeConfirmModal();
        }
    });

    syncBatchBar();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
