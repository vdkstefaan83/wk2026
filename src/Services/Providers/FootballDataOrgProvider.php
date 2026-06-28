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
    private ?int   $season;

    public function __construct()
    {
        $this->baseUrl     = rtrim((string) Config::get('FOOTBALL_DATA_ORG_BASE_URL', 'https://api.football-data.org/v4'), '/');
        $this->token       = (string) Config::get('FOOTBALL_DATA_ORG_TOKEN', '');
        $this->competition = (string) Config::get('FOOTBALL_DATA_ORG_COMPETITION', 'WC');
        $season            = Config::get('FOOTBALL_DATA_ORG_SEASON', '2026');
        $this->season      = $season === '' || $season === null ? null : (int) $season;
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
        $query = $this->season ? ['season' => $this->season] : [];
        $data = $this->get("/competitions/{$this->competition}/matches", $query);
        $matches = $data['matches'] ?? [];
        $out = [];
        foreach ($matches as $m) {
            $status = (string) ($m['status'] ?? '');
            $isFinal = $status === 'FINISHED';

            $homeGoals = $m['score']['fullTime']['home'] ?? null;
            $awayGoals = $m['score']['fullTime']['away'] ?? null;

            // football-data.org sometimes returns name=null when the draw / data
            // hasn't been ingested yet — fall back through tla / shortName / id.
            $home = $m['homeTeam'] ?? [];
            $away = $m['awayTeam'] ?? [];

            $out[] = [
                'home_iso'   => $this->iso($home),
                'home_name'  => $home['name'] ?? $home['shortName'] ?? null,
                'away_iso'   => $this->iso($away),
                'away_name'  => $away['name'] ?? $away['shortName'] ?? null,
                'home_goals' => $homeGoals === null ? null : (int) $homeGoals,
                'away_goals' => $awayGoals === null ? null : (int) $awayGoals,
                'is_final'   => $isFinal,
                'kickoff_at' => $m['utcDate'] ?? null,
                'stage'      => $this->mapStage((string) ($m['stage'] ?? '')),
            ];
        }
        return $out;
    }

    private function mapStage(string $apiStage): ?string
    {
        return [
            'GROUP_STAGE'    => 'group',
            'LAST_32'        => 'r32',
            'LAST_16'        => 'r16',
            'QUARTER_FINALS' => 'qf',
            'SEMI_FINALS'    => 'sf',
            'THIRD_PLACE'    => null,   // we don't model the 3rd place game
            'FINAL'          => 'final',
        ][$apiStage] ?? null;
    }

    public function topScorers(): array
    {
        $query = ['limit' => 100];
        if ($this->season) $query['season'] = $this->season;
        $data = $this->get("/competitions/{$this->competition}/scorers", $query);
        $rows = $data['scorers'] ?? [];
        $out = [];
        foreach ($rows as $r) {
            $team = $r['team'] ?? [];
            $out[] = [
                'name'      => (string) ($r['player']['name'] ?? ''),
                'goals'     => (int) ($r['goals'] ?? 0),
                'team_iso'  => $this->iso($team),
                'team_name' => $team['name'] ?? $team['shortName'] ?? null,
            ];
        }
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

        $maxAttempts = 3;
        $lastCurlErr = '';
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['X-Auth-Token: ' . $this->token],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($body !== false) {
                if ($http === 429 && $attempt < $maxAttempts) {
                    // Rate-limited — back off and retry.
                    sleep($attempt);
                    continue;
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

            // cURL transport error — retry with a short backoff.
            $lastCurlErr = $err ?: 'unknown';
            if ($attempt < $maxAttempts) {
                usleep(500_000 * $attempt); // 0.5s, 1.0s, 1.5s
            }
        }
        throw new \RuntimeException("football-data.org request failed after {$maxAttempts} attempts: {$lastCurlErr}");
    }

    /**
     * football-data.org uses TLA (Three-Letter Abbreviation) such as ENG, BEL, …
     * which match our `teams.iso3` column for most countries.
     */
    private function iso(array $team): ?string
    {
        $tla = $team['tla'] ?? null;
        if (!$tla) return null;
        return strtoupper((string) $tla);
    }

    /** Fetch & dump the first match — handy for diagnosing schema surprises. */
    public function debugFirstMatch(): array
    {
        $query = $this->season ? ['season' => $this->season] : [];
        $data = $this->get("/competitions/{$this->competition}/matches", $query);
        return $data['matches'][0] ?? [];
    }
}
