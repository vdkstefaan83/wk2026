<?php
declare(strict_types=1);

namespace App\Core;

use Dotenv\Dotenv;

final class Config
{
    private static array $items = [];

    public static function load(string $basePath): void
    {
        if (is_file($basePath . '/.env')) {
            Dotenv::createImmutable($basePath)->safeLoad();
        }
        self::$items['base_path'] = $basePath;
        // CLI entry points (cron, debug scripts) bypass App::run() — make sure
        // the timezone is still correct so strtotime() reconciles naive DB
        // datetimes against ISO-8601 UTC strings the same way the web does.
        date_default_timezone_set((string) self::get('APP_TIMEZONE', 'Europe/Brussels'));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$items)) {
            return self::$items[$key];
        }
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        return match (strtolower((string)$value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    public static function set(string $key, mixed $value): void
    {
        self::$items[$key] = $value;
    }

    public static function basePath(string $append = ''): string
    {
        $base = self::$items['base_path'] ?? dirname(__DIR__, 2);
        return $append ? $base . '/' . ltrim($append, '/') : $base;
    }
}
