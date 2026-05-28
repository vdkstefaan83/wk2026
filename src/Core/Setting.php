<?php
declare(strict_types=1);

namespace App\Core;

final class Setting
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = Database::fetchAll('SELECT `key`, `value` FROM settings');
        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
        return self::$cache = $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $exists = Database::fetchColumn('SELECT 1 FROM settings WHERE `key` = ?', [$key]);
        if ($exists) {
            Database::update('settings', ['value' => (string) $value, 'updated_at' => date('Y-m-d H:i:s')], ['key' => $key]);
        } else {
            Database::insert('settings', ['key' => $key, 'value' => (string) $value, 'updated_at' => date('Y-m-d H:i:s')]);
        }
        self::$cache = null;
    }
}
