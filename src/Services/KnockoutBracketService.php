<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Build the official FIFA 2026 knockout bracket (48-team format).
 *
 * The Round-of-32 structure is fixed: 4 W-vs-2nd matches, 4 2nd-vs-2nd matches,
 * and 8 W-vs-3rd matches where each W-vs-3rd slot has a pre-defined list of
 * "allowed source groups" for the third-placed team. The 8 best thirds are
 * picked from the 12 group thirds via FIFA tiebreakers (points → GD → GF →
 * group code as deterministic substitute for fair-play / drawing of lots).
 *
 * Slot constraints are taken from FIFA's published 2026 schedule (matches
 * 73–88 = R32, in numerical / chronological order).
 *
 * Downstream feeders (R16 → Final) also follow the FIFA bracket structure.
 */
final class KnockoutBracketService
{
    /**
     * R32 slot definitions. Each entry describes how the slot's two participants
     * are sourced:
     *   - 'W{code}' for group winner (e.g. W1A = winner of group A)
     *   - '2{code}' for runner-up
     *   - '3#{A,B,…}' for a third-placed team picked from one of the listed groups
     */
    private const R32 = [
        'R32-01' => ['2A',                 '2B'],
        'R32-02' => ['W1C',                '2F'],
        'R32-03' => ['W1E',                '3#A,B,C,D,F'],
        'R32-04' => ['W1F',                '2C'],
        'R32-05' => ['2E',                 '2I'],
        'R32-06' => ['W1I',                '3#C,D,F,G,H'],
        'R32-07' => ['W1A',                '3#C,E,F,H,I'],
        'R32-08' => ['W1L',                '3#E,H,I,J,K'],
        'R32-09' => ['W1G',                '3#A,E,H,I,J'],
        'R32-10' => ['W1D',                '3#B,E,F,I,J'],
        'R32-11' => ['W1H',                '2J'],
        'R32-12' => ['2K',                 '2L'],
        'R32-13' => ['W1B',                '3#E,F,G,I,J'],
        'R32-14' => ['2D',                 '2G'],
        'R32-15' => ['W1J',                '2H'],
        'R32-16' => ['W1K',                '3#D,E,I,J,L'],
    ];

    /**
     * Downstream feeders per FIFA 2026 bracket structure.
     */
    private const FEEDERS = [
        'R16-01' => ['R32-01','R32-03'],
        'R16-02' => ['R32-02','R32-05'],
        'R16-03' => ['R32-04','R32-06'],
        'R16-04' => ['R32-07','R32-08'],
        'R16-05' => ['R32-11','R32-12'],
        'R16-06' => ['R32-09','R32-10'],
        'R16-07' => ['R32-14','R32-16'],
        'R16-08' => ['R32-13','R32-15'],
        'QF-01'  => ['R16-01','R16-02'],
        'QF-02'  => ['R16-05','R16-06'],
        'QF-03'  => ['R16-03','R16-04'],
        'QF-04'  => ['R16-07','R16-08'],
        'SF-01'  => ['QF-01','QF-02'],
        'SF-02'  => ['QF-03','QF-04'],
        'F-01'   => ['SF-01','SF-02'],
    ];

    /**
     * @param array<string, list<array>> $groupStandings  group code => rows from FifaRankingService::rank()
     * @return array{
     *   firsts: list<array>, seconds: list<array>,
     *   thirds_ranked: list<array>, qualified_thirds: list<array>,
     *   r32: list<array{slot:string, home:array, away:array}>
     * }
     */
    public static function build(array $groupStandings): array
    {
        $firsts = $seconds = $thirds = [];
        foreach ($groupStandings as $code => $rows) {
            if (isset($rows[0])) $firsts[$code]  = ['group' => $code] + $rows[0];
            if (isset($rows[1])) $seconds[$code] = ['group' => $code] + $rows[1];
            if (isset($rows[2])) $thirds[$code]  = ['group' => $code] + $rows[2];
        }

        $thirdsRanked = self::rankThirds(array_values($thirds));
        $qualified    = array_slice($thirdsRanked, 0, 8);
        $thirdsByGroup = [];
        foreach ($qualified as $t) $thirdsByGroup[$t['group']] = $t;

        // Assign each qualifying third to a valid R32 slot.
        $thirdSlotAssignment = self::assignThirdsToSlots($thirdsByGroup);

        // Build R32 match pairs.
        $r32 = [];
        foreach (self::R32 as $slot => [$leftDef, $rightDef]) {
            $r32[] = [
                'slot' => $slot,
                'home' => self::resolveTeam($leftDef,  $firsts, $seconds, $thirdSlotAssignment, $slot),
                'away' => self::resolveTeam($rightDef, $firsts, $seconds, $thirdSlotAssignment, $slot),
            ];
        }

        return [
            'firsts'           => array_values($firsts),
            'seconds'          => array_values($seconds),
            'thirds_ranked'    => $thirdsRanked,
            'qualified_thirds' => $qualified,
            'r32'              => $r32,
        ];
    }

