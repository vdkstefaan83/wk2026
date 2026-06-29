<?php
declare(strict_types=1);

namespace App\Services\Providers;

use App\Core\Config;
use App\Services\ApiFootballClient;
use App\Services\MatchDataProvider;

final class ApiFootballProvider implements MatchDataProvider
{
    public function __construct(private ApiFootballClient $client = new ApiFootballClient()) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function name(): string
    {
        return 'api-football';
    }

    public function fixtures(): array
    {
        $leagueId = (int) Config::get('API_FOOTBALL_LEAGUE_ID', 1);
        $season   = (int) Config::get('API_FOOTBALL_SEASON', 2026);
        $raw      = $this->client->fixtures($leagueId, $season);

        $out = [];
        foreach ($raw as $f) {
            $status     = (string) ($f['fixture']['status']['short'] ?? '');
            $isFinal    = in_array($status, ['FT', 'AET', 'PEN'], true);
            $homeGoals  = $f['goals']['home'] ?? null;
            $awayGoals  = $f['goals']['away'] ?? null;
            $out[] = [
                'home_iso'   => isset($f['teams']['home']['code']) ? strtoupper((string) $f['teams']['home']['code']) : null,
                'home_name'  => $f['teams']['home']['name'] ?? null,
                'away_iso'   => isset($f['teams']['away']['code']) ? strtoupper((string) $f['teams']['away']['code']) : null,
                'away_name'  => $f['teams']['away']['name'] ?? null,
                'home_goals' => $homeGoals === null ? null : (int) $homeGoals,
                'away_goals' => $awayGoals === null ? null : (int) $awayGoals,
                'is_final'   => $isFinal,
                'kickoff_at' => $f['fixture']['date'] ?? null,
                'stage'      => $this->mapRound((string) ($f['league']['round'] ?? '')),
            ];
        }
        return $out;
    }

    private function mapRound(string $round): ?string
    {
        $r = strtolower($round);
        if (str_contains($r, 'group'))            return 'group';
        if (str_contains($r, 'round of 32'))      return 'r32';
        if (str_contains($r, 'round of 16'))      return 'r16';
        if (str_contains($r, 'quarter'))          return 'qf';
        if (str_contains($r, 'semi'))             return 'sf';
        if (str_contains($r, '3rd') || str_contains($r, 'third')) return null;
        if (str_contains($r, 'final'))            return 'final';
        return null;
    }

    public function topScorers(): array
    {
        $leagueId = (int) Config::get('API_FOOTBALL_LEAGUE_ID', 1);
        $season   = (int) Config::get('API_FOOTBALL_SEASON', 2026);
        $raw      = $this->client->topScorers($leagueId, $season);

        $out = [];
        foreach ($raw as $p) {
            $team = $p['statistics'][0]['team'] ?? [];
            $out[] = [
                'name'      => (string) ($p['player']['name'] ?? ''),
                'goals'     => (int) ($p['statistics'][0]['goals']['total'] ?? 0),
                'team_iso'  => isset($team['code']) ? strtoupper((string) $team['code']) : null,
                'team_name' => $team['name'] ?? null,
            ];
        }
        return $out;
    }

    public function supportsMatchGoals(): bool
    {
        return false;
    }

    public function fetchMatchGoals(int $providerMatchId): array
    {
        return [];
    }
}
