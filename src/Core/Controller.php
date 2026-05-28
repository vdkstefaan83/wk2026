<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $template, array $data = []): string
    {
        return View::render($template, $data);
    }

    protected function render(string $template, array $data = []): void
    {
        View::display($template, $data);
    }

    protected function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function redirect(string $path, int $status = 302): void
    {
        $url = str_starts_with($path, 'http') ? $path : rtrim((string) Config::get('APP_URL', ''), '/') . '/' . ltrim($path, '/');
        header('Location: ' . $url, true, $status);
        exit;
    }

    protected function back(): void
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '/';
        header('Location: ' . $referer);
        exit;
    }

    protected function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    protected function jsonInput(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    protected function requireCsrf(): void
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!Session::verifyCsrf(is_string($token) ? $token : null)) {
            http_response_code(419);
            echo View::render('errors/csrf.twig');
            exit;
        }
    }

    protected function requireAuth(): array
    {
        $user = Auth::user();
        if (!$user) {
            Session::flash('error', 'You need to sign in first.');
            $this->redirect('/login');
        }
        return $user;
    }

    protected function requireAdmin(): array
    {
        $user = $this->requireAuth();
        if (empty($user['is_admin'])) {
            http_response_code(403);
            echo View::render('errors/403.twig');
            exit;
        }
        return $user;
    }
}
