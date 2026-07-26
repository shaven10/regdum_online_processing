<?php
/**
 * Run registrar compliance migration for existing installations.
 * http://localhost/regdum_ol_docs_prcsng/migrate-registrar.php
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/compliance.php';

$messages = [];

try {
    ensureRequestStatuses();
    ensureComplianceSchema();
    ensureDefaultRegistrarUser();

    $messages[] = ['success', 'Registrar compliance tables and statuses are ready.'];
    $messages[] = ['info', 'Login: registrar@regdum.edu.ph / Registrar@123'];
    $messages[] = ['warning', 'Delete migrate-registrar.php after running.'];
} catch (PDOException $e) {
    $messages[] = ['error', $e->getMessage()];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Migration - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body class="auth-page">
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <h2>Registrar Compliance Migration</h2>
            <p>Requirement verification RBAC setup</p>
        </div>
        <?php foreach ($messages as [$type, $msg]): ?>
            <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
        <?php endforeach; ?>
        <a href="<?= APP_URL ?>/auth/login.php" class="btn btn-primary btn-block">Go to Login</a>
        <a href="<?= APP_URL ?>/registrar/dashboard.php" class="btn btn-outline btn-block" style="margin-top:.5rem">Registrar Dashboard</a>
    </div>
</div>
</body>
</html>
