<?php

function renderDashboardWelcome(array $user, string $subtitle = ''): void {
    $hour = (int) date('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    ?>
    <div class="dashboard-welcome">
        <div>
            <p class="dashboard-greeting"><?= e($greeting) ?>, <?= e($user['first_name']) ?></p>
            <h1 class="dashboard-title"><?= e(ucfirst($user['role_name'])) ?> Dashboard</h1>
            <?php if ($subtitle): ?>
                <p class="dashboard-subtitle"><?= e($subtitle) ?></p>
            <?php endif; ?>
        </div>
        <div class="dashboard-role-badge">
            <i class="fas fa-id-badge"></i>
            <?= e(ucwords(str_replace('_', ' ', $user['role_name']))) ?>
        </div>
    </div>
    <?php
}

function renderDashboardActions(array $actions): void {
    if (empty($actions)) {
        return;
    }
    echo '<div class="dashboard-actions">';
    foreach ($actions as $action) {
        $class = $action['class'] ?? 'btn-outline';
        $icon = $action['icon'] ?? 'fa-arrow-right';
        echo '<a href="' . e($action['url']) . '" class="btn ' . e($class) . '">';
        echo '<i class="fas ' . e($icon) . '"></i> ' . e($action['label']);
        echo '</a>';
    }
    echo '</div>';
}

function studentDashboardStats(int $userId): array {
    $db = getDB();
    $base = 'SELECT COUNT(*) FROM requests WHERE user_id = ?';
    $stats = [];

    $queries = [
        'total' => '',
        'active' => " AND status NOT IN ('completed','rejected')",
        'completed' => " AND status = 'completed'",
        'needs_action' => " AND status IN ('awaiting_requirements','needs_revision','requirements_verified')",
        'in_review' => " AND status IN ('submitted','under_review','requirements_submitted')",
        'rejected' => " AND status = 'rejected'",
    ];

    foreach ($queries as $key => $extra) {
        $stmt = $db->prepare($base . $extra);
        $stmt->execute([$userId]);
        $stats[$key] = (int) $stmt->fetchColumn();
    }

    return $stats;
}

function staffDashboardStats(int $userId): array {
    require_once __DIR__ . '/request-items.php';
    ensureRequestItemsSchema();

    $db = getDB();
    $stats = [];

    $stmt = $db->prepare("SELECT COUNT(*) FROM request_items
        WHERE assigned_to = ? AND item_status IN ('processing', 'ready_for_pickup')");
    $stmt->execute([$userId]);
    $stats['assigned'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM request_items
        WHERE assigned_to = ? AND item_status = 'processing'");
    $stmt->execute([$userId]);
    $stats['processing'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM request_items
        WHERE assigned_to = ? AND item_status = 'ready_for_pickup'");
    $stmt->execute([$userId]);
    $stats['ready'] = (int) $stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM request_items
        WHERE assigned_to = ? AND item_status = 'completed'");
    $stmt->execute([$userId]);
    $stats['completed'] = (int) $stmt->fetchColumn();

    $dueSoon = $db->prepare("SELECT COUNT(*) FROM request_items
        WHERE assigned_to = ?
          AND item_status = 'processing'
          AND release_date IS NOT NULL
          AND release_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
    $dueSoon->execute([$userId]);
    $stats['due_soon'] = (int) $dueSoon->fetchColumn();

    return $stats;
}

function adminWorkflowStats(): array {
    $db = getDB();
    return [
        'awaiting_requirements' => (int) $db->query("SELECT COUNT(*) FROM requests WHERE status IN ('awaiting_requirements','needs_revision')")->fetchColumn(),
        're_evaluation' => (int) $db->query("SELECT COUNT(*) FROM requests WHERE status = 'requirements_submitted'")->fetchColumn(),
        'awaiting_payment' => (int) $db->query("SELECT COUNT(*) FROM requests WHERE status = 'requirements_verified'")->fetchColumn(),
        'processing' => (int) $db->query("SELECT COUNT(*) FROM requests WHERE status IN ('payment_verified','processing')")->fetchColumn(),
    ];
}

function clearanceDashboardStats(int $departmentId): array {
    $db = getDB();
    $pending = $db->prepare("SELECT COUNT(*) FROM request_clearances WHERE department_id = ? AND status = 'pending'");
    $pending->execute([$departmentId]);
    $onHold = $db->prepare("SELECT COUNT(*) FROM request_clearances WHERE department_id = ? AND status = 'on_hold'");
    $onHold->execute([$departmentId]);
    $clearedToday = $db->prepare("SELECT COUNT(*) FROM request_clearances WHERE department_id = ? AND status = 'cleared' AND DATE(cleared_at) = CURDATE()");
    $clearedToday->execute([$departmentId]);

    return [
        'pending' => (int) $pending->fetchColumn(),
        'on_hold' => (int) $onHold->fetchColumn(),
        'cleared_today' => (int) $clearedToday->fetchColumn(),
    ];
}
