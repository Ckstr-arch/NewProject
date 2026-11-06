<?php
declare(strict_types=1);

namespace SportsHub\Controllers;

use SportsHub\Core\Auth;
use SportsHub\Core\ListQuery;
use SportsHub\Core\Request;
use SportsHub\Core\Response;

/** Admin-only aggregate queries; never return private records to calculate counts in JavaScript. */
final class ReportController
{
    public function memberships(Request $request, array $params): void
    {
        $request->noBody();
        Auth::requireUser('admin');
        $sql = 'SELECT c.name AS clubName,
                       COALESCE(SUM(m.status = \'pending\'), 0) AS pending,
                       COALESCE(SUM(m.status = \'approved\'), 0) AS approved,
                       COALESCE(SUM(m.status = \'rejected\'), 0) AS rejected
                FROM clubs c LEFT JOIN memberships m ON m.club_id = c.id
                GROUP BY c.id, c.name';
        Response::listing(ListQuery::run($sql, [], $request->query, [
            'clubName' => 'string', 'pending' => 'int', 'approved' => 'int', 'rejected' => 'int',
        ]));
    }

    public function participation(Request $request, array $params): void
    {
        $request->noBody();
        Auth::requireUser('admin');
        // A registration is counted once, even if it has linked participation evidence.
        // Unlinked registered participation is permitted for historical event records.
        $sql = 'SELECT sports.sport,
                       COALESCE(attendance.attended, 0) AS attended,
                       COALESCE(enrollment.registered, 0) AS registered
                FROM (
                    SELECT sport FROM participation
                    UNION SELECT sport FROM tournaments
                ) sports
                LEFT JOIN (
                    SELECT sport, COUNT(*) AS attended FROM participation
                    WHERE status = \'attended\' GROUP BY sport
                ) attendance ON attendance.sport = sports.sport
                LEFT JOIN (
                    SELECT records.sport, COUNT(*) AS registered FROM (
                        SELECT t.sport FROM registrations r
                        INNER JOIN tournaments t ON t.id = r.tournament_id
                        UNION ALL
                        SELECT p.sport FROM participation p
                        WHERE p.status = \'registered\' AND p.registration_id IS NULL
                    ) records GROUP BY records.sport
                ) enrollment ON enrollment.sport = sports.sport';
        Response::listing(ListQuery::run($sql, [], $request->query, [
            'sport' => 'string', 'attended' => 'int', 'registered' => 'int',
        ], ['sport' => null]));
    }

    public function tournaments(Request $request, array $params): void
    {
        $request->noBody();
        Auth::requireUser('admin');
        $sql = 'SELECT t.name, COUNT(r.id) AS registrations, t.capacity
                FROM tournaments t LEFT JOIN registrations r ON r.tournament_id = t.id
                GROUP BY t.id, t.name, t.capacity';
        Response::listing(ListQuery::run($sql, [], $request->query, [
            'name' => 'string', 'registrations' => 'int', 'capacity' => 'int',
        ]));
    }

    public function bookings(Request $request, array $params): void
    {
        $request->noBody();
        Auth::requireUser('admin');
        $sql = 'SELECT f.name AS facilityName,
                       COALESCE(SUM(b.status = \'pending\'), 0) AS pending,
                       COALESCE(SUM(b.status = \'approved\'), 0) AS approved,
                       COALESCE(SUM(b.status = \'rejected\'), 0) AS rejected,
                       COALESCE(SUM(b.status = \'cancelled\'), 0) AS cancelled
                FROM facilities f LEFT JOIN bookings b ON b.facility_id = f.id
                GROUP BY f.id, f.name';
        Response::listing(ListQuery::run($sql, [], $request->query, [
            'facilityName' => 'string', 'pending' => 'int', 'approved' => 'int',
            'rejected' => 'int', 'cancelled' => 'int',
        ]));
    }
}
