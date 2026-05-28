<?php
declare(strict_types=1);

/**
 * Migration & seed runner.
 *   php migrations/migrate.php           # schema + seeds + match generation
 *   php migrations/migrate.php --fresh   # drop and rebuild
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));

$pdo = Database::connection();

echo "→ Schema...\n";
$pdo->exec(file_get_contents(__DIR__ . '/001_schema.sql'));

echo "→ Settings + email templates...\n";
$pdo->exec(file_get_contents(__DIR__ . '/seeds/001_settings.sql'));

echo "→ Groups + teams...\n";
$pdo->exec(file_get_contents(__DIR__ . '/seeds/002_groups_teams.sql'));

echo "→ Generating group-stage matches (72)...\n";
$groups = Database::fetchAll('SELECT id, code FROM team_groups ORDER BY sort_order');
$matchNo = 1;
// 6 group matches per group following standard round-robin schedule
// pairings (0,1) (2,3) (0,2) (1,3) (0,3) (1,2)
$pairings = [[0,1],[2,3],[0,2],[1,3],[0,3],[1,2]];
foreach ($groups as $g) {
    $teams = Database::fetchAll('SELECT id FROM teams WHERE group_id = ? ORDER BY id', [$g['id']]);
    if (count($teams) !== 4) {
        echo "  ! Group {$g['code']} has " . count($teams) . " teams (expected 4) – skipping\n";
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

echo "→ Generating knockout match shells (R32 → Final)...\n";
$ko = [
    ['stage'=>'r32', 'count'=>16],
    ['stage'=>'r16', 'count'=>8],
    ['stage'=>'qf',  'count'=>4],
    ['stage'=>'sf',  'count'=>2],
    ['stage'=>'final','count'=>1],
];
foreach ($ko as $k) {
    for ($i = 1; $i <= $k['count']; $i++) {
        Database::insert('matches', [
            'stage'        => $k['stage'],
            'match_number' => $matchNo++,
        ]);
    }
}

echo "→ Default admin (admin@psb.ugent.be / admin) ...\n";
Database::query(
    'INSERT INTO users (email,name,password_hash,auth_provider,is_admin,created_at) VALUES (?,?,?,?,1,NOW())
     ON DUPLICATE KEY UPDATE is_admin = 1',
    ['admin@psb.ugent.be', 'Admin', password_hash('admin', PASSWORD_DEFAULT), 'db']
);

echo "✓ Done.\n";
