<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments.php';
require_once __DIR__ . '/../includes/ui.php';
requireRole('cashier');

$user = currentUser();
$period = $_GET['period'] ?? 'daily';
$date = trim($_GET['date'] ?? date('Y-m-d'));
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$method = trim($_GET['method'] ?? '');
$autoPdf = !empty($_GET['pdf']);

$filters = [
    'period' => $period,
    'date' => $date,
    'status' => $status,
    'search' => $search,
    'method' => $method,
];

$report = getPaymentReportData($filters, null, null);
$summary = $report['summary'];
$periodInfo = $report['period'];
$payments = $report['rows'];
$generatedAt = date('M d, Y h:i A');

$backQuery = array_filter([
    'period' => $periodInfo['period'],
    'date' => $periodInfo['date'],
    'status' => $status,
    'search' => $search,
    'method' => $method,
], static fn($v) => $v !== '' && $v !== null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Report — <?= e($periodInfo['label']) ?></title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body.payment-report-print-page {
            background: #eef1f5;
            margin: 0;
            padding: 1.25rem;
            font-family: "Plus Jakarta Sans", Arial, sans-serif;
            color: #111827;
        }
        .payment-report-print-toolbar {
            width: min(1100px, 100%);
            margin: 0 auto 1rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: .75rem;
        }
        .payment-report-print-sheet {
            width: min(1100px, 100%);
            margin: 0 auto;
            background: #fff;
            border: 1px solid #cbd5e1;
            box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
            padding: 1.5rem;
            box-sizing: border-box;
        }
        .payment-report-print-header {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            border-bottom: 2px solid #111827;
            padding-bottom: .85rem;
            margin-bottom: 1rem;
        }
        .payment-report-print-brand {
            display: flex;
            gap: .75rem;
            align-items: center;
        }
        .payment-report-print-brand img {
            width: 56px;
            height: 56px;
            object-fit: contain;
        }
        .payment-report-print-brand h1 {
            margin: 0;
            font-size: 1.25rem;
        }
        .payment-report-print-brand p {
            margin: .15rem 0 0;
            color: #64748b;
            font-size: .875rem;
        }
        .payment-report-print-meta {
            text-align: right;
            font-size: .85rem;
            color: #475569;
        }
        .payment-report-print-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .75rem;
            margin-bottom: 1rem;
        }
        .payment-report-print-summary-item {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: .75rem;
            background: #f8fafc;
        }
        .payment-report-print-summary-item span {
            display: block;
            font-size: .75rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .payment-report-print-summary-item strong {
            display: block;
            margin-top: .25rem;
            font-size: 1.05rem;
        }
        .payment-report-print-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .8rem;
        }
        .payment-report-print-table th,
        .payment-report-print-table td {
            border: 1px solid #cbd5e1;
            padding: .4rem .45rem;
            text-align: left;
            vertical-align: top;
        }
        .payment-report-print-table th {
            background: #f1f5f9;
            font-weight: 700;
        }
        .payment-report-print-footer {
            margin-top: 1.25rem;
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            font-size: .8rem;
            color: #64748b;
            border-top: 1px solid #e2e8f0;
            padding-top: .75rem;
        }
        @media print {
            body.payment-report-print-page {
                background: #fff;
                padding: 0;
            }
            .no-print { display: none !important; }
            .payment-report-print-sheet {
                width: 100%;
                border: 0;
                box-shadow: none;
                padding: 0;
            }
            .payment-report-print-summary {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        @media (max-width: 800px) {
            .payment-report-print-summary { grid-template-columns: 1fr 1fr; }
            .payment-report-print-header { flex-direction: column; }
            .payment-report-print-meta { text-align: left; }
        }
    </style>
</head>
<body class="payment-report-print-page">
    <div class="payment-report-print-toolbar no-print">
        <a href="reports.php?<?= e(http_build_query($backQuery)) ?>" class="btn btn-outline btn-sm">
            <i class="fas fa-arrow-left"></i> Back to Reports
        </a>
        <div class="payment-report-actions">
            <button type="button" class="btn btn-outline btn-sm" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
            <button type="button" class="btn btn-primary btn-sm" id="paymentReportPdfBtn">
                <i class="fas fa-file-pdf"></i> Save as PDF
            </button>
        </div>
    </div>

    <div class="payment-report-print-sheet" id="paymentReportPrintSheet">
        <div class="payment-report-print-header">
            <div class="payment-report-print-brand">
                <?= renderAppLogo('default') ?>
                <div>
                    <h1><?= e(APP_NAME) ?></h1>
                    <p>Cashier Payment Report · <?= e(ucfirst($periodInfo['period'])) ?></p>
                </div>
            </div>
            <div class="payment-report-print-meta">
                <div><strong><?= e($periodInfo['label']) ?></strong></div>
                <div><?= e($periodInfo['from']) ?> to <?= e($periodInfo['to']) ?></div>
                <?php if ($status !== ''): ?><div>Status: <?= e(ucfirst($status)) ?></div><?php endif; ?>
                <?php if ($method !== ''): ?><div>Method: <?= e(paymentMethodLabel($method)) ?></div><?php endif; ?>
                <?php if ($search !== ''): ?><div>Search: <?= e($search) ?></div><?php endif; ?>
                <div>Generated: <?= e($generatedAt) ?></div>
                <div>Prepared by: <?= e(fullName($user)) ?></div>
            </div>
        </div>

        <div class="payment-report-print-summary">
            <div class="payment-report-print-summary-item">
                <span>Total Payments</span>
                <strong><?= (int) $summary['total_count'] ?></strong>
                <small><?= e(formatMoney((float) $summary['total_amount'])) ?></small>
            </div>
            <div class="payment-report-print-summary-item">
                <span>Verified</span>
                <strong><?= (int) $summary['verified_count'] ?></strong>
                <small><?= e(formatMoney((float) $summary['verified_amount'])) ?></small>
            </div>
            <div class="payment-report-print-summary-item">
                <span>Pending</span>
                <strong><?= (int) $summary['pending_count'] ?></strong>
                <small><?= e(formatMoney((float) $summary['pending_amount'])) ?></small>
            </div>
            <div class="payment-report-print-summary-item">
                <span>Rejected</span>
                <strong><?= (int) $summary['rejected_count'] ?></strong>
                <small><?= e(formatMoney((float) $summary['rejected_amount'])) ?></small>
            </div>
        </div>

        <?php if (!empty($summary['by_method'])): ?>
            <h3 style="margin:0 0 .5rem;font-size:1rem;">Verified by Payment Method</h3>
            <table class="payment-report-print-table" style="margin-bottom:1rem;max-width:420px">
                <thead>
                    <tr><th>Method</th><th>Count</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($summary['by_method'] as $m): ?>
                        <tr>
                            <td><?= e(paymentMethodLabel($m['payment_method'] ?? null)) ?></td>
                            <td><?= (int) ($m['count'] ?? 0) ?></td>
                            <td><?= e(formatMoney((float) ($m['total'] ?? 0))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3 style="margin:0 0 .5rem;font-size:1rem;">Payment Details (<?= count($payments) ?>)</h3>
        <table class="payment-report-print-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Request</th>
                    <th>Student</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Reference</th>
                    <th>OR #</th>
                    <th>Status</th>
                    <th>Payment Date</th>
                    <th>Verified By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="10">No payment records found for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($payments as $i => $p): ?>
                        <?php
                        $studentName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                        $verifier = trim(($p['verifier_first'] ?? '') . ' ' . ($p['verifier_last'] ?? ''));
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e($p['request_number']) ?></td>
                            <td><?= e($studentName) ?><br><small><?= e($p['student_id'] ?? '') ?></small></td>
                            <td><?= e(paymentMethodLabel($p['payment_method'] ?? null)) ?></td>
                            <td><?= e(formatMoney((float) $p['amount'])) ?></td>
                            <td><?= e($p['reference_number'] ?? '—') ?></td>
                            <td><?= e($p['or_number'] ?? '—') ?></td>
                            <td><?= e(ucfirst((string) $p['status'])) ?></td>
                            <td><?= !empty($p['payment_date']) ? e(formatDate($p['payment_date'])) : '—' ?></td>
                            <td><?= $verifier !== '' ? e($verifier) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="payment-report-print-footer">
            <div>Verified collections: <?= e(formatMoney((float) $summary['verified_amount'])) ?></div>
            <div><?= e(APP_SYSTEM_NAME) ?></div>
        </div>
    </div>

    <script>
    (function () {
        const btn = document.getElementById('paymentReportPdfBtn');
        const autoPdf = <?= $autoPdf ? 'true' : 'false' ?>;

        function triggerPdf() {
            window.print();
        }

        if (btn) {
            btn.addEventListener('click', triggerPdf);
        }

        if (autoPdf) {
            window.addEventListener('load', function () {
                setTimeout(triggerPdf, 250);
            });
        }
    })();
    </script>
</body>
</html>
