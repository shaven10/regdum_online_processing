<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/onsite-request.php';
require_once __DIR__ . '/../includes/claim-stub.php';
requireRole('registrar');

ensureOnsiteRequestSchema();
ensureRequestItemsSchema();
ensurePaymentMethodSchema();

$user = currentUser();
$requestIds = normalizeOnsiteRequestIdList($_GET['ids'] ?? '');

if ($requestIds === []) {
    setFlash('error', 'No multi-student batch was selected.');
    redirect(APP_URL . '/registrar/onsite-requests.php');
}

$slips = fetchOnsiteRequestSlipBatch($requestIds, $user);

if ($slips === []) {
    setFlash('error', 'None of those onsite requests could be found.');
    redirect(APP_URL . '/registrar/onsite-requests.php');
}

$printableIds = [];
$verifiedClaimIds = [];
$batchTotal = 0.0;
foreach ($slips as $slip) {
    $batchTotal += (float) $slip['amount'];
    if (!empty($slip['payment_code'])) {
        $printableIds[] = (int) $slip['request']['id'];
    }
    if (($slip['payment']['status'] ?? '') === 'verified') {
        $verifiedClaimIds[] = (int) $slip['request']['id'];
    }
}

$firstRequest = $slips[0]['request'];
$documentSummary = formatRequestItemsSummary($slips[0]['items'] ?? [], 0);
if ($documentSummary === '—' && !empty($firstRequest['document_type_id'])) {
    $documentSummary = 'Requested credentials';
}

$pageTitle = 'Multi-Student Onsite Batch';
$activeNav = 'onsite-request';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h2>Multi-Student Onsite Batch</h2>
            <p class="text-muted request-form-subtitle">
                <?= count($slips) ?> requestor<?= count($slips) === 1 ? '' : 's' ?> share the same document selection.
                Each one has its own request number and cashier payment code.
            </p>
        </div>
        <div class="card-header-actions">
            <?php if ($printableIds !== []): ?>
                <a href="<?= APP_URL ?>/registrar/onsite-request-slip.php?<?= e(onsiteBatchSlipQuery($printableIds, 'combined', true)) ?>"
                   class="btn btn-primary btn-sm"
                   target="_blank"
                   rel="noopener">
                    <i class="fas fa-file-alt"></i> Print Combined Slip
                </a>
                <a href="<?= APP_URL ?>/registrar/onsite-request-slip.php?<?= e(onsiteBatchSlipQuery($printableIds, 'separate', true)) ?>"
                   class="btn btn-outline btn-sm"
                   target="_blank"
                   rel="noopener">
                    <i class="fas fa-copy"></i> Print All Slips
                </a>
            <?php endif; ?>
            <?php if (count($verifiedClaimIds) > 1): ?>
                <a href="<?= e(registrarClaimStubUrl($verifiedClaimIds, 'combined', true)) ?>"
                   class="btn btn-primary btn-sm"
                   target="_blank"
                   rel="noopener">
                    <i class="fas fa-ticket-alt"></i> Print Combined Claim Slip
                </a>
            <?php elseif (count($verifiedClaimIds) === 1): ?>
                <a href="<?= e(registrarClaimStubUrl($verifiedClaimIds, '', true)) ?>"
                   class="btn btn-primary btn-sm"
                   target="_blank"
                   rel="noopener">
                    <i class="fas fa-ticket-alt"></i> Print Claim Slip
                </a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/registrar/onsite-requests.php" class="btn btn-outline btn-sm">
                <i class="fas fa-list"></i> Onsite Request Records
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="detail-grid onsite-batch-summary">
            <div class="detail-item">
                <label>Requestors</label>
                <span><?= count($slips) ?> of max <?= ONSITE_MULTI_STUDENT_MAX ?></span>
            </div>
            <div class="detail-item">
                <label>Documents</label>
                <span><?= e($documentSummary) ?></span>
            </div>
            <div class="detail-item">
                <label>Purpose</label>
                <span><?= e(purposeLabel($firstRequest['purpose'] ?? '')) ?></span>
            </div>
            <div class="detail-item">
                <label>Batch Total</label>
                <span class="amount-large"><?= formatMoney($batchTotal) ?></span>
            </div>
        </div>

        <div class="table-responsive onsite-batch-table">
            <table class="data-table data-table-responsive">
                <thead>
                    <tr>
                        <th>Requestor</th>
                        <th>ID No.</th>
                        <th>Request #</th>
                        <th>Payment Code</th>
                        <th>Amount</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slips as $slip): ?>
                        <?php
                        $request = $slip['request'];
                        $requestId = (int) $request['id'];
                        $paymentCode = (string) ($slip['payment_code'] ?? '');
                        ?>
                        <tr>
                            <td data-label="Requestor"><?= e(studentRecordName($request)) ?></td>
                            <td data-label="ID No."><?= e($request['student_id'] ?? '—') ?></td>
                            <td data-label="Request #"><strong><?= e($request['request_number']) ?></strong></td>
                            <td data-label="Payment Code">
                                <?php if ($paymentCode !== ''): ?>
                                    <span class="onsite-batch-code"><?= e($paymentCode) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Amount"><?= formatMoney((float) $slip['amount']) ?></td>
                            <td data-label="Action">
                                <div class="onsite-batch-row-actions">
                                    <?php if ($paymentCode !== ''): ?>
                                        <a class="btn btn-sm btn-outline"
                                           href="<?= APP_URL ?>/registrar/onsite-request-slip.php?id=<?= $requestId ?>&print=1"
                                           target="_blank"
                                           rel="noopener">
                                            <i class="fas fa-print"></i> Slip
                                        </a>
                                    <?php endif; ?>
                                    <?= renderRegistrarClaimSlipButtonsHtml($request, true, $slip['payment'] ?? null) ?>
                                    <a class="btn btn-sm btn-outline" href="<?= APP_URL ?>/registrar/verify-request.php?id=<?= $requestId ?>">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="text-muted onsite-batch-note">
            Print a <strong>combined slip</strong> for one cashier sheet covering the whole batch, or
            <strong>all slips</strong> to hand each requestor their own copy. Cashiers still verify one payment code at a time.
        </p>

        <div class="form-actions onsite-batch-actions">
            <a class="btn btn-outline" href="<?= APP_URL ?>/registrar/new-onsite-request.php?mode=multi">
                <i class="fas fa-users"></i> New Multi-Student Request
            </a>
            <a class="btn btn-outline" href="<?= APP_URL ?>/registrar/new-onsite-request.php">
                <i class="fas fa-plus"></i> New Single Request
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
