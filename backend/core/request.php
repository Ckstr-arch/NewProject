<?php
declare(strict_types=1);

function requestMethod(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function requestHeader(string $name): ?string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    foreach ([$key, 'REDIRECT_' . $key, strtoupper(str_replace('-', '_', $name))] as $candidate) {
        if (isset($_SERVER[$candidate]) && is_string($_SERVER[$candidate])) return trim($_SERVER[$candidate]);
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strcasecmp($key, $name) === 0) return trim((string) $value);
        }
    }
    return null;
}

function jsonBody(): array
{
    static $body;
    if ($body !== null) return $body;
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) abortRequest('PAYLOAD_TOO_LARGE', 'The request body is too large.', 413);
    $mediaType = strtolower(trim(explode(';', requestHeader('Content-Type') ?? '')[0]));
    if ($mediaType !== 'application/json') abortRequest('UNSUPPORTED_MEDIA_TYPE', 'Use Content-Type: application/json.', 415);
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if ($raw === false || strlen($raw) > 65536) abortRequest('PAYLOAD_TOO_LARGE', 'The request body is too large.', 413);
    try {
        $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        abortRequest('INVALID_JSON', 'The request body contains malformed JSON.', 400);
    }
    if (!$decoded instanceof stdClass) abortRequest('INVALID_JSON', 'The request body must be a JSON object.', 400);
    $body = (array) $decoded;
    return $body;
}

function queryParams(): array
{
    foreach ($_GET as $value) {
        if (!is_string($value)) abortRequest('VALIDATION_ERROR', 'Query parameters must be scalar strings.', 422);
    }
    return $_GET;
}

function routeId(): int
{
    $id = $GLOBALS['routeParams']['id'] ?? null;
    if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', $id) || (int) $id > 2147483647) {
        abortRequest('VALIDATION_ERROR', 'A valid positive resource ID is required.', 422, ['id' => 'Invalid resource ID.']);
    }
    return (int) $id;
}
