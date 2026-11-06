<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../core/resources.php';
require_once __DIR__ . '/resource.php';

function handleBookingsCollection(): never
{
    $method = requestMethod();
    if ($method === 'GET') {
        $user = requirePermission('bookings.read');
        jsonResponse(listResources('bookings', $user));
    }
    if ($method === 'POST') {
        $user = requirePermission('bookings.create');
        $data = validateResourceData('bookings', jsonBody(), $user);
        $record = resourceTransaction(static function () use ($data): array {
            lockBookingFacilities([$data['facility']]);
            checkBookingConflict($data);
            return insertResource('bookings', $data);
        });
        jsonResponse($record, 201);
    }
    resourceMethodNotAllowed(['GET', 'POST', 'OPTIONS']);
}
