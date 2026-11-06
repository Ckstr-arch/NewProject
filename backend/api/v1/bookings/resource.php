<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../core/resources.php';

/** A real facility row exists even when no bookings exist: this closes the empty-range race. */
function lockBookingFacilities(array $facilities): void
{
    $facilities = array_values(array_unique($facilities));
    sort($facilities, SORT_STRING);
    $statement = db()->prepare('SELECT name FROM facilities WHERE name = :facility FOR UPDATE');
    foreach ($facilities as $facility) {
        $statement->execute(['facility' => $facility]);
        if ($statement->fetchColumn() === false) abortRequest('VALIDATION_ERROR', 'Validation failed.', 422, ['facility' => 'Unknown facility.']);
    }
}

/** Call only while holding the corresponding facility lock in a transaction. */
function checkBookingConflict(array $data, int $excludeId = 0): void
{
    if ($data['status'] === 'cancelled') return;
    $statement = db()->prepare(
        'SELECT id FROM bookings WHERE facility = :facility AND booking_date = :bookingDate '
        . "AND status <> 'cancelled' AND start_time < :requestedEnd AND end_time > :requestedStart AND id <> :excludeId LIMIT 1 FOR UPDATE"
    );
    $statement->execute([
        'facility' => $data['facility'], 'bookingDate' => $data['booking_date'],
        'requestedEnd' => $data['end_time'], 'requestedStart' => $data['start_time'], 'excludeId' => $excludeId,
    ]);
    if ($statement->fetchColumn() !== false) {
        abortRequest('BOOKING_CONFLICT', 'The selected facility is already booked during this time.', 409, [
            'facility' => $data['facility'], 'date' => $data['booking_date'],
        ]);
    }
}

function handleBookingResource(): never
{
    $method = requestMethod();
    $id = routeId();
    if ($method === 'GET') {
        $user = requirePermission('bookings.read');
        $record = resourceById('bookings', $id);
        requireBookingOwner($record, $user);
        jsonResponse($record);
    }
    if ($method === 'PUT') {
        $user = requireAuth();
        $body = jsonBody();
        if (isBookingAdmin($user)) requirePermission('bookings.update');
        else requirePermission('bookings.cancel_own');
        $record = resourceTransaction(static function () use ($id, $user, $body): array {
            $current = resourceById('bookings', $id, true);
            requireBookingOwner($current, $user);
            if (!isBookingAdmin($user)) {
                if (count($body) !== 1 || ($body['status'] ?? null) !== 'cancelled') {
                    abortRequest('FORBIDDEN', 'You may only cancel your own pending or confirmed booking.', 403);
                }
                if (!in_array($current['status'], ['pending', 'confirmed'], true)) {
                    abortRequest('INVALID_BOOKING_TRANSITION', 'Only pending or confirmed bookings can be cancelled.', 409);
                }
                lockBookingFacilities([$current['facility']]);
                return updateResource('bookings', $id, ['status' => 'cancelled']);
            }
            $allowed = ['facility', 'booked_by', 'sport', 'booking_date', 'start_time', 'end_time', 'status', 'notes'];
            $errors = [];
            validateFields($body, $allowed, $errors);
            if ($body === []) $errors['body'] = 'At least one editable field is required.';
            validationFailure($errors);
            $editableCurrent = array_intersect_key($current, array_flip($allowed));
            $data = validateResourceData('bookings', array_replace($editableCurrent, $body), $user);
            // Lock old and new facilities in a stable order when moving a booking.
            lockBookingFacilities([$current['facility'], $data['facility']]);
            checkBookingConflict($data, $id);
            return updateResource('bookings', $id, $data);
        });
        jsonResponse($record);
    }
    if ($method === 'DELETE') {
        requirePermission('bookings.delete');
        $deleted = resourceTransaction(static function () use ($id): array {
            $record = resourceById('bookings', $id, true);
            lockBookingFacilities([$record['facility']]);
            return deleteResource('bookings', $id);
        });
        jsonResponse($deleted);
    }
    resourceMethodNotAllowed(['GET', 'PUT', 'DELETE', 'OPTIONS']);
}
