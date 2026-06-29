<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Setting;
use App\Services\Providers\ApiFootballProvider;
use App\Services\Providers\FootballDataOrgProvider;

/**
 * Provider-agnostic sync:
 *
 *  - Picks a backend based on env var MATCH_DATA_PROVIDER
 *    ("api_football" or "football_data_org"). Falls back to api_football
 *    for backwards compatibility.
 *  - Each backend implements MatchDataProvider with a normalized fixture +
 *    top-scorer shape, so the matching / scoring logic below doesn't care
 *    which API the data came from.
 *
 * Outputs:
 *  - actual_home_goals / actual_away_goals on `matches`
 *  - actual_topscorer_player_id + per-player goal counts in `settings`
 *  - triggers ScoringService::recomputeAll when fixtures or topscorer change
 */
final class MatchSyncService
{
    public function __construct(private ?MatchDataProvider $provider = null)
    {
        $this->provider ??= self::pickProvider();
    }

    public static function pickProvider(): MatchDataProvider
    {
        $name = strtolower((string) Config::get('MATCH_DATA_PROVIDER', 'api_football'));
        return match ($name) {
            'football_data_org', 'football-data.org', 'football_data' => new FootballDataOrgProvider(),
            default => new ApiFootballProvider(),
        };
    }

    /** @return array{provider:string, updated:int, finals_recomputed:bool, topscorer:?array, errors:list<string>} */
    public function sync(bool $includeTopscorer = false): array
    {
        $errors = [];
        $updated = 0;
        $finalsRecomputed = false;
        $topscorerInfo = null;
        $topscorerChanged = false;
        $fixtures = null;
        $enrichInfo = null;

        if (!$this->provider->isConfigured()) {
            $errors[] = $this->provider->name() . ': not configured (check .env)';
        } else {
            // 1. Fixtures
            try {
                $fixtures = $this->provider->fixtures();
                [$updated, $finalsRecomputed] = $this->applyFixtures($fixtures, $errors);
            } catch (\Throwable $e) {
                $errors[] = 'fixtures: ' . $e->getMessage();
            }

            // 2. Topscorer (throttled) — global /scorers ranking
            if ($includeTopscorer || $this->topscorerStale()) {
                try {
                    $top = $this->provider->topScorers();
                    $topscorerInfo = $this->applyTopscorer($top, $errors);
                    $topscorerChanged = $topscorerInfo !== null;
                } catch (\Throwable $e) {
                    $errors[] = 'topscorer: ' . $e->getMessage();
                }
            }

            // 3. Per-match goal enrichment for picked players. The /scorers
            //    feed caps at the top 100, so picks outside that cutoff get
            //    their goal count by aggregating /matches/{id} goal events.
            if ($fixtures !== null && self::enrichmentTableExists()) {
                try {
                    $enrichInfo = $this->enrichPickedTopscorers($fixtures, $errors);
                    if (($enrichInfo['players_updated'] ?? 0) > 0) {
                        $topscorerChanged = true;
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'enrich: ' . $e->getMessage();
                }
            }
        }

        if ($finalsRecomputed || $topscorerChanged) {
            ScoringService::recomputeAll();
        }

        $summary = [
            'provider'          => $this->provider->name(),
            'updated'           => $updated,
            'finals_recomputed' => $finalsRecomputed,
            'topscorer'         => $topscorerInfo,
            'enrichment'        => $enrichInfo,
            'errors'            => $errors,
            'synced_at'         => date('Y-m-d H:i:s'),
        ];
        Setting::set('last_sync_at',      $summary['synced_at']);
        Setting::set('last_sync_summary', json_encode($summary, JSON_UNESCAPED_UNICODE));
        return $summary;
    }

    private static function enrichmentTableExists(): bool
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        try {
            Database::fetchColumn('SELECT 1 FROM match_enriched LIMIT 1');
            return $cached = true;
        } catch (\Throwable) {
            return $cached = false;
        }
    }

    private function topscorerStale(): bool
    {
        $hours = (float) Config::get('API_FOOTBALL_TOPSCORER_INTERVAL_HOURS', 1);
        $last  = (string) Setting::get('last_topscorer_sync_at', '');
        if ($last === '') return true;
        return (time() - strtotime($last)) > $hours * 3600;
    }

