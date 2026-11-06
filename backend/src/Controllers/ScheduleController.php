<?php
declare(strict_types=1);

namespace SportsHub\Controllers;

use SportsHub\Config\Database;
use SportsHub\Core\Auth;
use SportsHub\Core\Request;
use SportsHub\Core\Response;
use SportsHub\Core\Validator;
use SportsHub\Models\Records;

/** Training, event and match schedules, with names/sport derived from the club. */
final class ScheduleController
{
    public function index(Request $request, array $params): void
    {
        Auth::requireUser();
        $request->noBody();
        Response::listing(Records::listing('schedules', $request->query));
    }

    public function create(Request $request, array $params): void
    {
        $user = Auth::requireUser('admin');
        $body = Validator::resource('schedules', $request->body());
        $schedule = Database::transaction(function () use ($body, $user): array {
            Records::require('clubs', $body['clubId'], true);
            $id = Records::insert('schedules', Records::sqlBody('schedules', $body));
            Records::activity($user['id'], 'Schedule created', $body['title']);
            return Records::get('schedules', $id);
        });
        Response::ok($schedule, 'Schedule created', 201);
    }

    public function update(Request $request, array $params): void
    {
        $user = Auth::requireUser('admin');
        $body = Validator::resource('schedules', $request->body());
        $id = $params['id'];
        $schedule = Database::transaction(function () use ($body, $id, $user): array {
            Records::require('clubs', $body['clubId'], true);
            Records::require('schedules', $id, true);
            Records::update('schedules', $id, Records::sqlBody('schedules', $body));
            Records::activity($user['id'], 'Schedule updated', $body['title']);
            return Records::get('schedules', $id);
        });
        Response::ok($schedule, 'Schedule updated');
    }

    public function delete(Request $request, array $params): void
    {
        $user = Auth::requireUser('admin');
        $request->noBody();
        $id = $params['id'];
        Database::transaction(function () use ($id, $user): void {
            $schedule = Records::require('schedules', $id, true);
            // Participation references use RESTRICT so deleting a schedule cannot erase history.
            Database::execute('DELETE FROM schedules WHERE id = ?', [$id]);
            Records::activity($user['id'], 'Schedule deleted', $schedule['title']);
        });
        Response::noContent();
    }
}
