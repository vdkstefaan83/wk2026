<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::get('DB_PORT', '3306');
        $db   = Config::get('DB_NAME', 'wk2026');
        $user = Config::get('DB_USER', 'root');
        $pass = Config::get('DB_PASS', '');
        $charset = Config::get('DB_CHARSET', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchColumn(string $sql, array $params = []): mixed
    {
        return self::query($sql, $params)->fetchColumn();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(fn($c) => "`{$c}`", $cols)),
            implode(', ', $placeholders)
        );
        self::query($sql, $data);
        return (int) self::connection()->lastInsertId();
    }

    public static function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn($c) => "`{$c}` = :set_{$c}", array_keys($data)));
        $whereClause = implode(' AND ', array_map(fn($c) => "`{$c}` = :w_{$c}", array_keys($where)));
        $params = [];
        foreach ($data as $k => $v)  { $params['set_' . $k] = $v; }
        foreach ($where as $k => $v) { $params['w_' . $k] = $v; }
        return self::query("UPDATE `{$table}` SET {$set} WHERE {$whereClause}", $params)->rowCount();
    }

    public static function delete(string $table, array $where): int
    {
        $whereClause = implode(' AND ', array_map(fn($c) => "`{$c}` = :{$c}", array_keys($where)));
        return self::query("DELETE FROM `{$table}` WHERE {$whereClause}", $where)->rowCount();
    }

    public static function beginTransaction(): void { self::connection()->beginTransaction(); }
    public static function commit(): void { self::connection()->commit(); }
    public static function rollBack(): void
    {
        if (self::connection()->inTransaction()) {
            self::connection()->rollBack();
        }
    }
}
