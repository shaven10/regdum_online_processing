<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/document-statistics-report.php';
requireRole('registrar');

$period = $_GET['period'] ?? 'monthly';
$date = trim($_GET['date'] ?? appToday());
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$channel = trim($_GET['channel'] ?? '');
$export = $_GET['export'] ?? '';

$filters = [
    'period' => $period,
    'date' => $date,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'channel' => $channel,
];

if ($export === 'csv') {
    exportDocumentStatisticsReportCsv(getDocumentStatisticsReport($filters));
}

$report = getDocumentStatisticsReport($filters);
$summary = $report['summary'];
$periodInfo = $report['period'];
$appliedChannel = $report['channel'];

$queryBase = array_filter([
    'period' => $periodInfo['period'],
    'date' => $periodInfo['date'],
    'date_from' => $periodInfo['from'],
    'date_to' => $periodInfo['to'],
    'channel' => $appliedChannel,
], static fn($value) => $value !== '' && $value !== null);

$isDefaultRange = $periodInfo['period'] === 'monthly'
    && $periodInfo['from'] === date('Y-m-01')
    && $periodInfo['to'] === date('Y-m-t')
    && $appliedChannel === '';

$pageTitle = 'Documents & Assignments';
$activeNav = 'document-statistics';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="payment-report-page document-statistics-page">
    <div class="card no-print">
        <div class="card-header">
            <div>
                <h2>Documents &amp; Assignments</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Statistics for documents requested in the selected period, and how those documents are assigned to personnel. Rejected requests are excluded.
                </p>
            </div>
            <div class="payment-report-actions">
                <a href="document-statistics-report-print.php?<?= e(http_build_query($queryBase + ['print' => '1'])) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="fas fa-print"></i> Print
                </a>
                <a href="document-statistics-report-print.php?<?= e(http_build_query($queryBase + ['print' => '1', 'pdf' => '1'])) ?>" target="_blank" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
                <a href="?<?= e(http_build_query($queryBase + ['export' => 'csv'])) ?>" class="btn btn-outline btn-sm">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar payment-report-filters" id="documentStatisticsFilterForm">
                <input type="hidden" name="period" id="reportPeriod" value="<?= e($periodInfo['period']) ?>">

                <div class="payment-report-period-tabs" role="group" aria-label="Quick date range">
                    <?php foreach (['daily' => 'Today', 'weekly' => 'This week', 'monthly' => 'This month'] as $key => $label): ?>
                        <label class="payment-report-period-option">
                            <input type="radio"
                                name="period_preset"
                                value="<?= e($key) ?>"
                                <?= $periodInfo['period'] === $key && $dateFrom === '' && $dateTo === '' ? 'checked' : '' ?>
                                data-report-period="<?= e($key) ?>">
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <label class="payment-report-filter-field">
                    <span>Date from</span>
                    <input type="date" name="date_from" id="reportDateFrom" value="<?= e($periodInfo['from']) ?>" max="<?= e(appToday()) ?>">
                </label>

                <label class="payment-report-filter-field">
                    <span>Date to</span>
                    <input type="date" name="date_to" id="reportDateTo" value="<?= e($periodInfo['to']) ?>" max="<?= e(appToday()) ?>">
                </label>

                <label class="payment-report-filter-field">
                    <span>Mode</span>
                    <select name="channel" aria-label="Mode of request">
                        <option value="">All Modes</option>
                        <option value="online" <?= $appliedChannel === 'online' ? 'selected' : '' ?>>Online</option>
                        <option value="onsite" <?= $appliedChannel === 'onsite' ? 'selected' : '' ?>>Onsite</option>
                    </select>
                </label>

                <div class="payment-report-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Apply</button>
                    <?php if (!$isDefaultRange): ?>
                        <a href="document-statistics-report.php" class="btn btn-outline btn-sm">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Summary — <?= e($periodInfo['label']) ?></h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    <?= e($periodInfo['from']) ?> to <?= e($periodInfo['to']) ?>
                    · <?= e(documentStatisticsChannelLabel($appliedChannel)) ?>
                </p>
            </div>
        </div>
        <div class="card-body">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-file-alt"></i></div>
                    <div class="stat-info"><h3><?= (int) $summary['requested_count'] ?></h3><p>Documents Requested</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon teal"><i class="fas fa-copy"></i></div>
                    <div class="stat-info"><h3><?= (int) $summary['copies_total'] ?></h3><p>Copies</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-user-clock"></i></div>
                    <div class="stat-info"><h3><?= (int) $summary['unassigned_count'] ?></h3><p>Unassigned</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="fas fa-user-check"></i></div>
                    <div class="stat-info"><h3><?= (int) $summary['assigned_count'] ?></h3><p>Assigned</p></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-info"><h3><?= (int) $summary['completed_count'] ?></h3><p>Completed</p></div>
                </div>
            </div>
            <div class="payment-report-summary-notes text-muted">
                <?= (int) $summary['document_types'] ?> document type<?= (int) $summary['document_types'] === 1 ? '' : 's' ?>
                · Online <?= (int) $summary['online_count'] ?>
                · Onsite <?= (int) $summary['onsite_count'] ?>
                · Awaiting assignment <?= (int) $summary['awaiting_count'] ?>
                · Processing <?= (int) $summary['processing_count'] ?>
                · Ready for pickup <?= (int) $summary['ready_count'] ?>
                · <?= (int) $summary['personnel_count'] ?> personnel with assignments
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2><i class="fas fa-file-alt"></i> Documents Requested</h2>
                <p class="text-muted" style="margin:.35rem 0 0">Each row is one document type. Share is that type’s portion of all documents requested in the period.</p>
            </div>
        </div>
        <div class="card-body">
            <?php renderDocumentStatisticsDocumentsTable($report['documents'], (int) $summary['requested_count']); ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2><i class="fas fa-user-check"></i> Personnel Assignment</h2>
                <p class="text-muted" style="margin:.35rem 0 0">Staff accounts assigned to documents requested in this period. Share is each person’s portion of all documents requested.</p>
            </div>
        </div>
        <div class="card-body">
            <?php renderDocumentStatisticsPersonnelTable($report['personnel']); ?>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('documentStatisticsFilterForm');
    const periodInput = document.getElementById('reportPeriod');
    const fromInput = document.getElementById('reportDateFrom');
    const toInput = document.getElementById('reportDateTo');
    if (!form || !fromInput || !toInput) {
        return;
    }

    function isoDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function applyPreset(period) {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        let from = new Date(today);
        let to = new Date(today);

        if (period === 'weekly') {
            const weekday = today.getDay() === 0 ? 6 : today.getDay() - 1;
            from.setDate(today.getDate() - weekday);
            to = new Date(from);
            to.setDate(from.getDate() + 6);
        } else if (period === 'monthly') {
            from = new Date(today.getFullYear(), today.getMonth(), 1);
            to = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        }

        fromInput.value = isoDate(from);
        toInput.value = isoDate(to);
        if (periodInput) {
            periodInput.value = period;
        }
        form.submit();
    }

    form.querySelectorAll('[data-report-period]').forEach(function (input) {
        input.addEventListener('change', function () {
            applyPreset(input.getAttribute('data-report-period') || 'monthly');
        });
    });

    [fromInput, toInput].forEach(function (input) {
        input.addEventListener('change', function () {
            if (periodInput) {
                periodInput.value = 'custom';
            }
            form.querySelectorAll('[data-report-period]').forEach(function (radio) {
                radio.checked = false;
            });
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
