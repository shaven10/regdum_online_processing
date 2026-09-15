<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('admin');

$db = getDB();
$count = (int) $db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
$sortColumns = [
    'created_at' => ['type' => 'date', 'sql' => 'a.created_at', 'default_dir' => 'desc'],
    'user' => ['type' => 'string', 'sql' => 'u.last_name, u.first_name'],
    'action' => ['type' => 'string', 'sql' => 'a.action'],
    'entity_type' => ['type' => 'string', 'sql' => 'a.entity_type, a.entity_id'],
    'ip_address' => ['type' => 'string', 'sql' => 'a.ip_address'],
];
$sortState = resolveRecordsSort($sortColumns, 'created_at', 'desc');
$listFilters = recordsSortFilterParams($sortState);
$logsPaging = recordsListPaging($count, $listFilters, 'auditLogsFilterForm', 'log', 'logs');
$pag = $logsPaging['pag'];
$sortQuery = $listFilters;
if ($logsPaging['per_page'] !== ITEMS_PER_PAGE) {
    $sortQuery['per_page'] = $logsPaging['per_page'];
}

$logs = $db->query("SELECT a.*, u.first_name, u.last_name, u.email FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id ORDER BY " . recordsSqlOrderBy($sortState, 'a.created_at DESC') . " LIMIT {$logsPaging['limit']} OFFSET {$logsPaging['offset']}")->fetchAll();

$pageTitle = 'Audit Logs';
$activeNav = 'audit';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Audit Logs</h2></div>
    <div class="card-body">
        <form method="GET" id="auditLogsFilterForm"><?= recordsSortFormFields($sortState) ?></form>
        <?= $logsPaging['meta_html'] ?>
        <table class="data-table">
            <thead><tr>
                <?= renderRecordsSortHeader('Date', 'created_at', $sortState, $sortQuery) ?>
                <?= renderRecordsSortHeader('User', 'user', $sortState, $sortQuery) ?>
                <?= renderRecordsSortHeader('Action', 'action', $sortState, $sortQuery) ?>
                <?= renderRecordsSortHeader('Entity', 'entity_type', $sortState, $sortQuery) ?>
                <?= renderRecordsSortHeader('IP Address', 'ip_address', $sortState, $sortQuery) ?>
            </tr></thead>
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
        <?= $logsPaging['html'] ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
