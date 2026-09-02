<?php

function ensureDatabaseToolsSchema(): void {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS database_backups (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(120) NOT NULL,
        backup_type ENUM('full', 'custom', 'restore_point') NOT NULL,
        filename VARCHAR(255) NOT NULL,
        tables_json TEXT NULL,
        file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_database_backups_filename (filename),
        KEY idx_database_backups_type (backup_type),
        KEY idx_database_backups_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    databaseToolsEnsureBackupDirectory();
}

function databaseToolsEnsureBackupDirectory(): void {
    $dir = databaseToolsBackupPath();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }

    $index = $dir . '/index.html';
    if (!is_file($index)) {
        file_put_contents($index, '');
    }
}

function databaseToolsBackupPath(): string {
    return APP_ROOT . '/storage/database-backups';
}

function databaseToolsSanitizeTableName(string $name): ?string {
    $name = trim($name);
    if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        return null;
    }

    return $name;
}

function databaseToolsListTables(): array {
    $db = getDB();
    $tables = [];
    foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $table = databaseToolsSanitizeTableName((string) ($row[0] ?? ''));
        if ($table !== null) {
            $tables[] = $table;
        }
    }

    sort($tables, SORT_STRING);
    return $tables;
}

function databaseToolsValidateTables(array $tables): array {
    $allowed = array_fill_keys(databaseToolsListTables(), true);
    $valid = [];

    foreach ($tables as $table) {
        $table = databaseToolsSanitizeTableName((string) $table);
        if ($table !== null && isset($allowed[$table])) {
            $valid[$table] = true;
        }
    }

    return array_keys($valid);
}

function databaseToolsSlug(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');

    return $value !== '' ? substr($value, 0, 40) : 'backup';
}

function databaseToolsGenerateFilename(string $type, string $label = ''): string {
    $prefix = match ($type) {
        'restore_point' => 'restore_point',
        'custom'        => 'custom',
        default         => 'full',
    };

    $slug = databaseToolsSlug($label);
    if ($slug === 'backup' && $label === '') {
        $slug = DB_NAME;
    }

    return $prefix . '_' . $slug . '_' . date('Ymd_His') . '.sql';
}

function databaseToolsSqlValue(PDO $db, mixed $value): string {
    if ($value === null) {
        return 'NULL';
    }

    return $db->quote((string) $value);
}

function databaseToolsWriteDump(array $tables, string $filepath): int {
    $db = getDB();
    $handle = fopen($filepath, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to create backup file.');
    }

    $write = static function ($handle, string $line): void {
        if (fwrite($handle, $line) === false) {
            throw new RuntimeException('Unable to write backup file.');
        }
    };

    $write($handle, "-- Database backup\n");
    $write($handle, '-- Database: ' . DB_NAME . "\n");
    $write($handle, '-- Generated: ' . date('Y-m-d H:i:s') . "\n");
    $write($handle, '-- Tables: ' . implode(', ', $tables) . "\n\n");
    $write($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
    $write($handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n");
    $write($handle, "SET time_zone = '+00:00';\n\n");

    foreach ($tables as $table) {
        $create = $db->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM);
        if (!$create || empty($create[1])) {
            continue;
        }

        $write($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
        $write($handle, $create[1] . ";\n\n");

        $stmt = $db->query('SELECT * FROM `' . $table . '`');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns = array_map(static fn(string $col): string => '`' . $col . '`', array_keys($row));
            $values = [];
            foreach (array_values($row) as $value) {
                $values[] = databaseToolsSqlValue($db, $value);
            }
            $write(
                $handle,
                'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n"
            );
        }

        $write($handle, "\n");
    }

    $write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($handle);

    return (int) filesize($filepath);
}

function databaseToolsTryMysqldump(array $tables, string $filepath): bool {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return false;
    }

    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'mysqldump',
    ];

    $binary = null;
    foreach ($candidates as $candidate) {
        if ($candidate === 'mysqldump') {
            $binary = $candidate;
            break;
        }
        if (is_file($candidate)) {
            $binary = $candidate;
            break;
        }
    }

    if ($binary === null) {
        return false;
    }

    $args = [
        escapeshellarg($binary),
        '--host=' . escapeshellarg(DB_HOST),
        '--user=' . escapeshellarg(DB_USER),
        DB_PASS !== '' ? '--password=' . escapeshellarg(DB_PASS) : '',
        '--default-character-set=' . escapeshellarg(DB_CHARSET),
        '--single-transaction',
        '--routines',
        '--triggers',
        '--add-drop-table',
        escapeshellarg(DB_NAME),
    ];

    foreach ($tables as $table) {
        $args[] = escapeshellarg($table);
    }

    $command = implode(' ', array_filter($args)) . ' > ' . escapeshellarg($filepath);
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = 'cmd /C ' . $command;
    }

    exec($command, $output, $exitCode);

    return $exitCode === 0 && is_file($filepath) && filesize($filepath) > 0;
}

