<?php
declare(strict_types=1);

namespace SportsHub\Services;

use SportsHub\Config\Database;
use SportsHub\Models\Records;

/** Generic league points: win 3, draw 1, loss 0; no sport-specific tie breakers. */
final class StandingsService
{
    public static function calculate(int $tournamentId): array
    {
        Records::require('tournaments', $tournamentId);
        $matches = Database::all(
            'SELECT home_team, away_team, home_score, away_score, status FROM matches WHERE tournament_id = ? ORDER BY id',
            [$tournamentId]
        );
        $teams = [];
        foreach ($matches as $match) {
            foreach (['home_team', 'away_team'] as $side) {
                $name = (string) $match[$side];
                // Prefix keys so numeric-looking team names remain strings in output.
                $key = 'team:' . $name;
                if (!isset($teams[$key])) {
                    $teams[$key] = ['team' => $name, 'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'points' => 0];
                }
            }
            if ($match['status'] !== 'completed') {
                continue;
            }
            $home = 'team:' . $match['home_team'];
            $away = 'team:' . $match['away_team'];
            $teams[$home]['played']++;
            $teams[$away]['played']++;
            if ((int) $match['home_score'] === (int) $match['away_score']) {
                $teams[$home]['drawn']++;
                $teams[$away]['drawn']++;
                $teams[$home]['points']++;
                $teams[$away]['points']++;
            } else {
                $winner = (int) $match['home_score'] > (int) $match['away_score'] ? $home : $away;
                $loser = $winner === $home ? $away : $home;
                $teams[$winner]['won']++;
                $teams[$winner]['points'] += 3;
                $teams[$loser]['lost']++;
            }
        }
        $rows = array_values($teams);
        usort($rows, static fn (array $a, array $b): int => ($b['points'] <=> $a['points']) ?: strcasecmp($a['team'], $b['team']) ?: strcmp($a['team'], $b['team']));
        return $rows;
    }
}
