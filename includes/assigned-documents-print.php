<?php

require_once __DIR__ . '/student.php';

$autoPdf = !empty($_GET['pdf']);
$statusFilterLabel = match ($status) {
    'processing'       => 'Processing',
    'ready_for_pickup' => 'Ready for Pickup',
    'completed'        => 'Completed',
    default            => 'Active Assignments',
};

$backQuery = array_filter([
    'status' => $status !== '' ? $status : null,
    'search' => $search !== '' ? $search : null,
], static fn($value) => $value !== null && $value !== '');

$preparedName = trim(fullName($user));
$roleLabel = ucwords(str_replace('_', ' ', (string) ($user['role_name'] ?? '')));
$printedAt = date('M d, Y h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($officeLabel ?? 'My Assignments') ?> — Print</title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body.assignments-print-page {
            background: #eef1f5;
            margin: 0;
            padding: 1rem;
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
            font-size: 10pt;
        }
        .assignments-print-toolbar {
            width: 8.5in;
            max-width: 100%;
            margin: 0 auto 1rem;
            display: flex;
            justify-content: space-between;
            gap: .75rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .assignments-print-toolbar-actions { display: flex; gap: .5rem; flex-wrap: wrap; }
        .assignments-print-sheet {
            width: 8.5in;
            min-height: 13in;
            max-width: 100%;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #cbd5e1;
            padding: .5in .45in .55in;
            box-sizing: border-box;
        }
        .enrollment-report-letterhead {
            position: relative;
            margin-bottom: .18in;
            text-align: center;
            min-height: .85in;
        }
        .enrollment-report-letterhead-brand {
            position: relative;
            display: block;
            min-height: .85in;
        }
        .enrollment-report-letterhead img {
            position: absolute;
            left: 1.5in;
            top: 50%;
            transform: translateY(-50%);
            width: .72in;
            height: .72in;
            object-fit: contain;
        }
        .enrollment-report-letterhead-text {
            width: 100%;
            padding: 0;
            box-sizing: border-box;
            text-align: center;
        }
        .enrollment-report-letterhead p {
            margin: 0;
            line-height: 1.2;
            text-align: center;
        }
        .enrollment-report-letterhead .republic { font-size: 10pt; }
        .enrollment-report-letterhead h1 {
            margin: .08rem 0 0;
            font-size: 13pt;
            letter-spacing: .01em;
            text-transform: uppercase;
            text-align: center;
            line-height: 1.15;
        }
        .enrollment-report-letterhead .former,
        .enrollment-report-letterhead .address { font-size: 10pt; }
        .enrollment-report-letterhead h2 {
            margin: .28rem 0 0;
            font-size: 11pt;
            text-transform: uppercase;
            text-align: center;
            line-height: 1.2;
            padding: 0 .1in;
        }
        .enrollment-report-letterhead .as-of {
            margin-top: .08rem;
            font-size: 10pt;
            text-align: center;
        }
        .assignments-print-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .12in .24in;
            margin: .16in 0 .2in;
            padding: .14in .18in;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            font-size: 9pt;
        }
        .assignments-print-info-item {
            display: flex;
            flex-direction: column;
            gap: .04in;
        }
        .assignments-print-info-item span {
            color: #475569;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: .03em;
        }
        .assignments-print-info-item strong {
            font-size: 9.5pt;
            font-weight: 700;
        }
        .assignments-print-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8pt;
            table-layout: fixed;
        }
        .assignments-print-table thead { display: table-header-group; }
        .assignments-print-table th,
        .assignments-print-table td {
            border: 1px solid #111;
            padding: .12rem .14rem;
            vertical-align: middle;
            word-wrap: break-word;
        }
        .assignments-print-table thead th {
            background: #f3f4f6;
            font-weight: 700;
            font-size: 7.5pt;
            text-align: center;
            line-height: 1.25;
        }
        .assignments-print-table tbody td { line-height: 1.3; }
        .assignments-print-table .col-no { width: 3.5%; text-align: center; }
        .assignments-print-table .col-request { width: 9%; text-align: center; font-weight: 700; }
        .assignments-print-table .col-document { width: 20.5%; text-align: left; }
        .assignments-print-table .col-student { width: 13%; text-align: left; }
        .assignments-print-table .col-course { width: 16%; text-align: left; }
        .assignments-print-table .assigned-student-id { display: block; font-size: 7pt; }
        .assignments-print-table .assigned-course-year-year { display: block; font-size: 7pt; }
        .assignments-print-table .col-or { width: 8%; text-align: center; }
        .assignments-print-table .col-release { width: 10%; text-align: center; }
        .assignments-print-table .col-status { width: 15%; text-align: left; }
        .assignments-print-table .assigned-status-row { display: block; }
        .assignments-print-table .assigned-status-label { font-weight: 700; }
        .assignments-print-table .assigned-status-detail { display: block; font-size: 7pt; }
        .assignments-print-table .assigned-document-item + .assigned-document-item { margin-top: .08rem; }
        .assignments-print-table .assigned-document-item { white-space: nowrap; }
        .assignments-print-table .assigned-document-name { font-weight: 700; }
        .assignments-print-table .assigned-document-term { font-size: 7.5pt; font-weight: 500; }
        .assignments-print-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: .16in;
            padding-top: .1in;
            border-top: 1px solid #cbd5e1;
            font-size: 9pt;
        }
        .assignments-print-summary strong { font-size: 10pt; }
        .assignments-print-empty {
            margin: .3in 0;
            text-align: center;
            font-size: 11pt;
            color: #475569;
        }
        .enrollment-report-signoff {
            display: flex;
            justify-content: space-between;
            gap: 1.5rem;
            margin-top: .4in;
            font-size: 10pt;
            page-break-inside: avoid;
        }
        .enrollment-report-signoff div { min-width: 2.6in; }
        .enrollment-report-signoff .caption { margin-bottom: .5in; }
        .enrollment-report-signoff .name {
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 1px solid #111;
            display: inline-block;
            min-width: 2.5in;
            padding-bottom: .12rem;
        }
        .enrollment-report-signoff .title { margin-top: .12rem; }
        @media print {
            @page { size: 8.5in 13in portrait; margin: .35in; }
            body.assignments-print-page { background: #fff; padding: 0; }
            .no-print { display: none !important; }
            .assignments-print-sheet {
                width: 8.5in;
                min-height: auto;
                border: 0;
                padding: 0;
            }
            .assignments-print-info { background: #fff; }
        }
    </style>
</head>
<body class="assignments-print-page">
    <div class="assignments-print-toolbar no-print">
        <a href="<?= e($listPageUrl . ($backQuery ? '?' . http_build_query($backQuery) : '')) ?>" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
        <div class="assignments-print-toolbar-actions">
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <a href="<?= e($listPageUrl . '?' . http_build_query($backQuery + ['print' => '1', 'pdf' => '1'])) ?>" class="btn btn-outline btn-sm"><i class="fas fa-file-pdf"></i> Export PDF</a>
        </div>
    </div>

    <div class="assignments-print-sheet">
        <header class="enrollment-report-letterhead">
            <div class="enrollment-report-letterhead-brand">
                <?= renderAppLogo('default') ?>
                <div class="enrollment-report-letterhead-text">
                    <p class="republic">Republic of the Philippines</p>
                    <h1>J.H. Cerilles State College</h1>
                    <p class="former">Dumingag Campus</p>
                    <p class="address">Dumingag, Zamboanga del Sur</p>
                </div>
            </div>
            <h2><?= e($officeLabel ?? 'My Assignments') ?></h2>
            <p class="as-of">As of <?= e($printedAt) ?></p>
        </header>

        <div class="assignments-print-info">
            <div class="assignments-print-info-item">
                <span>Prepared by</span>
                <strong><?= e($preparedName !== '' ? $preparedName : '—') ?></strong>
            </div>
            <div class="assignments-print-info-item">
                <span>Role</span>
                <strong><?= e($roleLabel) ?></strong>
            </div>
            <div class="assignments-print-info-item">
                <span>Status filter</span>
                <strong><?= e($statusFilterLabel) ?></strong>
            </div>
            <div class="assignments-print-info-item">
                <span>Search</span>
                <strong><?= e($search !== '' ? $search : 'All records') ?></strong>
            </div>
        </div>

        <?php if ($items === []): ?>
            <p class="assignments-print-empty">No document assignments found.</p>
        <?php else: ?>
            <table class="assignments-print-table">
                <thead>
                    <tr>
                        <th class="col-no">#</th>
                        <th class="col-request">Request #</th>
                        <th class="col-document">Document/s Requested</th>
                        <th class="col-student">Student</th>
                        <th class="col-course">Course / Enrollment</th>
                        <th class="col-or">OR #</th>
                        <th class="col-release">Date of Release</th>
                        <th class="col-status">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $index => $item): ?>
                        <tr>
                            <td class="col-no"><?= $index + 1 ?></td>
                            <td class="col-request"><?= e($item['request_number']) ?></td>
                            <td class="col-document"><?= renderAssignedDocumentLabelsHtml($item) ?></td>
                            <td class="col-student"><?= renderAssignedStudentNameIdHtml($item) ?></td>
                            <td class="col-course"><?= renderAssignedStudentCourseYearHtml($item) ?></td>
                            <td class="col-or"><?= e(assignedItemOrNumber($item)) ?></td>
                            <td class="col-release"><?= e(assignedItemReleaseLabel($item)) ?></td>
                            <td class="col-status">
                                <div class="assigned-status-row"><span class="assigned-status-label">Docs -</span> <?= e(requestItemStatusLabel((string) ($item['item_status'] ?? ''))) ?><?php if (($item['item_status'] ?? '') === 'mixed' && !empty($item['item_status_detail'])): ?> <span class="assigned-status-detail">(<?= e((string) $item['item_status_detail']) ?>)</span><?php endif; ?></div>
                                <div class="assigned-status-row"><span class="assigned-status-label">Batch -</span> <?= e(assignedItemBatchStatusLabel($item)) ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="assignments-print-summary">
                <span>Total requests</span>
                <strong><?= count($items) ?></strong>
            </div>
        <?php endif; ?>

        <div class="enrollment-report-signoff">
            <div>
                <p class="caption">Prepared by:</p>
                <p class="name"><?= e($preparedName) ?></p>
                <p class="title"><?= e($roleLabel) ?></p>
            </div>
            <div>
                <p class="caption">Noted by:</p>
                <p class="name">&nbsp;</p>
                <p class="title">Registrar</p>
            </div>
        </div>
    </div>

    <?php if ($autoPdf): ?>
    <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });</script>
    <?php endif; ?>
</body>
</html>
