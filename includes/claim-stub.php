<?php

function fetchClaimStubData(int $requestId): ?array {
    require_once __DIR__ . '/request-items.php';
    ensureRequestItemsSchema();

    $db = getDB();
    $stmt = $db->prepare('SELECT r.*, dt.name as document_name, dt.code as document_code,
        u.first_name, u.last_name, u.student_id, u.email, u.phone,
        sp.course, sp.year_level, sp.section,
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

    if (in_array($request['status'], ['processing', 'ready_for_pickup', 'shipped', 'completed'], true)) {
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

function canViewClaimStub(array $user, array $request): bool {
    if (hasRole('registrar', 'staff', 'admin')) {
        return true;
    }
    if (hasRole('student') && (int) $user['id'] === (int) $request['user_id']) {
        return in_array($request['status'], ['processing', 'ready_for_pickup', 'shipped', 'completed'], true);
    }
    return false;
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

    $documentSummary = formatRequestItemsSummary($items, 2);
    if ($documentSummary === '—' && !empty($request['document_name'])) {
        $documentSummary = (string) $request['document_name'];
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
        ['Documents', $documentSummary],
        ['Delivery', deliveryMethodLabel($request['delivery_method'] ?? null)],
        ['Release', $releaseSchedule],
        ['Amount Paid', formatMoney((float) $request['total_amount'])],
    ];

    if (($request['delivery_method'] ?? '') === 'authorized_representative' && !empty($request['representative_name'])) {
        $rows[] = ['Representative', (string) $request['representative_name']];
    }

    if ($payment && (!empty($payment['or_number']) || !empty($payment['reference_number']))) {
        $rows[] = ['OR / Ref.', $payment['or_number'] ?? $payment['reference_number']];
    }

    return $rows;
}

function renderClaimStubSheetHtml(array $data, string $autoDownload = ''): void {
    $request = $data['request'];
    $rows = buildClaimStubRows($data);
    $isOnSite = isOnSitePickupMethod($request['delivery_method'] ?? null);
    ?>
    <article class="claim-stub-sheet regdum-slip-sheet" id="claimStubSheet"
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
            <h1 class="claim-stub-heading regdum-slip-heading">Document Claim Stub</h1>
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

function renderClaimStubDocument(array $data, bool $autoPrint = false, string $autoDownload = ''): void {
    $request = $data['request'];
    $autoDownload = in_array($autoDownload, ['pdf', 'png'], true) ? $autoDownload : '';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Stub — <?= e($request['request_number']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/print.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="claim-stub-page regdum-slip-page<?= $autoPrint ? ' auto-print' : '' ?>">
    <div class="claim-stub-toolbar regdum-slip-toolbar no-print">
        <a href="javascript:history.back()" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
        <div class="claim-stub-toolbar-actions regdum-slip-toolbar-actions">
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
