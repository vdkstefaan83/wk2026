<?php
declare(strict_types=1);

/**
 * Add the default prize_distribution setting to existing installs.
 * Idempotent — does nothing if the setting already exists.
 *
 *   php migrations/migrate_add_prize_distribution.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));

$default = "Winner: 50% of the pot\n"
         . "2nd prize: 30% of the pot\n"
         . "3rd prize: 15% of the pot\n"
         . "7th place: USB cup warmer\n"
         . "17th place: USB cup warmer\n"
         . "29th prize: Smoothie maker\n"
         . "Last prize: 5% of the pot";

$exists = (int) Database::fetchColumn('SELECT COUNT(*) FROM settings WHERE `key` = ?', ['prize_distribution']);
if ($exists) {
    echo "✓ prize_distribution already exists — leaving it untouched.\n";
} else {
    Database::insert('settings', [
        'key'        => 'prize_distribution',
        'value'      => $default,
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    echo "✓ prize_distribution inserted with the default 7-line distribution.\n";
}
