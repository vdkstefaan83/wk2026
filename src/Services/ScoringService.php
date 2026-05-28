<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Computes the score of a submitted prediction form against actual results.
 *
 * Rules:
 *  - Topscorer: 10 points if correct player, +3 per goal the *predicted* player scores.
 *  - Group stage match: 1 point for correct outcome (1/X/2), +2 extra for exact score.
 *  - Round of 32: 5 points per correct team that reaches R32.
 *  - Round of 16: 10 points per correct team that reaches R16.
 *  - Quarter-final: 15 points per correct team.
 *  - Semi-final: 25 points per correct team.
 *  - Final: 50 points per correct team (i.e. correct finalists).
 *  - Winner: 100 points.
 */
final class ScoringService
{
    private const STAGE_POINTS = [
        'r32'   => 5,
        'r16'   => 10,
        'qf'    => 15,
        'sf'    => 25,
        'final' => 50,
    ];

    public static function score(int $formId): array
    {
        $breakdown = [
            'group_matches' => 0,
            'r32'           => 0,
            'r16'           => 0,
            'qf'            => 0,
            'sf'            => 0,
            'final'         => 0,
            'winner'        => 0,
            'topscorer'     => 0,
            'total'         => 0,
        ];

        // Group-stage matches
        $rows = Database::fetchAll(
            'SELECT p.home_goals AS p_h, p.away_goals AS p_a,
                    m.actual_home_goals AS a_h, m.actual_away_goals AS a_a
             FROM predictions p
             JOIN matches m ON m.id = p.match_id
             WHERE p.form_id = ? AND p.stage = "group"
               AND m.actual_home_goals IS NOT NULL AND m.actual_away_goals IS NOT NULL
               AND p.home_goals IS NOT NULL AND p.away_goals IS NOT NULL', [$formId]
        );
        foreach ($rows as $r) {
            $predRes   = self::outcome((int)$r['p_h'], (int)$r['p_a']);
            $actualRes = self::outcome((int)$r['a_h'], (int)$r['a_a']);
            if ($predRes === $actualRes) {
                $breakdown['group_matches'] += 1;
                if ((int)$r['p_h'] === (int)$r['a_h'] && (int)$r['p_a'] === (int)$r['a_a']) {
                    $breakdown['group_matches'] += 2;
                }
            }
        }

        // Knockout: count correct *teams* that actually appear in each stage.
        // Actual teams per stage = teams that appear as home/away in any played match of that stage.
        foreach (self::STAGE_POINTS as $stage => $pts) {
            $actualTeams = Database::fetchAll(
                'SELECT DISTINCT t.id FROM (
                     SELECT home_team_id AS id FROM matches WHERE stage = ? AND home_team_id IS NOT NULL
                     UNION
                     SELECT away_team_id AS id FROM matches WHERE stage = ? AND away_team_id IS NOT NULL
                 ) t', [$stage, $stage]
            );
            $actualSet = array_flip(array_map(fn($x) => (int)$x['id'], $actualTeams));

            $predictedTeams = Database::fetchAll(
                'SELECT DISTINCT team_id FROM predictions
                  WHERE form_id = ? AND stage = ? AND team_id IS NOT NULL',
                [$formId, $stage]
            );
            foreach ($predictedTeams as $pt) {
                if (isset($actualSet[(int)$pt['team_id']])) {
                    $breakdown[$stage] += $pts;
                }
            }
        }

        // Winner
        $form = Database::fetch('SELECT winner_team_id, topscorer_player_id FROM forms WHERE id = ?', [$formId]);
        if ($form) {
            $actualWinner = (int) (Database::fetchColumn(
                'SELECT CASE WHEN actual_home_goals > actual_away_goals THEN home_team_id
                             WHEN actual_away_goals > actual_home_goals THEN away_team_id
                             ELSE NULL END
                   FROM matches WHERE stage = "final" AND actual_home_goals IS NOT NULL
                   ORDER BY match_number ASC LIMIT 1'
            ) ?: 0);
            if ($form['winner_team_id'] && $actualWinner && (int)$form['winner_team_id'] === $actualWinner) {
                $breakdown['winner'] = 100;
            }

            // Topscorer
            if ($form['topscorer_player_id']) {
                $actualTopscorerId = (int) \App\Core\Setting::get('actual_topscorer_player_id', 0);
                $predictedPlayerGoals = (int) \App\Core\Setting::get('predicted_topscorer_goals_for_' . (int)$form['topscorer_player_id'], 0);
                if ($actualTopscorerId && $actualTopscorerId === (int)$form['topscorer_player_id']) {
                    $breakdown['topscorer'] += 10;
                }
                $breakdown['topscorer'] += 3 * $predictedPlayerGoals;
            }
        }

        $breakdown['total'] = array_sum(array_filter($breakdown, fn($v) => is_int($v)));
        return $breakdown;
    }

    private static function outcome(int $home, int $away): string
    {
        return $home > $away ? '1' : ($home < $away ? '2' : 'X');
    }

    public static function recomputeAll(): void
    {
        $ids = Database::fetchAll('SELECT id FROM forms WHERE status = "submitted"');
        foreach ($ids as $r) {
            $b = self::score((int) $r['id']);
            Database::update('forms', ['score' => $b['total']], ['id' => (int) $r['id']]);
        }
    }
}
