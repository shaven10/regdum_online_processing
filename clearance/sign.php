<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clearance.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/request-items.php';
require_once __DIR__ . '/../includes/onsite-request.php';
requireClearanceAccess();

$user = currentUser();
$requestId = (int) ($_GET['request_id'] ?? 0);
$department = getUserClearanceDepartment($user);

if (!$department) {
    setFlash('error', 'No clearance department assigned.');
    redirect(dashboardUrl());
}

if ($requestId <= 0) {
    setFlash('error', 'Select a clearance request to sign.');
    redirect(APP_URL . '/clearance/requests.php');
}

$context = loadAssignmentRequestContext($requestId);
$request = $context['request'] ?? [];

if (!$request) {
    setFlash('error', 'Request not found.');
    redirect(APP_URL . '/clearance/dashboard.php');
}

initRequestClearance($requestId);
$clearances = getRequestClearances($requestId);
$myClearance = null;
foreach ($clearances as $c) {
    if ((int) $c['department_id'] === (int) $department['id']) {
        $myClearance = $c;
        break;
    }
}

if (!$myClearance) {
    setFlash('error', 'Clearance record not found for your department.');
    redirect(APP_URL . '/clearance/dashboard.php');
}

if (!canClearanceOfficerAccessRequest($user, $requestId)) {
    setFlash('error', 'This request is not under your assigned course/program.', [
        'title' => 'Access Denied',
    ]);
    redirect(APP_URL . '/clearance/requests.php');
}

$progress = getClearanceProgress($requestId);
$isOnsite = isOnsiteRequestChannel($request['request_channel'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');
    $studentName = trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));

    if ($action === 'on_hold' && $remarks === '') {
        setFlash('error', 'Please provide remarks when placing a clearance on hold.', [
            'title' => 'Remarks Required',
        ]);
        redirect(APP_URL . '/clearance/sign.php?request_id=' . $requestId);
    }

    if (in_array($action, ['cleared', 'on_hold'], true) && processClearanceAction($requestId, (int) $department['id'], $user['id'], $action, $remarks)) {
        setFlash('success', $action === 'cleared' ? 'Clearance signed successfully.' : 'Clearance placed on hold.', [
            'title' => $action === 'cleared' ? 'Clearance Signed' : 'Clearance On Hold',
            'context' => [
                'Request' => $request['request_number'] ?? '',
                'Requestor' => $studentName,
                'Office' => $department['name'] ?? '',
            ],
            'next_step' => $action === 'cleared'
                ? 'Other offices and the Registrar can continue processing this request.'
                : 'The student was notified. Update remarks if more guidance is needed.',
        ]);
    } elseif ($action === 'reset' && processClearanceAction($requestId, (int) $department['id'], $user['id'], 'reset', $remarks)) {
        setFlash('success', 'Clearance reset to pending.', [
            'title' => 'Clearance Reset',
            'context' => [
                'Request' => $request['request_number'] ?? '',
                'Office' => $department['name'] ?? '',
            ],
        ]);
    } else {
        setFlash('error', 'Unable to update clearance.');
    }
    redirect(APP_URL . '/clearance/sign.php?request_id=' . $requestId);
}

