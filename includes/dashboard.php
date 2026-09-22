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
        'active' => " AND status NOT IN ('completed','rejected','cancelled')",
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

function clearanceDashboardStats(int $departmentId, ?int $programId = null): array {
    $db = getDB();
    $baseFrom = "FROM request_clearances rc
        JOIN requests r ON rc.request_id = r.id
        JOIN users u ON r.user_id = u.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        WHERE rc.department_id = ?
          AND r.status NOT IN ('completed','rejected')";
    $params = [$departmentId];
    if ($programId !== null) {
        $baseFrom .= ' AND sp.course_id = ?';
        $params[] = $programId;
    }

    $pending = $db->prepare("SELECT COUNT(*) $baseFrom AND rc.status = 'pending'");
    $pending->execute($params);
    $onHold = $db->prepare("SELECT COUNT(*) $baseFrom AND rc.status = 'on_hold'");
    $onHold->execute($params);
    $clearedToday = $db->prepare("SELECT COUNT(*) $baseFrom AND rc.status = 'cleared' AND DATE(rc.cleared_at) = CURDATE()");
    $clearedToday->execute($params);

    return [
        'pending' => (int) $pending->fetchColumn(),
        'on_hold' => (int) $onHold->fetchColumn(),
        'cleared_today' => (int) $clearedToday->fetchColumn(),
    ];
}

/**
 * Live request volume for dashboards, using Philippine app-calendar dates.
 *
 * @return array<string,int|float>
 */
