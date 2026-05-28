<?php
declare(strict_types=1);

namespace App\Core;

use League\OAuth2\Client\Provider\GenericProvider;

final class KeycloakClient
{
    public static function provider(): GenericProvider
    {
        $base   = rtrim((string) Config::get('KEYCLOAK_BASE_URL'), '/');
        $realm  = (string) Config::get('KEYCLOAK_REALM', 'master');
        $authz  = "{$base}/realms/{$realm}/protocol/openid-connect/auth";
        $token  = "{$base}/realms/{$realm}/protocol/openid-connect/token";
        $info   = "{$base}/realms/{$realm}/protocol/openid-connect/userinfo";

        return new GenericProvider([
            'clientId'                => (string) Config::get('KEYCLOAK_CLIENT_ID'),
            'clientSecret'            => (string) Config::get('KEYCLOAK_CLIENT_SECRET'),
            'redirectUri'             => (string) Config::get('KEYCLOAK_REDIRECT_URI'),
            'urlAuthorize'            => $authz,
            'urlAccessToken'          => $token,
            'urlResourceOwnerDetails' => $info,
            'scopes'                  => (string) Config::get('KEYCLOAK_SCOPES', 'openid profile email'),
            'scopeSeparator'          => ' ',
        ]);
    }

    public static function logoutUrl(?string $idToken = null): string
    {
        $base  = rtrim((string) Config::get('KEYCLOAK_BASE_URL'), '/');
        $realm = (string) Config::get('KEYCLOAK_REALM', 'master');
        $redirect = (string) Config::get('APP_URL', '');
        $url = "{$base}/realms/{$realm}/protocol/openid-connect/logout?post_logout_redirect_uri=" . urlencode($redirect);
        if ($idToken) {
            $url .= '&id_token_hint=' . urlencode($idToken);
        }
        return $url;
    }

    public static function isEnabled(): bool
    {
        $envEnabled = (bool) Config::get('KEYCLOAK_ENABLED', false);
        $setting    = Setting::get('auth_provider', 'db');
        return $envEnabled && $setting === 'keycloak';
    }
}
