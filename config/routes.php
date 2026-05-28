<?php
/** @var \Bramus\Router\Router $router */

use App\Controllers\HomeController;
use App\Controllers\AuthController;
use App\Controllers\PredictionController;
use App\Controllers\DashboardController;
use App\Controllers\AdminController;
use App\Controllers\ApiController;

$router->get('/',                  [HomeController::class, 'index']);

// --- Authentication ---
$router->get('/login',                 [AuthController::class, 'showLogin']);
$router->post('/login',                [AuthController::class, 'login']);
$router->get('/register',              [AuthController::class, 'showRegister']);
$router->post('/register',             [AuthController::class, 'register']);
$router->get('/logout',                [AuthController::class, 'logout']);
$router->get('/auth/keycloak/login',    [AuthController::class, 'keycloakLogin']);
$router->get('/auth/keycloak/callback', [AuthController::class, 'keycloakCallback']);

// --- Dashboard (logged in) ---
$router->get('/dashboard',         [DashboardController::class, 'index']);

// --- Prediction wizard ---
$router->get('/predictions/new',           [PredictionController::class, 'create']);
$router->post('/predictions/new',          [PredictionController::class, 'store']);
$router->get('/predictions/(\d+)',         [PredictionController::class, 'edit']);
$router->post('/predictions/(\d+)/save',   [PredictionController::class, 'save']);
$router->post('/predictions/(\d+)/submit', [PredictionController::class, 'submit']);
$router->get('/predictions/(\d+)/pdf',     [PredictionController::class, 'pdf']);
$router->post('/predictions/(\d+)/delete', [PredictionController::class, 'delete']);

// --- API (JSON, used by the wizard) ---
$router->post('/api/predictions/(\d+)/autosave', [ApiController::class, 'autosave']);
$router->get('/api/predictions/(\d+)/state',     [ApiController::class, 'state']);
$router->get('/api/players',                     [ApiController::class, 'players']);

// --- Admin ---
$router->get('/admin',                       [AdminController::class, 'dashboard']);
$router->get('/admin/settings',              [AdminController::class, 'settings']);
$router->post('/admin/settings',             [AdminController::class, 'saveSettings']);
$router->get('/admin/email-templates',       [AdminController::class, 'emailTemplates']);
$router->get('/admin/email-templates/(\w+)', [AdminController::class, 'editEmailTemplate']);
$router->post('/admin/email-templates/(\w+)',[AdminController::class, 'saveEmailTemplate']);
$router->get('/admin/teams',                 [AdminController::class, 'teams']);
$router->post('/admin/teams',                [AdminController::class, 'saveTeams']);
$router->get('/admin/matches',               [AdminController::class, 'matches']);
$router->post('/admin/matches',              [AdminController::class, 'saveMatches']);
$router->get('/admin/players',               [AdminController::class, 'players']);
$router->post('/admin/players',              [AdminController::class, 'savePlayers']);
$router->get('/admin/forms',                 [AdminController::class, 'forms']);
$router->post('/admin/forms/(\d+)/payment',  [AdminController::class, 'markPaid']);
$router->get('/admin/leaderboard',           [AdminController::class, 'leaderboard']);
$router->post('/admin/recompute',            [AdminController::class, 'recompute']);

$router->set404(function () {
    http_response_code(404);
    echo \App\Core\View::render('errors/404.twig');
});
