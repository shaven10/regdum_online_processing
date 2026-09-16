<?php

require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/request-items.php';
require_once __DIR__ . '/student.php';

/**
 * @param list<int>|int|string $paymentIds
 * @return list<int>
 */
function normalizeOfficialReceiptPaymentIds($paymentIds): array {
    $parts = is_array($paymentIds) ? $paymentIds : preg_split('/[,\s]+/', (string) $paymentIds);
    $ids = [];
    foreach ($parts ?: [] as $part) {
        $id = (int) trim((string) $part);
        if ($id > 0 && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function cashierOfficialReceiptUrl($paymentIds, bool $autoPrint = false): string {
    $ids = normalizeOfficialReceiptPaymentIds($paymentIds);
    if ($ids === []) {
        return APP_URL . '/cashier/payments.php';
    }

    $query = count($ids) === 1
        ? ['id' => $ids[0]]
        : ['ids' => implode(',', $ids)];
    if ($autoPrint) {
        $query['print'] = '1';
    }

    return APP_URL . '/cashier/official-receipt.php?' . http_build_query($query);
}

/**
 * @return 'template'|'data_only'
 */
function normalizeOfficialReceiptPrintMode(?string $mode): string {
    $mode = strtolower(trim((string) $mode));
    return $mode === 'data_only' ? 'data_only' : 'template';
}

function officialReceiptPrintModeSettingKey(int $userId): string {
    return 'or_print_mode_user_' . max(0, $userId);
}

/**
 * @return 'template'|'data_only'
 */
function getOfficialReceiptPrintMode(?int $userId = null): string {
    if (!function_exists('getAppSetting')) {
        require_once __DIR__ . '/compliance.php';
    }
    if ($userId === null) {
        $user = function_exists('currentUser') ? currentUser() : null;
        $userId = (int) ($user['id'] ?? 0);
    }
    if ($userId <= 0) {
        return 'template';
    }

    return normalizeOfficialReceiptPrintMode(
        getAppSetting(officialReceiptPrintModeSettingKey($userId), 'template')
    );
}

/**
 * @return 'template'|'data_only'
 */
function saveOfficialReceiptPrintMode(int $userId, string $mode): string {
    if (!function_exists('setAppSetting')) {
        require_once __DIR__ . '/compliance.php';
    }
    $mode = normalizeOfficialReceiptPrintMode($mode);
    if ($userId <= 0) {
        return $mode;
    }
    setAppSetting(officialReceiptPrintModeSettingKey($userId), $mode);
    return $mode;
}

function officialReceiptPrintModeLabel(string $mode): string {
    return normalizeOfficialReceiptPrintMode($mode) === 'data_only'
        ? 'Data only'
        : 'OR template';
}

/**
 * Convert a peso amount into Philippine English words.
 */
function amountToWordsPeso(float $amount): string {
    $amount = round(max(0, $amount), 2);
    $pesos = (int) floor($amount + 1e-9);
    $centavos = (int) round(($amount - $pesos) * 100);

    $pesosWords = numberToWordsEnglish($pesos);
    $result = $pesosWords . ' Peso' . ($pesos === 1 ? '' : 's');
    if ($centavos > 0) {
        $result .= ' and ' . numberToWordsEnglish($centavos) . ' Centavo' . ($centavos === 1 ? '' : 's');
    }
    return $result . ' Only';
}

function numberToWordsEnglish(int $number): string {
    if ($number === 0) {
        return 'Zero';
    }

    $ones = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $chunk = static function (int $n) use (&$chunk, $ones, $tens): string {
        if ($n < 20) {
            return $ones[$n];
        }
        if ($n < 100) {
            $t = intdiv($n, 10);
            $r = $n % 10;
            return trim($tens[$t] . ($r ? '-' . $ones[$r] : ''));
        }
        if ($n < 1000) {
            $h = intdiv($n, 100);
            $r = $n % 100;
            return trim($ones[$h] . ' Hundred' . ($r ? ' ' . $chunk($r) : ''));
        }
        return (string) $n;
    };

    $parts = [];
    $scales = [
        1000000000 => 'Billion',
        1000000 => 'Million',
        1000 => 'Thousand',
    ];

    foreach ($scales as $value => $label) {
        if ($number >= $value) {
            $count = intdiv($number, $value);
            $number %= $value;
            $parts[] = $chunk($count) . ' ' . $label;
        }
    }
    if ($number > 0) {
        $parts[] = $chunk($number);
    }

    return implode(' ', $parts);
}

function officialReceiptPaymentMethodFlags(?string $method): array {
    $method = (string) $method;
    $isCash = in_array($method, ['onsite_payment', 'cash', 'over_the_counter'], true);
    $isCheck = in_array($method, ['bank_transfer', 'check', 'cheque'], true);
    $isMoneyOrder = in_array($method, ['money_order'], true);

    if (!$isCash && !$isCheck && !$isMoneyOrder) {
        $isCash = true;
    }

    return [
        'cash' => $isCash,
        'check' => $isCheck,
        'money_order' => $isMoneyOrder,
    ];
}

/**
 * @param list<int> $paymentIds
 * @return array{
 *   payments:list<array<string,mixed>>,
 *   lines:list<array{nature:string,acct_code:string,amount:float}>,
 *   total:float,
 *   payor:string,
 *   campus:string,
 *   date:string,
 *   or_number:string,
 *   agency:string,
 *   fund:string,
 *   method_flags:array{cash:bool,check:bool,money_order:bool},
 *   collecting_officer:string,
 *   request_numbers:list<string>
 * }|null
 */
function fetchOfficialReceiptData(array $paymentIds, ?array $viewer = null): ?array {
    $paymentIds = normalizeOfficialReceiptPaymentIds($paymentIds);
    if ($paymentIds === []) {
        return null;
    }

    ensurePaymentVerificationSchema();
    ensureRequestItemsSchema();
    if (!function_exists('documentStampFeeAmount')) {
        require_once __DIR__ . '/document-rules.php';
    }

    $db = getDB();
    $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
    $stmt = $db->prepare(
        "SELECT p.*, r.request_number, r.request_channel, r.onsite_batch_key, r.total_amount AS request_amount,
                u.first_name, u.last_name, u.middle_name, u.student_id,
                sp.origin_campus_id,
                c.name AS campus_name, c.code AS campus_code,
                v.first_name AS verifier_first, v.last_name AS verifier_last
         FROM payments p
         JOIN requests r ON r.id = p.request_id
         JOIN users u ON u.id = r.user_id
         LEFT JOIN student_profiles sp ON sp.user_id = u.id
         LEFT JOIN campuses c ON c.id = sp.origin_campus_id
         LEFT JOIN users v ON v.id = p.verified_by
         WHERE p.id IN ($placeholders)
         ORDER BY p.id ASC"
    );
    $stmt->execute($paymentIds);
    $payments = $stmt->fetchAll();
    if ($payments === []) {
        return null;
    }

    foreach ($payments as $payment) {
        if (($payment['status'] ?? '') !== 'verified') {
            return null;
        }
    }

    $contextMap = buildPaymentVerificationDetailsMap(array_map(
        static fn(array $payment): int => (int) $payment['request_id'],
        $payments
    ));

    $lines = [];
    $total = 0.0;
    $requestNumbers = [];

    foreach ($payments as $payment) {
        $requestId = (int) $payment['request_id'];
        $requestNumbers[] = (string) ($payment['request_number'] ?? '');
        $context = $contextMap[$requestId] ?? ['items' => []];
        $items = $context['items'] ?? [];
        $paymentAmount = (float) ($payment['amount'] ?? 0);
        $total += $paymentAmount;

        if ($items === []) {
            $lines[] = [
                'nature' => 'Document request fee — ' . ($payment['request_number'] ?? 'Request'),
                'acct_code' => '',
                'amount' => $paymentAmount,
            ];
            continue;
        }

        $itemTotal = 0.0;
        $itemLines = [];
        foreach ($items as $item) {
            $authItems = $item['auth_items'] ?? [];
            $name = trim((string) ($item['document_name'] ?? 'Document'));
            $detail = paymentVerificationBreakdownDetail($item, $authItems, true);
            $lineAmount = (float) ($item['item_amount'] ?? 0);
            if ($lineAmount <= 0) {
                $copies = max(1, (int) ($item['copies'] ?? 1));
                $base = (float) ($item['base_fee'] ?? 0);
                if (!empty($item['requires_auth_document_type']) && $authItems) {
                    $sets = 0;
                    foreach ($authItems as $authItem) {
                        $sets += max(1, (int) ($authItem['sets'] ?? 1));
                    }
                    $lineAmount = $base * max(1, $sets);
                } elseif (!empty($item['fee_per_set'])) {
                    $lineAmount = $base;
                } else {
                    $lineAmount = $base * $copies;
                }
                if (!empty($item['requires_documentary_stamp'])) {
                    $lineAmount += documentStampFeeAmount();
                }
            }
            $itemTotal += $lineAmount;
            $nature = $name;
            if ($detail !== '') {
                $nature .= ' (' . $detail . ')';
            }
            if (count($payments) > 1) {
                $nature = ($payment['request_number'] ?? '') . ' — ' . $nature;
            }
            $itemLines[] = [
                'nature' => $nature,
                'acct_code' => '',
                'amount' => $lineAmount,
            ];
        }

        // Scale item lines to the actual verified payment amount when they differ.
        if ($itemTotal > 0.009 && abs($itemTotal - $paymentAmount) > 0.009) {
            $scale = $paymentAmount / $itemTotal;
            foreach ($itemLines as &$itemLine) {
                $itemLine['amount'] = round($itemLine['amount'] * $scale, 2);
            }
            unset($itemLine);
            $sumScaled = array_sum(array_column($itemLines, 'amount'));
            $delta = round($paymentAmount - $sumScaled, 2);
            if (abs($delta) >= 0.01 && $itemLines !== []) {
                $last = count($itemLines) - 1;
                $itemLines[$last]['amount'] = round($itemLines[$last]['amount'] + $delta, 2);
            }
        }

        foreach ($itemLines as $itemLine) {
            $lines[] = $itemLine;
        }
    }

    $primary = $payments[0];
    $payorName = trim(
        ($primary['first_name'] ?? '')
        . (!empty($primary['middle_name']) ? ' ' . $primary['middle_name'] : '')
        . ' ' . ($primary['last_name'] ?? '')
    );
    if (count($payments) > 1) {
        $uniquePayors = [];
        foreach ($payments as $payment) {
            $name = trim(($payment['first_name'] ?? '') . ' ' . ($payment['last_name'] ?? ''));
            if ($name !== '') {
                $uniquePayors[$name] = $name;
            }
        }
        if (count($uniquePayors) > 1) {
            $payorName = 'Multiple payors (' . count($uniquePayors) . ')';
        }
    }
    if ($payorName !== '' && !empty($primary['student_id']) && count($payments) === 1) {
        $payorName .= ' / ' . $primary['student_id'];
    }

    $campus = trim((string) ($primary['campus_name'] ?? ''));
    if ($campus !== '' && !empty($primary['campus_code'])) {
        $campus .= ' (' . $primary['campus_code'] . ')';
    }
    if ($campus === '') {
        $campus = 'Dumingag Campus';
    }

    $orNumber = '';
    foreach ($payments as $payment) {
        $candidate = trim((string) ($payment['or_number'] ?? ''));
        if ($candidate !== '') {
            $orNumber = $candidate;
            break;
        }
    }

    $dateRaw = $primary['payment_date'] ?? $primary['verified_at'] ?? null;
    $dateLabel = !empty($dateRaw) ? formatDate((string) $dateRaw) : formatDate(appToday());

    $verifier = trim(($primary['verifier_first'] ?? '') . ' ' . ($primary['verifier_last'] ?? ''));
    if ($verifier === '' && $viewer) {
        $verifier = trim(fullName($viewer));
    }

    $methodFlags = officialReceiptPaymentMethodFlags($primary['payment_method'] ?? null);

    return [
        'payments' => $payments,
        'lines' => $lines,
        'total' => round($total, 2),
        'payor' => $payorName !== '' ? $payorName : '—',
        'campus' => $campus,
        'date' => $dateLabel,
        'or_number' => $orNumber !== '' ? $orNumber : '—',
        'agency' => 'J.H. Cerilles State College',
        'fund' => '',
        'method_flags' => $methodFlags,
        'collecting_officer' => $verifier !== '' ? $verifier : '—',
        'request_numbers' => array_values(array_filter($requestNumbers)),
        'amount_in_words' => amountToWordsPeso($total),
    ];
}

function canViewOfficialReceipt(array $user, array $receipt): bool {
    if (hasRole('cashier', 'admin', 'registrar')) {
        return true;
    }
    return false;
}

function renderOfficialReceiptDocument(array $receipt, bool $autoPrint = false, string $backUrl = '', ?string $printMode = null): void {
    $backUrl = $backUrl !== '' ? $backUrl : (APP_URL . '/cashier/payments.php');
    $printMode = normalizeOfficialReceiptPrintMode($printMode ?? getOfficialReceiptPrintMode());
    $dataOnly = $printMode === 'data_only';

    $logo = APP_LOGO;
    $lines = $receipt['lines'] ?? [];
    $rowCount = max(12, count($lines));
    $flags = $receipt['method_flags'] ?? ['cash' => true, 'check' => false, 'money_order' => false];
    $settingsUrl = APP_URL . '/cashier/or-settings.php';
    $modeClass = $dataOnly ? ' or-mode-data-only' : ' or-mode-template';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Receipt — <?= e($receipt['or_number'] ?? '') ?></title>
    <link rel="icon" type="image/png" href="<?= e($logo) ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body.or-print-page {
            margin: 0;
            padding: 1rem;
            background: #e8edf3;
            color: #1e3a5f;
            font-family: "Times New Roman", Times, serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .or-toolbar {
            width: min(4.25in, 100%);
            margin: 0 0 .75rem;
            display: flex;
            justify-content: space-between;
            gap: .5rem;
            flex-wrap: wrap;
            align-items: center;
            font-family: "Plus Jakarta Sans", Arial, sans-serif;
        }
        .or-toolbar-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
        .or-hint {
            width: min(4.25in, 100%);
            margin: 0 0 .75rem;
            font-family: "Plus Jakarta Sans", Arial, sans-serif;
            font-size: 11px;
            color: #64748b;
            text-align: center;
        }
        .or-sheet {
            width: 4.25in;
            height: 8.5in;
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
            border: 1.5px solid #2c5282;
            border-radius: 8px;
            padding: .12in .14in .1in;
            box-sizing: border-box;
            color: #234e78;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-self: center;
        }
        .or-head {
            display: grid;
            grid-template-columns: .48in 1fr .48in;
            gap: .08in;
            align-items: start;
            text-align: center;
            margin-bottom: .06in;
            flex-shrink: 0;
        }
        .or-head img {
            width: .42in;
            height: .42in;
            object-fit: contain;
        }
        .or-head-center h1 {
            margin: 0;
            font-size: 11pt;
            letter-spacing: .03em;
            text-transform: uppercase;
            font-weight: 700;
            line-height: 1.1;
        }
        .or-head-center p {
            margin: .01in 0 0;
            font-size: 7.5pt;
            line-height: 1.15;
        }
        .or-head-center .or-office {
            font-weight: 700;
            margin-top: .02in;
        }
        .or-campus-line {
            margin-top: .04in;
            font-size: 7.5pt;
        }
        .or-campus-line .or-campus-value {
            display: inline-block;
            min-width: 1.6in;
            border-bottom: 1px solid #2c5282;
            padding: 0 .04in;
        }
        .or-meta-row {
            display: flex;
            justify-content: space-between;
            gap: .12in;
            font-size: 6.5pt;
            margin: .04in 0 .06in;
            flex-shrink: 0;
            line-height: 1.2;
        }
        .or-serial { text-align: right; }
        .or-serial .or-original {
            display: block;
            font-size: 10pt;
            font-weight: 800;
            letter-spacing: .05em;
        }
        .or-serial .or-number {
            display: block;
            margin-top: .02in;
            color: #c53030;
            font-size: 10pt;
            font-weight: 700;
        }
        .or-fields {
            font-size: 8pt;
            margin-bottom: .06in;
            flex-shrink: 0;
        }
        .or-fields .or-field {
            display: grid;
            grid-template-columns: .55in 1fr;
            gap: .05in;
            align-items: end;
            margin: .035in 0;
        }
        .or-fields .or-field.agency-fund {
            grid-template-columns: .55in 1fr .42in .85in;
        }
        .or-fields .or-line {
            border-bottom: 1px solid #2c5282;
            min-height: .16in;
            padding: 0 .03in .01in;
        }
        .or-table-wrap {
            flex: 1 1 auto;
            min-height: 0;
        }
        .or-table {
            width: 100%;
            height: 100%;
            border-collapse: collapse;
            font-size: 7.5pt;
            table-layout: fixed;
        }
        .or-table th,
        .or-table td {
            border: 1px solid #2c5282;
            padding: .03in .04in;
            vertical-align: top;
        }
        .or-table th {
            text-align: center;
            font-size: 6.5pt;
            font-weight: 700;
            background: #edf2f7;
        }
        .or-table .col-nature { width: 58%; }
        .or-table .col-acct { width: 18%; text-align: center; }
        .or-table .col-amount { width: 24%; text-align: right; }
        .or-table tbody tr { height: .2in; }
        .or-table .or-total-row td { font-weight: 700; }
        .or-table .or-total-row .col-nature { text-align: left; }
        .or-words {
            margin-top: .06in;
            font-size: 7.5pt;
            border: 1px solid #2c5282;
            border-radius: 4px;
            padding: .05in .06in;
            min-height: .32in;
            flex-shrink: 0;
        }
        .or-words label {
            display: block;
            font-size: 6.5pt;
            font-weight: 700;
            margin-bottom: .02in;
        }
        .or-footer {
            display: grid;
            grid-template-columns: 1in 1fr 1.2in;
            gap: .08in;
            margin-top: .08in;
            font-size: 7pt;
            align-items: start;
            flex-shrink: 0;
        }
        .or-pay-methods label {
            display: flex;
            align-items: center;
            gap: .08in;
            margin: .035in 0;
        }
        .or-pay-box {
            width: .12in;
            height: .12in;
            border: 1.5px solid #2c5282;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 6.5pt;
            line-height: 1;
        }
        .or-check-grid {
            width: 100%;
            border-collapse: collapse;
            font-size: 6pt;
        }
        .or-check-grid th,
        .or-check-grid td {
            border: 1px solid #2c5282;
            padding: .025in;
            height: .18in;
        }
        .or-sign {
            text-align: center;
            margin-top: .12in;
        }
        .or-sign .or-ack {
            text-align: left;
            margin-bottom: .28in;
            font-size: 7pt;
        }
        .or-sign .or-sign-line {
            border-top: 1px solid #2c5282;
            margin: 0 auto;
            width: 95%;
            padding-top: .03in;
            font-size: 6.5pt;
            font-weight: 700;
        }
        .or-footnote {
            margin-top: .06in;
            font-size: 5.5pt;
            font-style: italic;
            flex-shrink: 0;
        }

        /* Data-only uses the same AF51 layout; hide template chrome and keep spacing. */
        body.or-mode-data-only .or-sheet {
            border-color: #cbd5e0;
            border-style: dashed;
        }
        body.or-mode-data-only .or-template {
            visibility: hidden !important;
        }
        body.or-mode-data-only .or-campus-value,
        body.or-mode-data-only .or-fields .or-line,
        body.or-mode-data-only .or-words,
        body.or-mode-data-only .or-sign .or-sign-line {
            border-color: transparent !important;
        }
        body.or-mode-data-only .or-table th,
        body.or-mode-data-only .or-table td,
        body.or-mode-data-only .or-check-grid th,
        body.or-mode-data-only .or-check-grid td {
            border-color: transparent !important;
            background: transparent !important;
        }
        body.or-mode-data-only .or-pay-box {
            border-color: transparent !important;
        }
        body.or-mode-data-only .or-serial .or-number {
            color: inherit;
        }

        @media print {
            @page {
                size: 4.25in 8.5in;
                margin: .15in;
            }
            html, body.or-print-page {
                width: 4.25in;
                height: 8.5in;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                min-height: 0 !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: flex-start !important;
            }
            .no-print { display: none !important; }
            .or-sheet {
                width: 100% !important;
                height: auto !important;
                max-height: 100% !important;
                max-width: none !important;
                margin: 0 auto !important;
                border-radius: 6px;
                box-shadow: none;
                page-break-after: avoid;
                page-break-inside: avoid;
            }
            body.or-mode-data-only .or-sheet {
                border: none !important;
                background: transparent !important;
                border-radius: 0 !important;
            }
            body.or-mode-data-only .or-template {
                visibility: hidden !important;
                color: transparent !important;
            }
        }
    </style>
</head>
<body class="or-print-page<?= $modeClass ?><?= $autoPrint ? ' auto-print' : '' ?>">
    <div class="or-toolbar no-print">
        <a href="<?= e($backUrl) ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
        <div class="or-toolbar-actions">
            <a href="<?= e($settingsUrl) ?>" class="btn btn-outline btn-sm"><i class="fas fa-cog"></i> OR Settings</a>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> <?= $dataOnly ? 'Print OR Data' : 'Print OR' ?>
            </button>
        </div>
    </div>
    <?php if ($dataOnly): ?>
        <p class="or-hint no-print">Data only — same AF51 positions; template chrome is hidden for printing on physical OR paper.</p>
    <?php endif; ?>

    <article class="or-sheet" id="officialReceiptSheet">
        <header class="or-head">
            <div>
                <img class="or-template" src="<?= e($logo) ?>" alt="Republic / College Seal">
            </div>
            <div class="or-head-center">
                <h1 class="or-template">Invoice</h1>
                <p class="or-template">Republic of the Philippines</p>
                <p class="or-template"><strong>J.H. Cerilles State College</strong></p>
                <p class="or-template or-office">Office of the Treasurer</p>
                <p class="or-campus-line">
                    <span class="or-template">Campus: </span><span class="or-campus-value or-data"><?= e($receipt['campus'] ?? '') ?></span>
                </p>
            </div>
            <div>
                <img class="or-template" src="<?= e($logo) ?>" alt="J.H. Cerilles State College">
            </div>
        </header>

        <div class="or-meta-row">
            <div class="or-template">Accountable Form No. 51<br>(Revised June 2008)</div>
            <div class="or-serial">
                <span class="or-original or-template">ORIGINAL</span>
                <span class="or-number or-data">№ <?= e($receipt['or_number'] ?? '—') ?></span>
            </div>
        </div>

        <div class="or-fields">
            <div class="or-field">
                <strong class="or-template">DATE:</strong>
                <div class="or-line or-data"><?= e($receipt['date'] ?? '') ?></div>
            </div>
            <div class="or-field agency-fund">
                <strong class="or-template">AGENCY:</strong>
                <div class="or-line or-data"><?= e($receipt['agency'] ?? '') ?></div>
                <strong class="or-template">FUND:</strong>
                <div class="or-line or-data"><?= e($receipt['fund'] ?? '') ?></div>
            </div>
            <div class="or-field">
                <strong class="or-template">PAYOR:</strong>
                <div class="or-line or-data"><?= e($receipt['payor'] ?? '') ?></div>
            </div>
        </div>

        <div class="or-table-wrap">
            <table class="or-table">
                <thead>
                    <tr>
                        <th class="col-nature or-template">NATURE OF COLLECTION</th>
                        <th class="col-acct or-template">ACCT. CODE</th>
                        <th class="col-amount or-template">AMOUNT</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 0; $i < $rowCount; $i++):
                        $line = $lines[$i] ?? null;
                    ?>
                        <tr>
                            <td class="col-nature or-data"><?= $line ? e($line['nature']) : '&nbsp;' ?></td>
                            <td class="col-acct or-data"><?= $line && ($line['acct_code'] ?? '') !== '' ? e($line['acct_code']) : '&nbsp;' ?></td>
                            <td class="col-amount or-data"><?= $line ? e(number_format((float) $line['amount'], 2)) : '&nbsp;' ?></td>
                        </tr>
                    <?php endfor; ?>
                    <tr class="or-total-row">
                        <td class="col-nature or-template" colspan="2">TOTAL</td>
                        <td class="col-amount or-data"><span class="or-template">₱ </span><?= e(number_format((float) ($receipt['total'] ?? 0), 2)) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="or-words">
            <label class="or-template">Total Amount in Words</label>
            <div class="or-data"><?= e($receipt['amount_in_words'] ?? '') ?></div>
        </div>

        <div class="or-footer">
            <div class="or-pay-methods">
                <label>
                    <span class="or-pay-box or-data"><?= !empty($flags['cash']) ? '✓' : '' ?></span>
                    <span class="or-template"> Cash</span>
                </label>
                <label>
                    <span class="or-pay-box or-data"><?= !empty($flags['check']) ? '✓' : '' ?></span>
                    <span class="or-template"> Check</span>
                </label>
                <label>
                    <span class="or-pay-box or-data"><?= !empty($flags['money_order']) ? '✓' : '' ?></span>
                    <span class="or-template"> Money Order</span>
                </label>
            </div>
            <div>
                <table class="or-check-grid">
                    <thead>
                        <tr>
                            <th class="or-template">Drawee Bank</th>
                            <th class="or-template">Number</th>
                            <th class="or-template">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="or-sign">
                <p class="or-ack or-template">Received the amount stated above</p>
                <div class="or-sign-line">
                    <span class="or-data"><?= e($receipt['collecting_officer'] ?? '') ?></span><br>
                    <span class="or-template">Collecting Officer</span>
                </div>
            </div>
        </div>

        <p class="or-footnote or-template">Write the number and date of this receipt of check or money order received.</p>
    </article>

    <?php if ($autoPrint): ?>
    <script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 300);
    });
    </script>
    <?php endif; ?>
</body>
</html>
    <?php
}
