<?php
declare(strict_types=1);

/** Collect field-level errors before returning a single useful validation response. */
function validationFailure(array $errors): void
{
    if ($errors !== []) {
        abortRequest('VALIDATION_ERROR', 'Validation failed.', 422, $errors);
    }
}

function validateString(array $input, string $field, array &$errors, int $max = 255, bool $required = true, string $default = ''): string
{
    if (!array_key_exists($field, $input)) {
        if ($required) $errors[$field] = 'This field is required.';
        return $default;
    }
    if (!is_string($input[$field])) {
        $errors[$field] = 'Must be a string.';
        return $default;
    }
    $value = trim($input[$field]);
    if ($required && $value === '') $errors[$field] = 'This field is required.';
    // The application stores plain text, never arbitrary HTML fragments.
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) || strip_tags($value) !== $value) {
        $errors[$field] = 'Use plain text without HTML or control characters.';
    }
    $length = preg_match_all('/./us', $value, $unused);
    if ($length === false || $length > $max) $errors[$field] = "Must contain at most {$max} characters.";
    return $value;
}

function validateInteger(array $input, string $field, array &$errors, int $minimum = 0, ?int $default = null): ?int
{
    if (!array_key_exists($field, $input)) {
        if ($default === null) $errors[$field] = 'This field is required.';
        return $default;
    }
    $raw = $input[$field];
    if ((!is_int($raw) && !(is_string($raw) && preg_match('/^\d+$/D', $raw))) || filter_var($raw, FILTER_VALIDATE_INT) === false) {
        $errors[$field] = 'Must be an integer.';
        return $default;
    }
    $value = (int) $raw;
    if ($value < $minimum || $value > 9007199254740991) $errors[$field] = "Must be an integer between {$minimum} and 9007199254740991.";
    return $value;
}

function validateEnum(array $input, string $field, array $allowed, array &$errors, ?string $default = null): string
{
    $value = array_key_exists($field, $input) ? $input[$field] : $default;
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        $errors[$field] = 'Must be one of: ' . implode(', ', $allowed) . '.';
        return $default ?? '';
    }
    return $value;
}

function validDate(string $value): bool
{
    if (preg_match('/^[1-9]\d{3}-\d{2}-\d{2}$/D', $value) !== 1) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function validateDate(array $input, string $field, array &$errors): string
{
    $value = validateString($input, $field, $errors, 10);
    if (!validDate($value)) $errors[$field] = 'Must be a valid date in YYYY-MM-DD format.';
    return $value;
}

function validateTime(array $input, string $field, array &$errors): string
{
    $value = validateString($input, $field, $errors, 8);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value)) {
        $errors[$field] = 'Must be a valid time in HH:MM or HH:MM:SS format.';
        return '';
    }
    return strlen($value) === 5 ? $value . ':00' : $value;
}

function validateFields(array $input, array $allowed, array &$errors): void
{
    foreach (array_diff(array_keys($input), $allowed) as $field) $errors[$field] = 'Unknown or read-only field.';
}

function facilityNames(): array
{
    return ['Main Ground', 'Indoor Court', 'Gymnasium', 'Swimming Pool', 'Tennis Court', 'Badminton Court'];
}
