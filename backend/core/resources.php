<?php
declare(strict_types=1);

require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/pagination.php';

/** These hard-coded identifiers are the only identifiers used in dynamic SQL. */
function resourceDefinition(string $resource): array
{
    $definitions = [
        'clubs' => [
            'search' => ['name', 'sport', 'president', 'description'],
            'filters' => ['status' => 'status', 'sport' => 'sport'],
            'sorts' => ['id', 'name', 'sport', 'president', 'member_count', 'status', 'created_at', 'updated_at'],
            'defaultSort' => 'name', 'statuses' => ['active', 'inactive'],
        ],
        'schedules' => [
            'search' => ['sport', 'event_name', 'location', 'teams', 'description'],
            'filters' => ['sport' => 'sport', 'status' => 'status', 'location' => 'location', 'date' => 'event_date'],
            'sorts' => ['id', 'sport', 'event_name', 'event_date', 'start_time', 'end_time', 'location', 'status', 'created_at', 'updated_at'],
            'defaultSort' => 'event_date', 'statuses' => ['scheduled', 'live', 'completed', 'cancelled'],
        ],
        'tournaments' => [
            'search' => ['name', 'sport', 'format', 'description'],
            'filters' => ['sport' => 'sport', 'status' => 'status', 'date' => 'start_date'],
            'sorts' => ['id', 'name', 'sport', 'start_date', 'end_date', 'format', 'max_teams', 'registered_teams', 'status', 'created_at', 'updated_at'],
            'defaultSort' => 'start_date', 'statuses' => ['registration_open', 'upcoming', 'ongoing', 'completed', 'cancelled'],
        ],
        'bookings' => [
            'search' => ['facility', 'sport', 'notes'],
            'filters' => ['facility' => 'facility', 'status' => 'status', 'date' => 'booking_date'],
            'sorts' => ['id', 'facility', 'booked_by', 'sport', 'booking_date', 'start_time', 'end_time', 'status', 'created_at', 'updated_at'],
            'defaultSort' => 'booking_date', 'statuses' => ['pending', 'confirmed', 'cancelled', 'completed'],
        ],
    ];
    if (!isset($definitions[$resource])) throw new LogicException('Unknown resource.');
    return $definitions[$resource];
}

function resourceJson(array $row): array
{
    foreach (['id', 'manager_id', 'member_count', 'max_teams', 'registered_teams', 'booked_by'] as $field) {
        if (isset($row[$field])) $row[$field] = (int) $row[$field];
    }
    return $row;
}

function resourceById(string $resource, int $id, bool $forUpdate = false): array
{
    resourceDefinition($resource);
    $statement = db()->prepare("SELECT * FROM `{$resource}` WHERE id = :id" . ($forUpdate ? ' FOR UPDATE' : ''));
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
    if (!$record) abortRequest('NOT_FOUND', 'The requested resource was not found.', 404);
    return resourceJson($record);
}

function isBookingAdmin(array $user): bool
{
    return in_array($user['role'], ['sports_admin', 'super_admin'], true);
}

function requireBookingOwner(array $record, array $user): void
{
    if (!isBookingAdmin($user) && (int) $record['booked_by'] !== (int) $user['id']) {
        abortRequest('FORBIDDEN', 'You may access only your own bookings.', 403);
    }
}

function requireClubManager(array $record, array $user): void
{
    if ($user['role'] === 'club_manager' && (int) ($record['manager_id'] ?? 0) !== (int) $user['id']) {
        abortRequest('FORBIDDEN', 'You may update only clubs assigned to you.', 403);
    }
}

function validateUserReference(int $id, string $field, bool $managerOnly = false): void
{
    $statement = db()->prepare('SELECT role FROM users WHERE id = :id AND is_active = 1');
    $statement->execute(['id' => $id]);
    $role = $statement->fetchColumn();
    if (!$role || ($managerOnly && $role !== 'club_manager')) {
        abortRequest('VALIDATION_ERROR', 'Validation failed.', 422, [$field => $managerOnly ? 'Must identify an active club manager.' : 'Must identify an active user.']);
    }
}