    // ------------------------------------------------------------------
    // Apply normalized data to the DB
    // ------------------------------------------------------------------

    /**
     * @param list<array> $fixtures   Normalized rows
     * @return array{0:int,1:bool}
     */
    private function applyFixtures(array $fixtures, array &$errors): array
    {
        [$teamsByIso, $teamsByName] = $this->buildTeamLookup();

        $updatedCount = 0;
        $finalUpdated = false;

        foreach ($fixtures as $f) {
            // Knockout placeholders that don't have teams assigned yet
            // (R32/R16/QF/SF/F before the group stage decides them) come back
            // with empty team objects. Skip these silently — they're not errors.
            $hasHomeIdent = !empty($f['home_iso']) || !empty($f['home_name']);
            $hasAwayIdent = !empty($f['away_iso']) || !empty($f['away_name']);
            if (!$hasHomeIdent && !$hasAwayIdent) {
                continue;
            }

            $homeId = $this->lookupTeam($f['home_iso'] ?? null, $f['home_name'] ?? null, $teamsByIso, $teamsByName);
            $awayId = $this->lookupTeam($f['away_iso'] ?? null, $f['away_name'] ?? null, $teamsByIso, $teamsByName);

            if (!$homeId || !$awayId) {
                $errors[] = sprintf(
                    'Unknown team pair: %s [%s] vs %s [%s]',
                    $f['home_name'] ?? '?',
                    $f['home_iso']  ?? '?',
                    $f['away_name'] ?? '?',
                    $f['away_iso']  ?? '?'
                );
                continue;
            }
            if ($f['home_goals'] === null || $f['away_goals'] === null) {
                continue;
            }

            $candidates = Database::fetchAll(
                'SELECT id, stage, kickoff_at, actual_home_goals, actual_away_goals
                   FROM matches WHERE home_team_id = ? AND away_team_id = ?',
                [$homeId, $awayId]
            );
            $swap = false;
            if (empty($candidates)) {
                $candidates = Database::fetchAll(
                    'SELECT id, stage, kickoff_at, actual_home_goals, actual_away_goals
                       FROM matches WHERE home_team_id = ? AND away_team_id = ?',
                    [$awayId, $homeId]
                );
                $swap = !empty($candidates);
            }

            // Knockout slot fallback: nothing matched by team pair (because the
            // local row still has NULL home/away_team_id from seed) — find by
            // stage + kickoff_at and write the team assignment now. We compare
            // timestamps in PHP because the DB stores naive CEST/Belgian times
            // while the API ships ISO-8601 UTC ("…Z") — MySQL can't reconcile
            // those, strtotime() can (it interprets DB values via the default
            // timezone set in App::run).
            $assignedNow = false;
            if (empty($candidates) && !empty($f['stage']) && $f['stage'] !== 'group' && !empty($f['kickoff_at'])) {
                $apiTs = strtotime($f['kickoff_at']);
                $emptySlots = Database::fetchAll(
                    'SELECT id, stage, kickoff_at, actual_home_goals, actual_away_goals,
                            home_team_id, away_team_id
                       FROM matches
                      WHERE stage = ?
                        AND home_team_id IS NULL
                        AND away_team_id IS NULL
                        AND kickoff_at IS NOT NULL',
                    [$f['stage']]
                );
                $best = null; $bestDiff = PHP_INT_MAX;
                foreach ($emptySlots as $s) {
                    $diff = abs(strtotime($s['kickoff_at']) - $apiTs);
                    if ($diff < 3600 && $diff < $bestDiff) {
                        $best = $s;
                        $bestDiff = $diff;
                    }
                }
                if ($best) {
                    Database::update('matches', [
                        'home_team_id' => $homeId,
                        'away_team_id' => $awayId,
                    ], ['id' => (int) $best['id']]);
                    $candidates  = [$best];
                    $assignedNow = true;
                    $updatedCount++;
                }
            }

            if (empty($candidates)) {
                $errors[] = sprintf('No local match for %s vs %s', $f['home_name'] ?? '?', $f['away_name'] ?? '?');
                continue;
            }
            $match = $this->pickClosest($candidates, $f['kickoff_at'] ?? null);
            $h = $swap ? (int) $f['away_goals'] : (int) $f['home_goals'];
            $a = $swap ? (int) $f['home_goals'] : (int) $f['away_goals'];

            // No score yet — but if we just assigned teams that already counts
            // as a meaningful update (it unlocks the breakdown for this round).
            if ($f['home_goals'] === null || $f['away_goals'] === null) {
                continue;
            }
            if ((int) ($match['actual_home_goals'] ?? -1) === $h
             && (int) ($match['actual_away_goals'] ?? -1) === $a
             && !$assignedNow) {
                continue;
            }
            Database::update('matches', [
                'actual_home_goals' => $h,
                'actual_away_goals' => $a,
            ], ['id' => (int) $match['id']]);
            if (!$assignedNow) $updatedCount++;
            if ($f['is_final']) {
                $finalUpdated = true;
            }
        }
        return [$updatedCount, $finalUpdated];
    }

