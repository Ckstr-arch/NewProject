<?php
declare(strict_types=1);

function handleLogout(): never
{
    requireAuth();
    // This API uses expiring stateless JWTs. The client must discard its token.
    jsonResponse(['logged_out' => true, 'token_revoked' => false]);
}
