<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/compliance.php';
require_once __DIR__ . '/../includes/dashboard.php';
require_once __DIR__ . '/../includes/request-items.php';
requireRole('registrar');

$user = currentUser();
ensureComplianceSchema();
ensureRequestStatuses();
ensureRequestItemsSchema();
require_once __DIR__ . '/../includes/onsite-request.php';
ensureOnsiteRequestSchema();

$stats = getComplianceStats();
$assignmentStats = staffDashboardStats((int) $user['id']);
$analytics = getRegistrarDashboardAnalytics();
$volume = $analytics['volume'];
$channels = $analytics['channels'];
$documentFrequency = $analytics['document_frequency'];
$documentFrequencyTotal = max(1, (int) $analytics['document_frequency_total']);
$monthlyTrend = $analytics['monthly'];
$monthlyMax = max(1, ...array_map(static fn(array $row): int => (int) $row['count'], $monthlyTrend));
$processingByDocument = $analytics['processing_by_document'];
$topPurposes = $analytics['top_purposes'];
$processedByUser = $analytics['processed_by_user'] ?? [];
$processedByUserMax = max(1, ...array_map(
    static fn(array $row): int => (int) ($row['completed_count'] ?? 0),
    $processedByUser !== [] ? $processedByUser : [['completed_count' => 1]]
));
$purposeMax = max(1, ...array_map(static fn(array $row): int => (int) ($row['request_count'] ?? 0), $topPurposes ?: [['request_count' => 1]]));
$channelTotal = max(1, (int) $channels['total']);

$myAssignments = getStaffAssignedItems((int) $user['id']);
$processingAssignments = array_values(array_filter(
    $myAssignments,
    static fn(array $item): bool => ($item['item_status'] ?? '') === 'processing'
));
$readyAssignments = array_values(array_filter(
    $myAssignments,
    static fn(array $item): bool => ($item['item_status'] ?? '') === 'ready_for_pickup'
));
$pendingRequests = getRequestsForCompliance('pending');
$awaitingStudent = getRequestsForCompliance('awaiting_student');
$reEvaluation = getRequestsForCompliance('re_evaluation');
$assignmentQueue = getRequestsAwaitingStaffAssignment();
$completedTransactions = getRequestsForCompliance('completed');

$pageTitle = 'Registrar Dashboard';
$activeNav = 'dashboard';
require_once __DIR__ . '/../includes/header.php';

renderDashboardWelcome($user, 'Review requests, verify requirements, assign documents to staff, and process documents assigned to you.');
renderDashboardActions([
    ['url' => 'new-onsite-request.php', 'label' => 'Onsite Request', 'icon' => 'fa-store', 'class' => 'btn-primary'],
    ['url' => APP_URL . '/queue/window.php', 'label' => 'Queue Window', 'icon' => 'fa-list-ol'],
    ['url' => 'students.php', 'label' => 'Student Records', 'icon' => 'fa-users'],
    ['url' => 'grades-evaluation.php', 'label' => 'Grades Evaluation', 'icon' => 'fa-clipboard-list'],
    ['url' => 'grade-entry.php', 'label' => 'Enter Grades', 'icon' => 'fa-paste'],
    ['url' => 'reports.php', 'label' => 'All Requests', 'icon' => 'fa-chart-bar'],
    ['url' => 'enrollment-report.php', 'label' => 'Enrollment by Course', 'icon' => 'fa-table'],
    ['url' => 'enrollment-list-report.php', 'label' => 'Enrollment List', 'icon' => 'fa-user-graduate'],
    ['url' => 'documents.php', 'label' => 'My Assignments', 'icon' => 'fa-tasks'],
    ['url' => 'compliance.php', 'label' => 'Request Review', 'icon' => 'fa-clipboard-check'],
    ['url' => 'assignments.php', 'label' => 'Staff Assignment', 'icon' => 'fa-user-tag'],
    ['url' => 'compliance.php?filter=awaiting_student', 'label' => 'Awaiting Student', 'icon' => 'fa-list-check'],
    ['url' => 'compliance.php?filter=re_evaluation', 'label' => 'Re-evaluation', 'icon' => 'fa-search'],
    ['url' => 'attachments.php', 'label' => 'Attachments', 'icon' => 'fa-paperclip'],
    ['url' => 'compliance.php?filter=completed', 'label' => 'Completed', 'icon' => 'fa-check-circle'],
]);
?>