    /** @param list<array> $top */
    private function applyTopscorer(array $top, array &$errors): ?array
    {
        // Always record the attempt so the 1h throttle works even before any
        // goals have been scored. Without this, every 15-min cron tick would
        // re-hit the API just to receive another empty array.
        Setting::set('last_topscorer_sync_at', date('Y-m-d H:i:s'));

        if (empty($top)) {
            // No goals scored yet — not an error, just no data to apply.
            return null;
        }
        [$teamsByIso, $teamsByName] = $this->buildTeamLookup();

        // Cache all players once for fuzzy-matching.
        $allPlayers = Database::fetchAll('SELECT id, name, team_id FROM players');

        $best     = $top[0];
        $bestName = (string) $best['name'];
        $bestGoals= (int) $best['goals'];
        $bestTeamId = $this->lookupTeam($best['team_iso'] ?? null, $best['team_name'] ?? null, $teamsByIso, $teamsByName);

        $playerId = $this->resolvePlayerId($bestName, $bestTeamId, $allPlayers);
        if (!$playerId) {
            $playerId = Database::insert('players', ['name' => $bestName, 'team_id' => $bestTeamId]);
            $allPlayers[] = ['id' => $playerId, 'name' => $bestName, 'team_id' => $bestTeamId];
        }

        Setting::set('actual_topscorer_player_id', (string) $playerId);
        Setting::set('actual_topscorer_goals',     (string) $bestGoals);

        // Per-player goal totals — supports the "+3 per goal your predicted topscorer scored" rule.
        foreach ($top as $p) {
            $pname = (string) $p['name'];
            if ($pname === '') continue;
            $pteam = $this->lookupTeam($p['team_iso'] ?? null, $p['team_name'] ?? null, $teamsByIso, $teamsByName);
            $pid = $this->resolvePlayerId($pname, $pteam, $allPlayers);
            if ($pid) {
                Setting::set('predicted_topscorer_goals_for_' . $pid, (string) (int) $p['goals']);
            }
        }
        return ['player' => $bestName, 'goals' => $bestGoals];
    }

    /**
     * Find the local player id for a name supplied by the data provider.
     * Tries — in order:
     *   1. exact softNormalize'd match within the same team
     *   2. exact softNormalize'd match ignoring team
     *   3. last-name match within the same team
     *   4. unique last-name match across all players
     *
     * @param list<array{id:int,name:string,team_id:?int}> $players
     */
    private function resolvePlayerId(string $apiName, ?int $apiTeamId, array $players): int
    {
        $apiSoft = $this->softNormalize($apiName);
        $apiLast = $this->lastWord($apiSoft);
        if ($apiSoft === '') return 0;

        // 1. exact, within team
        foreach ($players as $p) {
            if ($this->softNormalize((string) $p['name']) === $apiSoft
                && (int) ($p['team_id'] ?? 0) === (int) $apiTeamId) {
                return (int) $p['id'];
            }
        }
        // 2. exact, ignoring team
        foreach ($players as $p) {
            if ($this->softNormalize((string) $p['name']) === $apiSoft) {
                return (int) $p['id'];
            }
        }
        // 3. last-name match within team (catches "Romelu Lukaku" vs "R. Lukaku")
        if ($apiTeamId && $apiLast !== '') {
            foreach ($players as $p) {
                if ((int) ($p['team_id'] ?? 0) !== (int) $apiTeamId) continue;
                if ($this->lastWord($this->softNormalize((string) $p['name'])) === $apiLast) {
                    return (int) $p['id'];
                }
            }
        }
        // 4. unique last-name match across all players
        if ($apiLast !== '') {
            $candidates = [];
            foreach ($players as $p) {
                if ($this->lastWord($this->softNormalize((string) $p['name'])) === $apiLast) {
                    $candidates[] = (int) $p['id'];
                }
            }
            if (count($candidates) === 1) return $candidates[0];
        }
        return 0;
    }