function databaseToolsCreateBackup(string $type, string $label, array $tables, int $userId, ?string $notes = ''): array {
    ensureDatabaseToolsSchema();

    $type = in_array($type, ['full', 'custom', 'restore_point'], true) ? $type : 'full';
    $label = trim($label);
    if ($label === '') {
        $label = match ($type) {
            'restore_point' => 'Restore Point',
            'custom'        => 'Custom Backup',
            default         => 'Full Database Backup',
        };
    }

    if ($type === 'full') {
        $tables = databaseToolsListTables();
    } else {
        $tables = databaseToolsValidateTables($tables);
        if ($tables === []) {
            throw new InvalidArgumentException('Select at least one valid table.');
        }
    }

    $filename = databaseToolsGenerateFilename($type, $label);
    $filepath = databaseToolsBackupPath() . '/' . $filename;

    if (!databaseToolsTryMysqldump($tables, $filepath)) {
        $size = databaseToolsWriteDump($tables, $filepath);
    } else {
        $size = (int) filesize($filepath);
    }

    $db = getDB();
    $stmt = $db->prepare('INSERT INTO database_backups (label, backup_type, filename, tables_json, file_size, created_by, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $label,
        $type,
        $filename,
        json_encode($tables),
        $size,
        $userId > 0 ? $userId : null,
        $notes !== null && trim($notes) !== '' ? trim($notes) : null,
    ]);

    return [
        'id'       => (int) $db->lastInsertId(),
        'label'    => $label,
        'filename' => $filename,
        'size'     => $size,
        'tables'   => $tables,
        'type'     => $type,
    ];
}