<div class="stats-grid">
    <?= statCardLink('reports.php?period=daily&date=' . urlencode(date('Y-m-d')), 'blue', 'fa-calendar-day', (string) $volume['today'], 'Requests Today') ?>
    <?= statCardLink('reports.php?period=weekly&date=' . urlencode(date('Y-m-d')), 'teal', 'fa-calendar-week', (string) $volume['week'], 'Last 7 Days') ?>
    <?= statCardLink('reports.php?period=monthly&date=' . urlencode(date('Y-m-d')), 'purple', 'fa-calendar', (string) $volume['month'], 'This Month') ?>
    <?= statCardLink('compliance.php?filter=', 'orange', 'fa-spinner', (string) $volume['active'], 'Active Requests') ?>
    <?= statCardLink('reports.php?channel=online', 'blue', 'fa-globe', (string) $channels['online'], 'Online Requests') ?>
    <?= statCardLink('reports.php?channel=onsite', 'purple', 'fa-store', (string) $channels['onsite'], 'Onsite Requests') ?>
    <?= statCardLink('compliance.php?filter=completed', 'green', 'fa-check-circle', (string) $volume['completed'], 'Completed') ?>
    <?= statCardLink('compliance.php?filter=completed', 'gold', 'fa-hourglass-half', number_format((float) $volume['avg_processing_days'], 1) . 'd', 'Avg. Processing') ?>
</div>

<div class="stats-grid">
    <?= statCardLink('compliance.php', 'orange', 'fa-clipboard-check', (string)($stats['review'] ?? 0), 'Review Queue') ?>
    <?= statCardLink('compliance.php?filter=pending', 'blue', 'fa-inbox', (string)$stats['pending'], 'New Requests') ?>
    <?= statCardLink('compliance.php?filter=needs_revision', 'gold', 'fa-exclamation-triangle', (string)$stats['needs_revision'], 'Needs Revision') ?>
    <?= statCardLink('compliance.php?filter=awaiting_student', 'purple', 'fa-list-check', (string)$stats['awaiting_student'], 'Awaiting Student') ?>
    <?= statCardLink('compliance.php?filter=re_evaluation', 'teal', 'fa-search', (string)$stats['re_evaluation'], 'Re-evaluation') ?>
    <?= statCardLink('compliance.php?filter=verified', 'green', 'fa-credit-card', (string)$stats['compliant'], 'Approved for Payment') ?>
    <?= statCardLink('assignments.php', 'purple', 'fa-user-tag', (string)($stats['assignment_pending'] ?? 0), 'Staff Assignment') ?>
    <?= statCardLink('documents.php', 'orange', 'fa-tasks', (string) ($assignmentStats['assigned'] ?? 0), 'My Assignments') ?>
    <?= statCardLink('documents.php?status=processing', 'blue', 'fa-cog', (string) ($assignmentStats['processing'] ?? 0), 'My Processing') ?>
    <?= statCardLink('documents.php?status=ready_for_pickup', 'green', 'fa-box-open', (string) ($assignmentStats['ready'] ?? 0), 'Ready for Pickup') ?>
    <?= statCardLink('compliance.php?filter=release_ready', 'blue', 'fa-file-export', (string)$stats['release_ready'], 'Document Release') ?>
    <?= statCardLink('compliance.php?filter=completed', 'teal', 'fa-check-circle', (string)$stats['completed'], 'Completed Transactions') ?>
</div>

