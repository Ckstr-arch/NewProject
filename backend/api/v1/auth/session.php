<?php
declare(strict_types=1);

function handleSession(): never
{
    $user = requireAuth();
    jsonResponse(['user' => publicUser($user), 'permissions' => permissionsForRole($user['role'])]);
}
