<?php
declare(strict_types=1);
namespace SportsHub\Core;
final class Request {
    public readonly string $method;
    public readonly string $path;
    public readonly array $query;
    private string $raw;
    public function __construct() {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $this->path = is_string($path) ? $path : '/';
        $this->query = $_GET;
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > 1048576) throw new ApiException('Request body is too large.', 400);
        $this->raw = (string)file_get_contents('php://input', false, null, 0, 1048577);
        if (strlen($this->raw) > 1048576) throw new ApiException('Request body is too large.', 400);
    }
    public function header(string $name): ?string {
        $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? ($name === 'Content-Type' ? ($_SERVER['CONTENT_TYPE'] ?? null) : null);
    }
    public function body(): array {
        if (!preg_match('~^application/json(?:\s*;|$)~i', $this->header('Content-Type') ?? '')) throw new ApiException('Send an application/json request body.', 400);
        try { $decoded = json_decode($this->raw, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiException('Malformed JSON request.', 400); }
        if (!$decoded instanceof \stdClass) throw new ApiException('The JSON body must be an object.', 400);
        return get_object_vars($decoded);
    }
    public function noBody(): void {
        if(trim($this->raw)==='')return;
        if(!in_array($this->method,['GET','DELETE'],true)){
            $body=$this->body();$errors=[];foreach($body as $key=>$value)$errors[$key]=['This field is not supported.'];
            throw new ApiException('This endpoint does not accept a request body.',422,$errors ?: ['body'=>['Omit the request body.']]);
        }
        throw new ApiException('This endpoint does not accept a request body.',400);
    }
    public function noQuery(): void { if ($this->query) throw new ApiException('This endpoint does not accept query parameters.', 422); }
}