function validateResourceData(string $resource, array $body, array $user): array
{
    $errors = [];
    $statuses = resourceDefinition($resource)['statuses'];
    switch ($resource) {
        case 'clubs':
            validateFields($body, ['name', 'sport', 'president', 'description', 'member_count', 'status', 'logo_url', 'manager_id'], $errors);
            $data = [
                'name' => validateString($body, 'name', $errors, 120),
                'sport' => validateString($body, 'sport', $errors, 80),
                'president' => validateString($body, 'president', $errors, 120),
                'description' => validateString($body, 'description', $errors, 5000, false),
                'member_count' => validateInteger($body, 'member_count', $errors, 0, 0),
                'status' => validateEnum($body, 'status', $statuses, $errors, 'active'),
                'logo_url' => null,
                'manager_id' => null,
            ];
            if (isset($body['logo_url']) && $body['logo_url'] !== '') {
                $logo = validateString($body, 'logo_url', $errors, 2048);
                if (!filter_var($logo, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($logo, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    $errors['logo_url'] = 'Must be an absolute HTTP or HTTPS URL.';
                }
                $data['logo_url'] = $logo;
            }
            if ($user['role'] === 'club_manager') {
                if (isset($body['manager_id']) && validateInteger($body, 'manager_id', $errors, 1) !== (int) $user['id']) {
                    abortRequest('FORBIDDEN', 'Club managers cannot assign another manager.', 403);
                }
                $data['manager_id'] = (int) $user['id'];
            } elseif (isset($body['manager_id'])) {
                $data['manager_id'] = validateInteger($body, 'manager_id', $errors, 1);
            }
            if ($data['member_count'] > 4294967295) $errors['member_count'] = 'Member count is too large.';
            validationFailure($errors);
            if ($data['manager_id'] !== null) validateUserReference($data['manager_id'], 'manager_id', true);
            return $data;
        case 'schedules':
            validateFields($body, ['sport', 'event_name', 'event_date', 'start_time', 'end_time', 'location', 'teams', 'status', 'description'], $errors);
            $data = [
                'sport' => validateString($body, 'sport', $errors, 80),
                'event_name' => validateString($body, 'event_name', $errors, 160),
                'event_date' => validateDate($body, 'event_date', $errors),
                'start_time' => validateTime($body, 'start_time', $errors),
                'end_time' => validateTime($body, 'end_time', $errors),
                'location' => validateString($body, 'location', $errors, 160),
                'teams' => validateString($body, 'teams', $errors, 255, false),
                'status' => validateEnum($body, 'status', $statuses, $errors),
                'description' => validateString($body, 'description', $errors, 5000, false),
            ];
            if ($data['end_time'] <= $data['start_time']) $errors['end_time'] = 'End time must be later than start time on the same day.';
            validationFailure($errors);
            return $data;
        case 'tournaments':
            validateFields($body, ['name', 'sport', 'start_date', 'end_date', 'format', 'max_teams', 'registered_teams', 'status', 'description'], $errors);
            $data = [
                'name' => validateString($body, 'name', $errors, 160),
                'sport' => validateString($body, 'sport', $errors, 80),
                'start_date' => validateDate($body, 'start_date', $errors),
                'end_date' => validateDate($body, 'end_date', $errors),
                'format' => validateString($body, 'format', $errors, 80),
                'max_teams' => validateInteger($body, 'max_teams', $errors, 1),
                'registered_teams' => validateInteger($body, 'registered_teams', $errors, 0, 0),
                'status' => validateEnum($body, 'status', $statuses, $errors, 'registration_open'),
                'description' => validateString($body, 'description', $errors, 5000, false),
            ];
            if ($data['end_date'] < $data['start_date']) $errors['end_date'] = 'End date must be on or after start date.';
            if ($data['registered_teams'] > $data['max_teams']) $errors['registered_teams'] = 'Registered teams cannot exceed the maximum teams.';
            foreach (['max_teams', 'registered_teams'] as $field) if ($data[$field] > 4294967295) $errors[$field] = 'Team count is too large.';
            validationFailure($errors);
            return $data;
        case 'bookings':
            validateFields($body, ['facility', 'booked_by', 'sport', 'booking_date', 'start_time', 'end_time', 'status', 'notes'], $errors);
            $data = [
                'facility' => validateEnum($body, 'facility', facilityNames(), $errors),
                'booked_by' => (int) $user['id'],
                'sport' => validateString($body, 'sport', $errors, 80),
                'booking_date' => validateDate($body, 'booking_date', $errors),
                'start_time' => validateTime($body, 'start_time', $errors),
                'end_time' => validateTime($body, 'end_time', $errors),
                'status' => validateEnum($body, 'status', $statuses, $errors, 'pending'),
                'notes' => validateString($body, 'notes', $errors, 5000, false),
            ];
            if (isBookingAdmin($user)) {
                $data['booked_by'] = validateInteger($body, 'booked_by', $errors, 1, (int) $user['id']);
            } else {
                if (isset($body['booked_by']) && validateInteger($body, 'booked_by', $errors, 1) !== (int) $user['id']) {
                    abortRequest('FORBIDDEN', 'You cannot book on behalf of another user.', 403);
                }
                if (isset($body['status']) && $body['status'] !== 'pending') {
                    abortRequest('FORBIDDEN', 'New bookings must be pending.', 403);
                }
                $data['status'] = 'pending';
            }
            if ($data['end_time'] <= $data['start_time']) $errors['end_time'] = 'End time must be later than start time on the same day.';
            validationFailure($errors);
            validateUserReference($data['booked_by'], 'booked_by');
            return $data;
    }
    throw new LogicException('Unknown resource.');
}

/** Transactions retry transient deadlocks without revealing database diagnostics. */
function resourceTransaction(callable $operation): mixed
{
    $pdo = db();
    for ($attempt = 0; ; $attempt++) {
        try {
            $pdo->beginTransaction();
            $result = $operation();
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error instanceof PDOException) {
                $driverCode = (int) ($error->errorInfo[1] ?? 0);
                if (in_array($driverCode, [1205, 1213], true)) {
                    if ($attempt < 2) continue;
                    abortRequest('RESOURCE_BUSY', 'The resource is busy. Please retry your request.', 409);
                }
                if ($driverCode === 1062) abortRequest('CONFLICT', 'A resource with these unique details already exists.', 409);
                if ($driverCode === 1451) abortRequest('RESOURCE_IN_USE', 'This resource is referenced by another record.', 409);
                if ($driverCode === 1452) abortRequest('VALIDATION_ERROR', 'A referenced resource does not exist.', 422);
            }
            throw $error;
        }
    }
}

function insertResource(string $resource, array $data): array
{
    resourceDefinition($resource);
    $columns = array_keys($data);
    $columnSql = '`' . implode('`, `', $columns) . '`';
    $parameters = ':' . implode(', :', $columns);
    $statement = db()->prepare("INSERT INTO `{$resource}` ({$columnSql}) VALUES ({$parameters})");
    $statement->execute($data);
    return resourceById($resource, (int) db()->lastInsertId());
}

function updateResource(string $resource, int $id, array $data): array
{
    resourceDefinition($resource);
    $assignments = array_map(static fn(string $column): string => "`{$column}` = :{$column}", array_keys($data));
    $statement = db()->prepare("UPDATE `{$resource}` SET " . implode(', ', $assignments) . ' WHERE id = :resourceId');
    $statement->execute($data + ['resourceId' => $id]);
    return resourceById($resource, $id);
}

function deleteResource(string $resource, int $id): array
{
    resourceDefinition($resource);
    $statement = db()->prepare("DELETE FROM `{$resource}` WHERE id = :id");
    $statement->execute(['id' => $id]);
    return ['deleted' => true];
}

function resourceMethodNotAllowed(array $methods): never
{
    header('Allow: ' . implode(', ', $methods));
    abortRequest('METHOD_NOT_ALLOWED', 'This HTTP method is not supported for this resource.', 405);
}

function handleResourceCollection(string $resource): never
{
    $method = requestMethod();
    if ($method === 'GET') {
        $user = requirePermission($resource . '.read');
        jsonResponse(listResources($resource, $user));
    }
    if ($method === 'POST') {
        $user = requirePermission($resource . '.create');
        $data = validateResourceData($resource, jsonBody(), $user);
        $record = resourceTransaction(static fn(): array => insertResource($resource, $data));
        jsonResponse($record, 201);
    }
    resourceMethodNotAllowed(['GET', 'POST', 'OPTIONS']);
}

function handleResourceItem(string $resource): never
{
    $method = requestMethod();
    $id = routeId();
    if ($method === 'GET') {
        requirePermission($resource . '.read');
        jsonResponse(resourceById($resource, $id));
    }
    if ($method === 'PUT' || $method === 'DELETE') {
        $user = requirePermission($resource . ($method === 'PUT' ? '.update' : '.delete'));
        $body = $method === 'PUT' ? jsonBody() : [];
        $record = resourceTransaction(static function () use ($resource, $id, $method, $user, $body): array {
            $current = resourceById($resource, $id, true);
            if ($resource === 'clubs') requireClubManager($current, $user);
            if ($method === 'DELETE') return deleteResource($resource, $id);
            $data = validateResourceData($resource, $body, $user);
            // An omitted manager assignment on an update preserves the relationship.
            if ($resource === 'clubs' && !array_key_exists('manager_id', $body) && $user['role'] !== 'club_manager') $data['manager_id'] = $current['manager_id'];
            return updateResource($resource, $id, $data);
        });
        jsonResponse($record);
    }
    resourceMethodNotAllowed(['GET', 'PUT', 'DELETE', 'OPTIONS']);
}
