<?php
declare(strict_types=1);
namespace SportsHub\Core;
final class ApiException extends \RuntimeException {
    public function __construct(string $message, public readonly int $status = 422, public readonly array $errors = []) { parent::__construct($message); }
}
