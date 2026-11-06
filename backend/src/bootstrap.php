<?php
declare(strict_types=1);
ini_set('display_errors','0');
error_reporting(E_ALL);
// Minimal autoloading keeps the application independent of Composer/frameworks.
spl_autoload_register(static function (string $class): void {
    $prefix = 'SportsHub\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) require $file;
    }
});
$environmentFile = dirname(__DIR__).'/.env';
if (is_file($environmentFile)) {
    $lines=@file($environmentFile, FILE_IGNORE_NEW_LINES);
    if($lines===false)throw new \SportsHub\Core\ApiException('Application configuration is unavailable.',503);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) && getenv($key) === false) putenv($key.'='.$value);
    }
}
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
error_reporting(E_ALL);
