<?php
declare(strict_types=1);

namespace SportsHub\Services;

use SportsHub\Config\Database;
use SportsHub\Core\ApiException;
use SportsHub\Core\Auth;
use SportsHub\Core\Validator;
use SportsHub\Models\Records;

/** Capacity-sensitive membership changes always lock the club before its requests. */
final class MembershipService
{
    /** @return array{data: array, created: bool} */
    public static function apply(int $userId, array $body): array
    {
        $input = Validator::fields($body, ['clubId' => ['int', 1, 2147483647]]);
        return Database::transaction(function () use ($userId, $input): array {
            $club = Records::require('clubs', $input['clubId'], true);
            $existing = Database::one(
                'SELECT * FROM memberships WHERE user_id = ? AND club_id = ? FOR UPDATE',
                [$userId, $club['id']]
            );
            if ($existing && $existing['status'] !== 'rejected') {
                throw new ApiException('You already have an active application or membership for this club.', 409);
            }
            $created = $existing === null;
            if ($existing) {
                $id = (int) $existing['id'];
                // Preserve the unique user/club identity while starting a new review cycle.
                Database::execute(
                    "UPDATE memberships SET status = 'pending', created_at = UTC_TIMESTAMP(3) WHERE id = ?",
                    [$id]
                );
            } else {
                $id = Records::insert('memberships', [
                    'user_id' => $userId,
                    'club_id' => (int) $club['id'],
                    'status' => 'pending',
                ]);
            }
            Records::activity($userId, 'Membership requested', (string) $club['name']);
            return ['data' => Records::get('memberships', $id), 'created' => $created];
        });
    }

    public static function review(int $id, array $body): array
    {
        $admin = Auth::requireUser('admin');
        $status = Validator::status($body, ['approved', 'rejected']);
        // club_id cannot be changed through the API, so this read only selects the lock parent.
        $reference = Records::require('memberships', $id);
        return Database::transaction(function () use ($id, $reference, $status, $admin): array {
            $club = Records::require('clubs', (int) $reference['club_id'], true);
            $membership = Records::require('memberships', $id, true);
            if ($membership['status'] !== 'pending') {
                throw new ApiException('Only pending memberships can be reviewed.', 409);
            }
            if ($status === 'approved') {
                $count = Database::one(
                    "SELECT COUNT(*) AS total FROM memberships WHERE club_id = ? AND status = 'approved'",
                    [$club['id']]
                );
                if ((int) $count['total'] >= (int) $club['capacity']) {
                    throw new ApiException('This club is full.', 409);
                }
            }
            Database::execute('UPDATE memberships SET status = ? WHERE id = ?', [$status, $id]);
            Records::activity((int) $admin['id'], 'Membership ' . $status, (string) $club['name']);
            return Records::get('memberships', $id);
        });
    }
}
