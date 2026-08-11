<?php
/**
 * Run accounting migration for existing installations.
 * Open once: http://localhost/regdum_online_processing/migrate-accounting.php
 * Delete this file after running.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/accounting.php';

$messages = [];

try {
    ensureAccountingModule();
    $messages[] = ['success', 'Accounting role added or already exists.'];
    $messages[] = ['success', 'Default accounting account ready.'];
    $messages[] = ['success', 'SOA document types now suggest Accounting as assignment office.'];

    $db = getDB();
    $role = $db->query("SELECT id, name, description FROM roles WHERE name = 'accounting'")->fetch();
    $user = $db->query("SELECT email, first_name, last_name FROM users WHERE email = 'accounting@regdum.edu.ph'")->fetch();

    if ($role) {
        $messages[] = ['info', 'Role ID: ' . $role['id'] . ' — ' . $role['description']];
    }
    if ($user) {
        $messages[] = ['info', 'Login: accounting@regdum.edu.ph / Accounting@123'];
    }
} catch (PDOException $e) {
    $messages[] = ['error', $e->getMessage()];
    $messages[] = ['warning', 'Ensure MySQL is running and the database is installed.'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounting Migration - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <h2>Accounting Migration</h2>
            <p>Add Accounting RBAC role for SOA document assignment</p>
        </div>
        <?php foreach ($messages as [$type, $msg]): ?>
            <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
        <?php endforeach; ?>
        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary btn-block">Go to Login</a>
        <a href="<?= APP_URL ?>/accounting/dashboard.php" class="btn btn-outline btn-block" style="margin-top:.5rem">Accounting Dashboard</a>
    </div>
</div>
</body>
</html>
