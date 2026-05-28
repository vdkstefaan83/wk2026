<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Builds the Round-of-32 bracket for the 48-team WK2026 format:
 *  - 12 group winners (1st)
 *  - 12 runners-up (2nd)
 *  - 8 best third-placed teams (selected from the 12 thirds by FIFA tiebreakers)
 *
 * Third-place team selection (best 8 out of 12) uses the same FIFA criteria
 * applied across thirds: points, GD, GF, fair-play (skipped), drawing of lots
 * (here: stable group code order).
 *
 * For each combination of which 8 third-placed groups qualify, FIFA publishes a
 * matrix that says which thirds go to which 1st-place team's R32 slot. We use a
 * simplified, deterministic mapping: thirds sorted by quality are paired to
 * the highest-seeded group winners. The exact official FIFA matrix can be
 * dropped in later via the admin UI.
 */
final class KnockoutBracketService
{
    /**
     * @param array<string, list<array>> $groupStandings  group code => rows from FifaRankingService::rank()
     * @return array{
     *   firsts: list<array>,  // 12 winners
     *   seconds: list<array>, // 12 runners-up
     *   thirds_ranked: list<array>, // 12 thirds ordered best→worst
     *   qualified_thirds: list<array>, // best 8
     *   r32: list<array{slot:string, home:array, away:array}>
     * }
     */
    public static function build(array $groupStandings): array
    {
        $firsts = $seconds = $thirds = [];
        foreach ($groupStandings as $code => $rows) {
            if (isset($rows[0])) $firsts[]  = ['group' => $code] + $rows[0];
            if (isset($rows[1])) $seconds[] = ['group' => $code] + $rows[1];
            if (isset($rows[2])) $thirds[]  = ['group' => $code] + $rows[2];
        }

        $thirdsRanked = self::rankThirds($thirds);
        $qualified    = array_slice($thirdsRanked, 0, 8);

        // R32 pairings:
        //  - 12 group winners face: 8 best thirds (4 of the winners may face thirds) + some runners-up
        //  - 12 runners-up: 4 of them face other runners-up (since 12 winners + 8 thirds = 20 teams,
        //    leaving 12 runners-up to fill 12 R32 slots — 8 vs winners, 4 vs other runners-up).
        //
        // We use a deterministic pairing that respects FIFA's spirit (avoid same-group rematches in R32).

        $r32 = self::pairR32($firsts, $seconds, $qualified);

        return [
            'firsts'           => $firsts,
            'seconds'          => $seconds,
            'thirds_ranked'    => $thirdsRanked,
            'qualified_thirds' => $qualified,
            'r32'              => $r32,
        ];
    }

    /** @param list<array> $thirds */
    private static function rankThirds(array $thirds): array
    {
        usort($thirds, function ($a, $b) {
            if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
            if ($a['gd']     !== $b['gd'])     return $b['gd']     <=> $a['gd'];
            if ($a['gf']     !== $b['gf'])     return $b['gf']     <=> $a['gf'];
            return strcmp((string)$a['group'], (string)$b['group']);
        });
        return $thirds;
    }

