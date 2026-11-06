<?php
declare(strict_types=1);

function applyCors(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Vary: Origin');
    $origin = requestHeader('Origin');
    $allowed = array_filter(array_map('trim', explode(',', (string) config('CORS_ORIGINS'))));
    $app = parse_url((string) config('APP_URL'));
    $appOrigin = isset($app['scheme'], $app['host']) ? $app['scheme'] . '://' . $app['host'] . (isset($app['port']) ? ':' . $app['port'] : '') : '';
    if ($origin !== null && $origin !== '' && !in_array($origin, $allowed, true) && $origin !== $appOrigin) {
        abortRequest('CORS_ORIGIN_DENIED', 'This origin is not allowed.', 403);
    }
    if ($origin !== null && $origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 600');
    }
    if (requestMethod() === 'OPTIONS') {
        $requestedMethod = requestHeader('Access-Control-Request-Method');
        if ($requestedMethod !== null && !in_array(strtoupper($requestedMethod), ['GET','POST','PUT','DELETE','OPTIONS'], true)) {
            header('Allow: GET, POST, PUT, DELETE, OPTIONS');
            abortRequest('METHOD_NOT_ALLOWED', 'This HTTP method is not supported.', 405);
        }
        $headers = array_filter(array_map('trim', explode(',', strtolower(requestHeader('Access-Control-Request-Headers') ?? ''))));
        if (array_diff($headers, ['content-type','authorization'])) abortRequest('CORS_HEADERS_DENIED', 'A requested header is not allowed.', 403);
        http_response_code(204);
        exit;
    }
}
