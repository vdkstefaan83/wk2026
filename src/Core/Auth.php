<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public static function user(): ?array
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return null;
        }
        static $cache = [];
        if (isset($cache[$userId])) {
            return $cache[$userId];
        }
        $u = Database::fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]);
        return $cache[$userId] = $u ?: null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function loginAs(int $userId): void
    {
        session_regenerate_id(true);
        Session::set('user_id', $userId);
    }

    public static function logout(): void
    {
        Session::destroy();
    }

    public static function attemptDb(string $email, string $password): ?array
    {
        $user = Database::fetch('SELECT * FROM users WHERE email = ? AND auth_provider = ? LIMIT 1', [strtolower(trim($email)), 'db']);
        if (!$user) {
            return null;
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }
        self::loginAs((int) $user['id']);
        return $user;
    }

    public static function registerDb(string $email, string $name, string $password): array
    {
        $email = strtolower(trim($email));
        $exists = Database::fetchColumn('SELECT id FROM users WHERE email = ?', [$email]);
        if ($exists) {
            throw new \RuntimeException('Er bestaat al een account met dit e-mailadres.');
        }
        $id = Database::insert('users', [
            'email'         => $email,
            'name'          => $name,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'auth_provider' => 'db',
            'is_admin'      => 0,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
        self::loginAs($id);
        return Database::fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function upsertFromOidc(array $claims): array
    {
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $name  = trim((string) ($claims['name'] ?? ($claims['preferred_username'] ?? $email)));
        $sub   = (string) ($claims['sub'] ?? '');

        if ($email === '' || $sub === '') {
            throw new \RuntimeException('OIDC claims missen email of sub.');
        }
        $user = Database::fetch('SELECT * FROM users WHERE oidc_sub = ? OR email = ? LIMIT 1', [$sub, $email]);
        if ($user) {
            Database::update('users', [
                'name'          => $name ?: $user['name'],
                'oidc_sub'      => $sub,
                'auth_provider' => 'keycloak',
                'last_login_at' => date('Y-m-d H:i:s'),
            ], ['id' => $user['id']]);
            $user = Database::fetch('SELECT * FROM users WHERE id = ?', [$user['id']]);
        } else {
            $id = Database::insert('users', [
                'email'         => $email,
                'name'          => $name ?: $email,
                'oidc_sub'      => $sub,
                'auth_provider' => 'keycloak',
                'is_admin'      => 0,
                'created_at'    => date('Y-m-d H:i:s'),
                'last_login_at' => date('Y-m-d H:i:s'),
            ]);
            $user = Database::fetch('SELECT * FROM users WHERE id = ?', [$id]);
        }
        self::loginAs((int) $user['id']);
        return $user;
    }
}
