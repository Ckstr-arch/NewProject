<?php
declare(strict_types=1);
namespace SportsHub\Core;
final class Response {
    public static function json(array $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    public static function ok(mixed $data, string $message = 'Operation completed successfully', int $status = 200): void { self::json(['success' => true, 'data' => $data, 'message' => $message], $status); }
    public static function listing(array $result): void { self::json(['success' => true, 'data' => $result['data'], 'pagination' => $result['pagination']]); }
    public static function noContent(): void { http_response_code(204); header('Cache-Control: no-store'); }
}
