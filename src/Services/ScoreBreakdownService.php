<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Setting;

/**
 * Builds the per-match / per-pick point breakdown that powers the public
 * "click a name on the leaderboard" detail view. Mirrors ScoringService's
 * point rules but exposes them in a presentation-friendly shape.
 */
final class ScoreBreakdownService
{
    private const STAGE_POINTS = [
        'r32'   => 5,
        'r16'   => 10,
        'qf'    => 15,
        'sf'    => 25,
        'final' => 50,
    ];

    /** Number of teams that ultimately appear in each knockout round. */
    private const STAGE_EXPECTED_TEAMS = [
        'r32'   => 32,
        'r16'   => 16,
        'qf'    => 8,
        'sf'    => 4,
        'final' => 2,
    ];

    /** wizard-pick stage  ->  the round those picks predict reaching */
    private const PICK_TO_REACHED = [
        'r32' => 'r16',
        'r16' => 'qf',
        'qf'  => 'sf',
        'sf'  => 'final',
    ];

    public static function forForm(int $formId): array
    {
        $form = Database::fetch(
            'SELECT f.*, u.name AS user_name
               FROM forms f
               JOIN users u ON u.id = f.user_id
              WHERE f.id = ?',
            [$formId]
        );
        if (!$form) {
            return [];
        }

        $group     = self::groupBreakdown($formId);
        $knockout  = self::knockoutBreakdown($formId);
        $winnerInf = self::winnerBreakdown($form);
        $topscorer = self::topscorerBreakdown($form);

        $totals = [
            'group_matches' => $group['total'],
            'r32'           => $knockout['r32']['total'],
            'r16'           => $knockout['r16']['total'],
            'qf'            => $knockout['qf']['total'],
            'sf'            => $knockout['sf']['total'],
            'final'         => $knockout['final']['total'],
            'winner'        => $winnerInf['points'],
            'topscorer'     => $topscorer['points'],
        ];
        $totals['total'] = array_sum($totals);

        return [
            'form'      => $form,
            'group'     => $group,
            'knockout'  => $knockout,
            'winner'    => $winnerInf,
            'topscorer' => $topscorer,
            'totals'    => $totals,
        ];
    }

    // ------------------------------------------------------------------

    private static function groupBreakdown(int $formId): array
    {
        $rows = Database::fetchAll(
            'SELECT m.id, m.match_number, m.kickoff_at,
                    g.code AS group_code,
                    h.name AS home_name,
                    a.name AS away_name,
                    m.actual_home_goals, m.actual_away_goals,
                    p.home_goals AS pred_home, p.away_goals AS pred_away
               FROM matches m
          LEFT JOIN team_groups g ON g.id = m.group_id
          LEFT JOIN teams h ON h.id = m.home_team_id
          LEFT JOIN teams a ON a.id = m.away_team_id
          LEFT JOIN predictions p ON p.match_id = m.id AND p.form_id = ? AND p.stage = "group"
              WHERE m.stage = "group"
           ORDER BY (m.kickoff_at IS NULL), m.kickoff_at, m.match_number',
            [$formId]
        );

        $total = 0;
        $matches = [];
        foreach ($rows as $r) {
            $points = 0;
            $status = 'pending';
            $exact  = false;
            $outcome= false;

            $hasActual = $r['actual_home_goals'] !== null && $r['actual_away_goals'] !== null;
            $hasPred   = $r['pred_home']        !== null && $r['pred_away']        !== null;

            if ($hasActual && $hasPred) {
                $aH = (int)$r['actual_home_goals']; $aA = (int)$r['actual_away_goals'];
                $pH = (int)$r['pred_home'];         $pA = (int)$r['pred_away'];
                if (self::outcome($pH, $pA) === self::outcome($aH, $aA)) {
                    $points  = 1;
                    $outcome = true;
                    if ($pH === $aH && $pA === $aA) {
                        $points += 2;
                        $exact   = true;
                    }
                }
                $status = $points > 0 ? 'correct' : 'wrong';
            } elseif (!$hasActual) {
                $status = 'pending';
            } else {
                $status = 'no_prediction';
            }
            $total += $points;
            $matches[] = [
                'group'      => $r['group_code'],
                'kickoff'    => $r['kickoff_at'],
                'date'       => $r['kickoff_at'] ? substr((string) $r['kickoff_at'], 0, 10) : null,
                'home_name'  => $r['home_name'],
                'away_name'  => $r['away_name'],
                'pred_home'  => $r['pred_home'],
                'pred_away'  => $r['pred_away'],
                'actual_home'=> $r['actual_home_goals'],
                'actual_away'=> $r['actual_away_goals'],
                'status'     => $status,
                'outcome'    => $outcome,
                'exact'      => $exact,
                'points'     => $points,
            ];
        }
        return ['total' => $total, 'matches' => $matches];
    }