    /**
     * Build the empty downstream bracket (R16 → Final) per FIFA structure.
     */
    public static function downstream(): array
    {
        $bucket = ['r16' => [], 'qf' => [], 'sf' => [], 'final' => null];
        foreach (self::FEEDERS as $slot => $feeds) {
            $entry = ['slot' => $slot, 'feeds' => $feeds];
            if (str_starts_with($slot, 'R16'))      $bucket['r16'][] = $entry;
            elseif (str_starts_with($slot, 'QF'))   $bucket['qf'][]  = $entry;
            elseif (str_starts_with($slot, 'SF'))   $bucket['sf'][]  = $entry;
            elseif (str_starts_with($slot, 'F'))    $bucket['final']  = $entry;
        }
        return $bucket;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

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
     * Bipartite matching: assign the 8 qualifying thirds to the 8 R32 third-slots.
     * Each slot accepts a third only from its allowed-groups list.
     * Strategy: backtracking — try each slot in order, pick any available third
     * whose group is in the allowed set; recurse. Always finds an assignment when
     * one exists.
     *
     * @param array<string, array> $thirdsByGroup
     * @return array<string, array>  slot => third row
     */
    private static function assignThirdsToSlots(array $thirdsByGroup): array
    {
        $thirdSlots = [];
        foreach (self::R32 as $slot => [$left, $right]) {
            foreach ([$left, $right] as $side) {
                if (str_starts_with($side, '3#')) {
                    $thirdSlots[$slot] = array_map('trim', explode(',', substr($side, 2)));
                    break;
                }
            }
        }

        $assignment = [];
        $solve = function (array $slotsLeft) use (&$solve, $thirdsByGroup, &$assignment) {
            if (empty($slotsLeft)) return true;
            $slot = array_key_first($slotsLeft);
            $allowed = $slotsLeft[$slot];
            unset($slotsLeft[$slot]);
            foreach ($allowed as $g) {
                if (!isset($thirdsByGroup[$g])) continue;
                if (in_array($g, $assignment, true)) continue;
                $assignment[$slot] = $g;
                if ($solve($slotsLeft)) return true;
                unset($assignment[$slot]);
            }
            return false;
        };

        if (!$solve($thirdSlots)) {
            // Should never happen — FIFA's matrix guarantees a solution exists for
            // every legal combination of 8 thirds. If we land here it usually means
            // the user's group-stage predictions are incomplete and we shouldn't
            // surface a bracket yet.
            return [];
        }

        // Map slot → actual third-row (not just group code)
        $out = [];
        foreach ($assignment as $slot => $g) $out[$slot] = $thirdsByGroup[$g];
        return $out;
    }

    /**
     * Translate a participant definition (W1X / 2X / 3#A,B,…) to a team row.
     */
    private static function resolveTeam(string $def, array $firsts, array $seconds, array $thirdSlots, string $slot): ?array
    {
        if (preg_match('/^W1([A-L])$/', $def, $m)) {
            return $firsts[$m[1]] ?? null;
        }
        if (preg_match('/^2([A-L])$/', $def, $m)) {
            return $seconds[$m[1]] ?? null;
        }
        if (str_starts_with($def, '3#')) {
            return $thirdSlots[$slot] ?? null;
        }
        return null;
    }
}
