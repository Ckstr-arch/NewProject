<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/core/response.php';
set_exception_handler('handleApiException');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/request.php';
require_once __DIR__ . '/config/cors.php';
applyCors();
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/resources.php';
require_once __DIR__ . '/core/router.php';
