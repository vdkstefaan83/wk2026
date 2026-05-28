<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Resolves the personal bracket of a single form:
 *  - Computes the user's predicted group standings from their group-stage scores.
 *  - Builds their predicted R32 bracket via KnockoutBracketService.
 *  - Walks downstream (R16→Final) using the user's per-slot picks from `predictions`.
 *
 * Pure: does not write to the database. Used to render the wizard live.
 */
final class PredictionResolver
{
    public static function resolve(int $formId): array
    {
        $groups = Database::fetchAll('SELECT * FROM team_groups ORDER BY sort_order');
        $standings = [];
        foreach ($groups as $g) {
            $teams = Database::fetchAll('SELECT id, name, iso3, flag_emoji FROM teams WHERE group_id = ?', [$g['id']]);
            $matches = Database::fetchAll(
                'SELECT m.id, m.home_team_id, m.away_team_id,
                        p.home_goals, p.away_goals
                   FROM matches m
              LEFT JOIN predictions p ON p.match_id = m.id AND p.form_id = ? AND p.stage = "group"
                  WHERE m.stage = "group" AND m.group_id = ?
               ORDER BY m.match_number', [$formId, $g['id']]
            );
            $standings[$g['code']] = FifaRankingService::rank($teams, $matches);
        }

        $bracket = KnockoutBracketService::build($standings);
        $downstream = KnockoutBracketService::downstream();

        // Overlay user picks for downstream slots
        $picks = Database::fetchAll(
            'SELECT slot_code, team_id FROM predictions WHERE form_id = ? AND team_id IS NOT NULL AND slot_code <> ""',
            [$formId]
        );
        $pickMap = [];
        foreach ($picks as $p) {
            $pickMap[$p['slot_code']] = (int) $p['team_id'];
        }

        return [
            'standings' => $standings,
            'bracket'   => $bracket,
            'downstream'=> $downstream,
            'picks'     => $pickMap,
        ];
    }
}
