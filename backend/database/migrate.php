<?php
declare(strict_types=1);

use SportsHub\Config\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/src/bootstrap.php';

/** MySQL DDL auto-commits: use additive, repeatable statements and record a checksum. */
try {
    $db = Database::connection();
    $version = (string)$db->query('SELECT VERSION()')->fetchColumn();
    if (stripos($version, 'mariadb') !== false || version_compare($version, '8.0.16', '<')) {
        throw new RuntimeException('Migration requires genuine MySQL 8.0.16+ with enforced CHECK constraints. MariaDB has not been validated.');
    }
    $selected = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($selected === '') throw new RuntimeException('Select a dedicated application database before migrating.');
    $lockName = 'sportshub:migrate:'.substr(hash('sha256', $selected), 0, 40);
    $lock = $db->prepare('SELECT GET_LOCK(?, 15)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Another migration is running. Try again after it finishes.');
    try {
        $path = __DIR__.'/schema.sql';
        $sql = file_get_contents($path);
        if ($sql === false) throw new RuntimeException('Could not read the schema file.');
        $checksum = hash('sha256', $sql);
        $migration = 'schema_v1';
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(128) NOT NULL PRIMARY KEY, checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, applied_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $existing = $db->prepare('SELECT checksum FROM schema_migrations WHERE version = ?');
        $existing->execute([$migration]);
        $applied = $existing->fetchColumn();
        if ($applied !== false) {
            if (!hash_equals((string)$applied, $checksum)) {
                throw new RuntimeException('Applied schema_v1 checksum differs. Preserve the existing migration and create a reviewed additive migration instead of editing applied SQL.');
            }
            echo "schema_v1 already applied; checksum verified. Existing records preserved.\n";
        } else {
            // This schema has only simple statements; no routines or user-provided SQL.
            foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
                if (trim($statement) !== '') $db->exec($statement);
            }
            $insert = $db->prepare('INSERT INTO schema_migrations(version, checksum) VALUES(?, ?)');
            $insert->execute([$migration, $checksum]);
            echo "schema_v1 applied. Existing records were not reset.\n";
        }
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
} catch (Throwable $error) {
    // SQL/connection details and credentials must stay out of console/shareable logs.
    $message = $error instanceof RuntimeException && !$error instanceof PDOException
        ? $error->getMessage() : 'Schema preparation failed. Check private server diagnostics, database permissions and MySQL compatibility.';
    fwrite(STDERR, $message."\n");
    exit(1);
}
