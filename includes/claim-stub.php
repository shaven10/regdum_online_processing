<?php

function fetchClaimStubData(int $requestId): ?array {
    require_once __DIR__ . '/request-items.php';
    ensureRequestItemsSchema();

    $db = getDB();
    $stmt = $db->prepare('SELECT r.*, dt.name as document_name, dt.code as document_code,
        u.first_name, u.last_name, u.student_id, u.email, u.phone,
        sp.course, sp.year_level, sp.section, sp.enrollment_status,
        sp.current_academic_year, sp.current_semester, sp.year_graduated, sp.last_school_year,
        s.first_name as staff_first, s.last_name as staff_last
        FROM requests r
        LEFT JOIN document_types dt ON r.document_type_id = dt.id
        JOIN users u ON r.user_id = u.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN users s ON r.assigned_to = s.id
        WHERE r.id = ?');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        return null;
    }

    $payment = $db->prepare("SELECT * FROM payments WHERE request_id = ? AND status = 'verified' ORDER BY verified_at DESC LIMIT 1");
    $payment->execute([$requestId]);
    $paymentData = $payment->fetch() ?: null;

    if (isClaimStubPrintableStatus((string) ($request['status'] ?? '')) || !empty($paymentData)) {
        $simpleCode = ensureSimpleVerificationCode((int) $request['id']);
        if ($simpleCode) {
            $request['verification_code'] = $simpleCode;
        }
    }

    $items = getRequestItems($requestId);
    $displayCode = formatVerificationCode($request['verification_code'] ?? '');

    return [
        'request' => $request,
        'payment' => $paymentData,
        'items' => $items,
        'verify_url' => APP_URL . '/verify.php?code=' . urlencode($displayCode) . '&ref=' . urlencode($request['request_number']),
    ];
}

function claimStubPrintableStatuses(): array {
    return ['payment_verified', 'processing', 'ready_for_pickup', 'shipped', 'completed'];
}

function isClaimStubPrintableStatus(?string $status): bool {
    return in_array((string) $status, claimStubPrintableStatuses(), true);
}

function canViewClaimStub(array $user, array $request): bool {
    if (hasRole('cashier', 'registrar', 'staff', 'admin')) {
        return true;
    }
    if (hasRole('student') && (int) $user['id'] === (int) $request['user_id']) {
        return in_array($request['status'], ['processing', 'ready_for_pickup', 'shipped', 'completed'], true);
    }
    return false;
}

function canPrintClaimStub(array $data): bool {
    $request = $data['request'] ?? [];
    $payment = $data['payment'] ?? null;
    if (!$request || empty($payment) || ($payment['status'] ?? '') !== 'verified') {
        return false;
    }

    return isClaimStubPrintableStatus((string) ($request['status'] ?? ''))
        || ($payment['status'] ?? '') === 'verified';
}

function canPrintCashierClaimStub(array $data): bool {
    return canPrintClaimStub($data);
}

function requestHasVerifiedPaymentForClaimSlip(array $request, ?array $payment = null): bool {
    if ($payment && ($payment['status'] ?? '') === 'verified') {
        return true;
    }

    return isClaimStubPrintableStatus((string) ($request['status'] ?? ''));
}

/**
 * @return list<int>
 */
function claimStubRelatedRequestIds(array $request): array {
    $requestId = (int) ($request['id'] ?? 0);
    $batchKey = trim((string) ($request['onsite_batch_key'] ?? ''));
    if ($batchKey === '') {
        return $requestId > 0 ? [$requestId] : [];
    }

    require_once __DIR__ . '/payments.php';
    $ids = [];
    foreach (listOnsiteBatchPaymentMembers($batchKey) as $member) {
        if (($member['status'] ?? '') !== 'verified') {
            continue;
        }
        $relatedId = (int) ($member['request_id'] ?? 0);
        if ($relatedId > 0 && !in_array($relatedId, $ids, true)) {
            $ids[] = $relatedId;
        }
    }

    if ($ids === [] && $requestId > 0) {
        return [$requestId];
    }

    return $ids;
}

