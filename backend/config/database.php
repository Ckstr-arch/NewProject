<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $host = (string) config('DB_HOST');
        $name = (string) config('DB_NAME');
        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/D', $host) || !preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
            throw new RuntimeException('Invalid database configuration.');
        }
        $pdo = new PDO(
            'mysql:host=' . $host . ';port=' . config('DB_PORT') . ';dbname=' . $name . ';charset=utf8mb4',
            (string) config('DB_USER'), (string) config('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]
        );
        $pdo->prepare("SET time_zone = '+00:00'")->execute();
    }
    return $pdo;
}
