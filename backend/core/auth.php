<?php
declare(strict_types=1);

/** The database remains authoritative for account state and roles. */
function permissionsForRole(string $role): array
{
    $read = ['dashboard.read', 'clubs.read', 'schedules.read', 'tournaments.read', 'bookings.read'];
    return match ($role) {
        'student' => [...$read, 'bookings.create', 'bookings.cancel_own'],
        'club_manager' => [...$read, 'clubs.create', 'clubs.update', 'schedules.create', 'schedules.update', 'bookings.create', 'bookings.cancel_own'],
        'sports_admin' => [...$read, 'clubs.create', 'clubs.update', 'clubs.delete', 'schedules.create', 'schedules.update', 'schedules.delete', 'tournaments.create', 'tournaments.update', 'tournaments.delete', 'bookings.create', 'bookings.update', 'bookings.delete', 'bookings.create_for_others'],
        'super_admin' => ['*'],
        default => [],
    };
}

function publicUser(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'student_id' => $user['student_id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'avatar_initials' => $user['avatar_initials'],
    ];
}

function hasPermission(array $user, string $permission): bool
{
    $permissions = permissionsForRole((string) $user['role']);
    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function jwtSecret(): string
{
    $secret = (string) config('JWT_SECRET');
    if (strlen($secret) < 32) {
        abortRequest('CONFIGURATION_ERROR', 'Authentication is not configured correctly.', 503);
    }
    return $secret;
}

function jwtIssuer(): string
{
    $issuer = rtrim((string) config('APP_URL'), '/');
    if ($issuer === '') {
        abortRequest('CONFIGURATION_ERROR', 'Authentication is not configured correctly.', 503);
    }
    return $issuer;
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64UrlDecode(string $value): string|false
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
        return false;
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded !== false && base64UrlEncode($decoded) === $value ? $decoded : false;
}

function generateToken(array $user): string
{
    $now = time();
    $ttl = max(60, min(86400, (int) (config('JWT_TTL') ?: 3600)));
    $header = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $payload = base64UrlEncode(json_encode([
        'iss' => jwtIssuer(),
        'aud' => 'sportshub-frontend',
        'sub' => (string) $user['id'],
        'role' => $user['role'],
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + $ttl,
        'jti' => bin2hex(random_bytes(16)),
    ], JSON_THROW_ON_ERROR));
    return $header . '.' . $payload . '.' . base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, jwtSecret(), true));
}

function unauthorized(): never
{
    header('WWW-Authenticate: Bearer realm="NSBM SportsHub"');
    abortRequest('UNAUTHORIZED', 'A valid bearer token is required.', 401);
}

