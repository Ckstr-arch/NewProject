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

/** Club CRUD; capacity changes share the club lock used by membership approval. */
final class ClubController
{
    public function index(Request $request, array $params): void
    {
        Auth::requireUser();
        $request->noBody();
        Response::listing(Records::listing('clubs', $request->query));
    }

    public function show(Request $request, array $params): void
    {
        Auth::requireUser();
        $request->noBody();
        Response::ok(Records::get('clubs', $params['id']));
    }

    public function create(Request $request, array $params): void
    {
        $user = Auth::requireUser('admin');
        $body = Validator::resource('clubs', $request->body());
        $club = Database::transaction(function () use ($body, $user): array {
            $id = Records::insert('clubs', Records::sqlBody('clubs', $body));
            Records::activity($user['id'], 'Club created', $body['name']);
            return Records::get('clubs', $id);
        });
        Response::ok($club, 'Club created', 201);
    }

    public function update(Request $request, array $params): void
    {
        $user = Auth::requireUser('admin');
        $body = Validator::resource('clubs', $request->body());
        $id = $params['id'];
        $club = Database::transaction(function () use ($body, $id, $user): array {
            // Always lock the parent before counting memberships. Approvals take this same lock.
            Records::require('clubs', $id, true);
            $approved = Database::one(
                "SELECT COUNT(*) AS total FROM memberships WHERE club_id = ? AND status = 'approved'",
                [$id]
            );
            if ($body['capacity'] < (int)$approved['total']) {
                throw new ApiException('Capacity cannot be less than current approved membership.', 409);
            }
            Records::update('clubs', $id, Records::sqlBody('clubs', $body));
            Records::activity($user['id'], 'Club updated', $body['name']);
            return Records::get('clubs', $id);
        });
        Response::ok($club, 'Club updated');
    }

    public function delete(Request $request, array $params): void
    {
        $user = Auth::requireUser('admin');
        $request->noBody();
        $id = $params['id'];
        Database::transaction(function () use ($id, $user): void {
            $club = Records::require('clubs', $id, true);
            // RESTRICT foreign keys protect membership and schedule history. The central
            // exception handler translates dependency failures into a safe HTTP 409.
            Database::execute('DELETE FROM clubs WHERE id = ?', [$id]);
            Records::activity($user['id'], 'Club deleted', $club['name']);
        });
        Response::noContent();
    }
}