function databaseToolsListBackups(): array {
    ensureDatabaseToolsSchema();

    $db = getDB();
    $stmt = $db->query('SELECT b.*, u.first_name, u.last_name
        FROM database_backups b
        LEFT JOIN users u ON u.id = b.created_by
        ORDER BY b.created_at DESC, b.id DESC');

    return $stmt->fetchAll();
}

function databaseToolsGetBackup(int $id): ?array {
    ensureDatabaseToolsSchema();

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM database_backups WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function databaseToolsBackupFilePath(array $backup): string {
    $filename = basename((string) ($backup['filename'] ?? ''));
    if ($filename === '' || !preg_match('/^[A-Za-z0-9._-]+\.sql$/', $filename)) {
        throw new InvalidArgumentException('Invalid backup file.');
    }

    return databaseToolsBackupPath() . '/' . $filename;
}

function databaseToolsDownloadBackup(int $id): void {
    $backup = databaseToolsGetBackup($id);
    if (!$backup) {
        throw new InvalidArgumentException('Backup not found.');
    }

    $filepath = databaseToolsBackupFilePath($backup);
    if (!is_file($filepath)) {
        throw new RuntimeException('Backup file is missing on disk.');
    }

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
    header('Content-Length: ' . (string) filesize($filepath));
    header('Cache-Control: no-store');

    readfile($filepath);
    exit;
}

function databaseToolsDeleteBackup(int $id, int $userId): void {
    $backup = databaseToolsGetBackup($id);
    if (!$backup) {
        throw new InvalidArgumentException('Backup not found.');
    }

    $filepath = databaseToolsBackupFilePath($backup);
    if (is_file($filepath)) {
        unlink($filepath);
    }

    $db = getDB();
    $stmt = $db->prepare('DELETE FROM database_backups WHERE id = ?');
    $stmt->execute([$id]);

    auditLog('database_backup_delete', 'database_backup', $id, [
        'label'    => $backup['label'],
        'filename' => $backup['filename'],
        'type'     => $backup['backup_type'],
    ], null);
}

function databaseToolsSplitSqlStatements(string $sql): array {
    $statements = [];
    $buffer = '';
    $inString = false;
    $stringChar = '';
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if (!$inString && ($char === '"' || $char === "'")) {
            $inString = true;
            $stringChar = $char;
        } elseif ($inString && $char === $stringChar && $prev !== '\\') {
            $inString = false;
            $stringChar = '';
        }

        if (!$inString && $char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $statement = trim($buffer);
    if ($statement !== '') {
        $statements[] = $statement;
    }

    return $statements;
}

function databaseToolsExecuteSqlFile(string $filepath): array {
    if (!is_file($filepath)) {
        throw new RuntimeException('SQL file not found.');
    }

    $sql = file_get_contents($filepath);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('SQL file is empty.');
    }

    $db = getDB();
    $db->exec('SET FOREIGN_KEY_CHECKS=0');

    $executed = 0;
    $errors = [];

    foreach (databaseToolsSplitSqlStatements($sql) as $statement) {
        if (preg_match('/^(--|#|\/\*)/', $statement)) {
            continue;
        }

        try {
            $db->exec($statement);
            $executed++;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            if (count($errors) >= 5) {
                break;
            }
        }
    }

    $db->exec('SET FOREIGN_KEY_CHECKS=1');

    if ($errors !== []) {
        throw new RuntimeException('Restore failed: ' . implode(' | ', $errors));
    }

    return ['executed' => $executed];
}

function databaseToolsTryMysqlRestore(string $filepath): bool {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        return false;
    }

    $candidates = [
        'C:\\xampp\\mysql\\bin\\mysql.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysql.exe',
        'mysql',
    ];

    $binary = null;
    foreach ($candidates as $candidate) {
        if ($candidate === 'mysql') {
            $binary = $candidate;
            break;
        }
        if (is_file($candidate)) {
            $binary = $candidate;
            break;
        }
    }

    if ($binary === null) {
        return false;
    }

    $args = [
        escapeshellarg($binary),
        '--host=' . escapeshellarg(DB_HOST),
        '--user=' . escapeshellarg(DB_USER),
        DB_PASS !== '' ? '--password=' . escapeshellarg(DB_PASS) : '',
        '--default-character-set=' . escapeshellarg(DB_CHARSET),
        escapeshellarg(DB_NAME),
    ];

    $command = implode(' ', array_filter($args)) . ' < ' . escapeshellarg($filepath);
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = 'cmd /C ' . $command;
    }

    exec($command, $output, $exitCode);

    return $exitCode === 0;
}

function databaseToolsRestoreBackup(int $id, int $userId): array {
    $backup = databaseToolsGetBackup($id);
    if (!$backup) {
        throw new InvalidArgumentException('Backup not found.');
    }

    $filepath = databaseToolsBackupFilePath($backup);

    if (!databaseToolsTryMysqlRestore($filepath)) {
        $result = databaseToolsExecuteSqlFile($filepath);
    } else {
        $result = ['executed' => null];
    }

    auditLog('database_backup_restore', 'database_backup', $id, null, [
        'label'    => $backup['label'],
        'filename' => $backup['filename'],
        'type'     => $backup['backup_type'],
    ]);

    return $result;
}

function databaseToolsRestoreUpload(array $file, int $userId): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload failed. Please choose a valid .sql file.');
    }

    $name = (string) ($file['name'] ?? '');
    if (!preg_match('/\.sql$/i', $name)) {
        throw new InvalidArgumentException('Only .sql files are supported.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Invalid uploaded file.');
    }

    if (!databaseToolsTryMysqlRestore($tmp)) {
        $result = databaseToolsExecuteSqlFile($tmp);
    } else {
        $result = ['executed' => null];
    }

    auditLog('database_backup_restore_upload', 'database_backup', null, null, [
        'filename' => basename($name),
    ]);

    return $result;
}

function databaseToolsBackupTypeLabel(string $type): string {
    return match ($type) {
        'restore_point' => 'Restore Point',
        'custom'        => 'Custom Backup',
        default         => 'Full Backup',
    };
}

function databaseToolsFormatBytes(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1048576) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return number_format($bytes / 1048576, 2) . ' MB';
}

function databaseToolsTablesSummary(?string $tablesJson): string {
    $tables = json_decode((string) $tablesJson, true);
    if (!is_array($tables) || $tables === []) {
        return 'All tables';
    }

    $count = count($tables);
    if ($count <= 3) {
        return implode(', ', array_map('strval', $tables));
    }

    return implode(', ', array_slice($tables, 0, 3)) . ' +' . ($count - 3) . ' more';
}
