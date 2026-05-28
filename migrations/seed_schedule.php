<?php
declare(strict_types=1);

/**
 * Seed kickoff dates/times and venues for all matches.
 *
 *   php migrations/seed_schedule.php          # only fill matches without kickoff/venue
 *   php migrations/seed_schedule.php --force  # overwrite all matches
 *
 * Schedule follows the WK2026 framework (June 11 – July 19, 2026). The exact
 * official venue per match is not auto-seeded; venues rotate through the 16
 * host cities. Tweak afterwards via /admin/matches if you want exact FIFA data.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));

$force = in_array('--force', $argv, true);

// 16 official host cities for WK2026
$venues = [
    'Mexico-Stad (Estadio Azteca)',
    'Atlanta (Mercedes-Benz Stadium)',
    'Boston (Gillette Stadium)',
    'Dallas (AT&T Stadium)',
    'Guadalajara (Estadio Akron)',
    'Houston (NRG Stadium)',
    'Kansas City (Arrowhead Stadium)',
    'Los Angeles (SoFi Stadium)',
    'Miami (Hard Rock Stadium)',
    'Monterrey (Estadio BBVA)',
    'New York/New Jersey (MetLife Stadium)',
    'Philadelphia (Lincoln Financial Field)',
    'San Francisco Bay (Levi\'s Stadium)',
    'Seattle (Lumen Field)',
    'Toronto (BMO Field)',
    'Vancouver (BC Place)',
];

// Kickoff times shown in Belgian local time (CEST) for the audience here.
$kickoffTimes = ['18:00:00', '20:00:00', '21:00:00', '00:00:00'];

function updateRow(int $id, string $kickoff, string $venue, bool $force): void {
    $where = ['id' => $id];
    if (!$force) {
        $row = Database::fetch('SELECT kickoff_at, venue FROM matches WHERE id = ?', [$id]);
        if ($row && $row['kickoff_at']) {
            return; // keep what admin already set
        }
    }
    Database::update('matches', [
        'kickoff_at' => $kickoff,
        'venue'      => $venue,
    ], $where);
}

// ----------------------------------------------------------------------
// Group stage – June 11 – June 27, 2026 (72 matches, 5 per day for 15 days)
// ----------------------------------------------------------------------
$groupStart = new DateTime('2026-06-11');
$matchesPerDay = 5;

$groupMatches = Database::fetchAll(
    'SELECT id, match_number FROM matches WHERE stage = "group" ORDER BY match_number'
);
foreach ($groupMatches as $i => $m) {
    $dayOffset = intdiv($i, $matchesPerDay);
    $slot      = $i % $matchesPerDay;
    $date  = (clone $groupStart)->modify("+{$dayOffset} day");
    $time  = $kickoffTimes[$slot % count($kickoffTimes)];
    $venue = $venues[$i % count($venues)];
    updateRow((int)$m['id'], $date->format('Y-m-d') . ' ' . $time, $venue, $force);
}

// ----------------------------------------------------------------------
// Knock-out stages
// ----------------------------------------------------------------------
$schedule = [
    'r32'   => ['start' => '2026-06-28', 'per_day' => [3,3,3,3,2,2]], // 16 matches
    'r16'   => ['start' => '2026-07-04', 'per_day' => [2,2,2,2]],     //  8 matches
    'qf'    => ['start' => '2026-07-09', 'per_day' => [2,1,1]],       //  4 matches
    'sf'    => ['start' => '2026-07-14', 'per_day' => [1,1]],         //  2 matches
    'final' => ['start' => '2026-07-19', 'per_day' => [1]],           //  1 match
];

foreach ($schedule as $stage => $cfg) {
    $rows = Database::fetchAll(
        'SELECT id, match_number FROM matches WHERE stage = ? ORDER BY match_number', [$stage]
    );
    $idx = 0;
    $date = new DateTime($cfg['start']);
    foreach ($cfg['per_day'] as $perDay) {
        for ($k = 0; $k < $perDay && $idx < count($rows); $k++, $idx++) {
            $time = $kickoffTimes[$k % count($kickoffTimes)];
            $venue = $stage === 'final'
                ? 'New York/New Jersey (MetLife Stadium)'
                : $venues[((int)$rows[$idx]['match_number']) % count($venues)];
            updateRow((int)$rows[$idx]['id'], $date->format('Y-m-d') . ' ' . $time, $venue, $force);
        }
        $date->modify('+1 day');
    }
}

echo "✓ Schedule seeded (" . ($force ? 'all matches overwritten' : 'only empty kickoff_at filled') . ").\n";