<div class="grid-2 registrar-analytics-grid">
    <div class="card">
        <div class="card-header">
            <div>
                <h2><i class="fas fa-chart-bar"></i> Documents Requested Frequency</h2>
                <p class="text-muted" style="margin:.35rem 0 0">Most requested documents across online and onsite requests.</p>
            </div>
            <a href="reports.php" class="btn btn-outline btn-sm">Open Reports</a>
        </div>
        <div class="card-body">
            <?php if ($documentFrequency === []): ?>
                <div class="empty-state"><i class="fas fa-file-alt"></i><p>No document request data yet.</p></div>
            <?php else: ?>
                <div class="registrar-doc-freq-list">
                    <?php foreach (array_slice($documentFrequency, 0, 10) as $doc): ?>
                        <?php
                        $count = (int) $doc['request_count'];
                        $pct = $documentFrequencyTotal > 0 ? round(($count / $documentFrequencyTotal) * 100) : 0;
                        ?>
                        <div class="status-bar-item registrar-doc-freq-item">
                            <span title="<?= e($doc['name']) ?>"><?= e($doc['name']) ?></span>
                            <div class="status-bar" aria-hidden="true">
                                <div class="status-bar-fill" style="width:<?= $pct ?>%"></div>
                            </div>
                            <strong><?= $count ?></strong>
                        </div>
                        <div class="registrar-doc-freq-meta text-muted">
                            <?= (int) $doc['this_month'] ?> this month
                            · <?= (int) $doc['last_30_days'] ?> in last 30 days
                            · <?= (int) $doc['copies_total'] ?> copies
                            · <?= $pct ?>% of requests
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($documentFrequency) > 10): ?>
                    <p class="text-muted" style="margin-top:.75rem">Showing top 10 of <?= count($documentFrequency) ?> document types.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2><i class="fas fa-chart-pie"></i> Request Insights</h2>
                <p class="text-muted" style="margin:.35rem 0 0">Channel mix, purpose trends, and processing pace.</p>
            </div>
        </div>
        <div class="card-body">
            <div class="detail-grid registrar-insight-grid">
                <div class="detail-item">
                    <label>Online share</label>
                    <span><?= round(($channels['online'] / $channelTotal) * 100) ?>% (<?= (int) $channels['online'] ?>)</span>
                </div>
                <div class="detail-item">
                    <label>Onsite share</label>
                    <span><?= round(($channels['onsite'] / $channelTotal) * 100) ?>% (<?= (int) $channels['onsite'] ?>)</span>
                </div>
                <div class="detail-item">
                    <label>Completed today</label>
                    <span><?= (int) $volume['completed_today'] ?></span>
                </div>
                <div class="detail-item">
                    <label>Rejected</label>
                    <span><?= (int) $volume['rejected'] ?></span>
                </div>
                <div class="detail-item">
                    <label>Year to date</label>
                    <span><?= (int) $volume['year'] ?> requests</span>
                </div>
                <div class="detail-item">
                    <label>Avg. days to complete</label>
                    <span><?= number_format((float) $volume['avg_processing_days'], 1) ?> days</span>
                </div>
            </div>

            <h3 class="registrar-insight-subtitle">Top request purposes</h3>
            <?php if ($topPurposes === []): ?>
                <p class="text-muted">No purpose data yet.</p>
            <?php else: ?>
                <?php foreach ($topPurposes as $purpose): ?>
                    <?php
                    $pCount = (int) ($purpose['request_count'] ?? 0);
                    $pPct = round(($pCount / $purposeMax) * 100);
                    ?>
                    <div class="status-bar-item">
                        <span><?= e(ucwords((string) ($purpose['purpose_label'] ?? 'Unspecified'))) ?></span>
                        <div class="status-bar"><div class="status-bar-fill" style="width:<?= $pPct ?>%"></div></div>
                        <strong><?= $pCount ?></strong>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($processingByDocument !== []): ?>
                <h3 class="registrar-insight-subtitle">Avg. processing days by document</h3>
                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <th>Document</th>
                                <th>Completed</th>
                                <th>Avg. Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($processingByDocument as $row): ?>
                                <tr>
                                    <td data-label="Document"><?= e($row['name']) ?></td>
                                    <td data-label="Completed"><?= (int) $row['completed_count'] ?></td>
                                    <td data-label="Avg. Days"><?= number_format((float) $row['avg_days'], 1) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2><i class="fas fa-user-check"></i> Documents Processed per User Account</h2>
            <p class="text-muted" style="margin:.35rem 0 0">Completed and in-progress document items by assigned staff account.</p>
        </div>
        <a href="assignments.php" class="btn btn-outline btn-sm">Staff Assignment</a>
    </div>
    <div class="card-body">
        <?php if ($processedByUser === []): ?>
            <div class="empty-state"><i class="fas fa-user-slash"></i><p>No assigned document processing activity yet.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table data-table-responsive registrar-processed-users-table">
                    <thead>
                        <tr>
                            <th>Staff Account</th>
                            <th>Role</th>
                            <th>Completed</th>
                            <th>This Month</th>
                            <th>Today</th>
                            <th>In Progress</th>
                            <th>Copies Done</th>
                            <th>Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($processedByUser as $staffRow): ?>
                            <?php
                            $completedCount = (int) $staffRow['completed_count'];
                            $sharePct = $processedByUserMax > 0 ? round(($completedCount / $processedByUserMax) * 100) : 0;
                            $staffName = trim(($staffRow['first_name'] ?? '') . ' ' . ($staffRow['last_name'] ?? ''));
                            $roleLabel = ucwords(str_replace('_', ' ', (string) ($staffRow['role_name'] ?? 'staff')));
                            ?>
                            <tr>
                                <td data-label="Staff Account">
                                    <strong><?= e($staffName !== '' ? $staffName : 'Unknown') ?></strong>
                                    <br><small class="text-muted"><?= e($staffRow['email'] ?? '') ?></small>
                                </td>
                                <td data-label="Role"><?= e($roleLabel) ?></td>
                                <td data-label="Completed"><strong><?= $completedCount ?></strong></td>
                                <td data-label="This Month"><?= (int) $staffRow['completed_month'] ?></td>
                                <td data-label="Today"><?= (int) $staffRow['completed_today'] ?></td>
                                <td data-label="In Progress">
                                    <?php if ((int) $staffRow['in_progress'] > 0): ?>
                                        <span class="badge badge-processing"><?= (int) $staffRow['in_progress'] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Copies Done"><?= (int) $staffRow['copies_completed'] ?></td>
                                <td data-label="Share" class="registrar-processed-share">
                                    <div class="status-bar" aria-hidden="true">
                                        <div class="status-bar-fill" style="width:<?= $sharePct ?>%"></div>
                                    </div>
                                    <small class="text-muted"><?= $sharePct ?>%</small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2><i class="fas fa-chart-line"></i> Monthly Request Volume — <?= e(date('Y')) ?></h2>
            <p class="text-muted" style="margin:.35rem 0 0">Submitted requests and completions by month.</p>
        </div>
        <a href="reports.php?period=monthly&date=<?= e(date('Y-m-d')) ?>" class="btn btn-outline btn-sm">Monthly Report</a>
    </div>
    <div class="card-body">
        <div class="registrar-monthly-bars">
            <?php foreach ($monthlyTrend as $monthRow): ?>
                <?php
                $mCount = (int) $monthRow['count'];
                $mHeight = $monthlyMax > 0 ? max($mCount > 0 ? 8 : 0, (int) round(($mCount / $monthlyMax) * 100)) : 0;
                $isCurrent = (int) $monthRow['month'] === (int) date('n');
                ?>
                <div class="registrar-monthly-bar<?= $isCurrent ? ' is-current' : '' ?>" title="<?= e($monthRow['label']) ?>: <?= $mCount ?> requests, <?= (int) $monthRow['completed'] ?> completed">
                    <div class="registrar-monthly-bar-track">
                        <div class="registrar-monthly-bar-fill" style="height:<?= $mHeight ?>%"></div>
                    </div>
                    <strong><?= $mCount ?></strong>
                    <span><?= e($monthRow['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-tasks"></i> My Assignments</h2>
            <a href="documents.php" class="btn btn-primary btn-sm">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($myAssignments)): ?>
                <div class="empty-state"><i class="fas fa-inbox"></i><p>No documents assigned to you yet.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Document</th>
                                <th>Student</th>
                                <th>Release Date</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($myAssignments, 0, 8) as $item): ?>
                            <tr>
                                <td data-label="Request #"><strong><?= e($item['request_number']) ?></strong></td>
                                <td data-label="Document"><?= e($item['document_name']) ?></td>
                                <td data-label="Student">
                                    <?= e($item['first_name'] . ' ' . $item['last_name']) ?>
                                    <br><small class="text-muted"><?= e($item['student_id'] ?? '') ?></small>
                                </td>
                                <td data-label="Release Date">
                                    <?php if (!empty($item['release_date'])): ?>
                                        <?= formatDate($item['release_date']) ?>
                                        <?php if (!empty($item['release_time'])): ?>
                                            <br><small class="text-muted"><?= date('g:i A', strtotime((string) $item['release_time'])) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Status"><?= requestItemStatusBadge($item['item_status']) ?></td>
                                <td data-label="Action">
                                    <a href="process-document.php?item_id=<?= (int) $item['id'] ?>" class="btn btn-sm btn-primary">Process</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2>New Requests — Set Requirements</h2>
            <a href="compliance.php?filter=pending" class="btn btn-primary btn-sm">View All</a>
        </div>
        <div class="card-body">
            <?php if (empty($pendingRequests)): ?>
                <div class="empty-state"><i class="fas fa-check"></i><p>No new requests awaiting review.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table data-table-responsive">
                        <thead><tr><th>Request #</th><th>Student</th><th>Document</th><th>Submitted</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($pendingRequests, 0, 8) as $req): ?>
                            <tr>
                                <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                                <td data-label="Student"><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                                <td data-label="Document"><?= e($req['document_name']) ?></td>
                                <td data-label="Submitted"><?= formatDate($req['created_at']) ?></td>
                                <td data-label="Action"><a href="verify-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">Review</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Staff Assignment Queue</h2>
        <a href="assignments.php" class="btn btn-primary btn-sm">View All</a>
    </div>
    <div class="card-body">
        <?php if (empty($assignmentQueue)): ?>
            <div class="empty-state"><i class="fas fa-user-check"></i><p>No documents waiting for staff assignment.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table data-table-responsive">
                    <thead><tr><th>Request #</th><th>Student</th><th>Documents</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($assignmentQueue, 0, 8) as $req): ?>
                        <tr>
                            <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                            <td data-label="Student"><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                            <td data-label="Documents">
                                <?= e($req['document_name'] ?? '—') ?>
                                <?php if ((int) ($req['pending_assignment_count'] ?? 0) > 0): ?>
                                    <br><small class="text-muted"><?= (int) $req['pending_assignment_count'] ?> pending</small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Action">
                                <a href="assignments.php?id=<?= (int) $req['id'] ?>" class="btn btn-sm btn-primary">Assign Staff</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($reEvaluation)): ?>
