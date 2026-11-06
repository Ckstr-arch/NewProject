<?php
declare(strict_types=1);

/** OS environment > private config.local.php > backend/.env > safe defaults. */
function config(?string $key = null): mixed
{
    static $settings;
    if ($settings === null) {
        $env = [];
        $envFile = dirname(__DIR__) . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$name, $value] = explode('=', $line, 2);
                $value = trim($value);
                if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $value = substr($value, 1, -1);
                }
                $env[trim($name)] = $value;
            }
        }
        $localFile = __DIR__ . '/config.local.php';
        $local = is_file($localFile) ? require $localFile : [];
        if (!is_array($local)) throw new RuntimeException('Invalid local configuration.');
        $defaults = [
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_NAME' => 'nsbm_sportshub',
            'DB_USER' => 'nsbm_sportshub', 'DB_PASSWORD' => '',
            'APP_ENV' => 'development', 'APP_URL' => 'http://localhost:8000',
            'JWT_SECRET' => '', 'JWT_TTL' => '3600',
            'CORS_ORIGINS' => 'http://localhost:5500,http://127.0.0.1:5500',
        ];
        $settings = [];
        foreach ($defaults as $name => $default) {
            $value = getenv($name);
            $settings[$name] = $value !== false ? $value : ($local[$name] ?? $env[$name] ?? $default);
        }
        $settings['DB_PORT'] = (int) $settings['DB_PORT'];
        $settings['JWT_TTL'] = (int) $settings['JWT_TTL'];
        $settings['APP_URL'] = rtrim((string) $settings['APP_URL'], '/');
    }
    return $key === null ? $settings : ($settings[$key] ?? null);
}
