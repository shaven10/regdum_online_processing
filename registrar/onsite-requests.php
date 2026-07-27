<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/onsite-request.php';
requireRole('registrar');

ensureOnsiteRequestSchema();
ensureRequestItemsSchema();
ensurePaymentMethodSchema();

$status = trim((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$requests = getOnsiteRequestsList($status, $search);

$statusOptions = [
    'awaiting_requirements' => 'Awaiting Clearance',
    'requirements_verified' => 'Ready for Payment',
    'payment_verified' => 'Payment Verified',
    'processing' => 'Processing',
    'ready_for_pickup' => 'Ready for Pickup',
    'completed' => 'Completed',
    'rejected' => 'Rejected',
];

$pageTitle = 'Onsite Request Records';
$activeNav = 'onsite-request';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2>Onsite Request Records</h2>
            <p class="text-muted request-form-subtitle">Walk-in credential requests created at the Registrar for cashier payment.</p>
        </div>
        <div class="card-header-actions">
            <a href="<?= APP_URL ?>/registrar/new-onsite-request.php" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> New Onsite Request
            </a>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" class="filter-bar">
            <input type="text" name="search" placeholder="Search request #, requestor, payment code..." value="<?= e($search) ?>">
            <select name="status" aria-label="Filter by status">
                <option value="">All statuses</option>
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <?php if ($status !== '' || $search !== ''): ?>
                <a href="<?= APP_URL ?>/registrar/onsite-requests.php" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
        </form>

        <?php if (empty($requests)): ?>
            <div class="empty-state">
                <i class="fas fa-store"></i>
                <p>No onsite requests found.</p>
                <a href="<?= APP_URL ?>/registrar/new-onsite-request.php" class="btn btn-primary btn-sm" style="margin-top:.75rem">
                    <i class="fas fa-plus"></i> Create Onsite Request
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table data-table-responsive">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Requestor</th>
                            <th>Documents</th>
                            <th>Payment Code</th>
                            <th>Amount</th>
                            <th>Clearance</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                            <tr>
                                <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                                <td data-label="Requestor">
                                    <?= e(trim(($req['first_name'] ?? '') . ' ' . ($req['last_name'] ?? ''))) ?>
                                    <br><small class="text-muted"><?= e($req['student_id'] ?? '—') ?></small>
                                </td>
                                <td data-label="Documents"><?= e($req['document_summary'] ?? '—') ?></td>
                                <td data-label="Payment Code">
                                    <?php if (!empty($req['payment_code'])): ?>
                                        <strong><?= e($req['payment_code']) ?></strong>
                                        <?php if (!empty($req['payment_status'])): ?>
                                            <br><small class="text-muted"><?= e(ucfirst((string) $req['payment_status'])) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Amount"><strong><?= formatMoney((float) ($req['payment_amount'] ?? $req['total_amount'] ?? 0)) ?></strong></td>
                                <td data-label="Clearance">
                                    <?php if (!empty($req['clearance_required'])): ?>
                                        <?php if (!empty($req['clearance_blocked'])): ?>
                                            <small class="payment-clearance-pill is-pending">
                                                <?= (int) $req['clearance_cleared'] ?>/<?= (int) $req['clearance_total'] ?>
                                            </small>
                                        <?php else: ?>
                                            <small class="payment-clearance-pill is-complete">Complete</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not required</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status"><?= statusBadge((string) $req['status']) ?></td>
                                <td data-label="Created">
                                    <?= formatDateTime($req['created_at']) ?>
                                    <?php if (!empty($req['created_by_first'])): ?>
                                        <br><small class="text-muted"><?= e(trim($req['created_by_first'] . ' ' . ($req['created_by_last'] ?? ''))) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Actions" class="payment-actions-cell">
                                    <a href="<?= APP_URL ?>/registrar/verify-request.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="<?= APP_URL ?>/registrar/onsite-request-slip.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-outline" target="_blank">
                                        <i class="fas fa-print"></i> Slip
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
