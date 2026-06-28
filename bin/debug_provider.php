<?php
declare(strict_types=1);

/**
 * Diagnostic: dump the first raw match + a top-scorer slice from whichever
 * MATCH_DATA_PROVIDER is configured. Useful when the sync reports "Unknown
 * team pair" or "empty response" and you need to see what the API is really
 * returning.
 *
 *   php bin/debug_provider.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\MatchSyncService;

Config::load(dirname(__DIR__));

$provider = MatchSyncService::pickProvider();
echo "Provider: " . $provider->name() . "\n";
echo "Configured: " . ($provider->isConfigured() ? 'yes' : 'no') . "\n\n";

try {
    echo "=== fixtures() (first 3 normalized rows) ===\n";
    $fixtures = $provider->fixtures();
    echo "Total fixtures returned: " . count($fixtures) . "\n";
    foreach (array_slice($fixtures, 0, 3) as $i => $f) {
        echo "[$i] " . json_encode($f, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    echo "\n=== first non-group (knockout) fixture ===\n";
    $ko = null;
    foreach ($fixtures as $f) {
        if (($f['stage'] ?? '') !== 'group') { $ko = $f; break; }
    }
    echo $ko ? json_encode($ko, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
             : "(none found — all 104 fixtures are group-stage in the provider response)\n";

    echo "\n=== stage values across all fixtures ===\n";
    $counts = [];
    foreach ($fixtures as $f) {
        $key = $f['stage'] ?? '(null)';
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    foreach ($counts as $stage => $n) {
        echo "  {$stage}: {$n}\n";
    }
} catch (\Throwable $e) {
    echo "fixtures error: " . $e->getMessage() . "\n";
}

try {
    echo "\n=== topScorers() (first 3 normalized rows) ===\n";
    $scorers = $provider->topScorers();
    echo "Total scorers returned: " . count($scorers) . "\n";
    foreach (array_slice($scorers, 0, 3) as $i => $s) {
        echo "[$i] " . json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (\Throwable $e) {
    echo "topScorers error: " . $e->getMessage() . "\n";
}

// If provider supports it, dump raw payload of first match for full diagnosis.
if (method_exists($provider, 'debugFirstMatch')) {
    try {
        echo "\n=== debugFirstMatch() — raw provider response ===\n";
        echo json_encode($provider->debugFirstMatch(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } catch (\Throwable $e) {
        echo "debugFirstMatch error: " . $e->getMessage() . "\n";
    }
}