function getRegistrarRequestVolumeStats(): array {
    $db = getDB();
    $today = appDayBounds();
    $todayStart = appStartOfDay();
    $weekStart = $todayStart->modify('-6 days')->format('Y-m-d H:i:s');
    $monthStart = $todayStart->modify('first day of this month');
    $monthEnd = $monthStart->modify('first day of next month')->format('Y-m-d H:i:s');
    $yearStart = appStartOfDay($todayStart->format('Y') . '-01-01');
    $yearEnd = $yearStart->modify('+1 year')->format('Y-m-d H:i:s');

    $countCreated = $db->prepare('SELECT COUNT(*) FROM requests WHERE created_at >= ? AND created_at < ?');
    $countCreated->execute([$today['start'], $today['end']]);
    $todayCount = (int) $countCreated->fetchColumn();
    $countCreated->execute([$weekStart, $today['end']]);
    $weekCount = (int) $countCreated->fetchColumn();
    $countCreated->execute([$monthStart->format('Y-m-d H:i:s'), $monthEnd]);
    $monthCount = (int) $countCreated->fetchColumn();
    $countCreated->execute([$yearStart->format('Y-m-d H:i:s'), $yearEnd]);
    $yearCount = (int) $countCreated->fetchColumn();

    $completedToday = $db->prepare("SELECT COUNT(*) FROM requests
        WHERE status = 'completed' AND completed_at >= ? AND completed_at < ?");
    $completedToday->execute([$today['start'], $today['end']]);

    return [
        'today' => $todayCount,
        'week' => $weekCount,
        'month' => $monthCount,
        'year' => $yearCount,
        'active' => (int) $db->query("SELECT COUNT(*) FROM requests WHERE status NOT IN ('completed','rejected')")->fetchColumn(),
        'completed' => (int) $db->query("SELECT COUNT(*) FROM requests WHERE status = 'completed'")->fetchColumn(),
        'completed_today' => (int) $completedToday->fetchColumn(),
        'rejected' => (int) $db->query("SELECT COUNT(*) FROM requests WHERE status = 'rejected'")->fetchColumn(),
        'avg_processing_days' => (float) $db->query("SELECT COALESCE(AVG(DATEDIFF(completed_at, created_at)), 0)
            FROM requests WHERE completed_at IS NOT NULL AND status = 'completed'")->fetchColumn(),
    ];
}

/**
 * Registrar dashboard analytics: document request frequency and volume metrics.
 *
 * @return array{
 *   volume:array<string,int|float>,
 *   channels:array{online:int,onsite:int,total:int},
 *   document_frequency:list<array<string,mixed>>,
 *   document_frequency_total:int,
 *   monthly:list<array{month:int,label:string,count:int,completed:int}>,
 *   processing_by_document:list<array<string,mixed>>,
 *   top_purposes:list<array<string,mixed>>,
 *   processed_by_user:list<array<string,mixed>>
 * }
 */
function getRegistrarDashboardAnalytics(): array {
    require_once __DIR__ . '/request-items.php';
    require_once __DIR__ . '/onsite-request.php';
    ensureRequestItemsSchema();
    ensureOnsiteRequestSchema();

    $db = getDB();
    $months = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];

    $volume = getRegistrarRequestVolumeStats();

    $online = (int) $db->query("SELECT COUNT(*) FROM requests
        WHERE COALESCE(request_channel, 'online') <> 'onsite'")->fetchColumn();
    $onsite = (int) $db->query("SELECT COUNT(*) FROM requests WHERE request_channel = 'onsite'")->fetchColumn();
    $channels = [
        'online' => $online,
        'onsite' => $onsite,
        'total' => $online + $onsite,
    ];

    $itemCount = (int) $db->query('SELECT COUNT(*) FROM request_items')->fetchColumn();
    if ($itemCount > 0) {
        $documentFrequency = $db->query("SELECT dt.id, dt.name, dt.code,
                COUNT(ri.id) AS request_count,
                COALESCE(SUM(ri.copies), 0) AS copies_total,
                SUM(CASE WHEN r.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS last_30_days,
                SUM(CASE WHEN MONTH(r.created_at) = MONTH(CURDATE())
                          AND YEAR(r.created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS this_month,
                SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) AS completed_count
            FROM request_items ri
            JOIN document_types dt ON dt.id = ri.document_type_id
            JOIN requests r ON r.id = ri.request_id
            WHERE r.status <> 'rejected'
            GROUP BY dt.id, dt.name, dt.code
            ORDER BY request_count DESC, dt.name ASC")->fetchAll() ?: [];
    } else {
        $documentFrequency = $db->query("SELECT dt.id, dt.name, dt.code,
                COUNT(r.id) AS request_count,
                COALESCE(SUM(r.copies), 0) AS copies_total,
                SUM(CASE WHEN r.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS last_30_days,
                SUM(CASE WHEN MONTH(r.created_at) = MONTH(CURDATE())
                          AND YEAR(r.created_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS this_month,
                SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) AS completed_count
            FROM document_types dt
            LEFT JOIN requests r ON r.document_type_id = dt.id AND r.status <> 'rejected'
            GROUP BY dt.id, dt.name, dt.code
            HAVING request_count > 0
            ORDER BY request_count DESC, dt.name ASC")->fetchAll() ?: [];
    }

    $documentFrequencyTotal = 0;
    foreach ($documentFrequency as &$docRow) {
        $docRow['request_count'] = (int) ($docRow['request_count'] ?? 0);
        $docRow['copies_total'] = (int) ($docRow['copies_total'] ?? 0);
        $docRow['last_30_days'] = (int) ($docRow['last_30_days'] ?? 0);
        $docRow['this_month'] = (int) ($docRow['this_month'] ?? 0);
        $docRow['completed_count'] = (int) ($docRow['completed_count'] ?? 0);
        $documentFrequencyTotal += $docRow['request_count'];
    }
    unset($docRow);

    $monthlyRows = $db->query('SELECT MONTH(created_at) AS month,
            COUNT(*) AS count,
            SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS completed
        FROM requests
        WHERE YEAR(created_at) = YEAR(CURDATE())
        GROUP BY MONTH(created_at)
        ORDER BY month')->fetchAll() ?: [];
    $monthlyMap = [];
    foreach ($monthlyRows as $row) {
        $monthlyMap[(int) $row['month']] = [
            'month' => (int) $row['month'],
            'label' => $months[(int) $row['month']] ?? (string) $row['month'],
            'count' => (int) $row['count'],
            'completed' => (int) $row['completed'],
        ];
    }
    $monthly = [];
    for ($m = 1; $m <= 12; $m++) {
        $monthly[] = $monthlyMap[$m] ?? [
            'month' => $m,
            'label' => $months[$m],
            'count' => 0,
            'completed' => 0,
        ];
    }

    $processingByDocument = $db->query("SELECT dt.name,
            COUNT(r.id) AS completed_count,
            ROUND(AVG(DATEDIFF(r.completed_at, r.created_at)), 1) AS avg_days
        FROM requests r
        JOIN document_types dt ON dt.id = r.document_type_id
        WHERE r.completed_at IS NOT NULL AND r.status = 'completed'
        GROUP BY dt.id, dt.name
        HAVING completed_count > 0
        ORDER BY avg_days DESC, completed_count DESC
        LIMIT 8")->fetchAll() ?: [];

    $topPurposes = $db->query("SELECT
            CASE
                WHEN purpose = 'other' AND purpose_other IS NOT NULL AND purpose_other <> '' THEN purpose_other
                WHEN purpose IS NULL OR purpose = '' THEN 'Unspecified'
                ELSE REPLACE(purpose, '_', ' ')
            END AS purpose_label,
            COUNT(*) AS request_count
        FROM requests
        WHERE status <> 'rejected'
        GROUP BY purpose_label
        ORDER BY request_count DESC
        LIMIT 8")->fetchAll() ?: [];

    $processedByUser = $db->query("SELECT u.id, u.first_name, u.last_name, u.email, rl.name AS role_name,
            COUNT(ri.id) AS assigned_total,
            SUM(CASE WHEN ri.item_status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN ri.item_status = 'completed'
                      AND ri.completed_at IS NOT NULL
                      AND DATE(ri.completed_at) = CURDATE() THEN 1 ELSE 0 END) AS completed_today,
            SUM(CASE WHEN ri.item_status = 'completed'
                      AND ri.completed_at IS NOT NULL
                      AND MONTH(ri.completed_at) = MONTH(CURDATE())
                      AND YEAR(ri.completed_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS completed_month,
            SUM(CASE WHEN ri.item_status IN ('processing', 'ready_for_pickup') THEN 1 ELSE 0 END) AS in_progress,
            SUM(CASE WHEN ri.item_status = 'pending_assignment' THEN 1 ELSE 0 END) AS pending_assignment,
            COALESCE(SUM(CASE WHEN ri.item_status = 'completed' THEN ri.copies ELSE 0 END), 0) AS copies_completed
        FROM request_items ri
        JOIN users u ON u.id = ri.assigned_to
        JOIN roles rl ON rl.id = u.role_id
        WHERE ri.assigned_to IS NOT NULL
        GROUP BY u.id, u.first_name, u.last_name, u.email, rl.name
        ORDER BY completed_count DESC, in_progress DESC, u.last_name ASC, u.first_name ASC")->fetchAll() ?: [];

    foreach ($processedByUser as &$userRow) {
        $userRow['assigned_total'] = (int) ($userRow['assigned_total'] ?? 0);
        $userRow['completed_count'] = (int) ($userRow['completed_count'] ?? 0);
        $userRow['completed_today'] = (int) ($userRow['completed_today'] ?? 0);
        $userRow['completed_month'] = (int) ($userRow['completed_month'] ?? 0);
        $userRow['in_progress'] = (int) ($userRow['in_progress'] ?? 0);
        $userRow['pending_assignment'] = (int) ($userRow['pending_assignment'] ?? 0);
        $userRow['copies_completed'] = (int) ($userRow['copies_completed'] ?? 0);
    }
    unset($userRow);

    return [
        'volume' => $volume,
        'channels' => $channels,
        'document_frequency' => $documentFrequency,
        'document_frequency_total' => $documentFrequencyTotal,
        'monthly' => $monthly,
        'processing_by_document' => $processingByDocument,
        'top_purposes' => $topPurposes,
        'processed_by_user' => $processedByUser,
    ];
}
