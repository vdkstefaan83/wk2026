<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Setting;

/**
 * Syncs match results (and optionally the tournament topscorer) from
 * API-Football into the local `matches` and `settings` tables.
 *
 * Matching strategy:
 *   - Teams: by ISO3 code (api-football exposes `team.code`), fallback to name.
 *   - Matches: by (home_team_id + away_team_id) tuple; if multiple, use the
 *     one closest in time to the API kickoff.
 *
 * Topscorer fetch is throttled (max once per N hours) to respect the free tier.
 */
final class MatchSyncService
{
    public function __construct(
        private ApiFootballClient $api = new ApiFootballClient(),
    ) {}

    /** @return array{updated:int, finals_recomputed:bool, topscorer:?array, errors:list<string>} */
    public function sync(bool $includeTopscorer = false): array
    {
        $errors = [];
        $updated = 0;
        $finalsRecomputed = false;
        $topscorerInfo = null;
        $topscorerChanged = false;

        $leagueId = (int) Config::get('API_FOOTBALL_LEAGUE_ID', 1);  // 1 = World Cup
        $season   = (int) Config::get('API_FOOTBALL_SEASON', 2026);

        // 1. Sync fixtures
        try {
            $fixtures = $this->api->fixtures($leagueId, $season);
            [$updated, $finalsRecomputed] = $this->applyFixtures($fixtures, $errors);
        } catch (\Throwable $e) {
            $errors[] = 'fixtures: ' . $e->getMessage();
        }

        // 2. Topscorer (throttled)
        if ($includeTopscorer || $this->topscorerStale()) {
            try {
                $top = $this->api->topScorers($leagueId, $season);
                $topscorerInfo = $this->applyTopscorer($top, $errors);
                $topscorerChanged = $topscorerInfo !== null;
            } catch (\Throwable $e) {
                $errors[] = 'topscorer: ' . $e->getMessage();
            }
        }

        // 3. Recompute scores whenever match results OR topscorer goal counts moved.
        if ($finalsRecomputed || $topscorerChanged) {
            ScoringService::recomputeAll();
        }

        $summary = [
            'updated'           => $updated,
            'finals_recomputed' => $finalsRecomputed,
            'topscorer'         => $topscorerInfo,
            'errors'            => $errors,
            'synced_at'         => date('Y-m-d H:i:s'),
        ];

        Setting::set('last_sync_at',     $summary['synced_at']);
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

    /**
     * @param list<array> $fixtures
     * @return array{0:int,1:bool}
     */
    private function applyFixtures(array $fixtures, array &$errors): array
    {
        // Build team lookup once
        $teamsByIso = [];
        $teamsByName = [];
        foreach (Database::fetchAll('SELECT id, name, iso3 FROM teams') as $t) {
            $teamsByIso[strtoupper((string)$t['iso3'])] = (int)$t['id'];
            $teamsByName[$this->normalize($t['name'])] = (int)$t['id'];
        }

        $updated = 0;
        $finalUpdated = false;

        foreach ($fixtures as $f) {
            $homeApi = $f['teams']['home'] ?? [];
            $awayApi = $f['teams']['away'] ?? [];
            $homeId = $this->lookupTeam($homeApi, $teamsByIso, $teamsByName);
            $awayId = $this->lookupTeam($awayApi, $teamsByIso, $teamsByName);

            if (!$homeId || !$awayId) {
                $errors[] = sprintf('Onbekend team-paar: %s vs %s',
                    $homeApi['name'] ?? '?', $awayApi['name'] ?? '?');
                continue;
            }

            $statusShort = (string) ($f['fixture']['status']['short'] ?? '');
            $isFinal = in_array($statusShort, ['FT','AET','PEN'], true);
            $homeGoals = $f['goals']['home'] ?? null;
            $awayGoals = $f['goals']['away'] ?? null;
            if ($homeGoals === null || $awayGoals === null) continue;

            // Find local match: prefer one closest to API kickoff
            $apiKickoff = $f['fixture']['date'] ?? null;
            $candidates = Database::fetchAll(
                'SELECT id, stage, kickoff_at, actual_home_goals, actual_away_goals
                   FROM matches
                  WHERE home_team_id = ? AND away_team_id = ?',
                [$homeId, $awayId]
            );
            // Also try reversed (the draw may have swapped home/away vs our seed)
            $reversed = Database::fetchAll(
                'SELECT id, stage, kickoff_at, actual_home_goals, actual_away_goals
                   FROM matches
                  WHERE home_team_id = ? AND away_team_id = ?',
                [$awayId, $homeId]
            );
            $swap = false;
            if (empty($candidates) && !empty($reversed)) {
                $candidates = $reversed;
                $swap = true;
            }
            if (empty($candidates)) {
                $errors[] = sprintf('Geen lokale match voor %s vs %s', $homeApi['name'] ?? '?', $awayApi['name'] ?? '?');
                continue;
            }

            $match = $this->pickClosest($candidates, $apiKickoff);
            $h = $swap ? (int)$awayGoals : (int)$homeGoals;
            $a = $swap ? (int)$homeGoals : (int)$awayGoals;
            if ((int)($match['actual_home_goals'] ?? -1) === $h && (int)($match['actual_away_goals'] ?? -1) === $a) {
                continue; // no change
            }
            Database::update('matches', [
                'actual_home_goals' => $h,
                'actual_away_goals' => $a,
            ], ['id' => (int)$match['id']]);
            $updated++;
            if ($isFinal) {
                $finalUpdated = true;
            }
        }

        return [$updated, $finalUpdated];
    }

    private function lookupTeam(array $apiTeam, array $byIso, array $byName): ?int
    {
        $code = strtoupper((string)($apiTeam['code'] ?? ''));
        if ($code !== '' && isset($byIso[$code])) return $byIso[$code];
        $name = $this->normalize((string)($apiTeam['name'] ?? ''));
        if ($name !== '' && isset($byName[$name])) return $byName[$name];
        return null;
    }

    /**
     * @param list<array> $candidates
     */
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

    private function applyTopscorer(array $top, array &$errors): ?array
    {
        if (empty($top)) {
            $errors[] = 'topscorer: lege response';
            return null;
        }
        // API-Football returns players sorted by goals desc.
        $best = $top[0];
        $name   = (string)($best['player']['name'] ?? '');
        $goals  = (int)($best['statistics'][0]['goals']['total'] ?? 0);
        $teamId = $this->resolveTeamFromApi($best['statistics'][0]['team'] ?? []);

        // Find or create player record
        $playerId = (int) Database::fetchColumn(
            'SELECT id FROM players WHERE LOWER(name) = LOWER(?) AND (team_id <=> ?)',
            [$name, $teamId]
        );
        if (!$playerId) {
            $playerId = Database::insert('players', [
                'name'    => $name,
                'team_id' => $teamId,
            ]);
        }

        Setting::set('actual_topscorer_player_id', (string)$playerId);
        Setting::set('actual_topscorer_goals',     (string)$goals);
        Setting::set('last_topscorer_sync_at',     date('Y-m-d H:i:s'));

        // Per-player goal totals for "3pts per goal jouw topscorer scoorde" rule
        foreach ($top as $p) {
            $pname = (string)($p['player']['name'] ?? '');
            $pgoal = (int)($p['statistics'][0]['goals']['total'] ?? 0);
            $pteam = $this->resolveTeamFromApi($p['statistics'][0]['team'] ?? []);
            $pid = (int) Database::fetchColumn(
                'SELECT id FROM players WHERE LOWER(name) = LOWER(?) AND (team_id <=> ?)',
                [$pname, $pteam]
            );
            if ($pid) {
                Setting::set('predicted_topscorer_goals_for_' . $pid, (string)$pgoal);
            }
        }

        return ['player' => $name, 'goals' => $goals];
    }

    private function resolveTeamFromApi(array $apiTeam): ?int
    {
        $byIso = $byName = [];
        foreach (Database::fetchAll('SELECT id, name, iso3 FROM teams') as $t) {
            $byIso[strtoupper((string)$t['iso3'])] = (int)$t['id'];
            $byName[$this->normalize($t['name'])] = (int)$t['id'];
        }
        return $this->lookupTeam($apiTeam, $byIso, $byName);
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
