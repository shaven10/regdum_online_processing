<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/registrar-reports.php';
require_once __DIR__ . '/../includes/ui.php';
requireRole('registrar');

$user = currentUser();
$period = $_GET['period'] ?? 'daily';
$date = trim($_GET['date'] ?? date('Y-m-d'));
$channel = trim($_GET['channel'] ?? '');
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');
$autoPdf = !empty($_GET['pdf']);

$filters = [
    'period' => $period,
    'date' => $date,
    'channel' => $channel,
    'status' => $status,
    'search' => $search,
];

$report = getRegistrarRequestReportData($filters, null, null);
$summary = $report['summary'];
$periodInfo = $report['period'];
$requests = $report['rows'];
$applied = $report['filters'];
$generatedAt = date('M d, Y h:i A');

$backQuery = array_filter([
    'period' => $periodInfo['period'],
    'date' => $periodInfo['date'],
    'channel' => $applied['channel'],
    'status' => $applied['status'],
    'search' => $applied['search'],
], static fn($v) => $v !== '' && $v !== null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Requests Report — <?= e($periodInfo['label']) ?></title>
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
            font-size: .78rem;
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
        .request-report-doc-link {
            color: #1d4ed8;
            text-decoration: underline;
            word-break: break-all;
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
            <button type="button" class="btn btn-primary btn-sm" id="requestReportPdfBtn">
                <i class="fas fa-file-pdf"></i> Save as PDF
            </button>
        </div>
    </div>

    <div class="payment-report-print-sheet" id="requestReportPrintSheet">
        <div class="payment-report-print-header">
            <div class="payment-report-print-brand">
                <?= renderAppLogo('default') ?>
                <div>
                    <h1><?= e(APP_NAME) ?></h1>
                    <p>Registrar Requests Report · <?= e(ucfirst($periodInfo['period'])) ?></p>
                </div>
            </div>
            <div class="payment-report-print-meta">
                <div><strong><?= e($periodInfo['label']) ?></strong></div>
                <div><?= e($periodInfo['from']) ?> to <?= e($periodInfo['to']) ?></div>
                <?php if ($applied['channel'] !== ''): ?>
                    <div>Mode: <?= e(ucfirst($applied['channel'])) ?></div>
                <?php endif; ?>
                <?php if ($applied['status'] !== ''): ?>
                    <div>Status: <?= e(ucwords(str_replace('_', ' ', $applied['status']))) ?></div>
                <?php endif; ?>
                <?php if ($applied['search'] !== ''): ?>
                    <div>Search: <?= e($applied['search']) ?></div>
                <?php endif; ?>
                <div>Generated: <?= e($generatedAt) ?></div>
                <div>Prepared by: <?= e(fullName($user)) ?></div>
            </div>
        </div>

        <div class="payment-report-print-summary">
            <div class="payment-report-print-summary-item">
                <span>Total Requests</span>
                <strong><?= (int) ($summary['total_count'] ?? 0) ?></strong>
                <small><?= e(formatMoney((float) ($summary['total_amount'] ?? 0))) ?></small>
            </div>
            <div class="payment-report-print-summary-item">
                <span>Online</span>
                <strong><?= (int) ($summary['online_count'] ?? 0) ?></strong>
            </div>
            <div class="payment-report-print-summary-item">
                <span>Onsite</span>
                <strong><?= (int) ($summary['onsite_count'] ?? 0) ?></strong>
            </div>
            <div class="payment-report-print-summary-item">
                <span>Completed</span>
                <strong><?= (int) ($summary['completed_count'] ?? 0) ?></strong>
            </div>
        </div>

        <h3 style="margin:0 0 .5rem;font-size:1rem;">Request Details (<?= count($requests) ?>)</h3>
        <table class="payment-report-print-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Request</th>
                    <th>Mode</th>
                    <th>Requestor</th>
                    <th>Documents</th>
                    <th>Status</th>
                    <th>Amount</th>
                    <th>Created</th>
                    <th>Slip / Stub</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="9" style="text-align:center">No requests found for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($requests as $i => $req): ?>
                        <?php $requestor = trim(($req['first_name'] ?? '') . ' ' . ($req['last_name'] ?? '')); ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= e($req['request_number']) ?></td>
                            <td><?= e($req['channel_label']) ?></td>
                            <td>
                                <?= e($requestor) ?>
                                <br><small><?= e($req['student_id'] ?? '—') ?></small>
                            </td>
                            <td><?= e($req['document_summary'] ?? '—') ?></td>
                            <td><?= e(ucwords(str_replace('_', ' ', (string) ($req['status'] ?? '')))) ?></td>
                            <td><?= e(formatMoney((float) ($req['total_amount'] ?? 0))) ?></td>
                            <td><?= e(formatDateTime($req['created_at'] ?? null)) ?></td>
                            <td>
                                <?php if (!empty($req['document_link'])): ?>
                                    <a class="request-report-doc-link" href="<?= e($req['document_link']) ?>" target="_blank">
                                        <?= e($req['document_link_label']) ?>
                                    </a>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="payment-report-print-footer">
            <div>Onsite rows link to Onsite Request Slip. Online rows link to Claim Stub.</div>
            <div><?= e(APP_NAME) ?> · Registrar Report</div>
        </div>
    </div>

    <script>
    (function () {
        var btn = document.getElementById('requestReportPdfBtn');
        if (btn) {
            btn.addEventListener('click', function () {
                window.print();
            });
        }
        <?php if ($autoPdf): ?>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 350);
        });
        <?php endif; ?>
    })();
    </script>
</body>
</html>
