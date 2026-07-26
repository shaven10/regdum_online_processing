<?php
/**
 * Run cashier migration for existing installations.
 * Open once: http://localhost/regdum_ol_docs_prcsng/migrate-cashier.php
 * Delete this file after running.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payments.php';

$messages = [];

try {
    $db = getDB();

    ensureCashierRole();
    $messages[] = ['success', 'Cashier role added or already exists.'];

    ensureDefaultCashierUser();
    $messages[] = ['success', 'Default cashier account ready.'];

    $role = $db->query("SELECT id, name, description FROM roles WHERE name = 'cashier'")->fetch();
    $cashier = $db->query("SELECT email, first_name, last_name FROM users WHERE email = 'cashier@regdum.edu.ph'")->fetch();

    if ($role) {
        $messages[] = ['info', 'Role ID: ' . $role['id'] . ' — ' . $role['description']];
    }
    if ($cashier) {
        $messages[] = ['info', 'Login: cashier@regdum.edu.ph / Cashier@123'];
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
    <title>Cashier Migration - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <h2>Cashier Migration</h2>
            <p>Add Cashier RBAC role and default account</p>
        </div>
        <?php foreach ($messages as [$type, $msg]): ?>
            <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
        <?php endforeach; ?>
        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary btn-block">Go to Login</a>
        <a href="<?= APP_URL ?>/cashier/dashboard.php" class="btn btn-outline btn-block" style="margin-top:.5rem">Cashier Dashboard</a>
    </div>
</div>
</body>
</html>
