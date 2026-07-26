<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
requireRole('admin');

ensureRequestStatuses();

$page = max(1, (int)($_GET['page'] ?? 1));
$status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');

$listQuery = array_filter([
    'status' => $status,
    'search' => $search,
    'page' => $page > 1 ? (string) $page : '',
]);
$listUrl = APP_URL . '/admin/requests.php' . ($listQuery ? '?' . http_build_query($listQuery) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $requestIds = normalizeAdminBatchRequestIds($_POST['request_ids'] ?? []);

    if (empty($requestIds)) {
        setFlash('error', 'Select at least one request.', ['title' => 'No Requests Selected']);
        redirect($listUrl);
    }

    if ($action === 'batch_update_status') {
        $newStatus = trim($_POST['status'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        $result = adminBatchUpdateRequestStatus($requestIds, $newStatus, $remarks ?: null);

        if (($result['updated'] ?? 0) > 0) {
            setFlash('success', $result['updated'] . ' request(s) updated to ' . ucwords(str_replace('_', ' ', $newStatus)) . '.', [
                'title' => 'Batch Status Updated',
                'context' => [
                    'Updated' => (string) $result['updated'],
                    'Unchanged' => (string) ($result['unchanged'] ?? 0),
                ],
            ]);
        } elseif (($result['unchanged'] ?? 0) > 0 && empty($result['failed'])) {
            setFlash('info', 'Selected requests already have the chosen status.', ['title' => 'No Changes Needed']);
        } else {
            setFlash('error', implode(' ', $result['failed'] ?? ['Unable to update selected requests.']), [
                'title' => 'Batch Update Failed',
            ]);
        }
    } elseif ($action === 'batch_delete') {
        $result = adminBatchDeleteRequests($requestIds);

        if (($result['deleted'] ?? 0) > 0) {
            setFlash('success', $result['deleted'] . ' request(s) deleted permanently.', [
                'title' => 'Requests Deleted',
            ]);
        } else {
            setFlash('error', implode(' ', $result['failed'] ?? ['Unable to delete selected requests.']), [
                'title' => 'Batch Delete Failed',
            ]);
        }
    } else {
        setFlash('error', 'Unknown batch action.', ['title' => 'Action Failed']);
    }

    redirect($listUrl);
}

require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/header.php';

$db = getDB();

$where = ['1=1'];
$params = [];
if ($status) { $where[] = 'r.status = ?'; $params[] = $status; }
if ($search) { $where[] = '(r.request_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_id LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%", "%$search%"); }
$whereClause = implode(' AND ', $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM requests r JOIN users u ON r.user_id = u.id WHERE $whereClause");
$countStmt->execute($params);
$pag = paginate((int)$countStmt->fetchColumn(), $page);

$stmt = $db->prepare("SELECT r.*, dt.name as document_name, u.first_name, u.last_name, u.student_id FROM requests r JOIN document_types dt ON r.document_type_id = dt.id JOIN users u ON r.user_id = u.id WHERE $whereClause ORDER BY r.created_at DESC LIMIT {$pag['per_page']} OFFSET {$pag['offset']}");
$stmt->execute($params);
$requests = $stmt->fetchAll();
$statusOptions = requestStatusOptions();

$pageTitle = 'Manage Requests';
$activeNav = 'requests';
?>

<div class="card">
    <div class="card-header"><h2>All Requests</h2></div>
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search..." value="<?= e($search) ?>">
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach ($statusOptions as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
        </form>

        <form method="POST" id="adminRequestsBatchForm" class="admin-requests-batch-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="adminBatchAction" value="">

            <div class="batch-action-bar" id="adminBatchActionBar" hidden>
                <span class="batch-action-count"><strong id="adminBatchSelectedCount">0</strong> selected</span>
                <div class="batch-action-buttons">
                    <button type="button" class="btn btn-primary btn-sm" id="openBatchStatusModal">
                        <i class="fas fa-sync-alt"></i> Change Status
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" id="adminBatchDeleteBtn">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th class="batch-select-col">
                            <label class="checkbox-label batch-select-all-label">
                                <input type="checkbox" id="adminSelectAllRequests" aria-label="Select all requests on this page">
                            </label>
                        </th>
                        <th>Request #</th>
                        <th>Student ID</th>
                        <th>Name</th>
                        <th>Document</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="9" class="text-muted">No requests found.</td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $req): ?>
                        <tr>
                            <td class="batch-select-col">
                                <label class="checkbox-label">
                                    <input type="checkbox" class="admin-request-select" name="request_ids[]" value="<?= (int) $req['id'] ?>">
                                </label>
                            </td>
                            <td><strong><?= e($req['request_number']) ?></strong></td>
                            <td><?= e($req['student_id']) ?></td>
                            <td><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                            <td><?= e($req['document_name']) ?></td>
                            <td><?= formatMoney((float)$req['total_amount']) ?></td>
                            <td><?= statusBadge($req['status']) ?></td>
                            <td><?= formatDate($req['created_at']) ?></td>
                            <td><a href="request-manage.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline">Manage</a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </form>

        <?= paginationLinks($pag, '?' . http_build_query(array_filter(['status' => $status, 'search' => $search]))) ?>
    </div>
</div>

<?php
renderAdminFormModalOpen('Requests', 'Batch Change Status', 'adminBatchStatusModal');
?>
<form method="POST" id="adminBatchStatusForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="batch_update_status">
    <div id="adminBatchStatusHiddenIds"></div>
    <div class="form-group">
        <label for="batch_status">New Status *</label>
        <select id="batch_status" name="status" required>
            <?php foreach ($statusOptions as $s): ?>
                <option value="<?= e($s) ?>"><?= ucwords(str_replace('_', ' ', $s)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="batch_remarks">Remarks</label>
        <input type="text" id="batch_remarks" name="remarks" placeholder="Optional note for status history">
    </div>
    <?php renderAdminFormModalFooter('Update Selected', 'fa-sync-alt'); ?>
</form>
<?php renderAdminFormModalClose(); ?>

<script>
(function () {
    const batchForm = document.getElementById('adminRequestsBatchForm');
    const batchBar = document.getElementById('adminBatchActionBar');
    const countEl = document.getElementById('adminBatchSelectedCount');
    const selectAll = document.getElementById('adminSelectAllRequests');
    const rowChecks = () => Array.from(document.querySelectorAll('.admin-request-select'));
    const statusModal = document.getElementById('adminBatchStatusModal');
    const statusForm = document.getElementById('adminBatchStatusForm');
    const hiddenIds = document.getElementById('adminBatchStatusHiddenIds');
    const deleteBtn = document.getElementById('adminBatchDeleteBtn');
    const openStatusBtn = document.getElementById('openBatchStatusModal');

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

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rowChecks().forEach(function (cb) { cb.checked = selectAll.checked; });
            syncBatchBar();
        });
    }

    rowChecks().forEach(function (cb) {
        cb.addEventListener('change', syncBatchBar);
    });

    if (openStatusBtn && statusModal && statusForm && hiddenIds) {
        function closeBatchStatusModal() {
            statusModal.classList.remove('is-open');
            statusModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        statusModal.querySelectorAll('[data-close-admin-form]').forEach(function (el) {
            el.addEventListener('click', closeBatchStatusModal);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && statusModal.classList.contains('is-open')) {
                closeBatchStatusModal();
            }
        });

        openStatusBtn.addEventListener('click', function () {
            const selected = selectedChecks();
            if (!selected.length) {
                alert('Select at least one request.');
                return;
            }
            hiddenIds.innerHTML = '';
            selected.forEach(function (cb) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'request_ids[]';
                input.value = cb.value;
                hiddenIds.appendChild(input);
            });
            statusModal.classList.add('is-open');
            statusModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            const statusField = document.getElementById('batch_status');
            if (statusField) statusField.focus();
        });
    }

    if (deleteBtn && batchForm) {
        deleteBtn.addEventListener('click', function () {
            const selected = selectedChecks();
            if (!selected.length) {
                alert('Select at least one request.');
                return;
            }
            const message = selected.length === 1
                ? 'Delete the selected request permanently? This cannot be undone.'
                : 'Delete ' + selected.length + ' selected requests permanently? This cannot be undone.';
            if (!confirm(message)) {
                return;
            }
            document.getElementById('adminBatchAction').value = 'batch_delete';
            batchForm.submit();
        });
    }

    syncBatchBar();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
