<?php
/** @var \Bramus\Router\Router $router */

use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\PredictionController;
use App\Controllers\DashboardController;
use App\Controllers\AdminController;
use App\Controllers\ApiController;

// Bramus Router expects "Class@method" strings, not [Class, 'method'] arrays.
$home       = HomeController::class;
$auth       = AuthController::class;
$prediction = PredictionController::class;
$dashboard  = DashboardController::class;
$admin      = AdminController::class;
$api        = ApiController::class;

$router->get('/',                  $home . '@index');

// --- Authentication ---
$router->get('/login',                  $auth . '@showLogin');
$router->post('/login',                 $auth . '@login');
$router->get('/register',               $auth . '@showRegister');
$router->post('/register',              $auth . '@register');
$router->get('/logout',                 $auth . '@logout');
$router->get('/auth/keycloak/login',    $auth . '@keycloakLogin');
$router->get('/auth/keycloak/callback', $auth . '@keycloakCallback');

// --- Dashboard ---
$router->get('/dashboard',          $dashboard . '@index');

// --- Prediction wizard ---
$router->get('/predictions/new',           $prediction . '@create');
$router->post('/predictions/new',          $prediction . '@store');
$router->get('/predictions/(\d+)',         $prediction . '@edit');
$router->post('/predictions/(\d+)/save',   $prediction . '@save');
$router->post('/predictions/(\d+)/submit', $prediction . '@submit');
$router->get('/predictions/(\d+)/pdf',     $prediction . '@pdf');
$router->post('/predictions/(\d+)/delete', $prediction . '@delete');

// --- API ---
$router->post('/api/predictions/(\d+)/autosave', $api . '@autosave');
$router->get('/api/predictions/(\d+)/state',     $api . '@state');
$router->get('/api/players',                     $api . '@players');

// --- Admin ---
$router->get('/admin',                        $admin . '@dashboard');
$router->get('/admin/settings',               $admin . '@settings');
$router->post('/admin/settings',              $admin . '@saveSettings');
$router->get('/admin/email-templates',        $admin . '@emailTemplates');
$router->get('/admin/email-templates/(\w+)',  $admin . '@editEmailTemplate');
$router->post('/admin/email-templates/(\w+)', $admin . '@saveEmailTemplate');
$router->get('/admin/teams',                  $admin . '@teams');
$router->post('/admin/teams',                 $admin . '@saveTeams');
$router->get('/admin/matches',                $admin . '@matches');
$router->post('/admin/matches',               $admin . '@saveMatches');
$router->get('/admin/players',                $admin . '@players');
$router->post('/admin/players',               $admin . '@savePlayers');
$router->get('/admin/forms',                  $admin . '@forms');
$router->post('/admin/forms/(\d+)/payment',   $admin . '@markPaid');
$router->get('/admin/leaderboard',            $admin . '@leaderboard');
$router->post('/admin/recompute',             $admin . '@recompute');
$router->post('/admin/sync-matches',          $admin . '@syncMatches');

$router->set404(function () {
    http_response_code(404);
    echo \App\Core\View::render('errors/404.twig');
});
