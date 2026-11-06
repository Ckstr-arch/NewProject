<?php
declare(strict_types=1);

namespace SportsHub\Controllers;

use SportsHub\Core\Auth;
use SportsHub\Core\Request;
use SportsHub\Core\Response;
use SportsHub\Core\Validator;
use SportsHub\Models\Records;
use SportsHub\Services\BookingService;

final class BookingController
{
    public function facilities(Request $request, array $params): void
    {
        Auth::requireUser();
        $request->noBody();
        Response::listing(Records::listing('facilities', $request->query));
    }

    public function index(Request $request, array $params): void
    {
        Auth::requireUser('admin');
        $request->noBody();
        Response::listing(Records::listing('bookings', $request->query));
    }

    public function create(Request $request, array $params): void
    {
        $user = Auth::requireUser('student');
        Response::ok(BookingService::create((int) $user['id'], $request->body()), 'Booking request sent', 201);
    }

    public function status(Request $request, array $params): void
    {
        $user = Auth::requireUser();
        Response::ok(BookingService::change($user, Validator::id($params['id']), $request->body()), 'Booking updated');
    }

    public function mine(Request $request, array $params): void
    {
        $user = Auth::requireUser();
        $request->noBody();
        Response::listing(Records::listing('bookings', $request->query, (int) $user['id']));
    }
}
