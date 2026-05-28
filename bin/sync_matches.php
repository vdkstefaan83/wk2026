<?php
declare(strict_types=1);

/**
 * CLI sync entry point. Designed for cron:
 *
 *   * /15 * * * *  www-data  cd /var/www/html/public/wk2026 && /usr/bin/php bin/sync_matches.php >> storage/logs/sync.log 2>&1
 *
 * Flags:
 *   --topscorer   Force a topscorer fetch (otherwise throttled to once / 6h).
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\MatchSyncService;

Config::load(dirname(__DIR__));

$forceTop = in_array('--topscorer', $argv, true);

$svc = new MatchSyncService();
$result = $svc->sync($forceTop);

$ts = date('Y-m-d H:i:s');
echo "[$ts] updated={$result['updated']} finals_recomputed=" . ($result['finals_recomputed'] ? '1' : '0');
if ($result['topscorer']) {
    echo " topscorer=" . $result['topscorer']['player'] . " ({$result['topscorer']['goals']})";
}
if ($result['errors']) {
    echo " errors=" . count($result['errors']);
    foreach ($result['errors'] as $e) echo "\n  - $e";
}
echo "\n";

exit($result['errors'] ? 1 : 0);
