<?php
declare(strict_types=1);
namespace SportsHub\Config;
use PDO;
use SportsHub\Core\ApiException;
final class Database {
    private static ?PDO $connection = null;
    public static function connection(): PDO {
        if (self::$connection) return self::$connection;
        $name = getenv('DB_NAME') ?: '';
        $port = getenv('DB_PORT') ?: '3306';
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $name) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 || preg_match('/[;\r\n]/', $host)) throw new ApiException('Database configuration is unavailable.', 503);
        try {
            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
            $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            if (stripos($version, 'mariadb') !== false || version_compare($version, '8.0.16', '<')) throw new ApiException('This deployment requires verified MySQL 8.0.16 or newer.', 503);
            $pdo->exec("SET time_zone='+00:00'");
            self::$connection = $pdo;
            return $pdo;
        } catch (ApiException $e) { throw $e; }
        catch (\PDOException $e) { error_log('SportsHub database connection failed; SQLSTATE '.$e->getCode()); throw new ApiException('The database is unavailable. Please try again later.', 503); }
    }
    public static function execute(string $sql, array $params = []): \PDOStatement {
        $statement = self::connection()->prepare($sql);
        foreach ($params as $key => $value) $statement->bindValue(is_int($key) ? $key + 1 : (str_starts_with((string)$key, ':') ? $key : ':'.$key), $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        $statement->execute();
        return $statement;
    }
    public static function one(string $sql, array $params = []): ?array { return self::execute($sql, $params)->fetch() ?: null; }
    public static function all(string $sql, array $params = []): array { return self::execute($sql, $params)->fetchAll(); }
    public static function transaction(callable $work): mixed {
        $pdo = self::connection();
        if ($pdo->inTransaction()) throw new \LogicException('Nested transactions are not supported.');
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
        try { $result = $work(); $pdo->commit(); return $result; }
        catch (\Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