    private static function knockoutBreakdown(int $formId): array
    {
        $out = [];

        // -------- R32: 32 teams derived from the user's group standings --------
        $r32Actual = self::actualStageTeams('r32');
        $r32Decided = count($r32Actual) >= self::STAGE_EXPECTED_TEAMS['r32'];

        $resolved = PredictionResolver::resolve($formId);
        $bracket  = $resolved['bracket'] ?? [];
        $teamsById = self::teamsById();

        $r32Items = [];
        $r32Total = 0;
        foreach ([
            ['firsts',           '1st'],
            ['seconds',          '2nd'],
            ['qualified_thirds', '3rd'],
        ] as [$key, $label]) {
            foreach (($bracket[$key] ?? []) as $row) {
                $teamId = (int) ($row['team_id'] ?? 0);
                if (!$teamId) continue;
                $hit = isset($r32Actual[$teamId]);
                if ($hit)              { $status = 'correct'; $points = self::STAGE_POINTS['r32']; }
                elseif (!$r32Decided)  { $status = 'pending'; $points = 0; }
                else                   { $status = 'wrong';   $points = 0; }
                $r32Total += $points;
                $r32Items[] = [
                    'slot'      => ($row['group'] ?? '') . ' ' . $label,
                    'team_name' => $teamsById[$teamId]['name'] ?? '(unknown)',
                    'status'    => $status,
                    'points'    => $points,
                ];
            }
        }
        $out['r32'] = [
            'total'        => $r32Total,
            'pts_per_pick' => self::STAGE_POINTS['r32'],
            'items'        => $r32Items,
            'derived_from_groups' => true,
        ];

        // -------- R16 / QF / SF / Final: shift wizard picks one round up --------
        foreach (self::PICK_TO_REACHED as $pickStage => $reached) {
            $actualSet = self::actualStageTeams($reached);
            $stageDecided = count($actualSet) >= (self::STAGE_EXPECTED_TEAMS[$reached] ?? PHP_INT_MAX);

            $picks = Database::fetchAll(
                'SELECT p.slot_code, p.team_id, t.name AS team_name
                   FROM predictions p
              LEFT JOIN teams t ON t.id = p.team_id
                  WHERE p.form_id = ? AND p.stage = ? AND p.team_id IS NOT NULL
               ORDER BY p.slot_code',
                [$formId, $pickStage]
            );

            $pts = self::STAGE_POINTS[$reached];
            $items = [];
            $total = 0;
            foreach ($picks as $p) {
                $hit = isset($actualSet[(int) $p['team_id']]);
                if ($hit)              { $status = 'correct'; $points = $pts; }
                elseif (!$stageDecided){ $status = 'pending'; $points = 0; }
                else                   { $status = 'wrong';   $points = 0; }
                $total += $points;
                $items[] = [
                    'slot'      => $p['slot_code'],
                    'team_name' => $p['team_name'],
                    'status'    => $status,
                    'points'    => $points,
                ];
            }
            $out[$reached] = [
                'total'        => $total,
                'pts_per_pick' => $pts,
                'items'        => $items,
                'derived_from_groups' => false,
            ];
        }
        return $out;
    }

