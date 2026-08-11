<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/request-items.php';
requireRole('registrar');

ensureComplianceSchema();
ensureRequestStatuses();
ensureRequestItemsSchema();

$allowedFilters = [
    'review',
    'pending',
    'needs_revision',
    'awaiting_student',
    're_evaluation',
    'verified',
    'payment_ready',
    'release_ready',
    'completed',
    '',
];

$filter = array_key_exists('filter', $_GET) ? (string) ($_GET['filter'] ?? '') : 'review';
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'review';
}

$search = trim($_GET['search'] ?? '');
$stats = getComplianceStats();
$requests = getRequestsForCompliance($filter);

if ($search !== '') {
    $requests = array_values(array_filter($requests, static function (array $r) use ($search): bool {
        $haystack = strtolower(
            ($r['request_number'] ?? '') . ' '
            . ($r['first_name'] ?? '') . ' '
            . ($r['last_name'] ?? '') . ' '
            . ($r['student_id'] ?? '') . ' '
            . ($r['document_name'] ?? '')
        );
        return str_contains($haystack, strtolower($search));
    }));
}

$stageCards = [
    [
        'key' => 'review',
        'label' => 'Review Queue',
        'hint' => 'New + Needs Revision',
        'count' => (int) ($stats['review'] ?? 0),
        'color' => 'orange',
        'icon' => 'fa-clipboard-check',
    ],
    [
        'key' => 'pending',
        'label' => 'New Requests',
        'hint' => 'Submitted / Under review',
        'count' => (int) ($stats['pending'] ?? 0),
        'color' => 'blue',
        'icon' => 'fa-inbox',
    ],
    [
        'key' => 'needs_revision',
        'label' => 'Needs Revision',
        'hint' => 'Corrections requested',
        'count' => (int) ($stats['needs_revision'] ?? 0),
        'color' => 'gold',
        'icon' => 'fa-exclamation-triangle',
    ],
    [
        'key' => 'awaiting_student',
        'label' => 'Awaiting Student',
        'hint' => 'Requirements pending',
        'count' => (int) ($stats['awaiting_student'] ?? 0),
        'color' => 'purple',
        'icon' => 'fa-list-check',
    ],
    [
        'key' => 're_evaluation',
        'label' => 'Re-evaluation',
        'hint' => 'Student resubmitted',
        'count' => (int) ($stats['re_evaluation'] ?? 0),
        'color' => 'teal',
        'icon' => 'fa-search',
    ],
    [
        'key' => 'verified',
        'label' => 'Awaiting Payment',
        'hint' => 'Approved requirements',
        'count' => (int) ($stats['compliant'] ?? 0),
        'color' => 'green',
        'icon' => 'fa-credit-card',
    ],
];

$filterLabels = [
    'review' => 'Review Queue (New + Needs Revision)',
    'pending' => 'New Requests',
    'needs_revision' => 'Needs Revision',
    'awaiting_student' => 'Awaiting Student',
    're_evaluation' => 'Re-evaluation',
    'verified' => 'Awaiting Payment',
    'payment_ready' => 'Staff Assignment / Processing',
    'release_ready' => 'Document Release',
    'completed' => 'Completed Transactions',
    '' => 'All Active Requests',
];

$pageTitle = 'Request Review';
$activeNav = 'compliance';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="payment-report-page">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Request Review</h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Combined compliance queue for new requests, pending review, and needs revision — plus the rest of the workflow.
                </p>
            </div>
        </div>
        <div class="card-body">
            <div class="stats-grid">
                <?php foreach ($stageCards as $card): ?>
                    <?php
                    $cardQuery = ['filter' => $card['key']];
                    if ($search !== '') {
                        $cardQuery['search'] = $search;
                    }
                    ?>
                    <?= statCardLink(
                        'compliance.php?' . http_build_query($cardQuery),
                        $card['color'],
                        $card['icon'],
                        (string) $card['count'],
                        $card['label'] . ($filter === $card['key'] ? ' · Active' : '')
                    ) ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2><?= e($filterLabels[$filter] ?? 'Requests') ?></h2>
                <p class="text-muted" style="margin:.35rem 0 0">
                    Showing <?= count($requests) ?> request<?= count($requests) === 1 ? '' : 's' ?>
                </p>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-bar">
                <input type="text" name="search" placeholder="Search request #, student, document..." value="<?= e($search) ?>">
                <select name="filter" aria-label="Review stage">
                    <option value="review" <?= $filter === 'review' ? 'selected' : '' ?>>Review Queue (New + Needs Revision)</option>
                    <option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>New Requests only</option>
                    <option value="needs_revision" <?= $filter === 'needs_revision' ? 'selected' : '' ?>>Needs Revision only</option>
                    <option value="awaiting_student" <?= $filter === 'awaiting_student' ? 'selected' : '' ?>>Awaiting Student</option>
                    <option value="re_evaluation" <?= $filter === 're_evaluation' ? 'selected' : '' ?>>Re-evaluation</option>
                    <option value="verified" <?= $filter === 'verified' ? 'selected' : '' ?>>Awaiting Payment</option>
                    <option value="payment_ready" <?= $filter === 'payment_ready' ? 'selected' : '' ?>>Staff Assignment / Processing</option>
                    <option value="release_ready" <?= $filter === 'release_ready' ? 'selected' : '' ?>>Document Release</option>
                    <option value="completed" <?= $filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="" <?= $filter === '' ? 'selected' : '' ?>>All Active</option>
                </select>
                <button type="submit" class="btn btn-outline btn-sm">Filter</button>
                <?php if ($search !== '' || $filter !== 'review'): ?>
                    <a href="compliance.php" class="btn btn-outline btn-sm">Reset</a>
                <?php endif; ?>
            </form>

            <?php if (empty($requests)): ?>
                <div class="empty-state"><i class="fas fa-clipboard-list"></i><p>No requests found for this stage.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Document</th>
                                <th>Workflow Stage</th>
                                <th>Requirements</th>
                                <th><?= $filter === 'completed' ? 'Completed' : 'Submitted' ?></th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $req): ?>
                                <tr>
                                    <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                                    <td data-label="Student ID"><?= e($req['student_id'] ?? '—') ?></td>
                                    <td data-label="Name"><?= e(trim(($req['first_name'] ?? '') . ' ' . ($req['last_name'] ?? ''))) ?></td>
                                    <td data-label="Document"><?= e($req['document_name'] ?? '—') ?></td>
                                    <td data-label="Workflow Stage">
                                        <?= statusBadge($req['status']) ?>
                                        <br><small class="text-muted"><?= e(workflowPhaseLabel($req['status'])) ?></small>
                                    </td>
                                    <td data-label="Requirements"><?= (int) ($req['requirement_count'] ?? 0) ?></td>
                                    <td data-label="<?= $filter === 'completed' ? 'Completed' : 'Submitted' ?>">
                                        <?= $filter === 'completed'
                                            ? e(formatDateTime($req['completed_at'] ?? $req['updated_at'] ?? null))
                                            : e(formatDate($req['created_at'] ?? null)) ?>
                                    </td>
                                    <td data-label="Action" class="payment-actions-cell">
                                        <?php if ($filter === 'payment_ready' || ($req['status'] ?? '') === 'payment_verified'): ?>
                                            <a href="assignments.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-primary">Assign Staff</a>
                                        <?php else: ?>
                                            <a href="verify-request.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-primary">Open</a>
                                        <?php endif; ?>
                                        <a href="view-attachments.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-outline" title="View attachments">
                                            <i class="fas fa-paperclip"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
