<?php
declare(strict_types=1);

/**
 * One-shot bootstrap: enrich every finished match that involves a player
 * picked as top scorer. Loops until match_enriched covers everything.
 *
 *   /usr/bin/php bin/bootstrap_enrichment.php
 *
 * Respects football-data.org's 10 req/min limit by sleeping 7s between
 * /matches/{id} calls. Safe to interrupt and re-run — each sync cycle is
 * idempotent and the script picks up from match_enriched on the next run.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\MatchSyncService;

Config::load(dirname(__DIR__));

echo "Bootstrap top-scorer enrichment\n";
echo "===============================\n\n";

// Run sync repeatedly until no more matches get enriched per pass.
$totalCalls = 0;
$pass = 0;
while (true) {
    $pass++;
    echo "Pass {$pass}…\n";
    $beforeCount = (int) Database::fetchColumn('SELECT COUNT(*) FROM match_enriched');
    $svc = new MatchSyncService();
    $result = $svc->sync(false);
    $afterCount = (int) Database::fetchColumn('SELECT COUNT(*) FROM match_enriched');
    $enrichedThisPass = $afterCount - $beforeCount;
    $totalCalls += $enrichedThisPass;

    echo "  enriched this pass: {$enrichedThisPass}\n";
    if (!empty($result['enrichment'])) {
        echo "  players_updated:   " . ($result['enrichment']['players_updated'] ?? 0) . "\n";
        echo "  total_picked:      " . ($result['enrichment']['total_picked'] ?? 0) . "\n";
    }
    if (!empty($result['errors'])) {
        echo "  errors:\n";
        foreach ($result['errors'] as $e) echo "    - {$e}\n";
    }

    if ($enrichedThisPass === 0) {
        echo "\nDone. Total matches enriched: {$afterCount}\n";
        break;
    }

    // Stay under 10 req/min: each pass uses up to 8 API calls
    // (1 fixtures + 1 scorers + 6 enrichments). One minute between passes
    // resets the counter cleanly.
    if ($enrichedThisPass > 0) {
        echo "  sleeping 60s to respect rate limit…\n";
        sleep(60);
    }
}

// Summary
$picked = Database::fetchAll(
    'SELECT DISTINCT p.id, p.name, t.name AS team
       FROM forms f
       JOIN players p ON p.id = f.topscorer_player_id
  LEFT JOIN teams t ON t.id = p.team_id
      WHERE f.status = "submitted"'
);
echo "\nPicked-as-top-scorer players and their goal counts:\n";
foreach ($picked as $p) {
    $goals = (int) (Database::fetchColumn(
        'SELECT value FROM settings WHERE `key` = ?',
        ['predicted_topscorer_goals_for_' . (int) $p['id']]
    ) ?: 0);
    printf("  %3d  %-30s  %-20s  %d\n", $p['id'], $p['name'], $p['team'] ?? '-', $goals);
}
