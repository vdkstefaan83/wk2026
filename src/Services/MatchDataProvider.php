<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Provider-agnostic shape returned by every backend (API-Football,
 * football-data.org, …). MatchSyncService consumes this normalized form
 * so the rest of the app stays decoupled from any specific API's quirks.
 */
interface MatchDataProvider
{
    public function isConfigured(): bool;

    /** Short identifier used in logs / admin flashes (e.g. "api-football"). */
    public function name(): string;

    /**
     * @return list<array{
     *   home_iso: ?string, home_name: ?string,
     *   away_iso: ?string, away_name: ?string,
     *   home_goals: ?int, away_goals: ?int,
     *   is_final: bool,
     *   kickoff_at: ?string,
     *   stage: ?string                     // 'group', 'r32', 'r16', 'qf', 'sf', 'final', or null
     * }>
     */
    public function fixtures(): array;

    /**
     * @return list<array{
     *   name: string, goals: int,
     *   team_iso: ?string, team_name: ?string
     * }>
     */
    public function topScorers(): array;
}