    private function lastWord(string $s): string
    {
        $parts = preg_split('/\s+/', trim($s)) ?: [];
        return $parts ? (string) end($parts) : '';
    }

    // ------------------------------------------------------------------
    // Per-match goal enrichment for picked top-scorers
    // ------------------------------------------------------------------

    /**
     * For each player picked as top scorer by at least one user, make sure
     * we have an accurate goal count even if they fall outside the global
     * /scorers feed (which caps at the top 100). We fetch per-match goal
     * events from /matches/{id}, cache them in match_goals, and aggregate.
     *
     * Throttled to a small batch per sync to stay under the provider's
     * 10 req/min rate limit; subsequent syncs pick up where this one left off.
     *
     * @param list<array> $fixtures   Output of provider->fixtures()
     * @return array{enriched_calls:int, players_updated:int, total_picked:int}
     */
    private function enrichPickedTopscorers(array $fixtures, array &$errors): array
    {
        $picked = Database::fetchAll(
            'SELECT DISTINCT p.id, p.name, p.team_id, t.iso3 AS team_iso
               FROM forms f
               JOIN players p ON p.id = f.topscorer_player_id
          LEFT JOIN teams t ON t.id = p.team_id
              WHERE f.status = "submitted" AND f.topscorer_player_id IS NOT NULL'
        );
        if (empty($picked)) {
            return ['enriched_calls' => 0, 'players_updated' => 0, 'total_picked' => 0];
        }

        // Set of team ISOs that matter (i.e. at least one picked player plays for them).
        $teamIsos = [];
        foreach ($picked as $p) {
            $iso = strtoupper((string) ($p['team_iso'] ?? ''));
            if ($iso !== '') $teamIsos[$iso] = true;
        }

        // Already-enriched provider match IDs.
        $enriched = [];
        foreach (Database::fetchAll('SELECT provider_match_id FROM match_enriched') as $row) {
            $enriched[(int) $row['provider_match_id']] = true;
        }

        // Filter fixtures to finished matches involving a picked team, not yet enriched.
        $toEnrich = [];
        foreach ($fixtures as $f) {
            $pid = (int) ($f['provider_id'] ?? 0);
            if (!$pid || empty($f['is_final'])) continue;
            if (isset($enriched[$pid])) continue;
            $hi = strtoupper((string) ($f['home_iso'] ?? ''));
            $ai = strtoupper((string) ($f['away_iso'] ?? ''));
            if (!isset($teamIsos[$hi]) && !isset($teamIsos[$ai])) continue;
            $toEnrich[] = $pid;
        }

        // Throttle to stay well under 10 req/min (we've already used 2 for
        // fixtures + scorers this tick).
        $maxCalls = (int) Config::get('TOPSCORER_ENRICH_BATCH', 6);
        if ($maxCalls < 1) $maxCalls = 1;
        $toEnrich = array_slice($toEnrich, 0, $maxCalls);

        $enrichedCalls = 0;
        foreach ($toEnrich as $matchId) {
            try {
                $goals = $this->provider->fetchMatchGoals($matchId);
                foreach ($goals as $g) {
                    if (!empty($g['is_own_goal'])) continue;
                    Database::insert('match_goals', [
                        'provider_match_id' => $matchId,
                        'scorer_name'       => $g['scorer_name'],
                        'team_iso'          => $g['team_iso'],
                        'minute'            => $g['minute'],
                        'is_own_goal'       => 0,
                    ]);
                }
                Database::insert('match_enriched', [
                    'provider_match_id' => $matchId,
                    'enriched_at'       => date('Y-m-d H:i:s'),
                ]);
                $enrichedCalls++;
            } catch (\Throwable $e) {
                $errors[] = "match goals {$matchId}: " . $e->getMessage();
            }
        }

        // Recompute per-player goal counts from match_goals and override
        // settings if the cached-events count exceeds the API feed's count.
        $playersUpdated = 0;
        foreach ($picked as $p) {
            $playerId = (int) $p['id'];
            $count = $this->countGoalsForPlayer((string) $p['name'], (string) ($p['team_iso'] ?? ''));
            $key = 'predicted_topscorer_goals_for_' . $playerId;
            $existing = (int) Setting::get($key, 0);
            $new = max($existing, $count);
            if ($new !== $existing) {
                Setting::set($key, (string) $new);
                $playersUpdated++;
            }
        }

        return [
            'enriched_calls'  => $enrichedCalls,
            'players_updated' => $playersUpdated,
            'total_picked'    => count($picked),
        ];
    }

