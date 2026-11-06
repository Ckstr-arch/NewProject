<?php
declare(strict_types=1);

namespace SportsHub\Controllers;

use SportsHub\Config\Database;
use SportsHub\Core\ApiException;
use SportsHub\Core\Auth;
use SportsHub\Core\Request;
use SportsHub\Core\Response;
use SportsHub\Core\Validator;
use SportsHub\Models\Records;
use SportsHub\Services\StandingsService;
use SportsHub\Services\TournamentService;

/** Tournament management and the existing registration/standings endpoints. */
final class TournamentController
{
    public function index(Request $request, array $params): void
    {
        Auth::requireUser();
        $request->noBody();
        Response::listing(Records::listing('tournaments', $request->query));
    }

    public function show(Request $request, array $params): void
    {
        Auth::requireUser();
        $request->noBody();
        Response::ok(Records::get('tournaments', $params['id']));
    }

    public function create(Request $request, array $params): void
    {
        $user = Auth::requireUser('admin');
        $body = Validator::resource('tournaments', $request->body());
        $tournament = Database::transaction(function () use ($body, $user): array {
            $id = Records::insert('tournaments', Records::sqlBody('tournaments', $body));
            Records::activity($user['id'], 'Tournament created', $body['name']);
            return Records::get('tournaments', $id);
        });
        Response::ok($tournament, 'Tournament created', 201);
    }

    public function update(Request $request, array $params): void
    {
        $user = Auth::requireUser('admin');
        $body = Validator::resource('tournaments', $request->body());
        $id = $params['id'];
        $tournament = Database::transaction(function () use ($body, $id, $user): array {
            // Registration uses the same parent lock, preventing capacity changes from racing it.
            Records::require('tournaments', $id, true);
            $registered = Database::one('SELECT COUNT(*) AS total FROM registrations WHERE tournament_id = ?', [$id]);
            if ($body['capacity'] < (int)$registered['total']) {
                throw new ApiException('Capacity cannot be less than existing registrations.', 409);
            }
            Records::update('tournaments', $id, Records::sqlBody('tournaments', $body));
            Records::activity($user['id'], 'Tournament updated', $body['name']);
            return Records::get('tournaments', $id);
        });
        Response::ok($tournament, 'Tournament updated');
    }

    public function delete(Request $request, array $params): void
    {
        $user = Auth::requireUser('admin');
        $request->noBody();
        $id = $params['id'];
        Database::transaction(function () use ($id, $user): void {
            $tournament = Records::require('tournaments', $id, true);
            Database::execute('DELETE FROM tournaments WHERE id = ?', [$id]);
            Records::activity($user['id'], 'Tournament deleted', $tournament['name']);
        });
        Response::noContent();
    }

    public function register(Request $request, array $params): void
    {
        $user = Auth::requireUser('student');
        $request->noBody();
        $registration = TournamentService::register($user['id'], $params['id']);
        Response::ok($registration, 'Tournament registration confirmed', 201);
    }

    public function registrations(Request $request, array $params): void
    {
        $user = Auth::requireUser();
        $request->noBody();
        Response::listing(Records::listing('registrations', $request->query, $user['id']));
    }

    public function standings(Request $request, array $params): void
    {
        Auth::requireUser();
        $request->noBody();
        Response::ok(StandingsService::calculate($params['id']));
    }
}
