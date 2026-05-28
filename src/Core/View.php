<?php
declare(strict_types=1);

namespace App\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class View
{
    private static ?Environment $twig = null;

    public static function twig(): Environment
    {
        if (self::$twig !== null) {
            return self::$twig;
        }

        $loader = new FilesystemLoader(Config::basePath('templates'));
        $debug  = (bool) Config::get('APP_DEBUG', false);
        $twig   = new Environment($loader, [
            'cache' => $debug ? false : Config::basePath('storage/cache/twig'),
            'debug' => $debug,
            'auto_reload' => true,
            'strict_variables' => false,
        ]);

        $twig->addGlobal('app', [
            'name'        => Config::get('APP_NAME', 'WK2026'),
            'url'         => rtrim((string) Config::get('APP_URL', ''), '/'),
            'env'         => Config::get('APP_ENV', 'production'),
            'request_uri' => strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?'),
        ]);
        $twig->addGlobal('flash', Session::flashAll());
        $twig->addGlobal('user',  Auth::user());
        $twig->addGlobal('settings', Setting::all());
        $twig->addGlobal('csrf', Session::csrfToken());

        $twig->addFunction(new TwigFunction('url', fn(string $path = '') => rtrim((string) Config::get('APP_URL', ''), '/') . '/' . ltrim($path, '/')));
        $twig->addFunction(new TwigFunction('asset', fn(string $path) => rtrim((string) Config::get('APP_URL', ''), '/') . '/assets/' . ltrim($path, '/')));
        $twig->addFunction(new TwigFunction('old', fn(string $key, mixed $default = '') => Session::old($key, $default)));

        if ($debug) {
            $twig->addExtension(new \Twig\Extension\DebugExtension());
        }

        return self::$twig = $twig;
    }

    public static function render(string $template, array $data = []): string
    {
        return self::twig()->render($template, $data);
    }

    public static function display(string $template, array $data = []): void
    {
        echo self::render($template, $data);
    }
}
