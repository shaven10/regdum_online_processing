<?php
/**
 * Application setup and database upgrade script.
 * Fresh install:  http://localhost/regdum_ol_docs_prcsng/install.php?step=run
 * Upgrade existing: http://localhost/regdum_ol_docs_prcsng/install.php?step=upgrade
 * Delete this file after setup in production.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';

$step = $_GET['step'] ?? 'check';
$messages = [];
$migrationLog = [];
$installed = false;
$needsInstall = false;

function runSqlFile(PDO $pdo, string $filepath): void {
    $sql = file_get_contents($filepath);
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function ensureUploadDirectories(): void {
    foreach (['documents', 'request_docs', 'receipts', 'student_ids'] as $dir) {
        $path = UPLOAD_PATH . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}

function runApplicationMigrations(): array {
    require_once __DIR__ . '/includes/payments.php';
    require_once __DIR__ . '/includes/compliance.php';
    require_once __DIR__ . '/includes/clearance.php';
    require_once __DIR__ . '/includes/auth.php';

    $log = [];

    ensureCashierRole();
    $log[] = 'Cashier role';

    require_once __DIR__ . '/includes/payments.php';
    ensurePaymentVerificationSchema();
    $log[] = 'Payment verification fields (OR number, payment date)';

    ensureDefaultCashierUser();
    $log[] = 'Default cashier account';

    ensureRequestStatuses();
    $log[] = 'Request workflow statuses';

    ensureComplianceSchema();
    $log[] = 'Compliance & requirement tables';

    ensureRequirementDefinitionsSchema();
    $log[] = 'Requirement type definitions and subcategories';

    ensureDefaultRegistrarUser();
    $log[] = 'Default registrar account';

    ensureClearanceSchema();
    $log[] = 'Online clearance schema';

    ensureDefaultClearanceUsers();
    $log[] = 'Clearance officer defaults';

    ensureDeliveryMethods();
    $log[] = 'On-site pickup delivery methods';

    ensureRequestCopyTypeSchema();
    $log[] = 'First request / second copy field';

    ensurePrivacyConsentSchema();
    $log[] = 'Data privacy consent timestamp on users';

    ensureStudentEmploymentFields();
    $log[] = 'Graduate employment profile fields';

    ensureStudentAcademicTermFields();
    $log[] = 'Current academic year and semester fields';

    ensureStudentValidIdField();
    $log[] = 'Student valid ID upload field';

    ensureAcademicProgramsSchema();
    $log[] = 'Academic courses and programs';

    ensureEnrollmentStatuses();
    $log[] = 'Enrollment status options';

    ensureCampusesSchema();
    $log[] = 'Campus locations';

    ensureDocumentEnrollmentRulesSchema();
    $log[] = 'Document release rules by enrollment status';

    ensureDocumentTypeFeeSchema();
    $log[] = 'Document fee settings (documentary stamp)';

    ensureRequestTermInfoSchema();
    $log[] = 'Request school year, semester, and Statement of Account fields';

    ensureRequestAuthenticationTypeSchema();
    $log[] = 'Authentication document type field';

    require_once __DIR__ . '/includes/purpose-suggestions.php';
    ensureRequestPurposesSchema();
    $log[] = 'Request purposes and suggested documents';

    require_once __DIR__ . '/includes/request-items.php';
    ensureRequestItemsSchema();
    $log[] = 'Batch request items (multi-document requests)';

    require_once __DIR__ . '/includes/assignment-offices.php';
    ensureDocumentAssignmentOfficeSchema();
    $log[] = 'Document assignment offices (Cashier, Guidance, Registrar)';

    ensureUploadDirectories();
    $log[] = 'Upload directories';

    return $log;
}

function seedDefaultUsers(PDO $db): bool {
    $existing = $db->query("SELECT COUNT(*) FROM users WHERE email IN ('admin@regdum.edu.ph','staff@regdum.edu.ph','cashier@regdum.edu.ph','registrar@regdum.edu.ph')")->fetchColumn();
    if ((int) $existing > 0) {
        return false;
    }

    $adminHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    $staffHash = password_hash('Staff@123', PASSWORD_BCRYPT);
    $cashierHash = password_hash('Cashier@123', PASSWORD_BCRYPT);
    $registrarHash = password_hash('Registrar@123', PASSWORD_BCRYPT);

    $cashierRoleId = $db->query("SELECT id FROM roles WHERE name = 'cashier'")->fetchColumn() ?: 5;
    $registrarRoleId = $db->query("SELECT id FROM roles WHERE name = 'registrar'")->fetchColumn() ?: 3;

    $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (4, ?, ?, ?, ?, 1, 1)')
       ->execute(['admin@regdum.edu.ph', $adminHash, 'System', 'Administrator']);

    $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (2, ?, ?, ?, ?, 1, 1)')
       ->execute(['staff@regdum.edu.ph', $staffHash, 'Registrar', 'Staff']);

    $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (?, ?, ?, ?, ?, 1, 1)')
       ->execute([$cashierRoleId, 'cashier@regdum.edu.ph', $cashierHash, 'Payment', 'Cashier']);

    $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (?, ?, ?, ?, ?, 1, 1)')
       ->execute([$registrarRoleId, 'registrar@regdum.edu.ph', $registrarHash, 'Records', 'Registrar']);

    return true;
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);

    if ($step === 'run') {
        runSqlFile($pdo, __DIR__ . '/database/schema.sql');

        $db = getDB();
        $seeded = seedDefaultUsers($db);
        $migrationLog = runApplicationMigrations();

        $messages[] = ['success', 'Installation complete!'];
        if ($seeded) {
            $messages[] = ['info', 'Default accounts created (see credentials below).'];
        } else {
            $messages[] = ['info', 'Default accounts already exist — skipped seeding.'];
        }
        $messages[] = ['warning', 'Delete install.php for security after setup.'];
        $installed = true;

    } elseif ($step === 'upgrade') {
        $db = getDB();
        $db->query('SELECT 1 FROM users LIMIT 1');
        $migrationLog = runApplicationMigrations();

        $messages[] = ['success', 'Database upgrade completed.'];
        $messages[] = ['info', 'All schema migrations and defaults are up to date.'];
        $installed = true;

    } else {
        $stmt = $pdo->query("SHOW DATABASES LIKE '" . DB_NAME . "'");
        if ($stmt->fetch()) {
            try {
                $db = getDB();
                $db->query('SELECT 1 FROM users LIMIT 1');
                $migrationLog = runApplicationMigrations();

                $messages[] = ['success', 'Database is installed and ready.'];
                $messages[] = ['info', 'Migrations verified on this visit. Use Upgrade if you deploy new changes.'];
                $installed = true;
            } catch (PDOException $e) {
                $messages[] = ['info', 'Database exists but tables may be missing. Click Install to set up.'];
                $needsInstall = true;
            }
        } else {
            $messages[] = ['info', 'Ready to install. Make sure MySQL is running in XAMPP, then click below.'];
            $needsInstall = true;
        }
    }
} catch (PDOException $e) {
    $messages[] = ['error', 'Database error: ' . $e->getMessage()];
    $messages[] = ['warning', 'Ensure MySQL is started in the XAMPP Control Panel.'];
    $needsInstall = ($step === 'check');
}

$defaultAccounts = [
    ['Admin', 'admin@regdum.edu.ph', 'Admin@123'],
    ['Staff', 'staff@regdum.edu.ph', 'Staff@123'],
    ['Cashier', 'cashier@regdum.edu.ph', 'Cashier@123'],
    ['Registrar', 'registrar@regdum.edu.ph', 'Registrar@123'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <style>
        .install-log { margin: 1rem 0; padding: 0; list-style: none; }
        .install-log li { padding: .35rem 0; font-size: .875rem; color: var(--gray-700, #374151); }
        .install-log li::before { content: "✓ "; color: #059669; font-weight: 700; }
        .install-accounts { width: 100%; border-collapse: collapse; font-size: .8125rem; margin: 1rem 0; }
        .install-accounts th, .install-accounts td { padding: .5rem .65rem; text-align: left; border-bottom: 1px solid var(--gray-200, #e5e7eb); }
        .install-actions { display: flex; flex-direction: column; gap: .5rem; margin-top: 1rem; }
    </style>
</head>
<body class="auth-page">
<div class="auth-container">
    <div class="auth-card" style="max-width: 520px;">
        <div class="auth-logo">
            <h2><?= e(APP_NAME) ?> Setup</h2>
            <p><?= $step === 'upgrade' ? 'Database Upgrade' : 'Database Installation' ?></p>
        </div>

        <?php foreach ($messages as [$type, $msg]): ?>
            <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
        <?php endforeach; ?>

        <?php if (!empty($migrationLog)): ?>
            <div class="alert alert-info">
                <strong>Applied checks</strong>
                <ul class="install-log">
                    <?php foreach ($migrationLog as $item): ?>
                        <li><?= e($item) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($step === 'run'): ?>
            <table class="install-accounts">
                <thead>
                    <tr><th>Role</th><th>Email</th><th>Password</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($defaultAccounts as [$role, $email, $password]): ?>
                        <tr>
                            <td><?= e($role) ?></td>
                            <td><?= e($email) ?></td>
                            <td><code><?= e($password) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="install-actions">
            <?php if ($installed): ?>
                <a href="index.php" class="btn btn-primary btn-block">Go to Application</a>
                <a href="?step=upgrade" class="btn btn-outline btn-block">Run Database Upgrade</a>
            <?php elseif ($needsInstall): ?>
                <a href="?step=run" class="btn btn-primary btn-block">Install Database</a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
