<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$db = getDB();
$page = max(1, (int) ($_GET['page'] ?? 1));
$pag = paginate((int) $db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(), $page);

$logs = $db->query("SELECT a.*, u.first_name, u.last_name, u.email FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT {$pag['per_page']} OFFSET {$pag['offset']}")->fetchAll();

$pageTitle = 'Audit Logs';
$activeNav = 'audit';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Audit Logs</h2></div>
    <div class="card-body">
        <table class="data-table">
            <thead><tr><th>Date</th><th>User</th><th>Action</th><th>Entity</th><th>IP Address</th></tr></thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= formatDateTime($log['created_at']) ?></td>
                    <td><?= $log['first_name'] ? e($log['first_name'] . ' ' . $log['last_name']) : 'System' ?></td>
                    <td><code><?= e($log['action']) ?></code></td>
                    <td><?= e($log['entity_type'] ?? '') ?> #<?= $log['entity_id'] ?? '' ?></td>
                    <td><?= e($log['ip_address'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?= paginationLinks($pag, '?') ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