<div class="card">
    <div class="card-header">
        <h2>Re-evaluation Queue</h2>
        <a href="compliance.php?filter=re_evaluation" class="btn btn-outline btn-sm">View All</a>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="data-table data-table-responsive">
                <thead><tr><th>Request #</th><th>Student</th><th>Document</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($reEvaluation, 0, 6) as $req): ?>
                    <tr>
                        <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                        <td data-label="Student"><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                        <td data-label="Document"><?= e($req['document_name']) ?></td>
                        <td data-label="Action"><a href="verify-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">Review</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($awaitingStudent)): ?>
<div class="card">
    <div class="card-header">
        <h2>Awaiting Student Requirements</h2>
        <a href="compliance.php?filter=awaiting_student" class="btn btn-outline btn-sm">View All</a>
    </div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="data-table data-table-responsive">
                <thead><tr><th>Request #</th><th>Student</th><th>Document</th><th>Requirements</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($awaitingStudent, 0, 6) as $req): ?>
                    <tr>
                        <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                        <td data-label="Student"><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                        <td data-label="Document"><?= e($req['document_name']) ?></td>
                        <td data-label="Requirements"><?= (int) ($req['requirement_count'] ?? 0) ?> assigned</td>
                        <td data-label="Action"><a href="verify-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-check-circle"></i> Completed Transactions</h2>
        <div class="card-header-actions">
            <?php if ((int) ($stats['completed_today'] ?? 0) > 0): ?>
                <span class="badge badge-completed"><?= (int) $stats['completed_today'] ?> today</span>
            <?php endif; ?>
            <a href="compliance.php?filter=completed" class="btn btn-outline btn-sm">View All</a>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($completedTransactions)): ?>
            <div class="empty-state"><i class="fas fa-clipboard-check"></i><p>No completed transactions yet.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table data-table-responsive">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Student</th>
                            <th>Document</th>
                            <th>Amount</th>
                            <th>Completed</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($completedTransactions, 0, 10) as $req): ?>
                        <tr>
                            <td data-label="Request #"><strong><?= e($req['request_number']) ?></strong></td>
                            <td data-label="Student">
                                <?= e($req['first_name'] . ' ' . $req['last_name']) ?>
                                <br><small class="text-muted"><?= e($req['student_id'] ?? '') ?></small>
                            </td>
                            <td data-label="Document"><?= e($req['document_name']) ?></td>
                            <td data-label="Amount"><?= formatMoney((float) ($req['total_amount'] ?? 0)) ?></td>
                            <td data-label="Completed"><?= formatDateTime($req['completed_at'] ?? $req['updated_at']) ?></td>
                            <td data-label="Action">
                                <a href="verify-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
