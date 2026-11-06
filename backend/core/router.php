<?php
declare(strict_types=1);

function dispatchRequest(): never
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($path) || preg_match('/%(?:2f|5c|00)/i', $path)) abortRequest('NOT_FOUND', 'API endpoint not found.', 404);
    $mount = rtrim((string) parse_url((string) config('APP_URL'), PHP_URL_PATH), '/');
    if ($mount !== '') {
        if (!str_starts_with($path, $mount . '/')) abortRequest('NOT_FOUND', 'API endpoint not found.', 404);
        $path = substr($path, strlen($mount));
    }
    $path = rtrim($path, '/');
    $routes = [
        '/api/v1/auth/login' => ['POST' => ['auth/login.php', 'handleLogin']],
        '/api/v1/auth/logout' => ['POST' => ['auth/logout.php', 'handleLogout']],
        '/api/v1/auth/session' => ['GET' => ['auth/session.php', 'handleSession']],
        '/api/v1/dashboard/metrics' => ['GET' => ['dashboard/metrics.php', 'handleMetrics']],
        '/api/v1/system/health' => ['GET' => ['system/health.php', 'handleHealth']],
    ];
    foreach (['clubs' => 'Club', 'schedules' => 'Schedule', 'tournaments' => 'Tournament', 'bookings' => 'Booking'] as $resource => $singular) {
        $collection = ['GET', 'POST'];
        $item = ['GET', 'PUT', 'DELETE'];
        $routes['/api/v1/' . $resource] = array_fill_keys($collection, [$resource . '/index.php', 'handle' . ucfirst($resource) . 'Collection']);
        if (preg_match('#^/api/v1/' . $resource . '/([^/]+)$#D', $path, $matches)) {
            $GLOBALS['routeParams'] = ['id' => $matches[1]];
            routeId();
            $routes[$path] = array_fill_keys($item, [$resource . '/resource.php', 'handle' . $singular . 'Resource']);
        }
    }
    if (!isset($routes[$path])) abortRequest('NOT_FOUND', 'API endpoint not found.', 404);
    $method = requestMethod();
    if (!isset($routes[$path][$method])) {
        header('Allow: ' . implode(', ', array_keys($routes[$path])) . ', OPTIONS');
        abortRequest('METHOD_NOT_ALLOWED', 'This HTTP method is not supported for this endpoint.', 405);
    }
    [$file, $handler] = $routes[$path][$method];
    require_once dirname(__DIR__) . '/api/v1/' . $file;
    $handler();
}
