<?php
session_start();
require_once 'config.php';
require_once 'jwks_cache.php';
require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

// 1. Validazione state (protezione CSRF)
if (empty($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth_state'] ?? '')) {
    die('Errore: state non valido. Possibile attacco CSRF.');
}
unset($_SESSION['oauth_state']);

if (isset($_GET['error'])) {
    die('Errore da Keycloak: ' . htmlspecialchars($_GET['error_description'] ?? $_GET['error']));
}

$code = $_GET['code'] ?? '';
if (!$code) {
    die('Errore: code mancante.');
}

// 2. Scambio code → token (server-to-server)
$ch = curl_init(KC_TOKEN_URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'client_id'     => KC_CLIENT_ID,
    'client_secret' => KC_CLIENT_SECRET,
    'redirect_uri'  => REDIRECT_URI,
]));
$response = curl_exec($ch);
curl_close($ch);

$tokens = json_decode($response, true);
if (empty($tokens['access_token'])) {
    die('Errore nello scambio del token: ' . htmlspecialchars($response));
}

// 3. Verifica firma JWT
$jwks = getJwks();
try {
    $payload = JWT::decode($tokens['access_token'], JWK::parseKeySet($jwks));
} catch (Exception $e) {
    die('Token JWT non valido: ' . htmlspecialchars($e->getMessage()));
}

// 4. Salva in sessione e redirect
$_SESSION['kc_user'] = [
    'username'  => $payload->preferred_username ?? '',
    'name'      => $payload->name ?? '',
    'email'     => $payload->email ?? '',
    'roles'     => $payload->realm_access->roles ?? [],
    'exp'       => $payload->exp ?? 0,
];
$_SESSION['kc_id_token'] = $tokens['id_token'] ?? '';

header('Location: dashboard.php');
exit;
