<?php
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(public readonly string $apiCode, string $message, public readonly int $status = 400, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}

function abortRequest(string $code, string $message, int $status = 400, array $details = []): never
{
    throw new ApiException($code, $message, $status, $details);
}

function writeJson(array $payload, int $status): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    exit;
}

function jsonResponse(mixed $data, int $status = 200): never
{
    writeJson(['data' => $data], $status);
}

function errorResponse(string $code, string $message, array $details = [], int $status = 400): never
{
    writeJson(['error' => ['code' => $code, 'message' => $message, 'details' => (object) $details]], $status);
}

function handleApiException(Throwable $error): never
{
    if ($error instanceof ApiException) {
        errorResponse($error->apiCode, $error->getMessage(), $error->details, $error->status);
    }
    // Exception text can contain SQL, credentials or paths. Log only its class and a correlation ID.
    $incident = bin2hex(random_bytes(8));
    error_log('SportsHub incident ' . $incident . ': ' . get_class($error));
    errorResponse('INTERNAL_SERVER_ERROR', 'An unexpected server error occurred.', [], 500);
}