    /**
     * Count goals for a player by fuzzy-matching cached match_goals events.
     * Match by last-name + team iso when available; falls back to last-name
     * alone if team is unknown.
     */
    private function countGoalsForPlayer(string $playerName, string $teamIso): int
    {
        $soft = $this->softNormalize($playerName);
        $last = $this->lastWord($soft);
        if ($last === '' && $soft === '') return 0;

        $teamIso = strtoupper(trim($teamIso));
        if ($teamIso !== '') {
            $events = Database::fetchAll(
                'SELECT scorer_name FROM match_goals WHERE team_iso = ?',
                [$teamIso]
            );
        } else {
            $events = Database::fetchAll('SELECT scorer_name FROM match_goals');
        }

        $count = 0;
        foreach ($events as $e) {
            $eSoft = $this->softNormalize((string) $e['scorer_name']);
            if ($eSoft === $soft) { $count++; continue; }
            if ($last !== '' && $this->lastWord($eSoft) === $last) { $count++; }
        }
        return $count;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{0:array<string,int>, 1:array<string,int>} */
    private function buildTeamLookup(): array
    {
        $byIso = $byName = [];
        foreach (Database::fetchAll('SELECT id, name, iso3 FROM teams') as $t) {
            $byIso[strtoupper((string) $t['iso3'])] = (int) $t['id'];
            $byName[$this->normalize((string) $t['name'])] = (int) $t['id'];
        }
        return [$byIso, $byName];
    }

    private function lookupTeam(?string $iso, ?string $name, array $byIso, array $byName): ?int
    {
        if ($iso) {
            $code = strtoupper($iso);
            if (isset($byIso[$code])) return $byIso[$code];
        }
        if ($name) {
            $n = $this->normalize($name);
            if ($n !== '' && isset($byName[$n])) return $byName[$n];
        }
        return null;
    }

    /** @param list<array> $candidates */
    private function pickClosest(array $candidates, ?string $apiKickoff): array
    {
        if (count($candidates) === 1 || !$apiKickoff) return $candidates[0];
        $target = strtotime($apiKickoff);
        usort($candidates, function ($a, $b) use ($target) {
            $da = $a['kickoff_at'] ? abs(strtotime($a['kickoff_at']) - $target) : PHP_INT_MAX;
            $db = $b['kickoff_at'] ? abs(strtotime($b['kickoff_at']) - $target) : PHP_INT_MAX;
            return $da <=> $db;
        });
        return $candidates[0];
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        if (function_exists('iconv')) {
            $tr = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($tr !== false) $s = $tr;
        }
        $s = preg_replace('/[^a-z0-9]+/', '', $s) ?? $s;
        return $s;
    }

    /**
     * Like normalize() but preserves word boundaries so lastWord() can split
     * "Romelu Lukaku" -> ["romelu","lukaku"]. The compact normalize() strips
     * spaces, which breaks last-name matching for variants like "R. Lukaku".
     */
    private function softNormalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        if (function_exists('iconv')) {
            $tr = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($tr !== false) $s = $tr;
        }
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }
}
