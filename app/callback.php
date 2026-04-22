<?php
session_start();
require_once 'auth.php';

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

// 3. Verifica firma JWT e salva in sessione
try {
    storeSession($tokens);
} catch (Exception $e) {
    die('Token JWT non valido: ' . htmlspecialchars($e->getMessage()));
}

header('Location: dashboard.php');
exit;
