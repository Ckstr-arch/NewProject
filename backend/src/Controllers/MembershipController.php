<?php
declare(strict_types=1);

namespace SportsHub\Controllers;

use SportsHub\Core\Auth;
use SportsHub\Core\Request;
use SportsHub\Core\Response;
use SportsHub\Core\Validator;
use SportsHub\Models\Records;
use SportsHub\Services\MembershipService;

final class MembershipController
{
    public function index(Request $request, array $params): void
    {
        Auth::requireUser('admin');
        $request->noBody();
        Response::listing(Records::listing('memberships', $request->query));
    }

    public function create(Request $request, array $params): void
    {
        $user = Auth::requireUser('student');
        $result = MembershipService::apply((int) $user['id'], $request->body());
        Response::ok($result['data'], 'Membership request sent', $result['created'] ? 201 : 200);
    }

    public function status(Request $request, array $params): void
    {
        Auth::requireUser('admin');
        Response::ok(MembershipService::review(Validator::id($params['id']), $request->body()), 'Membership reviewed');
    }

    public function mine(Request $request, array $params): void
    {
        $user = Auth::requireUser();
        $request->noBody();
        Response::listing(Records::listing('memberships', $request->query, (int) $user['id']));
    }
}