function claimStubPageUrl(string $scriptPath, array $requestIds, string $layout = '', bool $autoPrint = false): string {
    $requestIds = normalizeClaimStubRequestIds($requestIds);
    $query = [];
    if (count($requestIds) <= 1 && $layout !== 'combined') {
        $query['id'] = (int) ($requestIds[0] ?? 0);
    } else {
        $query['ids'] = implode(',', $requestIds);
        $query['layout'] = $layout !== '' ? $layout : (count($requestIds) > 1 ? 'combined' : 'separate');
    }
    if ($autoPrint) {
        $query['print'] = '1';
    }

    return rtrim(APP_URL, '/') . '/' . ltrim($scriptPath, '/') . '?' . http_build_query($query);
}

/**
 * @return list<int>
 */
function normalizeClaimStubRequestIds($raw): array {
    $parts = is_array($raw) ? $raw : preg_split('/[,\s]+/', (string) $raw);
    $ids = [];
    foreach ($parts ?: [] as $part) {
        $id = (int) trim((string) $part);
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * @param list<int> $requestIds
 * @return list<array>
 */
function fetchClaimStubsForRequests(array $requestIds, array $viewer): array {
    $slips = [];
    foreach (normalizeClaimStubRequestIds($requestIds) as $requestId) {
        $data = fetchClaimStubData($requestId);
        if (!$data || !canViewClaimStub($viewer, $data['request']) || !canPrintClaimStub($data)) {
            continue;
        }
        $slips[] = $data;
    }

    return $slips;
}

function cashierClaimStubUrl(array $requestIds, string $layout = '', bool $autoPrint = false): string {
    return claimStubPageUrl('cashier/claim-stub.php', $requestIds, $layout, $autoPrint);
}

function registrarClaimStubUrl(array $requestIds, string $layout = '', bool $autoPrint = false): string {
    return claimStubPageUrl('registrar/claim-stub.php', $requestIds, $layout, $autoPrint);
}

function renderRegistrarClaimSlipButtonsHtml(array $request, bool $compact = false, ?array $payment = null): string {
    $requestId = (int) ($request['id'] ?? 0);
    if ($requestId <= 0 || !requestHasVerifiedPaymentForClaimSlip($request, $payment)) {
        return '';
    }

    $relatedIds = claimStubRelatedRequestIds($request);
    $btnClass = $compact ? 'btn btn-sm btn-outline' : 'btn btn-outline';
    $primaryClass = $compact ? 'btn btn-sm btn-primary' : 'btn btn-primary';

    $html = '<a href="' . e(registrarClaimStubUrl([$requestId], '', true)) . '" target="_blank" class="' . $primaryClass . '">'
        . '<i class="fas fa-print"></i> Print Claim Slip</a> ';
    $html .= '<a href="' . e(registrarClaimStubUrl([$requestId])) . '" target="_blank" class="' . $btnClass . '">'
        . '<i class="fas fa-ticket-alt"></i> Preview Claim Slip</a>';

    if (count($relatedIds) > 1) {
        $html .= ' <a href="' . e(registrarClaimStubUrl($relatedIds, 'combined')) . '" target="_blank" class="' . $btnClass . '">'
            . '<i class="fas fa-file-alt"></i> Combined Claim Slip</a>';
    }

    return $html;
}

/**
 * @param list<array> $slips
 * @return array{
 *   document_summary:string,
 *   or_number:string,
 *   payment_date:string,
 *   requestor_count:int,
 *   batch_total:float,
 *   printable_ids:list<int>,
 *   first_request_number:string
 * }
 */
function summarizeClaimStubBatch(array $slips): array {
    $first = $slips[0] ?? [];
    $request = $first['request'] ?? [];
    $payment = $first['payment'] ?? [];
    $items = $first['items'] ?? [];

    $documentSummary = formatRequestItemsSummary($items, 0);
    if ($documentSummary === '—' && !empty($request['document_name'])) {
        $documentSummary = (string) $request['document_name'] . formatRequestItemTermSuffix($request);
    }
    if ($documentSummary === '—') {
        $documentSummary = 'Requested credentials';
    }

    $batchTotal = 0.0;
    $printableIds = [];
    $orNumber = '';
    $paymentDate = '';
    foreach ($slips as $slip) {
        $amount = (float) (($slip['payment']['amount'] ?? 0) ?: ($slip['request']['total_amount'] ?? 0));
        $batchTotal += $amount;
        $printableIds[] = (int) ($slip['request']['id'] ?? 0);
        if ($orNumber === '' && !empty($slip['payment']['or_number'])) {
            $orNumber = (string) $slip['payment']['or_number'];
        }
        if ($paymentDate === '' && !empty($slip['payment']['payment_date'])) {
            $paymentDate = (string) $slip['payment']['payment_date'];
        }
    }

    return [
        'document_summary' => $documentSummary,
        'or_number' => $orNumber !== '' ? $orNumber : (string) ($payment['or_number'] ?? $payment['reference_number'] ?? '—'),
        'payment_date' => $paymentDate !== '' ? $paymentDate : (string) ($payment['payment_date'] ?? ''),
        'requestor_count' => count($slips),
        'batch_total' => $batchTotal,
        'printable_ids' => array_values(array_filter($printableIds)),
        'first_request_number' => (string) ($request['request_number'] ?? 'claim-batch'),
    ];
}

function buildClaimStubRows(array $data): array {
    $request = $data['request'];
    $payment = $data['payment'];
    $items = $data['items'] ?? [];
    $studentName = trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
    $courseYear = trim(
        (string) ($request['course'] ?? '')
        . (!empty($request['year_level']) ? ' · ' . $request['year_level'] : '')
    );

    $documentSummary = formatRequestItemsSummary($items, 0);
    if ($documentSummary === '—' && !empty($request['document_name'])) {
        $documentSummary = (string) $request['document_name'] . formatRequestItemTermSuffix($request);
        if (!empty($request['copies'])) {
            $documentSummary .= ' × ' . (int) $request['copies'];
        }
    }

    $releaseSchedule = 'To be announced';
    if (isOnSitePickupMethod($request['delivery_method'])) {
        if (!empty($request['release_date'])) {
            $releaseSchedule = formatDate($request['release_date']);
            if (!empty($request['release_time'])) {
                $releaseSchedule .= ' · ' . date('g:i A', strtotime($request['release_time']));
            }
        } elseif (!empty($request['pickup_date'])) {
            $releaseSchedule = formatDate($request['pickup_date']);
            if (!empty($request['pickup_time'])) {
                $releaseSchedule .= ' · ' . date('g:i A', strtotime($request['pickup_time']));
            }
        }
    } elseif (($request['delivery_method'] ?? '') === 'courier') {
        $releaseSchedule = 'Courier delivery';
    }

    $rows = [
        ['Student', $studentName !== '' ? $studentName : '—'],
        ['Student ID', $request['student_id'] ?? '—'],
        ['Course / Year', $courseYear !== '' ? $courseYear : '—'],
    ];
    foreach (studentAcademicSlipRows($request) as $academicRow) {
        $rows[] = $academicRow;
    }
    $rows = array_merge($rows, [
        ['Documents', $documentSummary],
        ['Delivery', deliveryMethodLabel($request['delivery_method'] ?? null)],
        ['Release', $releaseSchedule],
        ['Amount Paid', formatMoney((float) $request['total_amount'])],
    ]);

    if (($request['delivery_method'] ?? '') === 'authorized_representative' && !empty($request['representative_name'])) {
        $rows[] = ['Representative', (string) $request['representative_name']];
    }

    if ($payment && (!empty($payment['or_number']) || !empty($payment['reference_number']))) {
        $rows[] = ['OR / Ref.', $payment['or_number'] ?? $payment['reference_number']];
    }

    return $rows;
}

function renderClaimStubSheetHtml(array $data, string $autoDownload = '', string $sheetId = 'claimStubSheet'): void {
    $request = $data['request'];
    $rows = buildClaimStubRows($data);
    $isOnSite = isOnSitePickupMethod($request['delivery_method'] ?? null);
    $title = trim((string) ($data['slip_title'] ?? 'Document Claim Stub'));
    ?>
    <article class="claim-stub-sheet regdum-slip-sheet" id="<?= e($sheetId) ?>"
        data-request-number="<?= e($request['request_number']) ?>"
        data-auto-download="<?= e($autoDownload) ?>"
        data-slip-width="4.25"
        data-slip-height="6.5">
        <header class="claim-stub-top regdum-slip-top">
            <img src="<?= e(APP_LOGO) ?>" alt="<?= e(APP_NAME) ?>" class="app-logo app-logo-claim regdum-slip-logo">
            <div class="regdum-slip-brand">
                <p class="claim-stub-office regdum-slip-office"><?= e(APP_NAME) ?></p>
                <p class="claim-stub-subtitle regdum-slip-subtitle"><?= e(APP_TAGLINE) ?></p>
            </div>
            <h1 class="claim-stub-heading regdum-slip-heading"><?= e($title !== '' ? $title : 'Document Claim Stub') ?></h1>
        </header>

        <div class="claim-stub-code-block regdum-slip-code-block">
            <span class="claim-stub-code-label regdum-slip-code-label">Verification Code</span>
            <strong class="claim-stub-code-value regdum-slip-code-value"><?= e(formatVerificationCode($request['verification_code'] ?? '') ?: '—') ?></strong>
            <span class="claim-stub-request-no regdum-slip-request-no">Request No. <?= e($request['request_number']) ?></span>
        </div>

        <table class="claim-stub-table regdum-slip-table">
            <tbody>
                <?php foreach ($rows as [$label, $value]): ?>
                <tr>
                    <th scope="row"><?= e($label) ?></th>
                    <td><?= e($value) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="claim-stub-note regdum-slip-note">
            <?php if ($isOnSite): ?>
                Present this stub and a valid ID at the Registrar's Office on or after the scheduled release.
            <?php else: ?>
                Present this stub and a valid ID when claiming. Staff will verify using the code above.
            <?php endif; ?>
        </p>

        <footer class="claim-stub-footer regdum-slip-footer">
            Generated <?= formatDateTime(date('Y-m-d H:i:s')) ?> · Verify: <?= e($data['verify_url']) ?>
        </footer>
    </article>
    <?php
}

function renderClaimStubDocument(array $data, bool $autoPrint = false, string $autoDownload = '', array $options = []): void {
    $request = $data['request'];
    $autoDownload = in_array($autoDownload, ['pdf', 'png'], true) ? $autoDownload : '';
    $backUrl = trim((string) ($options['back_url'] ?? ''));
    $backLabel = trim((string) ($options['back_label'] ?? 'Back'));
    $relatedIds = normalizeClaimStubRequestIds($options['related_ids'] ?? []);
    $slipUrl = (($options['audience'] ?? 'cashier') === 'registrar')
        ? static fn(array $ids, string $layout = '', bool $autoPrint = false): string => registrarClaimStubUrl($ids, $layout, $autoPrint)
        : static fn(array $ids, string $layout = '', bool $autoPrint = false): string => cashierClaimStubUrl($ids, $layout, $autoPrint);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Slip — <?= e($request['request_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/print.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="claim-stub-page regdum-slip-page<?= $autoPrint ? ' auto-print' : '' ?>">
    <div class="claim-stub-toolbar regdum-slip-toolbar no-print">
        <a href="<?= $backUrl !== '' ? e($backUrl) : 'javascript:history.back()' ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> <?= e($backLabel !== '' ? $backLabel : 'Back') ?></a>
        <div class="claim-stub-toolbar-actions regdum-slip-toolbar-actions">
            <?php if (count($relatedIds) > 1): ?>
                <a href="<?= e($slipUrl($relatedIds, 'combined')) ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-alt"></i> Combined Slip
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-outline btn-sm" data-claim-download="png">
                <i class="fas fa-image"></i> Download Image
            </button>
            <button type="button" class="btn btn-outline btn-sm" data-claim-download="pdf">
                <i class="fas fa-file-pdf"></i> Download PDF
            </button>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <?php renderClaimStubSheetHtml($data, $autoDownload); ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="<?= APP_URL ?>/assets/js/claim-stub.js"></script>
</body>
</html>
    <?php
}

/**
 * @param list<array> $slips
 */
function renderClaimStubBatchDocument(array $slips, bool $autoPrint = false, array $options = []): void {
    $slipCount = count($slips);
    $printableIds = summarizeClaimStubBatch($slips)['printable_ids'];
    $backUrl = trim((string) ($options['back_url'] ?? APP_URL . '/cashier/payments.php'));
    $backLabel = trim((string) ($options['back_label'] ?? 'Back'));
    $slipUrl = (($options['audience'] ?? 'cashier') === 'registrar')
        ? static fn(array $ids, string $layout = '', bool $autoPrint = false): string => registrarClaimStubUrl($ids, $layout, $autoPrint)
        : static fn(array $ids, string $layout = '', bool $autoPrint = false): string => cashierClaimStubUrl($ids, $layout, $autoPrint);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Slips — <?= $slipCount ?> requestor<?= $slipCount === 1 ? '' : 's' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/print.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="claim-stub-page claim-stub-batch-page regdum-slip-page<?= $autoPrint ? ' auto-print' : '' ?>">
    <div class="claim-stub-toolbar regdum-slip-toolbar no-print">
        <a href="<?= e($backUrl) ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> <?= e($backLabel !== '' ? $backLabel : 'Back') ?></a>
        <div class="claim-stub-toolbar-actions regdum-slip-toolbar-actions">
            <span class="onsite-slip-batch-count"><?= $slipCount ?> slip<?= $slipCount === 1 ? '' : 's' ?></span>
            <?php if (count($printableIds) > 1): ?>
                <a href="<?= e($slipUrl($printableIds, 'combined')) ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-alt"></i> Combined Slip
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> Print All Slips
            </button>
        </div>
    </div>

    <?php foreach ($slips as $index => $data): ?>
        <?php renderClaimStubSheetHtml($data, '', 'claimStubSheet' . ($index + 1)); ?>
    <?php endforeach; ?>

    <script>
    if (document.body.classList.contains('auto-print')) {
        window.addEventListener('load', function () {
            window.setTimeout(function () { window.print(); }, 400);
        });
    }
    </script>
</body>
</html>
    <?php
}

/**
 * One 4.25 × 13 in claim slip covering every requestor in a verified batch.
 *
 * @param list<array> $slips
 */
function renderClaimStubCombinedSheetHtml(array $slips): void {
    if (!function_exists('studentRecordName')) {
        require_once __DIR__ . '/student.php';
    }

    $summary = summarizeClaimStubBatch($slips);
    $requestorCount = (int) $summary['requestor_count'];
    $paymentDate = trim((string) $summary['payment_date']);
    ?>
    <article class="claim-stub-sheet claim-combined-slip-sheet onsite-combined-slip-sheet regdum-slip-sheet" id="claimStubSheet"
        data-request-number="<?= e($summary['first_request_number'] . '-batch') ?>"
        data-slip-width="4.25"
        data-slip-height="13">
        <header class="claim-stub-top regdum-slip-top">
            <img src="<?= e(APP_LOGO) ?>" alt="<?= e(APP_NAME) ?>" class="app-logo app-logo-claim regdum-slip-logo">
            <div class="regdum-slip-brand">
                <p class="claim-stub-office regdum-slip-office"><?= e(APP_NAME) ?></p>
                <p class="claim-stub-subtitle regdum-slip-subtitle"><?= e(APP_TAGLINE) ?></p>
            </div>
            <h1 class="claim-stub-heading regdum-slip-heading">Combined Claim Slip</h1>
        </header>

        <div class="onsite-combined-meta">
            <div>
                <span class="onsite-combined-meta-label">Requestors</span>
                <strong><?= $requestorCount ?> student<?= $requestorCount === 1 ? '' : 's' ?></strong>
            </div>
            <div>
                <span class="onsite-combined-meta-label">Documents</span>
                <strong><?= e($summary['document_summary']) ?></strong>
            </div>
            <div>
                <span class="onsite-combined-meta-label">OR Number</span>
                <strong><?= e($summary['or_number'] !== '' ? $summary['or_number'] : '—') ?></strong>
            </div>
            <div>
                <span class="onsite-combined-meta-label">Payment Date</span>
                <strong><?= $paymentDate !== '' ? e(formatDate($paymentDate)) : '—' ?></strong>
            </div>
            <div>
                <span class="onsite-combined-meta-label">Batch Total</span>
                <strong><?= formatMoney((float) $summary['batch_total']) ?></strong>
            </div>
        </div>

        <table class="onsite-combined-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Requestor</th>
                    <th>Code</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slips as $index => $slip): ?>
                    <?php
                    $request = $slip['request'] ?? [];
                    $payment = $slip['payment'] ?? [];
                    $code = formatVerificationCode($request['verification_code'] ?? '') ?: '—';
                    $requestorMeta = trim(($request['student_id'] ?? '') . ' · ' . ($request['request_number'] ?? ''), ' ·');
                    $amount = (float) (($payment['amount'] ?? 0) ?: ($request['total_amount'] ?? 0));
                    ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td>
                            <strong><?= e(studentRecordName($request)) ?></strong>
                            <?php if ($requestorMeta !== ''): ?>
                                <small><?= e($requestorMeta) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="onsite-combined-code"><?= e($code) ?></td>
                        <td><?= formatMoney($amount) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="claim-stub-note regdum-slip-note">
            Present this slip and a valid ID at the Registrar's Office. Each requestor has a separate verification code.
        </p>

        <footer class="claim-stub-footer regdum-slip-footer">
            Generated <?= formatDateTime(date('Y-m-d H:i:s')) ?>
        </footer>
    </article>
    <?php
}

/**
 * @param list<array> $slips
 */
function renderClaimStubCombinedDocument(array $slips, bool $autoPrint = false, array $options = []): void {
    $summary = summarizeClaimStubBatch($slips);
    $printableIds = $summary['printable_ids'];
    $slipCount = (int) $summary['requestor_count'];
    $backUrl = trim((string) ($options['back_url'] ?? APP_URL . '/cashier/payments.php'));
    $backLabel = trim((string) ($options['back_label'] ?? 'Back'));
    $slipUrl = (($options['audience'] ?? 'cashier') === 'registrar')
        ? static fn(array $ids, string $layout = '', bool $autoPrint = false): string => registrarClaimStubUrl($ids, $layout, $autoPrint)
        : static fn(array $ids, string $layout = '', bool $autoPrint = false): string => cashierClaimStubUrl($ids, $layout, $autoPrint);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Combined Claim Slip — <?= $slipCount ?> requestor<?= $slipCount === 1 ? '' : 's' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/print.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="claim-stub-page claim-stub-combined-page regdum-slip-page<?= $autoPrint ? ' auto-print' : '' ?>">
    <div class="claim-stub-toolbar regdum-slip-toolbar no-print">
        <a href="<?= e($backUrl) ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> <?= e($backLabel !== '' ? $backLabel : 'Back') ?></a>
        <div class="claim-stub-toolbar-actions regdum-slip-toolbar-actions">
            <?php if ($printableIds !== []): ?>
                <a href="<?= e($slipUrl($printableIds, 'separate')) ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-copy"></i> Individual Slips
                </a>
            <?php endif; ?>
            <button type="button" class="btn btn-outline btn-sm" data-claim-download="png">
                <i class="fas fa-image"></i> Download Image
            </button>
            <button type="button" class="btn btn-outline btn-sm" data-claim-download="pdf">
                <i class="fas fa-file-pdf"></i> Download PDF
            </button>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> Print Combined Slip
            </button>
        </div>
    </div>

    <?php renderClaimStubCombinedSheetHtml($slips); ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="<?= APP_URL ?>/assets/js/claim-stub.js"></script>
</body>
</html>
    <?php
}
