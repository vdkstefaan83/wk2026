<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Build a FIFA-style ranking for a group given a list of match results.
 *
 * Tiebreakers (FIFA, in order):
 *   1. Higher points
 *   2. Greater goal difference (in all group matches)
 *   3. Greater goals scored (in all group matches)
 *   4. Greater points among tied teams (head-to-head)
 *   5. Greater goal difference among tied teams (head-to-head)
 *   6. Greater goals scored among tied teams (head-to-head)
 *   7. (Fair play / drawing lots — skipped in this app, stable position used)
 */
final class FifaRankingService
{
    /**
     * @param list<array{id:int,name:string}> $teams
     * @param list<array{home_team_id:int,away_team_id:int,home_goals:?int,away_goals:?int}> $matches
     * @return list<array> Sorted team-stats rows.
     */
    public static function rank(array $teams, array $matches): array
    {
        $stats = [];
        foreach ($teams as $t) {
            $stats[$t['id']] = [
                'team_id' => $t['id'],
                'name'    => $t['name'],
                'played'  => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0,
                'gf' => 0, 'ga' => 0, 'gd' => 0, 'points' => 0,
            ];
        }
        $played = [];
        foreach ($matches as $m) {
            if ($m['home_goals'] === null || $m['away_goals'] === null) continue;
            $h = (int)$m['home_team_id']; $a = (int)$m['away_team_id'];
            if (!isset($stats[$h], $stats[$a])) continue;
            $hg = (int)$m['home_goals']; $ag = (int)$m['away_goals'];
            $stats[$h]['played']++; $stats[$a]['played']++;
            $stats[$h]['gf'] += $hg; $stats[$h]['ga'] += $ag;
            $stats[$a]['gf'] += $ag; $stats[$a]['ga'] += $hg;
            if ($hg > $ag) {
                $stats[$h]['won']++;  $stats[$a]['lost']++;
                $stats[$h]['points'] += 3;
            } elseif ($hg < $ag) {
                $stats[$a]['won']++;  $stats[$h]['lost']++;
                $stats[$a]['points'] += 3;
            } else {
                $stats[$h]['drawn']++; $stats[$a]['drawn']++;
                $stats[$h]['points']++; $stats[$a]['points']++;
            }
            $played[] = $m;
        }
        foreach ($stats as &$s) {
            $s['gd'] = $s['gf'] - $s['ga'];
        }
        unset($s);

        $rows = array_values($stats);

        usort($rows, function ($a, $b) use ($rows, $played) {
            return self::compareTeams($a, $b, $rows, $played);
        });

        // Group teams with identical (points, gd, gf) overall and re-sort by head-to-head if 3+ tied.
        $rows = self::resolveMultiwayTies($rows, $played);

        // Add position
        $position = 0;
        foreach ($rows as &$r) {
            $r['position'] = ++$position;
        }
        return $rows;
    }

    private static function compareTeams(array $a, array $b, array $allRows, array $played): int
    {
        if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
        if ($a['gd']     !== $b['gd'])     return $b['gd']     <=> $a['gd'];
        if ($a['gf']     !== $b['gf'])     return $b['gf']     <=> $a['gf'];

        // Head-to-head between just these two teams
        $h2h = self::headToHead([$a['team_id'], $b['team_id']], $played);
        $ha = $h2h[$a['team_id']]; $hb = $h2h[$b['team_id']];
        if ($ha['points'] !== $hb['points']) return $hb['points'] <=> $ha['points'];
        if ($ha['gd']     !== $hb['gd'])     return $hb['gd']     <=> $ha['gd'];
        if ($ha['gf']     !== $hb['gf'])     return $hb['gf']     <=> $ha['gf'];

        return strcmp((string)$a['name'], (string)$b['name']);
    }

    private static function resolveMultiwayTies(array $rows, array $played): array
    {
        $n = count($rows);
        $i = 0;
        while ($i < $n) {
            $j = $i + 1;
            while ($j < $n
                && $rows[$j]['points'] === $rows[$i]['points']
                && $rows[$j]['gd']     === $rows[$i]['gd']
                && $rows[$j]['gf']     === $rows[$i]['gf']) {
                $j++;
            }
            if ($j - $i >= 3) {
                $tiedIds = array_map(fn($r) => $r['team_id'], array_slice($rows, $i, $j - $i));
                $h2h = self::headToHead($tiedIds, $played);
                $slice = array_slice($rows, $i, $j - $i);
                usort($slice, function ($a, $b) use ($h2h) {
                    $ha = $h2h[$a['team_id']]; $hb = $h2h[$b['team_id']];
                    if ($ha['points'] !== $hb['points']) return $hb['points'] <=> $ha['points'];
                    if ($ha['gd']     !== $hb['gd'])     return $hb['gd']     <=> $ha['gd'];
                    if ($ha['gf']     !== $hb['gf'])     return $hb['gf']     <=> $ha['gf'];
                    return strcmp((string)$a['name'], (string)$b['name']);
                });
                array_splice($rows, $i, $j - $i, $slice);
            }
            $i = $j;
        }
        return $rows;
    }

    /** @param list<int> $teamIds */
    private static function headToHead(array $teamIds, array $matches): array
    {
        $set = array_flip($teamIds);
        $stats = [];
        foreach ($teamIds as $id) {
            $stats[$id] = ['points' => 0, 'gf' => 0, 'ga' => 0, 'gd' => 0];
        }
        foreach ($matches as $m) {
            if ($m['home_goals'] === null || $m['away_goals'] === null) continue;
            $h = (int)$m['home_team_id']; $a = (int)$m['away_team_id'];
            if (!isset($set[$h], $set[$a])) continue;
            $hg = (int)$m['home_goals']; $ag = (int)$m['away_goals'];
            $stats[$h]['gf'] += $hg; $stats[$h]['ga'] += $ag;
            $stats[$a]['gf'] += $ag; $stats[$a]['ga'] += $hg;
            if ($hg > $ag) { $stats[$h]['points'] += 3; }
            elseif ($hg < $ag) { $stats[$a]['points'] += 3; }
            else { $stats[$h]['points']++; $stats[$a]['points']++; }
        }
        foreach ($stats as &$s) { $s['gd'] = $s['gf'] - $s['ga']; }
        return $stats;
    }
}
