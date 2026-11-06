<?php
declare(strict_types=1);

namespace SportsHub\Controllers;

use SportsHub\Config\Database;
use SportsHub\Core\Auth;
use SportsHub\Core\Request;
use SportsHub\Core\Response;
use SportsHub\Core\Validator;
use SportsHub\Models\Records;

/** Fixtures/results use the existing contract; there is intentionally no delete endpoint. */
final class MatchController
{
    public function index(Request $request, array $params): void
    {
        Auth::requireUser();
        $request->noBody();
        Response::listing(Records::listing('matches', $request->query));
    }

    public function create(Request $request, array $params): void
    {
        $user = Auth::requireUser('admin');
        $body = Validator::resource('matches', $request->body());
        $match = Database::transaction(function () use ($body, $user): array {
            Records::require('tournaments', $body['tournamentId'], true);
            $id = Records::insert('matches', Records::sqlBody('matches', $body));
            Records::activity($user['id'], 'Match created', $body['homeTeam'].' vs '.$body['awayTeam']);
            return Records::get('matches', $id);
        });
        Response::ok($match, 'Match created', 201);
    }

    public function update(Request $request, array $params): void
    {
        $user = Auth::requireUser('admin');
        $body = Validator::resource('matches', $request->body());
        $id = $params['id'];
        $match = Database::transaction(function () use ($body, $id, $user): array {
            Records::require('tournaments', $body['tournamentId'], true);
            Records::require('matches', $id, true);
            Records::update('matches', $id, Records::sqlBody('matches', $body));
            Records::activity($user['id'], 'Match updated', $body['homeTeam'].' vs '.$body['awayTeam']);
            return Records::get('matches', $id);
        });
        Response::ok($match, 'Match updated');
    }
}
