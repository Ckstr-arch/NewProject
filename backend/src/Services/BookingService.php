<?php
declare(strict_types=1);

namespace SportsHub\Services;

use SportsHub\Config\Database;
use SportsHub\Core\ApiException;
use SportsHub\Core\Validator;
use SportsHub\Models\Records;

/** All booking writers lock the resource first, then the particular booking. */
final class BookingService
{
    public static function create(int $userId, array $body): array
    {
        $input = Validator::resource('bookings', $body);
        return Database::transaction(function () use ($userId, $input): array {
            $facility = Records::require('facilities', $input['facilityId'], true);
            self::assertFuture($input['startsAt']);
            self::assertAvailable((int) $facility['id'], $input['startsAt'], $input['endsAt']);
            $data = Records::sqlBody('bookings', $input);
            $data['user_id'] = $userId;
            $data['status'] = 'pending';
            $id = Records::insert('bookings', $data);
            Records::activity($userId, 'Booking requested', (string) $facility['name']);
            return Records::get('bookings', $id);
        });
    }

    public static function change(array $user, int $id, array $body): array
    {
        $admin = $user['role'] === 'admin';
        if (!$admin && isset($body['status']) && in_array($body['status'], ['approved', 'rejected'], true)) {
            throw new ApiException('Only administrators may review bookings.', 403);
        }
        $status = Validator::status($body, $admin ? ['approved', 'rejected', 'cancelled'] : ['cancelled']);
        // facility_id is immutable through this API; re-read the booking after locking its resource.
        $reference = Records::require('bookings', $id);
        if (!$admin && (int) $reference['user_id'] !== (int) $user['id']) {
            throw new ApiException('You can only cancel your own booking.', 403);
        }
        return Database::transaction(function () use ($user, $id, $status, $admin, $reference): array {
            $facility = Records::require('facilities', (int) $reference['facility_id'], true);
            $booking = Records::require('bookings', $id, true);
            if (!$admin && (int) $booking['user_id'] !== (int) $user['id']) {
                throw new ApiException('You can only cancel your own booking.', 403);
            }
            if (!in_array($booking['status'], ['pending', 'approved'], true)) {
                throw new ApiException('This booking can no longer be changed.', 409);
            }
            if (!$admin) {
                self::assertFuture((string) $booking['starts_at'], 409);
            }
            if ($status === 'approved') {
                self::assertAvailable((int) $facility['id'], (string) $booking['starts_at'], (string) $booking['ends_at'], $id);
            }
            Database::execute('UPDATE bookings SET status = ? WHERE id = ?', [$status, $id]);
            Records::activity((int) $user['id'], 'Booking ' . $status, (string) $facility['name']);
            return Records::get('bookings', $id);
        });
    }

    private static function assertFuture(string $start, int $status = 422): void
    {
        $date = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
        if ($date <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new ApiException('Choose a future booking start time.', $status, ['startsAt' => ['The booking must start in the future.']]);
        }
    }

    /** Caller holds the resource parent lock, which also covers currently empty time ranges. */
    private static function assertAvailable(int $facilityId, string $start, string $end, ?int $exceptId = null): void
    {
        $sql = "SELECT id FROM bookings WHERE facility_id = ? AND status IN ('pending', 'approved') AND starts_at < ? AND ends_at > ?";
        $params = [$facilityId, $end, $start];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        if (Database::one($sql, $params)) {
            throw new ApiException('This resource is already reserved during that time.', 409, ['startsAt' => ['Choose another time or resource.']]);
        }
    }
}
