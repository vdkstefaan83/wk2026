<?php
declare(strict_types=1);

namespace App\Core;

use Bramus\Router\Router;

final class App
{
    public function __construct(private string $basePath) {}

    public function run(): void
    {
        Config::load($this->basePath);

        date_default_timezone_set((string) Config::get('APP_TIMEZONE', 'Europe/Brussels'));
        $debug = (bool) Config::get('APP_DEBUG', false);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', Config::basePath('storage/logs/php-error.log'));
        error_reporting(E_ALL);

        Session::start();

        $router = new Router();
        require $this->basePath . '/config/routes.php';
        $router->run();
    }
}
