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
        $logDir = Config::basePath('storage/logs');
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
        if (is_writable($logDir)) {
            ini_set('error_log', $logDir . '/php-error.log');
        }
        error_reporting(E_ALL);

        Session::start();

        $router = new Router();
        require $this->basePath . '/config/routes.php';
        $router->run();
    }
}
