<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = currentUser();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_read') {
        markAllNotificationsRead((int) $user['id']);
        setFlash('success', 'All notifications marked as read.');
    } elseif ($action === 'read_one') {
        markNotificationRead((int) $user['id'], (int) ($_POST['id'] ?? 0));
    }
    redirect(APP_URL . '/notifications.php');
}

// Open a linked notification: mark it read, then go to its target page.
$readId = (int) ($_GET['read'] ?? 0);
if ($readId > 0) {
    markNotificationRead((int) $user['id'], $readId);
    $goto = trim((string) ($_GET['goto'] ?? ''));
    if (isSafeAppRedirect($goto)) {
        redirect($goto);
    }
    redirect(APP_URL . '/notifications.php');
}

$countStmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
$countStmt->execute([$user['id']]);
$sortColumns = [
    'title' => ['type' => 'string', 'sql' => 'title'],
    'type' => ['type' => 'string', 'sql' => 'type'],
    'created_at' => ['type' => 'date', 'sql' => 'created_at', 'default_dir' => 'desc'],
    'is_read' => ['type' => 'number', 'sql' => 'is_read'],
];
$sortState = resolveRecordsSort($sortColumns, 'created_at', 'desc');
$listFilters = recordsSortFilterParams($sortState);
$notifPaging = recordsListPaging(
    (int) $countStmt->fetchColumn(),
    $listFilters,
    'notificationsFilterForm',
    'notification',
    'notifications'
);
$sortQuery = $listFilters;
if ($notifPaging['per_page'] !== ITEMS_PER_PAGE) {
    $sortQuery['per_page'] = $notifPaging['per_page'];
}

$stmt = $db->prepare(
    'SELECT * FROM notifications WHERE user_id = ? ORDER BY ' . recordsSqlOrderBy($sortState, 'created_at DESC') . '
     LIMIT ' . (int) $notifPaging['limit'] . ' OFFSET ' . (int) $notifPaging['offset']
);
$stmt->execute([$user['id']]);
$items = $stmt->fetchAll();

// Viewing the notifications page marks unread items as read.
$unreadOnView = array_values(array_filter($items, static fn(array $n): bool => empty($n['is_read'])));
$latestUnreadNotifications = array_slice($unreadOnView, 0, 5);
$unreadCountStmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$unreadCountStmt->execute([$user['id']]);
if ((int) $unreadCountStmt->fetchColumn() > 0) {
    markAllNotificationsRead((int) $user['id']);
}

$pageTitle = 'Notifications';
$activeNav = 'notifications';
require_once __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h2>Notifications</h2>
        <?php if ($notifCount > 0): ?>
            <form method="POST" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mark_read">
                <button type="submit" class="btn btn-outline btn-sm">Mark All Read</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="GET" id="notificationsFilterForm"><?= recordsSortFormFields($sortState) ?></form>
        <div class="records-sort-bar">
            <span class="records-sort-label">Sort by</span>
            <?= renderRecordsSortHeader('Date', 'created_at', $sortState, $sortQuery, 'span') ?>
            <?= renderRecordsSortHeader('Title', 'title', $sortState, $sortQuery, 'span') ?>
            <?= renderRecordsSortHeader('Type', 'type', $sortState, $sortQuery, 'span') ?>
            <?= renderRecordsSortHeader('Read', 'is_read', $sortState, $sortQuery, 'span') ?>
        </div>
        <?= $notifPaging['meta_html'] ?>
        <?php if (empty($items)): ?>
            <div class="empty-state"><i class="fas fa-bell-slash"></i><p>No notifications.</p></div>
        <?php else: ?>
            <div class="notification-list">
                <?php foreach ($items as $n): ?>
                    <?php
                    $wasUnread = empty($n['is_read']);
                    $icon = match ($n['type']) {
                        'success' => 'check',
                        'error'   => 'times',
                        'warning' => 'exclamation-triangle',
                        default   => 'info',
                    };
                    $viewHref = $n['link']
                        ? (APP_URL . '/notifications.php?read=' . (int) $n['id'] . '&goto=' . rawurlencode($n['link']))
                        : '';
                    ?>
                    <div class="notification-item <?= $wasUnread ? 'unread just-viewed' : '' ?>">
                        <div class="notif-icon notif-<?= e($n['type']) ?>">
                            <i class="fas fa-<?= $icon ?>"></i>
                        </div>
                        <div class="notif-content">
                            <strong><?= e($n['title']) ?></strong>
                            <p><?= e($n['message']) ?></p>
                            <small><?= formatDateTime($n['created_at']) ?></small>
                            <?php if ($viewHref): ?>
                                <a href="<?= e($viewHref) ?>" class="btn btn-sm btn-outline">View</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?= $notifPaging['html'] ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
