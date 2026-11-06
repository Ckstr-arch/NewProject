<?php
declare(strict_types=1);

namespace SportsHub\Services;

use SportsHub\Config\Database;
use SportsHub\Core\ApiException;
use SportsHub\Models\Records;

/** A tournament parent lock serializes registration and capacity changes. */
final class TournamentService
{
    public static function register(int $userId, int $tournamentId): array
    {
        return Database::transaction(function () use ($userId, $tournamentId): array {
            $tournament = Records::require('tournaments', $tournamentId, true);
            $duplicate = Database::one(
                'SELECT id FROM registrations WHERE user_id = ? AND tournament_id = ?',
                [$userId, $tournamentId]
            );
            if ($duplicate) {
                throw new ApiException('You are already registered for this tournament.', 409);
            }
            // Check the deadline after obtaining the lock, not before waiting for another writer.
            $deadline = new \DateTimeImmutable((string) $tournament['registration_deadline'], new \DateTimeZone('UTC'));
            if ($deadline <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                throw new ApiException('Registration has closed.', 409);
            }
            $count = Database::one('SELECT COUNT(*) AS total FROM registrations WHERE tournament_id = ?', [$tournamentId]);
            if ((int) $count['total'] >= (int) $tournament['capacity']) {
                throw new ApiException('This tournament is full.', 409);
            }
            $id = Records::insert('registrations', ['user_id' => $userId, 'tournament_id' => $tournamentId]);
            Records::activity($userId, 'Tournament registration', (string) $tournament['name']);
            return Records::get('registrations', $id);
        });
    }
}
