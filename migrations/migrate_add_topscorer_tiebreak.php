<?php
declare(strict_types=1);

/**
 * Idempotent migration: add topscorer_custom_name + tiebreaker_value to `forms`
 * and ensure the tiebreaker settings rows exist. Safe to run multiple times.
 *
 *   php migrations/migrate_add_topscorer_tiebreak.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));

function columnExists(string $table, string $column): bool
{
    $dbName = Config::get('DB_NAME');
    $row = Database::fetch(
        'SELECT 1 FROM information_schema.columns
          WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1',
        [$dbName, $table, $column]
    );
    return (bool) $row;
}

if (!columnExists('forms', 'topscorer_custom_name')) {
    echo "→ Adding forms.topscorer_custom_name…\n";
    Database::query('ALTER TABLE forms ADD COLUMN topscorer_custom_name VARCHAR(128) NULL AFTER topscorer_player_id');
} else {
    echo "✓ forms.topscorer_custom_name already exists\n";
}

if (!columnExists('forms', 'tiebreaker_value')) {
    echo "→ Adding forms.tiebreaker_value…\n";
    Database::query('ALTER TABLE forms ADD COLUMN tiebreaker_value INT NULL AFTER topscorer_custom_name');
} else {
    echo "✓ forms.tiebreaker_value already exists\n";
}

$defaults = [
    'tiebreaker_question'      => 'Hoeveel doelpunten worden er in totaal gemaakt tijdens het toernooi?',
    'tiebreaker_correct_value' => '',
];
foreach ($defaults as $key => $value) {
    $exists = (int) Database::fetchColumn('SELECT 1 FROM settings WHERE `key` = ?', [$key]);
    if (!$exists) {
        echo "→ Adding setting `{$key}`…\n";
        Database::insert('settings', ['key' => $key, 'value' => $value, 'updated_at' => date('Y-m-d H:i:s')]);
    } else {
        echo "✓ setting `{$key}` already exists\n";
    }
}

echo "✓ Klaar.\n";
