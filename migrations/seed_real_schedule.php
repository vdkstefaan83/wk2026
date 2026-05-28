<?php
declare(strict_types=1);

/**
 * Update the real FIFA WC2026 kick-off times and venues.
 * Source: ESPN.nl (CEST / Belgian local time).
 *
 *   php migrations/seed_real_schedule.php          # update everything
 *   php migrations/seed_real_schedule.php --dry    # show what would change
 *
 * Group matches are matched by team pair (in either order).
 * Knockout matches are matched by slot code (R32-01 → match_number 73, etc.).
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));
$dry = in_array('--dry', $argv, true);

// ---------- Source data (ESPN.nl) ----------

// [datetime, group, homeTeamName, awayTeamName, venue]  — names match DB (English)
$groupMatches = [
    ['2026-06-11 03:00','A','Mexico','South Africa','Mexico City'],
    ['2026-06-12 10:00','A','South Korea','Czech Republic','Guadalajara'],
    ['2026-06-18 10:00','A','Czech Republic','South Africa','Atlanta'],
    ['2026-06-25 05:00','A','Czech Republic','Mexico','Mexico City'],
    ['2026-06-25 05:00','A','South Africa','South Korea','Monterrey'],

    ['2026-06-12 03:00','B','Canada','Bosnia and Herzegovina','Toronto'],
    ['2026-06-13 03:00','B','Qatar','Switzerland','San Francisco Bay'],
    ['2026-06-18 03:00','B','Switzerland','Bosnia and Herzegovina','Los Angeles'],
    ['2026-06-24 03:00','B','Switzerland','Canada','Vancouver'],
    ['2026-06-24 03:00','B','Bosnia and Herzegovina','Qatar','Seattle'],

    ['2026-06-14 06:00','C','Brazil','Morocco','New York/New Jersey'],
    ['2026-06-14 09:00','C','Haiti','Scotland','Boston'],
    ['2026-06-20 06:00','C','Scotland','Morocco','Boston'],
    ['2026-06-20 08:30','C','Brazil','Haiti','Philadelphia'],
    ['2026-06-27 07:00','C','Morocco','Haiti','Atlanta'],
    ['2026-06-27 07:00','C','Scotland','Brazil','Miami'],

    ['2026-06-13 09:00','D','United States','Paraguay','Los Angeles'],
    ['2026-06-13 12:00','D','Australia','Turkey','Vancouver'],
    ['2026-06-26 06:00','D','Turkey','United States','Los Angeles'],
    ['2026-06-26 06:00','D','Paraguay','Australia','San Francisco Bay'],

    ['2026-06-14 01:00','E','Germany','Curaçao','Houston'],
    ['2026-06-15 07:00','E','Ivory Coast','Ecuador','Philadelphia'],
    ['2026-06-20 04:00','E','Germany','Ivory Coast','Toronto'],
    ['2026-06-21 01:00','E','Ecuador','Curaçao','Kansas City'],
    ['2026-06-25 04:00','E','Ecuador','Germany','New York/New Jersey'],
    ['2026-06-25 04:00','E','Curaçao','Ivory Coast','Philadelphia'],

    ['2026-06-15 00:00','F','Netherlands','Japan','Dallas'],
    ['2026-06-15 10:00','F','Sweden','Tunisia','Monterrey'],
    ['2026-06-18 06:00','F','Tunisia','Japan','Monterrey'],
    ['2026-06-20 01:00','F','Netherlands','Sweden','Houston'],
    ['2026-06-25 07:00','F','Japan','Sweden','Dallas'],
    ['2026-06-25 07:00','F','Tunisia','Netherlands','Kansas City'],

    ['2026-06-15 03:00','G','Belgium','Egypt','Seattle'],
    ['2026-06-16 03:00','G','Iran','New Zealand','Los Angeles'],
    ['2026-06-21 03:00','G','Belgium','Iran','Los Angeles'],
    ['2026-06-21 08:00','G','New Zealand','Egypt','Vancouver'],
    ['2026-06-27 11:00','G','Egypt','Iran','Seattle'],
    ['2026-06-27 11:00','G','New Zealand','Belgium','Vancouver'],

    ['2026-06-15 00:00','H','Spain','Cape Verde','Atlanta'],
    ['2026-06-16 06:00','H','Saudi Arabia','Uruguay','Miami'],
    ['2026-06-21 00:00','H','Spain','Saudi Arabia','Atlanta'],
    ['2026-06-27 07:00','H','Cape Verde','Saudi Arabia','Houston'],
    ['2026-06-27 04:00','H','Uruguay','Spain','Guadalajara'],

    ['2026-06-16 03:00','I','France','Senegal','New York/New Jersey'],
    ['2026-06-17 06:00','I','Iraq','Norway','Boston'],
    ['2026-06-22 05:00','I','France','Iraq','Philadelphia'],
    ['2026-06-22 08:00','I','Norway','Senegal','New York/New Jersey'],
    ['2026-06-26 03:00','I','Norway','France','Boston'],
    ['2026-06-26 03:00','I','Senegal','Iraq','Toronto'],

    ['2026-06-16 12:00','J','Austria','Jordan','San Francisco Bay'],
    ['2026-06-17 09:00','J','Argentina','Algeria','Kansas City'],
    ['2026-06-22 01:00','J','Argentina','Austria','Dallas'],
    ['2026-06-23 11:00','J','Jordan','Algeria','San Francisco Bay'],
    ['2026-06-28 06:00','J','Algeria','Austria','Kansas City'],
    ['2026-06-28 06:00','J','Jordan','Argentina','Dallas'],

    ['2026-06-17 01:00','K','Portugal','DR Congo','Houston'],
    ['2026-06-18 10:00','K','Uzbekistan','Colombia','Mexico City'],
    ['2026-06-23 01:00','K','Portugal','Uzbekistan','Houston'],
    ['2026-06-24 10:00','K','Colombia','DR Congo','Guadalajara'],
    ['2026-06-28 03:30','K','Colombia','Portugal','Miami'],
    ['2026-06-28 03:30','K','DR Congo','Uzbekistan','Atlanta'],

    ['2026-06-18 00:00','L','England','Croatia','Dallas'],
    ['2026-06-18 07:00','L','Ghana','Panama','Toronto'],
    ['2026-06-23 00:00','L','England','Ghana','Boston'],
    ['2026-06-24 07:00','L','Panama','Croatia','Toronto'],
];

// Knockout: slot code → [datetime, venue]
$ko = [
    'R32-01' => ['2026-06-28 03:00','Los Angeles'],
    'R32-02' => ['2026-06-29 01:00','Houston'],
    'R32-03' => ['2026-06-29 04:30','Boston'],
    'R32-04' => ['2026-06-30 09:00','Monterrey'],
    'R32-05' => ['2026-06-30 01:00','Dallas'],
    'R32-06' => ['2026-06-30 05:00','New York/New Jersey'],
    'R32-07' => ['2026-07-01 09:00','Mexico City'],
    'R32-08' => ['2026-07-01 00:00','Atlanta'],
    'R32-09' => ['2026-07-01 04:00','San Francisco Bay'],
    'R32-10' => ['2026-07-02 08:00','Seattle'],
    'R32-11' => ['2026-07-02 03:00','Los Angeles'],
    'R32-12' => ['2026-07-03 07:00','Toronto'],
    'R32-13' => ['2026-07-03 11:00','Vancouver'],
    'R32-14' => ['2026-07-03 02:00','Dallas'],
    'R32-15' => ['2026-07-04 06:00','Miami'],
    'R32-16' => ['2026-07-04 09:30','Kansas City'],

    'R16-01' => ['2026-07-04 01:00','Houston'],
    'R16-02' => ['2026-07-04 05:00','Philadelphia'],
    'R16-03' => ['2026-07-05 04:00','New York/New Jersey'],
    'R16-04' => ['2026-07-06 08:00','Mexico City'],
    'R16-05' => ['2026-07-06 03:00','Dallas'],
    'R16-06' => ['2026-07-07 08:00','Seattle'],
    'R16-07' => ['2026-07-07 00:00','Atlanta'],
    'R16-08' => ['2026-07-07 04:00','Vancouver'],

    'QF-01'  => ['2026-07-09 04:00','Boston'],
    'QF-02'  => ['2026-07-10 03:00','Los Angeles'],
    'QF-03'  => ['2026-07-11 05:00','Miami'],
    'QF-04'  => ['2026-07-12 09:00','Kansas City'],

    'SF-01'  => ['2026-07-14 03:00','Dallas'],
    'SF-02'  => ['2026-07-15 03:00','Atlanta'],

    'F-01'   => ['2026-07-19 03:00','New York/New Jersey (MetLife Stadium)'],
];

// ---------- Apply ----------
$updated = 0;
$skipped = 0;
$missing = [];

// Helper: get team id by name
$teamId = function (string $name) {
    return (int) Database::fetchColumn('SELECT id FROM teams WHERE name = ?', [$name]);
};

// Group matches: match either home/away orientation.
foreach ($groupMatches as [$dt, $group, $home, $away, $venue]) {
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
        $missing[] = "No match in DB for {$home} vs {$away}";
        continue;
    }
    if ($dry) {
        echo "  GRP would update id={$row['id']} ({$home} vs {$away}) → {$dt} @ {$venue}\n";
        continue;
    }
    Database::update('matches', ['kickoff_at' => $dt, 'venue' => $venue], ['id' => $row['id']]);
    $updated++;
}

// Knockout: match by match_number based on slot position.
$slotToMatchNumber = [];
$base = 72; // group stage uses 1..72
foreach (['R32' => 16, 'R16' => 8, 'QF' => 4, 'SF' => 2, 'F' => 1] as $prefix => $count) {
    for ($i = 1; $i <= $count; $i++) {
        $slotToMatchNumber[sprintf('%s-%02d', $prefix, $i)] = ++$base;
    }
}

foreach ($ko as $slot => [$dt, $venue]) {
    if (!isset($slotToMatchNumber[$slot])) {
        $missing[] = "Unknown slot code: {$slot}";
        continue;
    }
    $matchNo = $slotToMatchNumber[$slot];
    $row = Database::fetch('SELECT id FROM matches WHERE match_number = ?', [$matchNo]);
    if (!$row) {
        $missing[] = "No match row for {$slot} (match_number={$matchNo})";
        continue;
    }
    if ($dry) {
        echo "  KO  would update id={$row['id']} ({$slot}) → {$dt} @ {$venue}\n";
        continue;
    }
    Database::update('matches', ['kickoff_at' => $dt, 'venue' => $venue], ['id' => $row['id']]);
    $updated++;
}

echo "✓ {$updated} matches updated" . ($dry ? ' (dry run, nothing actually written)' : '') . "\n";
if ($missing) {
    echo "⚠ Issues:\n  - " . implode("\n  - ", $missing) . "\n";
}
echo "ℹ Times are in CEST (Belgian local time). All matches are played in USA / Canada / Mexico.\n";
echo "  ESPN's published kalender does not include every single fixture — any match without a kickoff\n";
echo "  in the DB can be filled in manually via /admin/matches.\n";
