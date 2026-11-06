<?php
declare(strict_types=1);

namespace SportsHub\Controllers;

use SportsHub\Config\Database;
use SportsHub\Core\ApiException;
use SportsHub\Core\Auth;
use SportsHub\Core\Request;
use SportsHub\Core\Response;
use SportsHub\Models\Records;

/** Small, role-aware projections of the real operational records. */
final class DashboardController
{
    public function metrics(Request $request, array $params): void
    {
        $this->readOnlyRequest($request);
        $user = Auth::requireUser();
        $personal = $user['role'] === 'student';
        $membershipScope = $personal ? ' AND user_id = :membershipUserId' : '';
        $bookingScope = $personal ? ' AND user_id = :bookingUserId' : '';
        $values = $personal ? ['membershipUserId' => $user['id'], 'bookingUserId' => $user['id']] : [];
        $counts = Database::one(
            'SELECT (SELECT COUNT(*) FROM clubs) AS activeClubs,
                    (SELECT COUNT(*) FROM memberships WHERE status = \'approved\'' . $membershipScope . ') AS memberships,
                    (SELECT COUNT(*) FROM schedules WHERE starts_at > UTC_TIMESTAMP(3)) AS upcomingEvents,
                    (SELECT COUNT(*) FROM bookings WHERE status = \'pending\'' . $bookingScope . ') AS pendingBookings',
            $values
        );
        Response::ok([
            'activeClubs' => (int) $counts['activeClubs'],
            'memberships' => (int) $counts['memberships'],
            'upcomingEvents' => (int) $counts['upcomingEvents'],
            'pendingBookings' => (int) $counts['pendingBookings'],
        ]);
    }

    public function activity(Request $request, array $params): void
    {
        $this->readOnlyRequest($request);
        $user = Auth::requireUser();
        $scope = $user['role'] === 'student' ? ' WHERE user_id = :userId OR user_id IS NULL' : '';
        $values = $user['role'] === 'student' ? ['userId' => $user['id']] : [];
        $rows = Database::all(
            'SELECT id, title, description, created_at AS createdAt FROM activity_logs' . $scope .
            ' ORDER BY created_at DESC, id DESC LIMIT 8',
            $values
        );
        Response::ok(array_map([Records::class, 'normalize'], $rows));
    }

    public function upcoming(Request $request, array $params): void
    {
        $this->readOnlyRequest($request);
        Auth::requireUser();
        // Filter before LIMIT; otherwise past rows could hide the next five events.
        $rows = Database::all(
            'SELECT s.id, s.club_id AS clubId, c.name AS clubName, c.sport,
                    s.title, s.type, s.starts_at AS startsAt, s.ends_at AS endsAt, s.location
             FROM schedules s INNER JOIN clubs c ON c.id = s.club_id
             WHERE s.starts_at > UTC_TIMESTAMP(3)
             ORDER BY s.starts_at ASC, s.id ASC LIMIT 5'
        );
        Response::ok(array_map([Records::class, 'normalize'], $rows));
    }

    private function readOnlyRequest(Request $request): void
    {
        $request->noBody();
        if ($request->query !== []) {
            throw new ApiException('This endpoint does not accept query parameters.', 422);
        }
    }
}
