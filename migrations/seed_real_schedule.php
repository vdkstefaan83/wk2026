<?php
declare(strict_types=1);

/**
 * Update the official FIFA WC2026 kick-off times.
 * Source: KPN.com (CEST / Belgian local time, full 72 group matches + 31 knock-out slots).
 *
 *   php migrations/seed_real_schedule.php          # update everything
 *   php migrations/seed_real_schedule.php --dry    # show what would change
 *
 * Group matches are matched by team pair (in either order).
 * Knockout matches are matched by slot code (R32-01 → match_number 73, etc.).
 * Venues are kept as-is — KPN does not publish them; admin can fill via /admin/matches.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));
$dry = in_array('--dry', $argv, true);

// [datetime, group, homeTeamName, awayTeamName]  — names match DB (English)
$groupMatches = [
    // Group A
    ['2026-06-11 21:00','A','Mexico','South Africa'],
    ['2026-06-12 04:00','A','South Korea','Czech Republic'],
    ['2026-06-18 18:00','A','Czech Republic','South Africa'],
    ['2026-06-19 03:00','A','Mexico','South Korea'],
    ['2026-06-25 03:00','A','South Africa','South Korea'],
    ['2026-06-25 03:00','A','Czech Republic','Mexico'],

    // Group B
    ['2026-06-12 21:00','B','Canada','Bosnia and Herzegovina'],
    ['2026-06-13 21:00','B','Qatar','Switzerland'],
    ['2026-06-18 21:00','B','Switzerland','Bosnia and Herzegovina'],
    ['2026-06-19 00:00','B','Canada','Qatar'],
    ['2026-06-24 21:00','B','Switzerland','Canada'],
    ['2026-06-24 21:00','B','Bosnia and Herzegovina','Qatar'],

    // Group C
    ['2026-06-14 00:00','C','Brazil','Morocco'],
    ['2026-06-14 03:00','C','Haiti','Scotland'],
    ['2026-06-20 00:00','C','Scotland','Morocco'],
    ['2026-06-20 03:00','C','Brazil','Haiti'],
    ['2026-06-25 00:00','C','Morocco','Haiti'],
    ['2026-06-25 00:00','C','Scotland','Brazil'],

    // Group D
    ['2026-06-13 03:00','D','United States','Paraguay'],
    ['2026-06-14 06:00','D','Australia','Turkey'],
    ['2026-06-19 21:00','D','United States','Australia'],
    ['2026-06-20 06:00','D','Turkey','Paraguay'],
    ['2026-06-26 04:00','D','Paraguay','Australia'],
    ['2026-06-26 04:00','D','Turkey','United States'],

    // Group E
    ['2026-06-14 19:00','E','Germany','Curaçao'],
    ['2026-06-15 01:00','E','Ivory Coast','Ecuador'],
    ['2026-06-20 22:00','E','Germany','Ivory Coast'],
    ['2026-06-21 02:00','E','Ecuador','Curaçao'],
    ['2026-06-25 22:00','E','Curaçao','Ivory Coast'],
    ['2026-06-25 22:00','E','Ecuador','Germany'],

    // Group F
    ['2026-06-14 22:00','F','Netherlands','Japan'],
    ['2026-06-15 04:00','F','Sweden','Tunisia'],
    ['2026-06-20 19:00','F','Netherlands','Sweden'],
    ['2026-06-21 06:00','F','Tunisia','Japan'],
    ['2026-06-26 01:00','F','Japan','Sweden'],
    ['2026-06-26 01:00','F','Tunisia','Netherlands'],

    // Group G
    ['2026-06-15 21:00','G','Belgium','Egypt'],
    ['2026-06-16 03:00','G','Iran','New Zealand'],
    ['2026-06-21 21:00','G','Belgium','Iran'],
    ['2026-06-22 03:00','G','New Zealand','Egypt'],
    ['2026-06-27 05:00','G','Egypt','Iran'],
    ['2026-06-27 05:00','G','New Zealand','Belgium'],

    // Group H
    ['2026-06-15 18:00','H','Spain','Cape Verde'],
    ['2026-06-16 00:00','H','Saudi Arabia','Uruguay'],
    ['2026-06-21 18:00','H','Spain','Saudi Arabia'],
    ['2026-06-22 00:00','H','Uruguay','Cape Verde'],
    ['2026-06-27 02:00','H','Cape Verde','Saudi Arabia'],
    ['2026-06-27 02:00','H','Uruguay','Spain'],

    // Group I
    ['2026-06-16 21:00','I','France','Senegal'],
    ['2026-06-17 00:00','I','Iraq','Norway'],
    ['2026-06-22 23:00','I','France','Iraq'],
    ['2026-06-23 02:00','I','Norway','Senegal'],
    ['2026-06-26 21:00','I','Norway','France'],
    ['2026-06-26 21:00','I','Senegal','Iraq'],

    // Group J
    ['2026-06-17 03:00','J','Argentina','Algeria'],
    ['2026-06-17 06:00','J','Austria','Jordan'],
    ['2026-06-22 19:00','J','Argentina','Austria'],
    ['2026-06-23 04:00','J','Jordan','Algeria'],
    ['2026-06-28 04:00','J','Algeria','Austria'],
    ['2026-06-28 04:00','J','Jordan','Argentina'],

    // Group K
    ['2026-06-17 19:00','K','Portugal','DR Congo'],
    ['2026-06-18 04:00','K','Uzbekistan','Colombia'],
    ['2026-06-23 19:00','K','Portugal','Uzbekistan'],
    ['2026-06-24 04:00','K','Colombia','DR Congo'],
    ['2026-06-28 01:30','K','Colombia','Portugal'],
    ['2026-06-28 01:30','K','DR Congo','Uzbekistan'],

    // Group L
    ['2026-06-17 22:00','L','England','Croatia'],
    ['2026-06-18 01:00','L','Ghana','Panama'],
    ['2026-06-23 22:00','L','England','Ghana'],
    ['2026-06-24 01:00','L','Panama','Croatia'],
    ['2026-06-27 23:00','L','Croatia','Ghana'],
    ['2026-06-27 23:00','L','Panama','England'],
];

// Knockout: slot code → datetime
$ko = [
    'R32-01' => '2026-06-28 21:00',
    'R32-02' => '2026-06-29 19:00',
    'R32-03' => '2026-06-29 22:30',
    'R32-04' => '2026-06-30 03:00',
    'R32-05' => '2026-06-30 19:00',
    'R32-06' => '2026-06-30 23:00',
    'R32-07' => '2026-07-01 03:00',
    'R32-08' => '2026-07-01 18:00',
    'R32-09' => '2026-07-01 22:00',
    'R32-10' => '2026-07-02 02:00',
    'R32-11' => '2026-07-02 21:00',
    'R32-12' => '2026-07-03 01:00',
    'R32-13' => '2026-07-03 05:00',
    'R32-14' => '2026-07-03 20:00',
    'R32-15' => '2026-07-04 00:00',
    'R32-16' => '2026-07-04 03:30',

    'R16-01' => '2026-07-04 19:00',
    'R16-02' => '2026-07-04 23:00',
    'R16-03' => '2026-07-05 22:00',
    'R16-04' => '2026-07-06 02:00',
    'R16-05' => '2026-07-06 21:00',
    'R16-06' => '2026-07-07 02:00',
    'R16-07' => '2026-07-07 18:00',
    'R16-08' => '2026-07-07 22:00',

    'QF-01'  => '2026-07-09 22:00',
    'QF-02'  => '2026-07-10 21:00',
    'QF-03'  => '2026-07-11 23:00',
    'QF-04'  => '2026-07-12 03:00',

    'SF-01'  => '2026-07-14 21:00',
    'SF-02'  => '2026-07-15 21:00',

    'F-01'   => '2026-07-19 21:00',
];

$updated = 0;
$missing = [];

$teamId = fn(string $name) => (int) Database::fetchColumn('SELECT id FROM teams WHERE name = ?', [$name]);

foreach ($groupMatches as [$dt, $group, $home, $away]) {
    $hid = $teamId($home);
    $aid = $teamId($away);
    if (!$hid || !$aid) {
        $missing[] = "Team not in DB: {$home} or {$away}";
        continue;
    }
    $row = Database::fetch(
        'SELECT id FROM matches
          WHERE stage = "group"
            AND ((home_team_id = ? AND away_team_id = ?)
              OR (home_team_id = ? AND away_team_id = ?))
          LIMIT 1',
        [$hid, $aid, $aid, $hid]
    );
    if (!$row) {
        $missing[] = "No DB match for {$home} vs {$away}";
        continue;
    }
    if ($dry) {
        echo "  GRP {$group}  {$dt}  {$home} vs {$away}\n";
        continue;
    }
    Database::update('matches', ['kickoff_at' => $dt], ['id' => $row['id']]);
    $updated++;
}

$slotToMatchNumber = [];
$base = 72;
foreach (['R32' => 16, 'R16' => 8, 'QF' => 4, 'SF' => 2, 'F' => 1] as $prefix => $count) {
    for ($i = 1; $i <= $count; $i++) {
        $slotToMatchNumber[sprintf('%s-%02d', $prefix, $i)] = ++$base;
    }
}
foreach ($ko as $slot => $dt) {
    if (!isset($slotToMatchNumber[$slot])) {
        $missing[] = "Unknown slot: {$slot}";
        continue;
    }
    $matchNo = $slotToMatchNumber[$slot];
    $row = Database::fetch('SELECT id FROM matches WHERE match_number = ?', [$matchNo]);
    if (!$row) {
        $missing[] = "No DB match for {$slot} (number={$matchNo})";
        continue;
    }
    if ($dry) {
        echo "  KO   {$slot}  {$dt}\n";
        continue;
    }
    Database::update('matches', ['kickoff_at' => $dt], ['id' => $row['id']]);
    $updated++;
}

echo "✓ {$updated} matches updated" . ($dry ? ' (dry run)' : '') . "\n";
if ($missing) echo "⚠ Issues:\n  - " . implode("\n  - ", $missing) . "\n";
echo "ℹ Times in CEST (Belgian local time). Source: KPN.com\n";
echo "  Venues are not touched — fill via /admin/matches if you need them.\n";