$pageTitle = 'Sign Clearance — ' . ($request['request_number'] ?? '');
$activeNav = 'requests';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="clearance-sign-page">
    <div class="clearance-sign-header card">
        <div class="card-body clearance-sign-header-body">
            <div>
                <p class="text-muted clearance-sign-eyebrow">
                    <?= e($department['name']) ?> · <?= $isOnsite ? 'Onsite Walk-in' : 'Online Request' ?>
                </p>
                <h1 class="clearance-sign-title"><?= e($request['request_number'] ?? 'Request') ?></h1>
                <p class="text-muted" style="margin:.35rem 0 0">
                    <?= e(trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''))) ?>
                    · <?= e($request['student_id'] ?? '—') ?>
                    · Overall clearance <?= (int) ($progress['cleared'] ?? 0) ?>/<?= (int) ($progress['total'] ?? 0) ?>
                </p>
            </div>
            <div class="clearance-sign-header-badges">
                <?= statusBadge((string) ($request['status'] ?? '')) ?>
                <?= clearanceStatusBadge((string) ($myClearance['status'] ?? 'pending')) ?>
            </div>
        </div>
    </div>

    <div class="grid-2 clearance-sign-layout">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Requestor & Request Details</h2>
                    <p class="text-muted" style="margin:.35rem 0 0">Review student information before signing clearance.</p>
                </div>
            </div>
            <div class="card-body">
                <?= renderAssignmentRequestDetailsHtml($context) ?>
            </div>
        </div>

        <div class="card clearance-sign-actions-card">
            <div class="card-header">
                <div>
                    <h2>Sign as <?= e($department['name']) ?></h2>
                    <p class="text-muted" style="margin:.35rem 0 0">Clear, hold, or reset this office’s clearance decision.</p>
                </div>
            </div>
            <div class="card-body">
                <div class="detail-grid clearance-sign-status-grid">
                    <div class="detail-item">
                        <label>Your Office Status</label>
                        <span><?= clearanceStatusBadge((string) ($myClearance['status'] ?? 'pending')) ?></span>
                    </div>
                    <div class="detail-item">
                        <label>Request Status</label>
                        <span><?= statusBadge((string) ($request['status'] ?? '')) ?></span>
                    </div>
                    <?php if (!empty($myClearance['cleared_at'])): ?>
                        <div class="detail-item">
                            <label>Last Updated</label>
                            <span><?= e(formatDateTime($myClearance['cleared_at'])) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="detail-item">
                        <label>Overall Progress</label>
                        <span><?= (int) ($progress['cleared'] ?? 0) ?>/<?= (int) ($progress['total'] ?? 0) ?> offices cleared</span>
                    </div>
                </div>

                <?php if (!empty($myClearance['remarks'])): ?>
                    <div class="alert alert-info">
                        <strong>Current remarks:</strong> <?= e($myClearance['remarks']) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="form-grid clearance-sign-form" id="clearanceSignForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" id="clearanceSignAction" value="">

                    <div class="form-group">
                        <label for="remarks">Remarks <?= ($myClearance['status'] ?? '') !== 'on_hold' ? '' : '' ?></label>
                        <textarea id="remarks" name="remarks" rows="4"
                            placeholder="Optional notes for the student / registrar. Required when placing on hold."><?= e($myClearance['remarks'] ?? '') ?></textarea>
                        <small class="text-muted">Students are notified when you clear or place this clearance on hold.</small>
                    </div>

                    <div class="form-actions clearance-sign-form-actions">
                        <?php if (($myClearance['status'] ?? '') !== 'cleared'): ?>
                            <button type="button" class="btn btn-success" data-clearance-action="cleared"
                                data-confirm-title="Sign Clearance?"
                                data-confirm-message="Sign/clear this request for <?= e($department['name']) ?>?">
                                <i class="fas fa-check"></i> Sign / Clear
                            </button>
                        <?php endif; ?>
                        <?php if (($myClearance['status'] ?? '') !== 'on_hold'): ?>
                            <button type="button" class="btn btn-warning" data-clearance-action="on_hold"
                                data-confirm-title="Place On Hold?"
                                data-confirm-message="Place this clearance on hold? Remarks are required."
                                data-require-remarks="1">
                                <i class="fas fa-pause"></i> Place On Hold
                            </button>
                        <?php endif; ?>
                        <?php if (($myClearance['status'] ?? '') !== 'pending'): ?>
                            <button type="button" class="btn btn-outline" data-clearance-action="reset"
                                data-confirm-title="Reset Clearance?"
                                data-confirm-message="Reset this office clearance back to pending?">
                                <i class="fas fa-undo"></i> Reset to Pending
                            </button>
                        <?php endif; ?>
                        <a href="requests.php?status=<?= e($myClearance['status'] ?? 'pending') ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Queue
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="confirm-modal" id="clearanceSignConfirmModal" aria-hidden="true">
    <div class="confirm-modal-overlay" data-close-confirm-modal></div>
    <div class="confirm-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="clearanceSignConfirmTitle">
        <div class="confirm-modal-accent tone-error"></div>
        <button type="button" class="confirm-modal-close" data-close-confirm-modal aria-label="Close">
            <i class="fas fa-times"></i>
        </button>
        <div class="confirm-modal-icon-wrap tone-error">
            <i class="fas fa-stamp"></i>
        </div>
        <span class="confirm-modal-eyebrow">Confirm Action</span>
        <h2 class="confirm-modal-title" id="clearanceSignConfirmTitle">Confirm</h2>
        <p class="confirm-modal-message" id="clearanceSignConfirmMessage">Continue with this clearance action?</p>
        <dl class="confirm-modal-context" id="clearanceSignConfirmContext"></dl>
        <div class="confirm-modal-actions">
            <button type="button" class="btn btn-outline" data-close-confirm-modal>Cancel</button>
            <button type="button" class="btn btn-primary" id="clearanceSignConfirmBtn">
                <i class="fas fa-check"></i> Confirm
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('clearanceSignForm');
    const actionInput = document.getElementById('clearanceSignAction');
    const remarks = document.getElementById('remarks');
    const modal = document.getElementById('clearanceSignConfirmModal');
    const titleEl = document.getElementById('clearanceSignConfirmTitle');
    const messageEl = document.getElementById('clearanceSignConfirmMessage');
    const contextEl = document.getElementById('clearanceSignConfirmContext');
    const confirmBtn = document.getElementById('clearanceSignConfirmBtn');
    let pendingAction = null;

    function closeModal() {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        pendingAction = null;
    }

    function openModal(options) {
        pendingAction = options.action || null;
        if (titleEl) titleEl.textContent = options.title || 'Confirm';
        if (messageEl) messageEl.textContent = options.message || 'Continue with this clearance action?';
        if (contextEl) {
            contextEl.innerHTML = '';
            const context = options.context || {};
            Object.keys(context).forEach(function (key) {
                const dt = document.createElement('dt');
                dt.textContent = key;
                const dd = document.createElement('dd');
                dd.textContent = String(context[key]);
                contextEl.appendChild(dt);
                contextEl.appendChild(dd);
            });
            contextEl.hidden = Object.keys(context).length === 0;
        }
        if (confirmBtn) {
            confirmBtn.className = options.action === 'on_hold' ? 'btn btn-warning' : (options.action === 'cleared' ? 'btn btn-success' : 'btn btn-primary');
            confirmBtn.innerHTML = options.action === 'cleared'
                ? '<i class="fas fa-check"></i> Sign / Clear'
                : (options.action === 'on_hold'
                    ? '<i class="fas fa-pause"></i> Place On Hold'
                    : '<i class="fas fa-undo"></i> Reset');
        }
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        confirmBtn?.focus();
    }

    document.querySelectorAll('[data-clearance-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const action = btn.getAttribute('data-clearance-action') || '';
            const requireRemarks = btn.getAttribute('data-require-remarks') === '1';
            if (requireRemarks && (!remarks || !remarks.value.trim())) {
                remarks?.focus();
                alert('Please enter remarks before placing this clearance on hold.');
                return;
            }
            openModal({
                action: action,
                title: btn.getAttribute('data-confirm-title') || 'Confirm',
                message: btn.getAttribute('data-confirm-message') || 'Continue?',
                context: {
                    Request: <?= json_encode($request['request_number'] ?? '', JSON_UNESCAPED_UNICODE) ?>,
                    Requestor: <?= json_encode(trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? '')), JSON_UNESCAPED_UNICODE) ?>,
                    Office: <?= json_encode($department['name'] ?? '', JSON_UNESCAPED_UNICODE) ?>
                }
            });
        });
    });

    modal?.querySelectorAll('[data-close-confirm-modal]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    confirmBtn?.addEventListener('click', function () {
        if (!form || !actionInput || !pendingAction) {
            closeModal();
            return;
        }
        actionInput.value = pendingAction;
        closeModal();
        form.submit();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal?.classList.contains('is-open')) {
            closeModal();
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
