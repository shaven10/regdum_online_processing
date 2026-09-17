<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/onsite-request.php';
require_once __DIR__ . '/../includes/claim-stub.php';
requireRole('registrar');

ensureOnsiteRequestSchema();
ensureRequestItemsSchema();
ensurePaymentMethodSchema();

$status = trim((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$sortColumns = [
    'request_number' => ['type' => 'string', 'sql' => 'r.request_number'],
    'name' => ['type' => 'string', 'sql' => 'u.last_name, u.first_name'],
    'request_type' => [
        'type' => 'string',
        'sql' => "CASE WHEN r.onsite_batch_key IS NULL OR r.onsite_batch_key = '' THEN 0 ELSE 1 END",
    ],
    'document_summary' => ['type' => 'string', 'sql' => 'dt.name'],
    'payment_code' => ['type' => 'string', 'sql' => 'p.reference_number'],
    'amount' => [
        'type' => 'number',
        'sql' => "CASE
            WHEN r.onsite_batch_key IS NOT NULL AND r.onsite_batch_key <> '' THEN (
                SELECT COALESCE(SUM(r2.total_amount), 0)
                FROM requests r2
                WHERE r2.onsite_batch_key = r.onsite_batch_key
            )
            ELSE COALESCE(p.amount, r.total_amount)
        END",
        'default_dir' => 'desc',
    ],
    'status' => ['type' => 'string', 'sql' => 'r.status'],
    'created_at' => ['type' => 'date', 'sql' => 'r.created_at', 'default_dir' => 'desc'],
];
$sortState = resolveRecordsSort($sortColumns, 'created_at', 'desc');
$listFilters = array_merge(
    ['status' => $status, 'search' => $search],
    recordsSortFilterParams($sortState)
);
$onsitePaging = recordsListPaging(
    countOnsiteRequestsList($status, $search),
    $listFilters,
    'onsiteRecordsFilterForm',
    'request',
    'requests'
);
$requests = getOnsiteRequestsList(
    $status,
    $search,
    $onsitePaging['limit'],
    $onsitePaging['offset'],
    recordsSqlOrderBy($sortState, 'r.created_at DESC')
);
$sortQuery = $listFilters;
if ($onsitePaging['per_page'] !== ITEMS_PER_PAGE) {
    $sortQuery['per_page'] = $onsitePaging['per_page'];
}

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
        <form method="GET" class="filter-bar" id="onsiteRecordsFilterForm">
            <?= recordsSortFormFields($sortState) ?>
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
        <?= $onsitePaging['meta_html'] ?>

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
                            <?= renderRecordsSortHeader('Request / Requestor', 'request_number', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Type', 'request_type', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Documents', 'document_summary', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Payment / Amount', 'payment_code', $sortState, $sortQuery) ?>
                            <th>Clearance</th>
                            <?= renderRecordsSortHeader('Status', 'status', $sortState, $sortQuery) ?>
                            <?= renderRecordsSortHeader('Created', 'created_at', $sortState, $sortQuery) ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                            <tr>
                                <td data-label="Request / Requestor">
                                    <strong><?= e($req['request_number']) ?></strong>
                                    <br>
                                    <?= e(trim(($req['first_name'] ?? '') . ' ' . ($req['last_name'] ?? ''))) ?>
                                    <br><small class="text-muted"><?= e($req['student_id'] ?? '—') ?></small>
                                </td>
                                <td data-label="Type">
                                    <?php if (!empty($req['is_multiple'])): ?>
                                        <?php $batchSize = max(2, (int) ($req['batch_size'] ?? 2)); ?>
                                        <span class="payment-scope-pill is-multiple onsite-type-pill" title="<?= $batchSize ?> requestors">
                                            <span class="onsite-type-pill-label">Multiple</span>
                                            <span class="onsite-type-pill-count"><?= $batchSize ?> requestors</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="payment-scope-pill is-single">Single</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Documents"><?= e($req['document_summary'] ?? '—') ?></td>
                                <td data-label="Payment / Amount">
                                    <?php if (!empty($req['payment_code'])): ?>
                                        <strong><?= e($req['payment_code']) ?></strong>
                                        <?php if (!empty($req['payment_status'])): ?>
                                            <br><small class="text-muted"><?= e(ucfirst((string) $req['payment_status'])) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                    <br>
                                    <strong><?= formatMoney((float) ($req['display_amount'] ?? $req['payment_amount'] ?? $req['total_amount'] ?? 0)) ?></strong>
                                    <?php if (!empty($req['is_multiple'])): ?>
                                        <br><small class="text-muted">Batch total</small>
                                    <?php endif; ?>
                                </td>
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
                                    <?= renderOnsiteRequestSlipButtonHtml((int) $req['id'], true) ?>
                                    <?php if (!empty($req['is_multiple'])): ?>
                                        <?= renderOnsiteCombinedSlipButtonHtml($req['batch_request_ids'] ?? [], true) ?>
                                    <?php endif; ?>
                                    <?= renderRegistrarClaimSlipButtonsHtml(
                                        $req,
                                        true,
                                        !empty($req['payment_status']) ? ['status' => $req['payment_status']] : null
                                    ) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $onsitePaging['html'] ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
