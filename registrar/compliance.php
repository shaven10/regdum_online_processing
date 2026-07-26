<?php

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../includes/compliance.php';

requireRole('registrar');



ensureComplianceSchema();

ensureRequestStatuses();



$filter = $_GET['filter'] ?? 'pending';

$search = trim($_GET['search'] ?? '');



$requests = getRequestsForCompliance($filter);



if ($search) {

    $requests = array_values(array_filter($requests, function ($r) use ($search) {

        $haystack = strtolower($r['request_number'] . ' ' . $r['first_name'] . ' ' . $r['last_name'] . ' ' . ($r['student_id'] ?? ''));

        return str_contains($haystack, strtolower($search));

    }));

}



$pageTitle = 'Request Review';

$activeNav = match ($filter) {

    'needs_revision' => 'revision',

    'verified', 'payment_ready', 'release_ready' => 'verified',

    'completed' => 'completed',

    default => 'compliance',

};

require_once __DIR__ . '/../includes/header.php';

?>



<div class="card">

    <div class="card-header"><h2>Document Request Workflow</h2></div>

    <div class="card-body">

        <form method="GET" class="filter-bar">

            <input type="text" name="search" placeholder="Search request #, student..." value="<?= e($search) ?>">

            <select name="filter">

                <option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>Step 2 — New requests</option>

                <option value="awaiting_student" <?= $filter === 'awaiting_student' ? 'selected' : '' ?>>Step 2 — Awaiting student</option>

                <option value="re_evaluation" <?= $filter === 're_evaluation' ? 'selected' : '' ?>>Step 3 — Re-evaluation</option>

                <option value="verified" <?= $filter === 'verified' ? 'selected' : '' ?>>Step 4 — Awaiting payment</option>

                <option value="payment_ready" <?= $filter === 'payment_ready' ? 'selected' : '' ?>>Step 5 — Staff assignment / processing</option>

                <option value="release_ready" <?= $filter === 'release_ready' ? 'selected' : '' ?>>Step 6 — Document release</option>

                <option value="needs_revision" <?= $filter === 'needs_revision' ? 'selected' : '' ?>>Needs revision</option>

                <option value="completed" <?= $filter === 'completed' ? 'selected' : '' ?>>Completed transactions</option>

                <option value="" <?= $filter === '' ? 'selected' : '' ?>>All active</option>

            </select>

            <button type="submit" class="btn btn-outline btn-sm">Filter</button>

        </form>



        <?php if (empty($requests)): ?>

            <div class="empty-state"><i class="fas fa-clipboard-list"></i><p>No requests found.</p></div>

        <?php else: ?>

            <table class="data-table">

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

                        <td><strong><?= e($req['request_number']) ?></strong></td>

                        <td><?= e($req['student_id']) ?></td>

                        <td><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>

                        <td><?= e($req['document_name']) ?></td>

                        <td><?= statusBadge($req['status']) ?><br><small class="text-muted"><?= e(workflowPhaseLabel($req['status'])) ?></small></td>

                        <td><?= (int)$req['requirement_count'] ?></td>

                        <td><?= $filter === 'completed'
                            ? formatDateTime($req['completed_at'] ?? $req['updated_at'])
                            : formatDate($req['created_at']) ?></td>

                        <td>
                            <?php if ($filter === 'payment_ready' || ($req['status'] ?? '') === 'payment_verified'): ?>
                                <a href="assignments.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">Assign Staff</a>
                            <?php else: ?>
                                <a href="verify-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">Open</a>
                            <?php endif; ?>
                            <a href="view-attachments.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-outline" title="View attachments"><i class="fas fa-paperclip"></i></a>
                        </td>

                    </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

    </div>

</div>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>

