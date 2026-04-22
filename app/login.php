<?php
session_start();
require_once 'config.php';

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$params = http_build_query([
    'client_id'     => KC_CLIENT_ID,
    'redirect_uri'  => REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid profile email roles',
    'state'         => $state,
]);

header('Location: ' . KC_AUTH_URL . '?' . $params);
exit;
