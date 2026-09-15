<?php
/**
 * Application setup and database upgrade script.
 * Fresh install:  http://localhost/regdum_online_processing/install.php?step=run
 * Upgrade existing: http://localhost/regdum_online_processing/install.php?step=upgrade
 * Delete this file after setup in production.
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';
$step = $_GET['step'] ?? 'check';
$messages = [];
$migrationLog = [];
$installed = false;
$needsInstall = false;
$databaseExists = false;
$coreTablesPresent = false;
$pendingSqlMigrations = 0;

function installDatabaseName(): string {
    return str_replace('`', '``', DB_NAME);

}

function installPdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]);
    }
    return $pdo;

}

function ensureInstallDatabase(PDO $pdo): void {
    $dbName = installDatabaseName();
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

}

function installDatabaseExists(PDO $pdo): bool {
    $dbName = installDatabaseName();
    $stmt = $pdo->prepare('SHOW DATABASES LIKE ?');
    $stmt->execute([DB_NAME]);
    return (bool) $stmt->fetch();

}

function installCoreTablesPresent(PDO $pdo): bool {
    if (!installDatabaseExists($pdo)) {
        return false;
    }
    ensureInstallDatabase($pdo);
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    return (bool) $stmt->fetch();

}

function parseSqlStatements(string $sql): array {
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $sql = preg_replace('/^\s*USE\s+[^;]+;\s*$/mi', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', $sql))));

}

function shouldSkipSqlStatement(string $statement): bool {
    if ($statement === '') {
        return true;
    }
    return (bool) preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\s/i', $statement);

}

function runSqlFile(PDO $pdo, string $filepath): void {
    ensureInstallDatabase($pdo);
    $statements = parseSqlStatements((string) file_get_contents($filepath));
    foreach ($statements as $statement) {
        if (shouldSkipSqlStatement($statement)) {
            continue;
        }
        $pdo->exec($statement);
    }

}

function ensureMigrationTrackingSchema(PDO $pdo): void {
    ensureInstallDatabase($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(191) NOT NULL UNIQUE,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci");

}

function listSqlMigrationFiles(): array {
    $files = glob(__DIR__ . '/database/migrations/*.sql') ?: [];
    sort($files);
    return $files;

}

function getAppliedSqlMigrations(PDO $pdo): array {
    ensureMigrationTrackingSchema($pdo);
    $rows = $pdo->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];

}

function countPendingSqlMigrations(PDO $pdo): int {
    $applied = array_flip(getAppliedSqlMigrations($pdo));
    $pending = 0;
    foreach (listSqlMigrationFiles() as $file) {
        if (!isset($applied[basename($file)])) {
            $pending++;
        }
    }
    return $pending;

}

function markSqlMigrationsApplied(PDO $pdo, array $filenames): void {
    ensureMigrationTrackingSchema($pdo);
    $stmt = $pdo->prepare('INSERT IGNORE INTO schema_migrations (migration) VALUES (?)');
    foreach ($filenames as $filename) {
        $stmt->execute([$filename]);
    }

}

function markAllSqlMigrationsApplied(PDO $pdo): void {
    $filenames = array_map('basename', listSqlMigrationFiles());
    markSqlMigrationsApplied($pdo, $filenames);

}

function runSqlMigrationFiles(PDO $pdo): array {
    ensureMigrationTrackingSchema($pdo);
    $applied = array_flip(getAppliedSqlMigrations($pdo));
    $log = [];
    foreach (listSqlMigrationFiles() as $file) {
        $name = basename($file);
        if (isset($applied[$name])) {
            continue;
        }
        $statements = parseSqlStatements((string) file_get_contents($file));
        foreach ($statements as $statement) {
            if (shouldSkipSqlStatement($statement)) {
                continue;
            }
            $pdo->exec($statement);
        }
        $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)')->execute([$name]);
        $log[] = 'SQL migration applied: ' . $name;
    }
    return $log;

}

function runPhpApplicationMigrations(): array {
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

    ensureAuditLogsSchema();
    $log[] = 'Audit logs nullable columns for logout/login events';

    require_once __DIR__ . '/includes/academic-term.php';
    ensureAcademicTermSettings();
    $log[] = 'Active school year and semester settings';

    ensureStudentEmploymentFields();
    $log[] = 'Graduate employment profile fields';
    ensureStudentAcademicTermFields();
    $log[] = 'Current academic year and semester fields';
    ensureStudentValidIdField();
    $log[] = 'Student valid ID upload field';
    ensureStudentImportProfileFields();
    $log[] = 'Student import profile fields';
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
    require_once __DIR__ . '/includes/onsite-request.php';
    ensureOnsiteRequestSchema();
    $log[] = 'Onsite walk-in request channel, created_by, and multi-student batch key';
    require_once __DIR__ . '/includes/queue.php';
    ensureQueueSchema();
    $log[] = 'Onsite queuing windows, staff assignments, and priority tickets';
    require_once __DIR__ . '/includes/assignment-offices.php';
    ensureDocumentAssignmentOfficeSchema();
    $log[] = 'Document assignment offices (Cashier, Accounting, Guidance, Registrar, Clearance)';
    require_once __DIR__ . '/includes/accounting.php';
    ensureAccountingModule();
    $log[] = 'Accounting RBAC role for SOA document assignment';
    require_once __DIR__ . '/includes/external-api.php';
    ensureExternalApiSchema();
    $log[] = 'External API keys, encrypted storage, and request logs';
    require_once __DIR__ . '/includes/database-tools.php';
    ensureDatabaseToolsSchema();
    $log[] = 'Database backup tools schema';
    ensureUploadDirectories();
    $log[] = 'Upload directories';
    return $log;

}

function runApplicationMigrations(PDO $pdo, bool $freshSchemaInstalled = false): array {
    if ($freshSchemaInstalled) {
        markAllSqlMigrationsApplied($pdo);
        $log = ['SQL migrations marked as applied (fresh schema is current).'];
    } else {
        $log = runSqlMigrationFiles($pdo);
        if ($log === []) {
            $log[] = 'SQL migrations: none pending';
        }
    }
    return array_merge($log, runPhpApplicationMigrations());

}

function ensureUploadDirectories(): void {
    foreach (['documents', 'request_docs', 'receipts', 'student_ids'] as $dir) {
        $path = UPLOAD_PATH . '/' . $dir;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

}

function seedDefaultUsers(PDO $db): bool {
    $existing = $db->query("SELECT COUNT(*) FROM users WHERE email IN ('admin@regdum.edu.ph','staff@regdum.edu.ph','cashier@regdum.edu.ph','registrar@regdum.edu.ph','accounting@regdum.edu.ph')")->fetchColumn();
    if ((int) $existing > 0) {
        return false;
    }
    $adminHash = password_hash('Admin@123', PASSWORD_BCRYPT);
    $staffHash = password_hash('Staff@123', PASSWORD_BCRYPT);
    $cashierHash = password_hash('Cashier@123', PASSWORD_BCRYPT);
    $registrarHash = password_hash('Registrar@123', PASSWORD_BCRYPT);
    $accountingHash = password_hash('Accounting@123', PASSWORD_BCRYPT);
    $cashierRoleId = $db->query("SELECT id FROM roles WHERE name = 'cashier'")->fetchColumn() ?: 5;
    $registrarRoleId = $db->query("SELECT id FROM roles WHERE name = 'registrar'")->fetchColumn() ?: 3;
    $accountingRoleId = $db->query("SELECT id FROM roles WHERE name = 'accounting'")->fetchColumn();
    $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (4, ?, ?, ?, ?, 1, 1)')
       ->execute(['admin@regdum.edu.ph', $adminHash, 'System', 'Administrator']);
    $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (2, ?, ?, ?, ?, 1, 1)')
       ->execute(['staff@regdum.edu.ph', $staffHash, 'Registrar', 'Staff']);
    $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (?, ?, ?, ?, ?, 1, 1)')
       ->execute([$cashierRoleId, 'cashier@regdum.edu.ph', $cashierHash, 'Payment', 'Cashier']);
    $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (?, ?, ?, ?, ?, 1, 1)')
       ->execute([$registrarRoleId, 'registrar@regdum.edu.ph', $registrarHash, 'Records', 'Registrar']);
    if ($accountingRoleId) {
        $db->prepare('INSERT INTO users (role_id, email, password, first_name, last_name, is_active, email_verified) VALUES (?, ?, ?, ?, ?, 1, 1)')
           ->execute([$accountingRoleId, 'accounting@regdum.edu.ph', $accountingHash, 'SOA', 'Accounting']);
    }
    return true;

}

function upgradeExistingDatabase(PDO $pdo): array {
    return runApplicationMigrations($pdo, false);

}

try {
    $pdo = installPdo();
    $databaseExists = installDatabaseExists($pdo);
    $coreTablesPresent = installCoreTablesPresent($pdo);
    if ($databaseExists && $coreTablesPresent) {
        $pendingSqlMigrations = countPendingSqlMigrations($pdo);
    }
    if ($step === 'run') {
        ensureInstallDatabase($pdo);
        if ($coreTablesPresent) {
            $migrationLog = upgradeExistingDatabase($pdo);
            $messages[] = ['success', 'Existing database detected. Latest migrations have been applied.'];
            $messages[] = ['info', 'No data was removed. Schema changes were applied incrementally.'];
            $installed = true;
        } else {
            runSqlFile($pdo, __DIR__ . '/database/schema.sql');
            $db = getDB();
            $seeded = seedDefaultUsers($db);
            $migrationLog = runApplicationMigrations($pdo, true);
            $messages[] = ['success', 'Installation complete!'];
            if ($seeded) {
                $messages[] = ['info', 'Default accounts created (see credentials below).'];
            } else {
                $messages[] = ['info', 'Default accounts already exist — skipped seeding.'];
            }
            $messages[] = ['warning', 'Delete install.php for security after setup.'];
            $installed = true;
        }
    } elseif ($step === 'upgrade') {
        if (!$databaseExists || !$coreTablesPresent) {
            $messages[] = ['error', 'Database is not installed yet. Use Install Database first.'];
            $needsInstall = true;
        } else {
            $migrationLog = upgradeExistingDatabase($pdo);
            $messages[] = ['success', 'Database upgrade completed.'];
            $messages[] = ['info', 'All pending SQL and runtime migrations are up to date.'];
            $installed = true;
        }
    } else {
        if ($databaseExists && $coreTablesPresent) {
            $migrationLog = upgradeExistingDatabase($pdo);
            $messages[] = ['success', 'Database is installed and has been updated with the latest migrations.'];
            if ($pendingSqlMigrations > 0) {
                $messages[] = ['info', 'Applied ' . $pendingSqlMigrations . ' pending SQL migration(s).'];
            } else {
                $messages[] = ['info', 'No pending SQL migrations were found. Runtime schema checks were verified.'];
            }
            $installed = true;
        } elseif ($databaseExists) {
            $messages[] = ['info', 'Database exists but core tables are missing. Click Install Database to create the schema.'];
            $needsInstall = true;
        } else {
            $messages[] = ['info', 'Ready to install. Make sure MySQL is running in XAMPP, then click below.'];
            $needsInstall = true;
        }
    }
} catch (PDOException $e) {
    $messages[] = ['error', 'Database error: ' . $e->getMessage()];
    $messages[] = ['warning', 'Ensure MySQL is started in the XAMPP Control Panel.'];
    $needsInstall = ($step === 'check' || $step === 'run');

}

$defaultAccounts = [
    ['Admin', 'admin@regdum.edu.ph', 'Admin@123'],
    ['Staff', 'staff@regdum.edu.ph', 'Staff@123'],
    ['Cashier', 'cashier@regdum.edu.ph', 'Cashier@123'],
    ['Accounting', 'accounting@regdum.edu.ph', 'Accounting@123'],
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
        .install-status { font-size: .875rem; color: var(--gray-600, #4b5563); margin: .5rem 0 0; }
    </style>
</head>

<body class="auth-page">
<div class="auth-container">
    <div class="auth-card" style="max-width: 520px;">
        <div class="auth-logo">
            <h2><?= e(APP_NAME) ?> Setup</h2>
            <p><?= $step === 'upgrade' ? 'Database Upgrade' : 'Database Installation' ?></p>
        </div>
        <?php if ($databaseExists): ?>
            <p class="install-status">
                Database <code><?= e(DB_NAME) ?></code> detected.
                <?= $coreTablesPresent ? 'Core tables are present.' : 'Core tables are missing.' ?>
                <?php if ($coreTablesPresent && $pendingSqlMigrations > 0 && $step === 'check'): ?>
                    <?= (int) $pendingSqlMigrations ?> SQL migration(s) were pending before this visit.
                <?php endif; ?>
            </p>
        <?php endif; ?>
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
        <?php if ($step === 'run' && !$coreTablesPresent): ?>
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
                <?php if ($coreTablesPresent): ?>
                    <a href="?step=upgrade" class="btn btn-outline btn-block">Run Database Upgrade Again</a>
                <?php endif; ?>
            <?php elseif ($needsInstall): ?>
                <a href="?step=run" class="btn btn-primary btn-block"><?= $databaseExists ? 'Install Missing Tables' : 'Install Database' ?></a>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
