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

/** Identity always comes from the authenticated session, never a submitted user ID. */
final class UserController
{
    public function show(Request $request, array $params): void
    {
        $request->noBody();
        $this->noQuery($request);
        $user = Auth::requireUser();
        Response::ok($this->profile((int) $user['id']));
    }

    public function update(Request $request, array $params): void
    {
        $this->noQuery($request);
        $user = Auth::requireUser();
        $body = $request->body();
        if ($body === []) {
            throw new ApiException('Supply at least one editable profile field.', 422, [
                'name' => ['Supply name, faculty or notifications.'],
            ]);
        }
        $values = Validator::fields($body, [
            'name' => ['string', 2, 80],
            'faculty' => ['nullableString', 0, 100],
            'notifications' => ['bool'],
        ], true);
        $result = Database::transaction(function () use ($user, $values): array {
            Records::require('users', (int) $user['id'], true);
            $changes = $values;
            if (array_key_exists('notifications', $changes)) {
                $changes['notifications'] = $changes['notifications'] ? 1 : 0;
            }
            Records::update('users', (int) $user['id'], $changes);
            Records::activity((int) $user['id'], 'Profile updated', 'Personal profile preferences were updated.');
            return $this->profile((int) $user['id']);
        });
        Response::ok($result, 'Profile updated');
    }

    public function participation(Request $request, array $params): void
    {
        $request->noBody();
        $user = Auth::requireUser();
        Response::listing(Records::listing('participation', $request->query, (int) $user['id']));
    }

    private function profile(int $id): array
    {
        $row = Database::one(
            'SELECT id, name, email, role, student_id AS studentId, faculty, notifications
             FROM users WHERE id = :id',
            ['id' => $id]
        );
        if ($row === null) {
            throw new ApiException('Your account is no longer available. Please sign in again.', 401);
        }
        return Records::normalize($row);
    }

    private function noQuery(Request $request): void
    {
        if ($request->query !== []) {
            throw new ApiException('This endpoint does not accept query parameters.', 422);
        }
    }
}
