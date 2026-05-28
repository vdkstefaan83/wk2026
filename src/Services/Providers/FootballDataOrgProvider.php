<?php
declare(strict_types=1);

namespace App\Services\Providers;

use App\Core\Config;
use App\Services\MatchDataProvider;

/**
 * football-data.org adapter — free tier covers the FIFA World Cup
 * (competition code "WC"), 10 req/min, no season-locked paywall.
 * Sign up: https://www.football-data.org/client/register
 *
 * Auth header:  X-Auth-Token: <your_token>
 * Endpoints we use:
 *   GET /v4/competitions/WC/matches
 *   GET /v4/competitions/WC/scorers?limit=100
 */
final class FootballDataOrgProvider implements MatchDataProvider
{
    private string $baseUrl;
    private string $token;
    private string $competition;

    public function __construct()
    {
        $this->baseUrl     = rtrim((string) Config::get('FOOTBALL_DATA_ORG_BASE_URL', 'https://api.football-data.org/v4'), '/');
        $this->token       = (string) Config::get('FOOTBALL_DATA_ORG_TOKEN', '');
        $this->competition = (string) Config::get('FOOTBALL_DATA_ORG_COMPETITION', 'WC');
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    public function name(): string
    {
        return 'football-data.org';
    }

    public function fixtures(): array
    {
        $data = $this->get("/competitions/{$this->competition}/matches");
        $matches = $data['matches'] ?? [];
        $out = [];
        foreach ($matches as $m) {
            $status = (string) ($m['status'] ?? '');
            $isFinal = $status === 'FINISHED';

            $homeGoals = $m['score']['fullTime']['home'] ?? null;
            $awayGoals = $m['score']['fullTime']['away'] ?? null;

            $out[] = [
                'home_iso'   => $this->isoFromTla($m['homeTeam']['tla'] ?? null),
                'home_name'  => $m['homeTeam']['name'] ?? null,
                'away_iso'   => $this->isoFromTla($m['awayTeam']['tla'] ?? null),
                'away_name'  => $m['awayTeam']['name'] ?? null,
                'home_goals' => $homeGoals === null ? null : (int) $homeGoals,
                'away_goals' => $awayGoals === null ? null : (int) $awayGoals,
                'is_final'   => $isFinal,
                'kickoff_at' => $m['utcDate'] ?? null,
            ];
        }
        return $out;
    }

    public function topScorers(): array
    {
        $data = $this->get("/competitions/{$this->competition}/scorers", ['limit' => 100]);
        $rows = $data['scorers'] ?? [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name'      => (string) ($r['player']['name'] ?? ''),
                'goals'     => (int) ($r['goals'] ?? 0),
                'team_iso'  => $this->isoFromTla($r['team']['tla'] ?? null),
                'team_name' => $r['team']['name'] ?? null,
            ];
        }
        // football-data.org returns sorted by goals desc already, but normalize anyway.
        usort($out, fn($a, $b) => $b['goals'] <=> $a['goals']);
        return $out;
    }

    // ------------------------------------------------------------------

    private function get(string $path, array $query = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('FOOTBALL_DATA_ORG_TOKEN is not configured.');
        }
        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-Auth-Token: ' . $this->token],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("football-data.org request failed: {$err}");
        }
        if ($http >= 400) {
            throw new \RuntimeException("football-data.org returned HTTP {$http}: " . substr((string) $body, 0, 500));
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('football-data.org returned invalid JSON');
        }
        if (!empty($data['errorCode'])) {
            throw new \RuntimeException("football-data.org error: " . (string) ($data['message'] ?? 'unknown'));
        }
        return $data;
    }

    /**
     * football-data.org uses TLA (Three-Letter Abbreviation) such as ENG, BEL, …
     * which match our `teams.iso3` column directly for most countries. Pass
     * through as-is; the upstream lookup also tries name-fallback so small
     * deviations (e.g. KOR vs RKO) still resolve.
     */
    private function isoFromTla(?string $tla): ?string
    {
        if (!$tla) return null;
        return strtoupper($tla);
    }
}
