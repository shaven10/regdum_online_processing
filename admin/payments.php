<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/ui.php';
requireRole('admin');

$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$listQuery = array_filter([
    'search' => $search,
    'status' => $status,
]);
$listUrl = APP_URL . '/admin/payments.php' . ($listQuery ? '?' . http_build_query($listQuery) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        $result = adminDeletePayment($paymentId);

        if (!empty($result['ok'])) {
            setFlash('success', 'Payment deleted permanently.', [
                'title' => 'Payment Deleted',
                'context' => array_filter([
                    'Request' => $result['request_number'] ?? null,
                    'Amount' => $result['amount'] ?? null,
                ]),
            ]);
        } else {
            setFlash('error', $result['error'] ?? 'Unable to delete payment.', [
                'title' => 'Delete Failed',
            ]);
        }

        redirect($listUrl);
    }

    if ($action === 'batch_delete') {
        $paymentIds = normalizeAdminBatchRequestIds($_POST['payment_ids'] ?? []);
        if (empty($paymentIds)) {
            setFlash('error', 'Select at least one payment.', ['title' => 'No Payments Selected']);
            redirect($listUrl);
        }

        $result = adminBatchDeletePayments($paymentIds);
        $deleted = (int) ($result['deleted'] ?? 0);
        $failed = $result['failed'] ?? [];

        if ($deleted > 0) {
            setFlash('success', $deleted . ' payment' . ($deleted === 1 ? '' : 's') . ' deleted permanently.', [
                'title' => 'Payments Deleted',
                'context' => array_filter([
                    'Deleted' => (string) $deleted,
                    'Failed' => !empty($failed) ? (string) count($failed) : null,
                ]),
                'details' => !empty($failed) ? implode(' ', $failed) : null,
            ]);
        } else {
            setFlash('error', implode(' ', $failed ?: ['Unable to delete selected payments.']), [
                'title' => 'Bulk Delete Failed',
            ]);
        }

        redirect($listUrl);
    }

    setFlash('error', 'Unknown action.', ['title' => 'Action Failed']);
    redirect($listUrl);
}

$payments = getPaymentsList($status, $search);

$pageTitle = 'Payments';
$activeNav = 'payments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2>All Payments</h2>
            <p class="text-muted" style="margin:.35rem 0 0">Admins can permanently delete payment records. Verification is handled by the Cashier.</p>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search request #, student, or reference..." value="<?= e($search) ?>">
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach (['pending', 'verified', 'rejected', 'refunded'] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <?php if ($search || $status): ?>
                <a href="payments.php" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($payments)): ?>
            <div class="empty-state"><i class="fas fa-receipt"></i><p>No payments found.</p></div>
        <?php else: ?>
            <form method="POST" id="adminPaymentsBatchForm" class="admin-payments-batch-form">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="adminPaymentsBatchAction" value="batch_delete">

                <div class="batch-action-bar" id="adminPaymentsBatchActionBar" hidden>
                    <span class="batch-action-count"><strong id="adminPaymentsBatchSelectedCount">0</strong> selected</span>
                    <div class="batch-action-buttons">
                        <button type="button" class="btn btn-danger btn-sm" id="adminPaymentsBatchDeleteBtn">
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
                                    <input type="checkbox" id="adminSelectAllPayments" form="adminPaymentsBatchForm" aria-label="Select all payments on this page">
                                </label>
                            </th>
                            <th>Request #</th>
                            <th>Student</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Feedback</th>
                            <th>Verified By</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <?php
                            $studentName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                            $confirmMessage = 'Delete this payment for ' . ($p['request_number'] ?? 'this request') . ' permanently? This cannot be undone.';
                            ?>
                            <tr>
                                <td class="batch-select-col" data-label="Select">
                                    <label class="checkbox-label">
                                        <input type="checkbox"
                                            class="admin-payment-select"
                                            form="adminPaymentsBatchForm"
                                            name="payment_ids[]"
                                            value="<?= (int) $p['id'] ?>">
                                    </label>
                                </td>
                                <td data-label="Request #"><strong><?= e($p['request_number']) ?></strong></td>
                                <td data-label="Student"><?= e($studentName) ?></td>
                                <td data-label="Method"><?= e(paymentMethodLabel($p['payment_method'])) ?></td>
                                <td data-label="Amount"><?= formatMoney((float) $p['amount']) ?></td>
                                <td data-label="Reference"><?= e($p['reference_number'] ?? '—') ?></td>
                                <td data-label="Status"><?= statusBadge($p['status']) ?></td>
                                <td data-label="Feedback"><?= ($p['status'] === 'rejected' && !empty($p['notes'])) ? e($p['notes']) : '—' ?></td>
                                <td data-label="Verified By"><?= $p['verifier_first'] ? e($p['verifier_first'] . ' ' . $p['verifier_last']) : '—' ?></td>
                                <td data-label="Date"><?= formatDate($p['created_at']) ?></td>
                                <td data-label="Actions" class="action-cell">
                                    <div class="action-cell-buttons">
                                        <form method="POST" class="payment-delete-form"
                                            data-confirm-title="Delete Payment?"
                                            data-confirm-message="<?= e($confirmMessage) ?>"
                                            data-confirm-request="<?= e($p['request_number'] ?? '') ?>"
                                            data-confirm-student="<?= e($studentName) ?>"
                                            data-confirm-amount="<?= e(formatMoney((float) $p['amount'])) ?>"
                                            data-confirm-status="<?= e(ucfirst((string) ($p['status'] ?? ''))) ?>">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
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