/** HS256 only: reject algorithm substitution, malformed tokens and stale claims. */
function validateToken(string $token): array
{
    if (strlen($token) > 4096) {
        unauthorized();
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        unauthorized();
    }
    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
    $decodedHeader = base64UrlDecode($encodedHeader);
    $decodedPayload = base64UrlDecode($encodedPayload);
    $signature = base64UrlDecode($encodedSignature);
    if ($decodedHeader === false || $decodedPayload === false || $signature === false) {
        unauthorized();
    }
    $expected = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, jwtSecret(), true);
    if (!hash_equals($expected, $signature)) {
        unauthorized();
    }
    try {
        $header = json_decode($decodedHeader, true, 16, JSON_THROW_ON_ERROR);
        $claims = json_decode($decodedPayload, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        unauthorized();
    }
    if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'JWT'
        || array_diff(array_keys($header), ['alg', 'typ']) !== [] || !is_array($claims)) {
        unauthorized();
    }
    $now = time();
    if (($claims['iss'] ?? null) !== jwtIssuer() || ($claims['aud'] ?? null) !== 'sportshub-frontend'
        || !is_int($claims['iat'] ?? null) || !is_int($claims['nbf'] ?? null) || !is_int($claims['exp'] ?? null)
        || $claims['exp'] <= $now || $claims['nbf'] > $now || $claims['iat'] > $now + 30
        || $claims['exp'] <= $claims['iat'] || !is_string($claims['sub'] ?? null)
        || !preg_match('/^[1-9][0-9]*$/D', $claims['sub'])
        || filter_var($claims['sub'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
        unauthorized();
    }
    return $claims;
}

function requireAuth(): array
{
    static $currentUser = null;
    if ($currentUser !== null) {
        return $currentUser;
    }
    $authorization = requestHeader('Authorization') ?? '';
    if (!preg_match('/^Bearer[ \t]+([^ \t]+)$/iD', $authorization, $match)) {
        unauthorized();
    }
    $claims = validateToken($match[1]);
    $statement = db()->prepare('SELECT id, student_id, name, email, role, avatar_initials, is_active FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $claims['sub']]);
    $user = $statement->fetch();
    if (!$user || (int) $user['is_active'] !== 1 || permissionsForRole((string) $user['role']) === []) {
        unauthorized();
    }
    $currentUser = $user;
    return $user;
}

function requireRole(array $roles): array
{
    $user = requireAuth();
    if (!in_array($user['role'], $roles, true)) {
        abortRequest('FORBIDDEN', 'You do not have permission to perform this action.', 403);
    }
    return $user;
}

function requirePermission(string $permission): array
{
    $user = requireAuth();
    if (!hasPermission($user, $permission)) {
        abortRequest('FORBIDDEN', 'You do not have permission to perform this action.', 403);
    }
    return $user;
}

/**
 * Five failed attempts per email and per source IP in fifteen minutes.
 * Keys are HMACs, so the limiter stores neither an email address nor an IP.
 * Only REMOTE_ADDR is trusted; forwarded headers require explicit proxy setup.
 */
function authenticateCredentials(string $email, string $password): array
{
    $pdo = db();
    $now = time();
    $keySecret = jwtSecret();
    $keys = [
        'account' => hash_hmac('sha256', 'account:' . strtolower($email), $keySecret),
        'ip' => hash_hmac('sha256', 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $keySecret),
    ];
    $orderedKeys = array_values($keys);
    sort($orderedKeys, SORT_STRING);
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT INTO login_attempts (limiter_key, failures, window_started_at, blocked_until) VALUES (:key, 0, :started, NULL) ON DUPLICATE KEY UPDATE failures = failures');
        $lock = $pdo->prepare('SELECT failures, window_started_at, blocked_until FROM login_attempts WHERE limiter_key = :key FOR UPDATE');
        $reset = $pdo->prepare('UPDATE login_attempts SET failures = 0, window_started_at = :started, blocked_until = NULL WHERE limiter_key = :key');
        foreach ($orderedKeys as $key) {
            $insert->execute(['key' => $key, 'started' => gmdate('Y-m-d H:i:s', $now)]);
            $lock->execute(['key' => $key]);
            $attempt = $lock->fetch();
            $started = strtotime($attempt['window_started_at'] . ' UTC');
            if ($started <= $now - 900) {
                $reset->execute(['started' => gmdate('Y-m-d H:i:s', $now), 'key' => $key]);
            } elseif ((int) $attempt['failures'] >= 5) {
                $pdo->rollBack();
                header('Retry-After: ' . max(1, $started + 900 - $now));
                abortRequest('RATE_LIMITED', 'Too many login attempts. Please try again later.', 429);
            }
        }
        $statement = $pdo->prepare('SELECT id, student_id, name, email, password_hash, role, avatar_initials, is_active FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        // Generated with password_hash(random_bytes encoded as hex, PASSWORD_BCRYPT, cost 12).
        // Match the development accounts' bcrypt cost, avoiding an account-existence timing shortcut.
        // This is a disposable dummy hash, not a credential for any user.
        $dummyHash = '$2y$12$coYEF7/HWFQhzWbAEI/eDuD4b.0gUhmyRoiqgCO8oPROoRlsRHNEy';
        $verified = password_verify($password, $user ? $user['password_hash'] : $dummyHash);
        if (!$user || !$verified || (int) $user['is_active'] !== 1 || permissionsForRole((string) $user['role']) === []) {
            $failure = $pdo->prepare('UPDATE login_attempts SET blocked_until = CASE WHEN failures >= 4 THEN DATE_ADD(window_started_at, INTERVAL 900 SECOND) ELSE NULL END, failures = failures + 1 WHERE limiter_key = :key');
            foreach ($orderedKeys as $key) {
                $failure->execute(['key' => $key]);
            }
            $pdo->commit();
            abortRequest('INVALID_CREDENTIALS', 'Invalid email or password.', 401);
        }
        $reset->execute(['started' => gmdate('Y-m-d H:i:s', $now), 'key' => $keys['account']]);
        $pdo->commit();
        return $user;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
