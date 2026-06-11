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
            if (empty($candidates)) {
                $errors[] = sprintf('No local match for %s vs %s', $f['home_name'] ?? '?', $f['away_name'] ?? '?');
                continue;
            }
            $match = $this->pickClosest($candidates, $f['kickoff_at'] ?? null);
            $h = $swap ? (int) $f['away_goals'] : (int) $f['home_goals'];
            $a = $swap ? (int) $f['home_goals'] : (int) $f['away_goals'];

            if ((int) ($match['actual_home_goals'] ?? -1) === $h
             && (int) ($match['actual_away_goals'] ?? -1) === $a) {
                continue;
            }
            Database::update('matches', [
                'actual_home_goals' => $h,
                'actual_away_goals' => $a,
            ], ['id' => (int) $match['id']]);
            $updatedCount++;
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

        $best     = $top[0];
        $bestName = (string) $best['name'];
        $bestGoals= (int) $best['goals'];
        $bestTeamId = $this->lookupTeam($best['team_iso'] ?? null, $best['team_name'] ?? null, $teamsByIso, $teamsByName);

        $playerId = (int) Database::fetchColumn(
            'SELECT id FROM players WHERE LOWER(name) = LOWER(?) AND (team_id <=> ?)',
            [$bestName, $bestTeamId]
        );
        if (!$playerId) {
            $playerId = Database::insert('players', ['name' => $bestName, 'team_id' => $bestTeamId]);
        }

        Setting::set('actual_topscorer_player_id', (string) $playerId);
        Setting::set('actual_topscorer_goals',     (string) $bestGoals);
        // last_topscorer_sync_at already set at the top of this method

        // Per-player goal totals — supports the "+3 per goal your predicted topscorer scored" rule.
        foreach ($top as $p) {
            $pname = (string) $p['name'];
            if ($pname === '') continue;
            $pteam = $this->lookupTeam($p['team_iso'] ?? null, $p['team_name'] ?? null, $teamsByIso, $teamsByName);
            $pid = (int) Database::fetchColumn(
                'SELECT id FROM players WHERE LOWER(name) = LOWER(?) AND (team_id <=> ?)',
                [$pname, $pteam]
            );
            if ($pid) {
                Setting::set('predicted_topscorer_goals_for_' . $pid, (string) (int) $p['goals']);
            }
        }
        return ['player' => $bestName, 'goals' => $bestGoals];
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
