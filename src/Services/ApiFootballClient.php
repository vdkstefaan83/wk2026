<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Minimal API-Football (api-sports.io) client.
 * Docs: https://www.api-football.com/documentation-v3
 *
 * Authentication uses x-apisports-key header (direct subscription) or
 * x-rapidapi-key (via RapidAPI). The client picks whichever you configured.
 */
final class ApiFootballClient
{
    public function __construct(
        private ?string $apiKey   = null,
        private ?string $baseUrl  = null,
        private bool    $rapidApi = false,
    ) {
        $this->apiKey  ??= (string) Config::get('API_FOOTBALL_KEY', '');
        $this->baseUrl ??= (string) Config::get('API_FOOTBALL_BASE_URL', 'https://v3.football.api-sports.io');
        $this->rapidApi  = (bool)   Config::get('API_FOOTBALL_VIA_RAPIDAPI', $this->rapidApi);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function fixtures(int $leagueId, int $season): array
    {
        return $this->get('/fixtures', ['league' => $leagueId, 'season' => $season]);
    }

    public function topScorers(int $leagueId, int $season): array
    {
        return $this->get('/players/topscorers', ['league' => $leagueId, 'season' => $season]);
    }

    private function get(string $path, array $query): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('API_FOOTBALL_KEY is not configured.');
        }
        $url = rtrim($this->baseUrl, '/') . $path . '?' . http_build_query($query);

        $headers = $this->rapidApi
            ? [
                'x-rapidapi-key: ' . $this->apiKey,
                'x-rapidapi-host: ' . parse_url($this->baseUrl, PHP_URL_HOST),
            ]
            : ['x-apisports-key: ' . $this->apiKey];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("API-Football request failed: {$err}");
        }
        if ($http >= 400) {
            throw new \RuntimeException("API-Football returned HTTP {$http}: " . substr((string)$body, 0, 500));
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('API-Football returned invalid JSON');
        }
        if (!empty($data['errors']) && $data['errors'] !== []) {
            $first = is_array($data['errors']) ? json_encode($data['errors']) : (string)$data['errors'];
            throw new \RuntimeException('API-Football error: ' . $first);
        }
        return $data['response'] ?? [];
    }
}
