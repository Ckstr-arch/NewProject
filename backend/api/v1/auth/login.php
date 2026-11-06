<?php
declare(strict_types=1);

function handleLogin(): never
{
    $body = jsonBody();
    $details = [];
    if (!isset($body['email']) || !is_string($body['email']) || strlen($body['email']) > 254
        || filter_var(trim($body['email']), FILTER_VALIDATE_EMAIL) === false) {
        $details['email'] = 'A valid email address is required.';
    }
    if (!isset($body['password']) || !is_string($body['password']) || $body['password'] === '' || str_contains($body['password'], "\0") || strlen($body['password']) > 1024) {
        $details['password'] = 'A password without null bytes, of at most 1024 bytes, is required.';
    }
    if ($details !== []) {
        abortRequest('VALIDATION_ERROR', 'The request contains invalid data.', 422, $details);
    }
    $user = authenticateCredentials(strtolower(trim($body['email'])), $body['password']);
    jsonResponse([
        'access_token' => generateToken($user),
        'token_type' => 'Bearer',
        'user' => publicUser($user),
        'permissions' => permissionsForRole($user['role']),
    ]);
}
