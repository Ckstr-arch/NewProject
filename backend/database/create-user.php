<?php
declare(strict_types=1);

use SportsHub\Config\Database;

// This is an administrative CLI, never a public HTTP registration endpoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 2 || $argv[1] !== '--confirm-create') {
    fwrite(STDERR, "Usage: php database/create-user.php --confirm-create\nSupply USER_EMAIL, USER_PASSWORD, USER_NAME and USER_ROLE through a private process environment. Optional: USER_STUDENT_ID and USER_FACULTY. No values belong in command arguments.\n");
    exit(1);
}

// Read provisioning inputs from the caller's process, before loading backend .env.
// Database configuration may come from .env; account passwords must not be put there.
$name = trim((string)getenv('USER_NAME'));
$email = strtolower(trim((string)getenv('USER_EMAIL')));
$password = (string)getenv('USER_PASSWORD');
$role = (string)getenv('USER_ROLE');
$studentId = trim((string)getenv('USER_STUDENT_ID'));
$faculty = trim((string)getenv('USER_FACULTY'));

try {
    if (!extension_loaded('mbstring')) {
        throw new RuntimeException('The PHP mbstring extension is required.');
    }
    foreach (['USER_NAME' => $name, 'USER_EMAIL' => $email, 'USER_STUDENT_ID' => $studentId, 'USER_FACULTY' => $faculty] as $field => $value) {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException($field.' must contain valid UTF-8 text.');
        }
    }
    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        throw new InvalidArgumentException('USER_NAME must contain 2–80 characters.');
    }
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('USER_EMAIL must be a valid email address of at most 254 bytes.');
    }
    if (!in_array($role, ['student', 'admin'], true)) {
        throw new InvalidArgumentException('USER_ROLE must be student or admin.');
    }
    if (mb_strlen($studentId) > 100 || mb_strlen($faculty) > 100) {
        throw new InvalidArgumentException('Optional USER_STUDENT_ID and USER_FACULTY may contain at most 100 characters each.');
    }
    if (strlen($password) < 12 || strlen($password) > 128) {
        throw new InvalidArgumentException('USER_PASSWORD must contain 12–128 bytes.');
    }
    $algorithm = in_array('argon2id', password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    if ($algorithm === PASSWORD_BCRYPT && (strlen($password) > 72 || str_contains($password, "\0"))) {
        throw new InvalidArgumentException('This PHP build uses bcrypt: USER_PASSWORD must contain at most 72 bytes and no null bytes. Install an Argon2id-capable PHP build for longer passwords.');
    }
    $hash = password_hash($password, $algorithm);
    // Do not retain the provisioning password as an exported variable for child processes.
    unset($password);
    putenv('USER_PASSWORD');
    unset($_ENV['USER_PASSWORD'], $_SERVER['USER_PASSWORD']);

    require dirname(__DIR__).'/src/bootstrap.php';
    $db = Database::connection();
    $statement = $db->prepare(
        'INSERT INTO users (name,email,password_hash,role,student_id,faculty,notifications) VALUES (?,?,?,?,?,?,1)'
    );
    // INSERT only: unique keys atomically refuse an existing email or student ID.
    // This tool never resets a password or promotes an existing account.
    $statement->execute([$name, $email, $hash, $role, $studentId === '' ? null : $studentId, $faculty === '' ? null : $faculty]);
    echo 'Created user #'.(int)$db->lastInsertId().' ('.$role."). Existing accounts were not changed.\n";
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, 'Refused: '.$error->getMessage()."\n");
    exit(1);
} catch (PDOException $error) {
    if (($error->errorInfo[0] ?? $error->getCode()) === '23000') {
        fwrite(STDERR, "Refused: the email or student ID already exists. No account was changed.\n");
    } else {
        error_log('User provisioning failed: PDO connection/query error.');
        fwrite(STDERR, "Could not create the user. Check backend configuration, migrated schema and database access. No credentials were printed.\n");
    }
    exit(1);
} catch (Throwable $error) {
    error_log('User provisioning failed: '.get_class($error));
    fwrite(STDERR, "Could not create the user. Check PHP prerequisites, backend configuration and the database migration. No credentials were printed.\n");
    exit(1);
} finally {
    unset($password, $hash);
    putenv('USER_PASSWORD');
    unset($_ENV['USER_PASSWORD'], $_SERVER['USER_PASSWORD']);
}
