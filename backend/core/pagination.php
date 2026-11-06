<?php
declare(strict_types=1);

function paginationInput(array $query): array
{
    $errors = [];
    $page = validateInteger($query, 'page', $errors, 1, 1);
    $limit = validateInteger($query, 'limit', $errors, 1, 10);
    validationFailure($errors);
    $limit = min(100, $limit);
    // Avoid integer overflow in the offset calculation, including 32-bit PHP hosts.
    if ($page > intdiv(PHP_INT_MAX, $limit)) abortRequest('VALIDATION_ERROR', 'Validation failed.', 422, ['page' => 'Page is too large.']);
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
}

/** Only resource definitions supply SQL identifiers. All client values are bound. */
function listResources(string $resource, array $user): array
{
    $definition = resourceDefinition($resource);
    $query = queryParams();
    $paging = paginationInput($query);
    $allowedQuery = array_merge(['page', 'limit', 'search', 'sort', 'order'], array_keys($definition['filters']));
    $errors = [];
    validateFields($query, $allowedQuery, $errors);
    $sort = $query['sort'] ?? $definition['defaultSort'];
    if (!is_string($sort) || !in_array($sort, $definition['sorts'], true)) $errors['sort'] = 'Unknown sort field.';
    $order = $query['order'] ?? 'asc';
    if (!is_string($order) || !in_array(strtolower($order), ['asc', 'desc'], true)) $errors['order'] = 'Must be asc or desc.';
    $where = [];
    $bindings = [];
    if (array_key_exists('search', $query)) {
        $search = validateString($query, 'search', $errors, 200, false);
        if ($search !== '') {
            $parts = [];
            foreach ($definition['search'] as $i => $column) {
                $parts[] = "LOWER(`{$column}`) LIKE LOWER(:search{$i})";
                $bindings['search' . $i] = '%' . $search . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
    }
    foreach ($definition['filters'] as $parameter => $column) {
        if (!array_key_exists($parameter, $query) || $query[$parameter] === '') continue;
        $value = validateString($query, $parameter, $errors, 255);
        if ($parameter === 'date' && !validDate($value)) $errors[$parameter] = 'Must be a valid date in YYYY-MM-DD format.';
        if ($parameter === 'status' && !in_array($value, $definition['statuses'], true)) $errors[$parameter] = 'Invalid status.';
        if ($parameter === 'facility' && !in_array($value, facilityNames(), true)) $errors[$parameter] = 'Invalid facility.';
        if ($resource === 'tournaments' && $parameter === 'date') {
            $where[] = '`start_date` <= :dateEnd AND `end_date` >= :dateStart';
            $bindings['dateStart'] = $value;
            $bindings['dateEnd'] = $value;
        } else {
            $where[] = "`{$column}` = :filter_{$parameter}";
            $bindings['filter_' . $parameter] = $value;
        }
    }
    if ($resource === 'bookings' && !isBookingAdmin($user)) {
        $where[] = '`booked_by` = :owner';
        $bindings['owner'] = (int) $user['id'];
    }
    validationFailure($errors);
    $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $pdo = db();
    $count = $pdo->prepare("SELECT COUNT(*) FROM `{$resource}`{$clause}");
    $count->execute($bindings);
    $total = (int) $count->fetchColumn();
    $order = strtoupper($order);
    $secondary = $sort === 'id' ? '' : ', `id` ASC';
    $statement = $pdo->prepare("SELECT * FROM `{$resource}`{$clause} ORDER BY `{$sort}` {$order}{$secondary} LIMIT :rowLimit OFFSET :rowOffset");
    foreach ($bindings as $name => $value) $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $statement->bindValue(':rowLimit', $paging['limit'], PDO::PARAM_INT);
    $statement->bindValue(':rowOffset', $paging['offset'], PDO::PARAM_INT);
    $statement->execute();
    return ['items' => array_map('resourceJson', $statement->fetchAll()), 'page' => $paging['page'], 'limit' => $paging['limit'], 'total' => $total, 'pages' => (int) ceil($total / $paging['limit'])];
}
