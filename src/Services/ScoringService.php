<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Computes the score of a submitted prediction form against actual results.
 *
 * Knockout semantics — important:
 *   - "Round of 32" = the 32 countries that *reach* the round of 32. These are
 *     derived from the user's predicted group standings (top 2 of each group
 *     + the 8 best third-placed teams per FIFA tiebreakers). The wizard does
 *     NOT ask 32 explicit picks here.
 *   - "Round of 16" = the 16 countries that advance from R32. Those are exactly
 *     the wizard's R32 picks (one winner per R32 match).
 *   - The same shift applies up the bracket:
 *       wizard "R32" picks  ->  R16 reached
 *       wizard "R16" picks  ->  QF  reached
 *       wizard "QF"  picks  ->  SF  reached
 *       wizard "SF"  picks  ->  Final reached
 *       wizard "Final" pick ->  Champion (forms.winner_team_id)
 */
final class ScoringService
{
    private const STAGE_POINTS_REACHED = [
        'r32'   => 5,
        'r16'   => 10,
        'qf'    => 15,
        'sf'    => 25,
        'final' => 50,
    ];

    /** wizard-pick stage  ->  the round those picks predict reaching */
    private const PICK_TO_REACHED = [
        'r32' => 'r16',
        'r16' => 'qf',
        'qf'  => 'sf',
        'sf'  => 'final',
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

        // -------- Group-stage matches --------
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

        // -------- R32: derive 32 predicted teams from the user's groups --------
        $r32Predicted = self::derivePredictedR32($formId);
        $r32Actual    = self::actualStageTeams('r32');
        foreach ($r32Predicted as $teamId) {
            if (isset($r32Actual[$teamId])) {
                $breakdown['r32'] += self::STAGE_POINTS_REACHED['r32'];
            }
        }

        // -------- R16 / QF / SF / Final: shift wizard picks one round up --------
        foreach (self::PICK_TO_REACHED as $pickStage => $reached) {
            $actualSet = self::actualStageTeams($reached);
            $predicted = Database::fetchAll(
                'SELECT DISTINCT team_id FROM predictions
                  WHERE form_id = ? AND stage = ? AND team_id IS NOT NULL',
                [$formId, $pickStage]
            );
            foreach ($predicted as $pt) {
                if (isset($actualSet[(int) $pt['team_id']])) {
                    $breakdown[$reached] += self::STAGE_POINTS_REACHED[$reached];
                }
            }
        }

        // -------- Champion + topscorer --------
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

    /**
     * Derive the 32 teams the user predicted would reach the Round of 32, based
     * on their group-stage score predictions: top 2 of each group + the 8 best
     * third-placed teams (FIFA tiebreakers).
     *
     * @return list<int>  Unique team IDs.
     */
    public static function derivePredictedR32(int $formId): array
    {
        $resolved = PredictionResolver::resolve($formId);
        $bracket  = $resolved['bracket'] ?? [];

        $ids = [];
        foreach (['firsts', 'seconds', 'qualified_thirds'] as $group) {
            foreach (($bracket[$group] ?? []) as $row) {
                if (!empty($row['team_id'])) $ids[] = (int) $row['team_id'];
            }
        }
        return array_values(array_unique($ids));
    }

    /** @return array<int, true> Map of team_id => true for teams that appear in the given stage. */
    public static function actualStageTeams(string $stage): array
    {
        $rows = Database::fetchAll(
            'SELECT DISTINCT t.id FROM (
                 SELECT home_team_id AS id FROM matches WHERE stage = ? AND home_team_id IS NOT NULL
                 UNION
                 SELECT away_team_id AS id FROM matches WHERE stage = ? AND away_team_id IS NOT NULL
             ) t', [$stage, $stage]
        );
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = true;
        return $out;
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
