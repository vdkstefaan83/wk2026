<?php
declare(strict_types=1);

/**
 * Idempotent migration: add tables that store per-match goal events.
 *
 * football-data.org's /scorers feed only returns the top 100. Picks that fall
 * outside that cutoff never get a goal count automatically. To fix this we
 * fetch per-match goal events from /matches/{id}, cache them in match_goals,
 * and remember which matches we've already enriched in match_enriched.
 *
 *   php migrations/migrate_add_match_goals.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));

function tableExists(string $table): bool
{
    $dbName = Config::get('DB_NAME');
    $row = Database::fetch(
        'SELECT 1 FROM information_schema.tables
          WHERE table_schema = ? AND table_name = ? LIMIT 1',
        [$dbName, $table]
    );
    return (bool) $row;
}

if (!tableExists('match_goals')) {
    echo "→ Creating match_goals…\n";
    Database::query('
        CREATE TABLE match_goals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider_match_id INT NOT NULL,
            scorer_name VARCHAR(255) NOT NULL,
            team_iso VARCHAR(8) NULL,
            minute INT NULL,
            is_own_goal TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_provider_match (provider_match_id),
            INDEX idx_scorer_name (scorer_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
} else {
    echo "✓ match_goals already exists\n";
}

if (!tableExists('match_enriched')) {
    echo "→ Creating match_enriched…\n";
    Database::query('
        CREATE TABLE match_enriched (
            provider_match_id INT PRIMARY KEY,
            enriched_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
} else {
    echo "✓ match_enriched already exists\n";
}

echo "✓ Done.\n";
