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

            // 2. Topscorer (throttled)
            if ($includeTopscorer || $this->topscorerStale()) {
                try {
                    $top = $this->provider->topScorers();
                    $topscorerInfo = $this->applyTopscorer($top, $errors);
                    $topscorerChanged = $topscorerInfo !== null;
                } catch (\Throwable $e) {
                    $errors[] = 'topscorer: ' . $e->getMessage();
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
            'errors'            => $errors,
            'synced_at'         => date('Y-m-d H:i:s'),
        ];
        Setting::set('last_sync_at',      $summary['synced_at']);
        Setting::set('last_sync_summary', json_encode($summary, JSON_UNESCAPED_UNICODE));
        return $summary;
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
     *   1. exact, accent/case-insensitive match within the same team
     *   2. last-name match within the same team
     *   3. last-name match without team constraint (fallback for renamed teams)
     *
     * @param list<array{id:int,name:string,team_id:?int}> $players
     */
    private function resolvePlayerId(string $apiName, ?int $apiTeamId, array $players): int
    {
        $apiNorm = $this->normalize($apiName);
        $apiLast = $this->lastWord($apiNorm);
        if ($apiNorm === '') return 0;

        // 1. exact, within team
        foreach ($players as $p) {
            if ($this->normalize((string) $p['name']) === $apiNorm
                && (int) ($p['team_id'] ?? 0) === (int) $apiTeamId) {
                return (int) $p['id'];
            }
        }
        // 2. exact, ignoring team
        foreach ($players as $p) {
            if ($this->normalize((string) $p['name']) === $apiNorm) {
                return (int) $p['id'];
            }
        }
        // 3. last-name match within team (catches "Romelu Lukaku" vs "R. Lukaku")
        if ($apiTeamId) {
            foreach ($players as $p) {
                if ((int) ($p['team_id'] ?? 0) !== (int) $apiTeamId) continue;
                if ($this->lastWord($this->normalize((string) $p['name'])) === $apiLast) {
                    return (int) $p['id'];
                }
            }
        }
        // 4. unique last-name match across all players
        $candidates = [];
        foreach ($players as $p) {
            if ($this->lastWord($this->normalize((string) $p['name'])) === $apiLast) {
                $candidates[] = (int) $p['id'];
            }
        }
        if (count($candidates) === 1) return $candidates[0];

        return 0;
    }

    private function lastWord(string $s): string
    {
        $parts = preg_split('/\s+/', trim($s)) ?: [];
        return $parts ? (string) end($parts) : '';
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
}