<?php if (!empty($payments)): ?>
<div class="confirm-modal" id="paymentDeleteConfirmModal" aria-hidden="true">
    <div class="confirm-modal-overlay" data-close-confirm-modal></div>
    <div class="confirm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="paymentDeleteConfirmTitle">
        <div class="confirm-modal-accent tone-error"></div>
        <button type="button" class="confirm-modal-close" data-close-confirm-modal aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
        <div class="confirm-modal-icon-wrap tone-error">
            <i class="fas fa-trash-alt"></i>
        </div>
        <span class="confirm-modal-eyebrow">Confirm Deletion</span>
        <h2 class="confirm-modal-title" id="paymentDeleteConfirmTitle">Delete Payment?</h2>
        <p class="confirm-modal-message" id="paymentDeleteConfirmMessage">This action cannot be undone.</p>
        <dl class="confirm-modal-context" id="paymentDeleteConfirmContext" hidden></dl>
        <div class="confirm-modal-actions">
            <button type="button" class="btn btn-outline" data-close-confirm-modal>Cancel</button>
            <button type="button" class="btn btn-danger" id="paymentDeleteConfirmBtn">
                <i class="fas fa-trash-alt"></i> Delete Permanently
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    const batchForm = document.getElementById('adminPaymentsBatchForm');
    const batchBar = document.getElementById('adminPaymentsBatchActionBar');
    const countEl = document.getElementById('adminPaymentsBatchSelectedCount');
    const selectAll = document.getElementById('adminSelectAllPayments');
    const deleteBtn = document.getElementById('adminPaymentsBatchDeleteBtn');
    const modal = document.getElementById('paymentDeleteConfirmModal');
    const titleEl = document.getElementById('paymentDeleteConfirmTitle');
    const messageEl = document.getElementById('paymentDeleteConfirmMessage');
    const contextEl = document.getElementById('paymentDeleteConfirmContext');
    const confirmBtn = document.getElementById('paymentDeleteConfirmBtn');
    let pendingConfirm = null;

    const rowChecks = function () {
        return Array.from(document.querySelectorAll('.admin-payment-select'));
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

    document.querySelectorAll('.payment-delete-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            openConfirmModal({
                title: form.getAttribute('data-confirm-title') || 'Delete Payment?',
                message: form.getAttribute('data-confirm-message') || 'This action cannot be undone.',
                context: {
                    Request: form.getAttribute('data-confirm-request') || '—',
                    Student: form.getAttribute('data-confirm-student') || '—',
                    Amount: form.getAttribute('data-confirm-amount') || '—',
                    Status: form.getAttribute('data-confirm-status') || '—'
                },
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

            const count = selected.length;
            openConfirmModal({
                title: count === 1 ? 'Delete Payment?' : 'Delete Selected Payments?',
                message: count === 1
                    ? 'Delete the selected payment permanently? This cannot be undone.'
                    : 'Delete ' + count + ' selected payments permanently? This cannot be undone.',
                context: {
                    Selected: String(count)
                },
                onConfirm: function () {
                    document.getElementById('adminPaymentsBatchAction').value = 'batch_delete';
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
