<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/compliance.php';
require_once __DIR__ . '/includes/clearance.php';

$messages = [];

try {
    ensureClearanceSchema();
    seedClearanceRequirement();
    ensureDefaultClearanceUsers();

    $db = getDB();
    $deptCount = (int) $db->query('SELECT COUNT(*) FROM clearance_departments')->fetchColumn();

    $messages[] = ['success', 'Online clearance system is ready.'];
    $messages[] = ['info', $deptCount . ' clearance offices configured.'];
    $messages[] = ['info', 'Guidance: guidance@regdum.edu.ph / Clearance@123'];
    $messages[] = ['info', 'Library: library@regdum.edu.ph / Clearance@123'];
    $messages[] = ['info', 'Student Affairs: studentaffairs@regdum.edu.ph / Clearance@123'];
    $messages[] = ['info', 'Program Chair: programchair@regdum.edu.ph / Clearance@123'];
    $messages[] = ['info', 'Campus Director: campusdirector@regdum.edu.ph / Clearance@123'];
    $messages[] = ['info', 'Cashier & Registrar use existing accounts for their office clearance.'];
} catch (PDOException $e) {
    $messages[] = ['error', $e->getMessage()];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Clearance Migration - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-container">
    <div class="auth-card auth-card-wide">
        <div class="auth-logo"><h2>Online Clearance Migration</h2></div>
        <?php foreach ($messages as [$type, $msg]): ?>
            <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
        <?php endforeach; ?>
        <a href="<?= APP_URL ?>/clearance/dashboard.php" class="btn btn-primary btn-block">Clearance Dashboard</a>
    </div>
</div>
</body>
</html>
