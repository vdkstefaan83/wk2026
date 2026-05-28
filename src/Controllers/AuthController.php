<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\KeycloakClient;
use App\Core\Session;
use App\Core\Setting;
use App\Core\Validator;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        $this->render('auth/login.twig', ['keycloak' => KeycloakClient::isEnabled()]);
    }

    public function login(): void
    {
        $this->requireCsrf();
        $email = (string) $this->input('email', '');
        $password = (string) $this->input('password', '');

        $v = new Validator(['email' => $email, 'password' => $password]);
        $v->required('email', 'Email')->email('email', 'Email')->required('password', 'Password');
        if ($v->fails()) {
            $v->flashErrors();
            $this->redirect('/login');
        }
        $user = Auth::attemptDb($email, $password);
        if (!$user) {
            Session::flash('error', 'Invalid login.');
            $this->redirect('/login');
        }
        Session::clearOld();
        Session::flash('success', 'Welcome back, ' . $user['name'] . '.');
        $this->redirect('/dashboard');
    }

    public function showRegister(): void
    {
        if (Setting::get('auth_provider', 'db') !== 'db') {
            Session::flash('error', 'Registration via this page is disabled; please use your SSO account.');
            $this->redirect('/login');
        }
        if (Setting::get('registration_open', '1') !== '1') {
            Session::flash('error', 'Registrations are currently closed.');
            $this->redirect('/login');
        }
        $this->render('auth/register.twig');
    }

    public function register(): void
    {
        $this->requireCsrf();
        $email = (string) $this->input('email', '');
        $name  = (string) $this->input('name', '');
        $password = (string) $this->input('password', '');

        $v = new Validator(['email' => $email, 'name' => $name, 'password' => $password]);
        $v->required('email', 'Email')->email('email', 'Email')
          ->required('name', 'Name')
          ->required('password', 'Password')->min('password', 8, 'Password');
        if ($v->fails()) {
            $v->flashErrors();
            $this->redirect('/register');
        }
        try {
            Auth::registerDb($email, $name, $password);
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
            Session::setOld(['email' => $email, 'name' => $name]);
            $this->redirect('/register');
        }
        Session::flash('success', 'Account created!');
        $this->redirect('/dashboard');
    }

    public function logout(): void
    {
        $idToken = Session::get('id_token');
        $useKeycloak = KeycloakClient::isEnabled() && Session::get('auth_via') === 'keycloak';
        Auth::logout();
        if ($useKeycloak) {
            $this->redirect(KeycloakClient::logoutUrl(is_string($idToken) ? $idToken : null));
        }
        $this->redirect('/');
    }

    public function keycloakLogin(): void
    {
        if (!KeycloakClient::isEnabled()) {
            Session::flash('error', 'Keycloak is not enabled.');
            $this->redirect('/login');
        }
        $provider = KeycloakClient::provider();
        $authUrl = $provider->getAuthorizationUrl();
        Session::set('oauth2state', $provider->getState());
        $this->redirect($authUrl);
    }

    public function keycloakCallback(): void
    {
        if (!KeycloakClient::isEnabled()) {
            Session::flash('error', 'Keycloak is not enabled.');
            $this->redirect('/login');
        }
        $state = (string)($_GET['state'] ?? '');
        $code  = (string)($_GET['code']  ?? '');
        $stored = (string) Session::get('oauth2state', '');
        if ($state === '' || $stored === '' || !hash_equals($stored, $state)) {
            Session::forget('oauth2state');
            Session::flash('error', 'OIDC state mismatch.');
            $this->redirect('/login');
        }
        Session::forget('oauth2state');
        try {
            $provider = KeycloakClient::provider();
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $resourceOwner = $provider->getResourceOwner($token);
            $claims = $resourceOwner->toArray();
            Session::set('id_token', $token->getValues()['id_token'] ?? null);
            Session::set('auth_via', 'keycloak');
            Auth::upsertFromOidc($claims);
        } catch (\Throwable $e) {
            Session::flash('error', 'OIDC login failed: ' . $e->getMessage());
            $this->redirect('/login');
        }
        Session::flash('success', 'Signed in via Keycloak.');
        $this->redirect('/dashboard');
    }
}