    /** @return array<int, true> */
    private static function actualStageTeams(string $stage): array
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

    /** @return array<int, array{name:string}> */
    private static function teamsById(): array
    {
        $out = [];
        foreach (Database::fetchAll('SELECT id, name FROM teams') as $t) {
            $out[(int) $t['id']] = ['name' => $t['name']];
        }
        return $out;
    }

    private static function winnerBreakdown(array $form): array
    {
        $predWinnerId = $form['winner_team_id'] ? (int)$form['winner_team_id'] : 0;
        $actualWinnerId = (int)(Database::fetchColumn(
            'SELECT CASE WHEN actual_home_goals > actual_away_goals THEN home_team_id
                         WHEN actual_away_goals > actual_home_goals THEN away_team_id
                         ELSE NULL END
               FROM matches WHERE stage = "final" AND actual_home_goals IS NOT NULL
            ORDER BY match_number ASC LIMIT 1'
        ) ?: 0);

        $predName   = $predWinnerId   ? (string) Database::fetchColumn('SELECT name FROM teams WHERE id = ?', [$predWinnerId])   : '';
        $actualName = $actualWinnerId ? (string) Database::fetchColumn('SELECT name FROM teams WHERE id = ?', [$actualWinnerId]) : '';

        $points = 0;
        $status = $predWinnerId ? 'pending' : 'no_prediction';
        if ($actualWinnerId) {
            $hit    = $predWinnerId === $actualWinnerId;
            $points = $hit ? 100 : 0;
            $status = $hit ? 'correct' : 'wrong';
        }
        return [
            'pred_name'   => $predName,
            'actual_name' => $actualName,
            'status'      => $status,
            'points'      => $points,
        ];
    }

    private static function topscorerBreakdown(array $form): array
    {
        $predId = $form['topscorer_player_id'] ? (int)$form['topscorer_player_id'] : 0;
        $actualId = (int) Setting::get('actual_topscorer_player_id', 0);

        $predName   = '';
        if ($predId) {
            $row = Database::fetch(
                'SELECT p.name, t.name AS team_name FROM players p LEFT JOIN teams t ON t.id = p.team_id WHERE p.id = ?',
                [$predId]
            );
            $predName = $row ? $row['name'] . ($row['team_name'] ? ' (' . $row['team_name'] . ')' : '') : '';
        }
        $actualName = $actualId ? (string) Database::fetchColumn('SELECT name FROM players WHERE id = ?', [$actualId]) : '';

        $correctPlayerPoints = ($actualId && $actualId === $predId) ? 10 : 0;
        $goals               = (int) Setting::get('predicted_topscorer_goals_for_' . $predId, 0);
        $goalPoints          = 3 * $goals;
        $totalPoints         = $correctPlayerPoints + $goalPoints;

        // Tournament-finished only once the final has actual scores.
        $finalPlayed = (int) Database::fetchColumn(
            'SELECT COUNT(*) FROM matches WHERE stage = "final" AND actual_home_goals IS NOT NULL'
        ) > 0;

        if (!$actualId) {
            $status = 'pending';                               // nobody has scored yet
        } elseif ($predId === $actualId) {
            $status = $finalPlayed ? 'correct' : 'leading';     // matches current leader
        } else {
            $status = $finalPlayed ? 'wrong'   : 'in_progress'; // someone else leads, may still flip
        }

        return [
            'pred_name'             => $predName,
            'actual_name'           => $actualName,
            'predicted_player_goals'=> $goals,
            'correct_player_points' => $correctPlayerPoints,
            'goal_points'           => $goalPoints,
            'points'                => $totalPoints,
            'status'                => $status,
        ];
    }

    private static function outcome(int $h, int $a): string
    {
        return $h > $a ? '1' : ($h < $a ? '2' : 'X');
    }
}
