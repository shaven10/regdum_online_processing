<?php

function fetchClaimStubData(int $requestId): ?array {
    $db = getDB();
    $stmt = $db->prepare('SELECT r.*, dt.name as document_name, dt.code as document_code,
        u.first_name, u.last_name, u.student_id, u.email, u.phone,
        sp.course, sp.year_level, sp.section,
        s.first_name as staff_first, s.last_name as staff_last
        FROM requests r
        JOIN document_types dt ON r.document_type_id = dt.id
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

    $displayCode = formatVerificationCode($request['verification_code'] ?? '');
    return [
        'request' => $request,
        'payment' => $paymentData,
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
    $studentName = trim($request['first_name'] . ' ' . $request['last_name']);
    $courseYear = trim(($request['course'] ?? '—') . ($request['year_level'] ? ' · ' . $request['year_level'] : ''));

    $releaseSchedule = 'To be announced';
    if (isOnSitePickupMethod($request['delivery_method'])) {
        if (!empty($request['release_date'])) {
            $releaseSchedule = formatDate($request['release_date']);
            if (!empty($request['release_time'])) {
                $releaseSchedule .= ' at ' . date('g:i A', strtotime($request['release_time']));
            }
        } elseif (!empty($request['pickup_date'])) {
            $releaseSchedule = formatDate($request['pickup_date']);
            if (!empty($request['pickup_time'])) {
                $releaseSchedule .= ' at ' . date('g:i A', strtotime($request['pickup_time']));
            }
        }
    } elseif ($request['delivery_method'] === 'courier') {
        $releaseSchedule = trim(($request['delivery_city'] ?? '') . ', ' . ($request['delivery_province'] ?? ''), ' ,');
        if ($releaseSchedule === '') {
            $releaseSchedule = 'Courier delivery';
        }
    }

    $rows = [
        ['Student Name', $studentName],
        ['Student ID', $request['student_id'] ?? '—'],
        ['Course / Year', $courseYear ?: '—'],
        ['Document', $request['document_name']],
        ['Copies', (string) (int) $request['copies']],
        ['Purpose', purposeLabel($request['purpose']) . ($request['purpose_other'] ? ' — ' . $request['purpose_other'] : '')],
        ['Delivery', deliveryMethodLabel($request['delivery_method'])],
        ['Release Schedule', $releaseSchedule],
        ['Amount Paid', formatMoney((float) $request['total_amount'])],
    ];

    if (requestHasTermInfo($request)) {
        $rows[] = ['School Year', $request['request_school_year']];
        $rows[] = ['Semester', semesterLabel($request['request_semester'] ?? null)];
    }

    if (requestHasSoaInfo($request)) {
    }

    if (requestHasAuthenticationItems($request)) {
        foreach (getRequestAuthenticationItems((int) $request['id']) as $authItem) {
            $rows[] = [
                authenticationDocumentTypeLabel($authItem['auth_document_type']),
                (int) $authItem['sets'] . ' set' . ((int) $authItem['sets'] === 1 ? '' : 's'),
            ];
        }
    }

    if (($request['delivery_method'] ?? '') === 'authorized_representative') {
        $rows[] = ['Representative', trim(($request['representative_name'] ?? '—') . ' (' . ($request['representative_relationship'] ?? '—') . ')')];
    }

    if ($payment) {
        $rows[] = ['Payment Ref.', $payment['reference_number'] ?? ($payment['or_number'] ?? '—')];
    }

    return $rows;
}

function renderClaimStubSheetHtml(array $data, string $autoDownload = ''): void {
    $request = $data['request'];
    $rows = buildClaimStubRows($data);
    $isOnSite = isOnSitePickupMethod($request['delivery_method']);
    ?>
    <article class="claim-stub-sheet" id="claimStubSheet"
        data-request-number="<?= e($request['request_number']) ?>"
        data-auto-download="<?= e($autoDownload) ?>">
        <header class="claim-stub-top">
            <img src="<?= e(APP_LOGO) ?>" alt="<?= e(APP_NAME) ?>" class="app-logo app-logo-claim">
            <p class="claim-stub-office"><?= e(APP_NAME) ?></p>
            <p class="claim-stub-subtitle"><?= e(APP_TAGLINE) ?></p>
            <h1 class="claim-stub-heading">Document Claim Stub</h1>
        </header>

        <div class="claim-stub-code-block">
            <span class="claim-stub-code-label">Verification Code</span>
            <strong class="claim-stub-code-value"><?= e(formatVerificationCode($request['verification_code'] ?? '') ?: '—') ?></strong>
            <span class="claim-stub-request-no">Request No. <?= e($request['request_number']) ?></span>
        </div>

        <table class="claim-stub-table">
            <tbody>
                <?php foreach ($rows as [$label, $value]): ?>
                <tr>
                    <th scope="row"><?= e($label) ?></th>
                    <td><?= e($value) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($isOnSite && !empty($request['release_date'])): ?>
            <p class="claim-stub-note">
                Present this stub at the Registrar's Office on or after the scheduled release date.
            </p>
        <?php endif; ?>

        <section class="claim-stub-instructions">
            <h2>Claiming Instructions</h2>
            <ol>
                <li>Present this claim stub and a valid ID at the Registrar's Office counter.</li>
                <li>Staff will verify your identity using the verification code above.</li>
                <?php if ($isOnSite): ?>
                    <li>Collect your document on or after the scheduled release date and time.</li>
                    <?php if (($request['delivery_method'] ?? '') === 'authorized_representative'): ?>
                        <li>The authorized representative must bring the uploaded authorization letter and valid ID.</li>
                    <?php endif; ?>
                <?php else: ?>
                    <li>Your document will be prepared for delivery to the address on file.</li>
                <?php endif; ?>
            </ol>
        </section>

        <div class="claim-stub-signatures">
            <div class="claim-stub-sign-line">Student / Authorized Representative</div>
            <div class="claim-stub-sign-line">Registrar Staff</div>
        </div>

        <footer class="claim-stub-footer">
            Generated on <?= formatDateTime(date('Y-m-d H:i:s')) ?> · <?= e(APP_NAME) ?><br>
            Verify online: <?= e($data['verify_url']) ?>
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
<body class="claim-stub-page<?= $autoPrint ? ' auto-print' : '' ?>">
    <div class="claim-stub-toolbar no-print">
        <a href="javascript:history.back()" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
        <div class="claim-stub-toolbar-actions">
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
