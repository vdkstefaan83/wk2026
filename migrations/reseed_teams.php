<?php
declare(strict_types=1);

/**
 * Reseed teams + matches using the official WK2026 draw.
 *
 *   php migrations/reseed_teams.php          # safe — refuses if any predictions exist
 *   php migrations/reseed_teams.php --force  # wipes predictions/matches/teams and regenerates
 *
 * Use this when the team list in /admin/teams is stale and you want to
 * replace it with the current seed (002_groups_teams.sql).
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));
$force = in_array('--force', $argv, true);

$predCount = (int) Database::fetchColumn('SELECT COUNT(*) FROM predictions');
$formCount = (int) Database::fetchColumn('SELECT COUNT(*) FROM forms');

if (($predCount > 0 || $formCount > 0) && !$force) {
    fwrite(STDERR, "✋ Er bestaan al {$formCount} formulieren en {$predCount} voorspellingen.\n");
    fwrite(STDERR, "    Dit script wipet matches + teams + voorspellingen. Run met --force om te bevestigen.\n");
    exit(1);
}

$pdo = Database::connection();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
echo "→ wipen…\n";
$pdo->exec('TRUNCATE TABLE predictions');
$pdo->exec('TRUNCATE TABLE matches');
$pdo->exec('TRUNCATE TABLE teams');
$pdo->exec('TRUNCATE TABLE team_groups');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "→ seed teams + groepen…\n";
$pdo->exec(file_get_contents(__DIR__ . '/seeds/002_groups_teams.sql'));

echo "→ groepswedstrijden genereren (72)…\n";
$groups = Database::fetchAll('SELECT id, code FROM team_groups ORDER BY sort_order');
$pairings = [[0,1],[2,3],[0,2],[1,3],[0,3],[1,2]];
$matchNo = 1;
foreach ($groups as $g) {
    $teams = Database::fetchAll('SELECT id FROM teams WHERE group_id = ? ORDER BY id', [$g['id']]);
    if (count($teams) !== 4) {
        fwrite(STDERR, "  ! Groep {$g['code']} heeft " . count($teams) . " teams — overgeslagen\n");
        continue;
    }
    foreach ($pairings as $p) {
        Database::insert('matches', [
            'stage'        => 'group',
            'match_number' => $matchNo++,
            'group_id'     => $g['id'],
            'home_team_id' => $teams[$p[0]]['id'],
            'away_team_id' => $teams[$p[1]]['id'],
        ]);
    }
}

echo "→ knock-out shells genereren…\n";
foreach ([['r32',16],['r16',8],['qf',4],['sf',2],['final',1]] as [$stage, $count]) {
    for ($i = 1; $i <= $count; $i++) {
        Database::insert('matches', ['stage' => $stage, 'match_number' => $matchNo++]);
    }
}

echo "✓ Klaar. Run optioneel `php migrations/seed_schedule.php` voor data + locaties.\n";
