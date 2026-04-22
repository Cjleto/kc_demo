<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwks_cache.php';
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

/**
 * Da chiamare in cima a ogni pagina protetta.
 * Restituisce i dati utente dalla sessione.
 * Se l'access token è scaduto tenta il refresh automatico.
 * Se il refresh fallisce (token scaduto o revocato) redirect al login.
 *
 * Uso:
 *   require_once 'auth.php';
 *   $user = requireAuth();
 */
function requireAuth(): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['kc_user'])) {
        header('Location: login.php');
        exit;
    }

    // Refresh anticipato: rinnova il token 30 secondi prima della scadenza
    if (time() >= ($_SESSION['kc_exp'] ?? 0) - 30) {
        $tokens = _doRefresh($_SESSION['kc_refresh_token'] ?? '');

        if (!$tokens) {
            session_destroy();
            header('Location: login.php');
            exit;
        }

        _storeSession($tokens);
    }

    return $_SESSION['kc_user'];
}

/**
 * Salva access_token, refresh_token e dati utente in sessione.
 * Chiamato sia nel callback iniziale che dopo ogni refresh.
 */
function storeSession(array $tokens): void
{
    _storeSession($tokens);
}

function _storeSession(array $tokens): void
{
    $jwks    = getJwks();
    $payload = JWT::decode($tokens['access_token'], JWK::parseKeySet($jwks));

    $_SESSION['kc_user'] = [
        'username' => $payload->preferred_username ?? '',
        'name'     => $payload->name ?? '',
        'email'    => $payload->email ?? '',
        'roles'    => (array) ($payload->realm_access->roles ?? []),
    ];

    $_SESSION['kc_exp']           = $payload->exp;
    $_SESSION['kc_access_token']  = $tokens['access_token'];
    $_SESSION['kc_refresh_token'] = $tokens['refresh_token'] ?? '';
    $_SESSION['kc_id_token']      = $tokens['id_token'] ?? '';
}

function _doRefresh(string $refreshToken): ?array
{
    if (!$refreshToken) {
        return null;
    }

    $ch = curl_init(KC_TOKEN_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id'     => KC_CLIENT_ID,
        'client_secret' => KC_CLIENT_SECRET,
    ]));
    $response = curl_exec($ch);
    curl_close($ch);

    $tokens = json_decode($response, true);
    return !empty($tokens['access_token']) ? $tokens : null;
}
