<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ui.php';

$user = currentUser();
$pageTitle = $pageTitle ?? APP_NAME;
$activeNav = $activeNav ?? '';
$notifCount = $user ? getUnreadNotificationCount($user['id']) : 0;
if (!isset($latestUnreadNotifications) && $user) {
    $latestUnreadNotifications = getLatestUnreadNotifications((int) $user['id'], 5);
} elseif (!isset($latestUnreadNotifications)) {
    $latestUnreadNotifications = [];
}
$notificationToastPayload = array_map(static function (array $item): array {
    return [
        'id' => (int) ($item['id'] ?? 0),
        'title' => (string) ($item['title'] ?? ''),
        'message' => (string) ($item['message'] ?? ''),
        'type' => (string) ($item['type'] ?? 'info'),
        'link' => $item['link'] ?? null,
        'created_at' => (string) ($item['created_at'] ?? ''),
    ];
}, $latestUnreadNotifications);
$studentRegistrationCompletion = ($user && hasRole('student'))
    ? getStudentProfileCompletion($user['id'])
    : null;
$userInitials = strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? '', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <?php
    require_once __DIR__ . '/theme.php';
    renderThemeStyleTag();
    ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body<?= $user ? '' : ' class="auth-page"' ?>>
<?php if ($user): ?>
<div class="app-layout">
    <div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <?= renderAppLogo('sidebar') ?>
            <div class="sidebar-brand-text">
                <span class="sidebar-brand-title"><?= e(APP_NAME) ?></span>
                <span class="sidebar-brand-tagline"><?= e(APP_TAGLINE) ?></span>
                <span class="sidebar-brand-system"><?= e(APP_SYSTEM_NAME) ?></span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="sidebar-nav-section">
                <p class="sidebar-nav-section-title">Main Menu</p>
            <?php if (hasRole('student')): ?>
                <a href="<?= APP_URL ?>/student/dashboard.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>"><i class="fas fa-home"></i> Dashboard</a>
                <a href="<?= APP_URL ?>/student/new-request.php" class="<?= $activeNav === 'new-request' ? 'active' : '' ?>"><i class="fas fa-file-alt"></i> New Request</a>
                <a href="<?= APP_URL ?>/student/requests.php" class="<?= $activeNav === 'requests' ? 'active' : '' ?>"><i class="fas fa-list"></i> My Requests</a>
                <a href="<?= APP_URL ?>/student/profile.php" class="<?= $activeNav === 'profile' ? 'active' : '' ?>"><i class="fas fa-user"></i> Profile<?= $studentRegistrationCompletion ? renderStudentRegistrationStatus($studentRegistrationCompletion, 'mini') : '' ?></a>
            <?php elseif (hasRole('clearance_officer')): ?>
                <a href="<?= APP_URL ?>/clearance/dashboard.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>"><i class="fas fa-clipboard-list"></i> Clearance Dashboard</a>
                <a href="<?= APP_URL ?>/clearance/requests.php" class="<?= $activeNav === 'requests' ? 'active' : '' ?>"><i class="fas fa-tasks"></i> Sign Clearances</a>
                <a href="<?= APP_URL ?>/clearance/requests.php?status=pending" class="<?= $activeNav === 'pending' ? 'active' : '' ?>"><i class="fas fa-clock"></i> Pending</a>
                <a href="<?= APP_URL ?>/clearance/documents.php" class="<?= $activeNav === 'documents' ? 'active' : '' ?>"><i class="fas fa-file-signature"></i> Assigned Documents</a>
            <?php elseif (hasRole('registrar')): ?>
                <a href="<?= APP_URL ?>/registrar/dashboard.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="<?= APP_URL ?>/registrar/new-onsite-request.php" class="<?= $activeNav === 'onsite-request' ? 'active' : '' ?>"><i class="fas fa-store"></i> Onsite Request</a>
                <a href="<?= APP_URL ?>/registrar/compliance.php" class="<?= $activeNav === 'compliance' ? 'active' : '' ?>"><i class="fas fa-clipboard-check"></i> Compliance Review</a>
                <a href="<?= APP_URL ?>/registrar/compliance.php?filter=pending" class="<?= $activeNav === 'pending' ? 'active' : '' ?>"><i class="fas fa-clock"></i> Pending</a>
                <a href="<?= APP_URL ?>/registrar/compliance.php?filter=needs_revision" class="<?= $activeNav === 'revision' ? 'active' : '' ?>"><i class="fas fa-exclamation-triangle"></i> Needs Revision</a>
                <a href="<?= APP_URL ?>/registrar/assignments.php" class="<?= $activeNav === 'assignments' ? 'active' : '' ?>"><i class="fas fa-user-tag"></i> Staff Assignment</a>
                <a href="<?= APP_URL ?>/registrar/documents.php" class="<?= $activeNav === 'my-assignments' ? 'active' : '' ?>"><i class="fas fa-tasks"></i> My Assignments</a>
                <a href="<?= APP_URL ?>/registrar/attachments.php" class="<?= $activeNav === 'attachments' ? 'active' : '' ?>"><i class="fas fa-paperclip"></i> Attachments</a>
                <a href="<?= APP_URL ?>/registrar/students.php" class="<?= $activeNav === 'students' ? 'active' : '' ?>"><i class="fas fa-user-check"></i> Verify Students</a>
            <?php elseif (hasRole('cashier')): ?>
                <a href="<?= APP_URL ?>/cashier/dashboard.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="<?= APP_URL ?>/cashier/payments.php" class="<?= $activeNav === 'payments' ? 'active' : '' ?>"><i class="fas fa-credit-card"></i> Verify Payments</a>
                <a href="<?= APP_URL ?>/cashier/payments.php?status=pending" class="<?= $activeNav === 'pending' ? 'active' : '' ?>"><i class="fas fa-clock"></i> Pending</a>
                <a href="<?= APP_URL ?>/cashier/documents.php" class="<?= $activeNav === 'documents' ? 'active' : '' ?>"><i class="fas fa-file-invoice"></i> Assigned Documents</a>
                <a href="<?= APP_URL ?>/cashier/reports.php" class="<?= $activeNav === 'reports' ? 'active' : '' ?>"><i class="fas fa-receipt"></i> Payment Reports</a>
                <a href="<?= APP_URL ?>/cashier/bank-settings.php" class="<?= $activeNav === 'bank-settings' ? 'active' : '' ?>"><i class="fas fa-university"></i> Bank Settings</a>
            <?php elseif (hasRole('admin')): ?>
                <?php $adminSettingsNav = ['users', 'documents', 'release-rules', 'programs', 'campuses', 'requirement-types', 'requirements', 'purpose-suggestions', 'theme', 'audit']; ?>
                <?php $settingsMenuOpen = in_array($activeNav, $adminSettingsNav, true); ?>
                <a href="<?= APP_URL ?>/admin/dashboard.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="<?= APP_URL ?>/admin/requests.php" class="<?= $activeNav === 'requests' ? 'active' : '' ?>"><i class="fas fa-file-alt"></i> Requests</a>
                <a href="<?= APP_URL ?>/admin/students.php" class="<?= $activeNav === 'students' ? 'active' : '' ?>"><i class="fas fa-users"></i> Students</a>
                <a href="<?= APP_URL ?>/admin/payments.php" class="<?= $activeNav === 'payments' ? 'active' : '' ?>"><i class="fas fa-credit-card"></i> Payments</a>
                <a href="<?= APP_URL ?>/admin/reports.php" class="<?= $activeNav === 'reports' ? 'active' : '' ?>"><i class="fas fa-chart-bar"></i> Reports</a>
                <div class="sidebar-nav-group<?= $settingsMenuOpen ? ' open' : '' ?>">
                    <button type="button" class="sidebar-nav-group-toggle<?= $settingsMenuOpen ? ' active' : '' ?>" aria-expanded="<?= $settingsMenuOpen ? 'true' : 'false' ?>">
                        <span class="sidebar-nav-group-label"><i class="fas fa-cog"></i> Settings</span>
                        <i class="fas fa-chevron-down sidebar-nav-chevron"></i>
                    </button>
                    <div class="sidebar-nav-submenu">
                        <a href="<?= APP_URL ?>/admin/users.php" class="<?= $activeNav === 'users' ? 'active' : '' ?>"><i class="fas fa-user-cog"></i> User Management</a>
                        <a href="<?= APP_URL ?>/admin/document-types.php" class="<?= $activeNav === 'documents' ? 'active' : '' ?>"><i class="fas fa-file-alt"></i> Document Types</a>
                        <a href="<?= APP_URL ?>/admin/document-release-rules.php" class="<?= $activeNav === 'release-rules' ? 'active' : '' ?>"><i class="fas fa-user-check"></i> Release Rules</a>
                        <a href="<?= APP_URL ?>/admin/programs.php" class="<?= $activeNav === 'programs' ? 'active' : '' ?>"><i class="fas fa-graduation-cap"></i> Courses & Programs</a>
                        <a href="<?= APP_URL ?>/admin/campuses.php" class="<?= $activeNav === 'campuses' ? 'active' : '' ?>"><i class="fas fa-building"></i> Campuses</a>
                        <a href="<?= APP_URL ?>/admin/requirement-types.php" class="<?= $activeNav === 'requirement-types' ? 'active' : '' ?>"><i class="fas fa-list-check"></i> Requirement Types</a>
                        <a href="<?= APP_URL ?>/admin/requirement-settings.php" class="<?= $activeNav === 'requirements' ? 'active' : '' ?>"><i class="fas fa-sliders-h"></i> Requirement Settings</a>
                        <a href="<?= APP_URL ?>/admin/purpose-suggestions.php" class="<?= $activeNav === 'purpose-suggestions' ? 'active' : '' ?>"><i class="fas fa-bullseye"></i> Purpose & Suggestions</a>
                        <a href="<?= APP_URL ?>/admin/theme-settings.php" class="<?= $activeNav === 'theme' ? 'active' : '' ?>"><i class="fas fa-palette"></i> Theme Manager</a>
                        <a href="<?= APP_URL ?>/admin/audit-logs.php" class="<?= $activeNav === 'audit' ? 'active' : '' ?>"><i class="fas fa-shield-alt"></i> Audit Logs</a>
                    </div>
                </div>
            <?php elseif (hasRole('staff')): ?>
                <a href="<?= APP_URL ?>/staff/dashboard.php" class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="<?= APP_URL ?>/staff/requests.php" class="<?= $activeNav === 'requests' ? 'active' : '' ?>"><i class="fas fa-tasks"></i> My Assignments</a>
                <a href="<?= APP_URL ?>/staff/students.php" class="<?= $activeNav === 'students' ? 'active' : '' ?>"><i class="fas fa-search"></i> Student Records</a>
                <a href="<?= APP_URL ?>/staff/documents.php" class="<?= $activeNav === 'documents' ? 'active' : '' ?>"><i class="fas fa-print"></i> Documents</a>
            <?php endif; ?>
            </div>

            <div class="sidebar-nav-section">
                <p class="sidebar-nav-section-title">Support</p>
            <a href="<?= APP_URL ?>/faq.php" class="<?= $activeNav === 'faq' ? 'active' : '' ?>"><i class="fas fa-question-circle"></i> FAQ</a>
            <a href="<?= APP_URL ?>/help.php" class="<?= $activeNav === 'help' ? 'active' : '' ?>"><i class="fas fa-headset"></i> Help Desk</a>
            <a href="<?= APP_URL ?>/notifications.php" class="<?= $activeNav === 'notifications' ? 'active' : '' ?>">
                <i class="fas fa-bell"></i> Notifications
                <span class="notif-badge" data-notif-count <?= $notifCount > 0 ? '' : 'hidden' ?>><?= (int) $notifCount ?></span>
            </a>
            </div>
        </nav>
        <div class="sidebar-user-card">
            <div class="sidebar-user-avatar" aria-hidden="true"><?= e($userInitials) ?></div>
            <div class="sidebar-user-info">
                <strong><?= e(fullName($user)) ?></strong>
                <span><?= e(str_replace('_', ' ', $user['role_name'])) ?></span>
            </div>
        </div>
        <div class="sidebar-footer">
            <a href="<?= APP_URL ?>/auth/logout.php" class="sidebar-logout-btn"><i class="fas fa-sign-out-alt"></i> Sign Out</a>
        </div>
    </aside>
    <div class="main-content">
        <header class="topbar">
            <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            <h1 class="page-heading"><?= e($pageTitle) ?></h1>
            <div class="topbar-actions">
                <a href="<?= APP_URL ?>/notifications.php" class="topbar-notif-btn" id="topbarNotifBtn" title="Notifications" aria-label="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifCount > 0): ?>
                        <span class="notif-badge topbar-notif-badge" data-notif-count><?= $notifCount ?></span>
                    <?php else: ?>
                        <span class="notif-badge topbar-notif-badge" data-notif-count hidden>0</span>
                    <?php endif; ?>
                </a>
                <div class="topbar-user">
                    <span><?= e(fullName($user)) ?></span>
                    <span class="role-tag"><?= e(ucfirst($user['role_name'])) ?></span>
                </div>
            </div>
        </header>
        <main class="content-area">
        <div id="notificationToastHost"
            class="notification-toast-host"
            data-poll-url="<?= e(APP_URL) ?>/api/notifications.php"
            data-notifications-url="<?= e(APP_URL) ?>/notifications.php"
            data-mark-url="<?= e(APP_URL) ?>/api/notifications.php"
            data-csrf="<?= e(csrfToken()) ?>"
            data-suppress-initial="<?= ($activeNav ?? '') === 'notifications' ? '1' : '0' ?>"
            aria-live="polite"
            aria-relevant="additions"></div>
        <script type="application/json" id="notificationToastBootstrap"><?= json_encode(
            $notificationToastPayload,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
        ) ?></script>
<?php endif; ?>