    /**
     * Deterministic R32 pairing.
     * - 8 best thirds → paired against the top 8 group winners (seeded by quality).
     * - Bottom 4 group winners → paired against bottom 4 group runners-up.
     * - Top 8 group runners-up → paired against each other (4 ties).
     * Same-group teams are never paired together; if a clash would occur we swap with the next pair.
     *
     * Slot codes follow R32-01 … R32-16.
     */
    private static function pairR32(array $firsts, array $seconds, array $thirds): array
    {
        $rankFirsts = self::sortByQuality($firsts);
        $rankSeconds = self::sortByQuality($seconds);

        $topFirsts    = array_slice($rankFirsts, 0, 8);
        $bottomFirsts = array_slice($rankFirsts, 8, 4);
        $topSeconds   = array_slice($rankSeconds, 0, 8);
        $bottomSeconds= array_slice($rankSeconds, 8, 4);

        $pairs = [];
        // 1. Top 8 winners vs the 8 qualified thirds (reverse to give the strongest winner the weakest third)
        $thirdsAsc = array_reverse($thirds);
        foreach ($topFirsts as $i => $w) {
            $pairs[] = ['home' => $w, 'away' => $thirdsAsc[$i] ?? null];
        }
        // 2. Bottom 4 winners vs bottom 4 runners-up
        foreach ($bottomFirsts as $i => $w) {
            $pairs[] = ['home' => $w, 'away' => $bottomSeconds[$i] ?? null];
        }
        // 3. Top 8 runners-up paired against each other (1v8, 2v7, 3v6, 4v5)
        $n = count($topSeconds);
        for ($i = 0; $i < intdiv($n, 2); $i++) {
            $pairs[] = ['home' => $topSeconds[$i], 'away' => $topSeconds[$n - 1 - $i]];
        }

        // Avoid same-group rematches by swapping with the next pair when needed
        $count = count($pairs);
        for ($i = 0; $i < $count; $i++) {
            if (self::clash($pairs[$i])) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $swapped = ['home' => $pairs[$i]['home'], 'away' => $pairs[$j]['away']];
                    $other   = ['home' => $pairs[$j]['home'], 'away' => $pairs[$i]['away']];
                    if (!self::clash($swapped) && !self::clash($other)) {
                        $pairs[$i] = $swapped;
                        $pairs[$j] = $other;
                        break;
                    }
                }
            }
        }

        $out = [];
        foreach ($pairs as $i => $p) {
            $out[] = [
                'slot' => sprintf('R32-%02d', $i + 1),
                'home' => $p['home'],
                'away' => $p['away'],
            ];
        }
        return $out;
    }

    private static function clash(array $pair): bool
    {
        return isset($pair['home']['group'], $pair['away']['group'])
            && $pair['home']['group'] === $pair['away']['group'];
    }

    private static function sortByQuality(array $rows): array
    {
        usort($rows, function ($a, $b) {
            if ($a['points'] !== $b['points']) return $b['points'] <=> $a['points'];
            if ($a['gd']     !== $b['gd'])     return $b['gd']     <=> $a['gd'];
            if ($a['gf']     !== $b['gf'])     return $b['gf']     <=> $a['gf'];
            return strcmp((string)$a['group'], (string)$b['group']);
        });
        return $rows;
    }

    /**
     * Build the empty bracket downstream of R32 (R16 → Final). Slots are
     * R16-01..R16-08, QF-01..QF-04, SF-01..SF-02, F-01.
     * Each slot is fed by two parent slots — fed[0]=winner of slot X, fed[1]=winner of slot Y.
     */
    public static function downstream(): array
    {
        $r16 = $qf = $sf = [];
        for ($i = 1; $i <= 8; $i++) {
            $r16[] = [
                'slot' => sprintf('R16-%02d', $i),
                'feeds' => [sprintf('R32-%02d', $i * 2 - 1), sprintf('R32-%02d', $i * 2)],
            ];
        }
        for ($i = 1; $i <= 4; $i++) {
            $qf[] = [
                'slot' => sprintf('QF-%02d', $i),
                'feeds' => [sprintf('R16-%02d', $i * 2 - 1), sprintf('R16-%02d', $i * 2)],
            ];
        }
        for ($i = 1; $i <= 2; $i++) {
            $sf[] = [
                'slot' => sprintf('SF-%02d', $i),
                'feeds' => [sprintf('QF-%02d', $i * 2 - 1), sprintf('QF-%02d', $i * 2)],
            ];
        }
        $final = ['slot' => 'F-01', 'feeds' => ['SF-01', 'SF-02']];
        return ['r16' => $r16, 'qf' => $qf, 'sf' => $sf, 'final' => $final];
    }
}
