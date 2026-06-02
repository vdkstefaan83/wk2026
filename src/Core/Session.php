<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $lifetime = 8 * 3600; // 8 hours

            // Use a project-local session directory so the system-wide PHP
            // session GC cron (Debian/Ubuntu/RHEL) can't wipe our files
            // based on its own short gc_maxlifetime.
            $sessionDir = Config::basePath('storage/sessions');
            if (!is_dir($sessionDir)) {
                @mkdir($sessionDir, 0775, true);
            }
            if (is_writable($sessionDir)) {
                ini_set('session.save_path', $sessionDir);
                // Tighten GC probability so old files in our private dir
                // still get cleaned (1/100 requests is plenty for our scale).
                ini_set('session.gc_probability', '1');
                ini_set('session.gc_divisor',     '100');
            }

            ini_set('session.gc_maxlifetime',   (string) $lifetime);
            ini_set('session.cookie_lifetime',  (string) $lifetime);
            ini_set('session.use_strict_mode',  '1');

            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_name('wk2026_sess');
            session_start();
        }
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type][] = $message;
    }

    public static function flashAll(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    public static function setOld(array $data): void
    {
        $_SESSION['_old'] = $data;
    }

    public static function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_old'][$key] ?? $default;
    }

    public static function clearOld(): void
    {
        unset($_SESSION['_old']);
    }

    public static function csrfToken(): string
    {
        return $_SESSION['_csrf'] ?? '';
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token) && hash_equals((string)($_SESSION['_csrf'] ?? ''), $token);
    }
}
