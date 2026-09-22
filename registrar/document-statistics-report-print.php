<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/document-statistics-report.php';
require_once __DIR__ . '/../includes/ui.php';
requireRole('registrar');

$user = currentUser();
$period = $_GET['period'] ?? 'monthly';
$date = trim($_GET['date'] ?? appToday());
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$channel = trim($_GET['channel'] ?? '');
$autoPdf = !empty($_GET['pdf']);

$report = getDocumentStatisticsReport([
    'period' => $period,
    'date' => $date,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'channel' => $channel,
]);
$summary = $report['summary'];
$periodInfo = $report['period'];
$preparedName = trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
$generatedAt = date('M d, Y h:i A');

$backQuery = array_filter([
    'period' => $periodInfo['period'],
    'date' => $periodInfo['date'],
    'date_from' => $periodInfo['from'],
    'date_to' => $periodInfo['to'],
    'channel' => $report['channel'],
], static fn($value) => $value !== '' && $value !== null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents &amp; Assignments — <?= e($periodInfo['label']) ?></title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <style>
        body.payment-report-print-page {
            background: #eef1f5;
            margin: 0;
            padding: 1.25rem;
            font-family: "Plus Jakarta Sans", Arial, sans-serif;
            color: #111827;
        }
        .payment-report-print-toolbar,
        .payment-report-print-sheet {
            width: min(1100px, 100%);
            margin-left: auto;
            margin-right: auto;
        }
        .payment-report-print-toolbar {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: .75rem;
            margin-bottom: 1rem;
        }
        .payment-report-print-sheet {
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
            margin-bottom: 1.25rem;
        }
        .payment-report-print-brand h1 {
            margin: 0;
            font-size: 1.25rem;
        }
        .payment-report-print-brand p,
        .payment-report-print-meta {
            margin: .15rem 0 0;
            color: #64748b;
            font-size: .875rem;
        }
        .payment-report-print-meta { text-align: right; }
        .statistics-print-section { margin-top: 1.25rem; }
        .statistics-print-section h2 { margin: 0 0 .65rem; font-size: 1rem; }
        .payment-report-print-summary {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: .75rem;
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
            body.payment-report-print-page { background: #fff; padding: 0; }
            .no-print { display: none !important; }
            .payment-report-print-sheet { width: 100%; border: 0; box-shadow: none; padding: 0; }
        }
    </style>
</head>
<body class="payment-report-print-page">
    <div class="payment-report-print-toolbar no-print">
        <a href="document-statistics-report.php?<?= e(http_build_query($backQuery)) ?>" class="btn btn-outline btn-sm">Back to report</a>
        <button type="button" class="btn btn-primary btn-sm" id="statisticsReportPrintBtn"><i class="fas fa-print"></i> Print / Save PDF</button>
    </div>

    <div class="payment-report-print-sheet">
        <header class="payment-report-print-header">
            <div class="payment-report-print-brand">
                <h1>Documents &amp; Assignments</h1>
                <p><?= e(APP_NAME) ?> · <?= e(APP_TAGLINE) ?></p>
                <p><?= e($periodInfo['label']) ?> · <?= e(documentStatisticsChannelLabel((string) $report['channel'])) ?></p>
            </div>
            <div class="payment-report-print-meta">
                <div><?= e($periodInfo['from']) ?> to <?= e($periodInfo['to']) ?></div>
                <div>Prepared by <?= e($preparedName !== '' ? $preparedName : 'Registrar') ?></div>
                <div><?= e($generatedAt) ?></div>
            </div>
        </header>

        <div class="payment-report-print-summary">
            <div class="payment-report-print-summary-item"><span>Documents requested</span><strong><?= (int) $summary['requested_count'] ?></strong></div>
            <div class="payment-report-print-summary-item"><span>Copies</span><strong><?= (int) $summary['copies_total'] ?></strong></div>
            <div class="payment-report-print-summary-item"><span>Unassigned</span><strong><?= (int) $summary['unassigned_count'] ?></strong></div>
            <div class="payment-report-print-summary-item"><span>Assigned</span><strong><?= (int) $summary['assigned_count'] ?></strong></div>
            <div class="payment-report-print-summary-item"><span>Completed</span><strong><?= (int) $summary['completed_count'] ?></strong></div>
        </div>

        <section class="statistics-print-section">
            <h2>Documents Requested</h2>
            <?php renderDocumentStatisticsDocumentsTable($report['documents'], (int) $summary['requested_count'], false); ?>
        </section>

        <section class="statistics-print-section">
            <h2>Personnel Assignment</h2>
            <?php renderDocumentStatisticsPersonnelTable($report['personnel'], false); ?>
        </section>

        <div class="payment-report-print-footer">
            <div>Rejected requests are excluded. Share is each row’s portion of documents requested in this period.</div>
            <div><?= e(APP_NAME) ?> · Registrar Report</div>
        </div>
    </div>

    <script>
    (function () {
        var btn = document.getElementById('statisticsReportPrintBtn');
        if (btn) {
            btn.addEventListener('click', function () { window.print(); });
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
