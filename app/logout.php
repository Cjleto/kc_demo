<?php
session_start();
require_once 'config.php';

$idToken = $_SESSION['kc_id_token'] ?? '';
session_destroy();

$params = http_build_query([
    'post_logout_redirect_uri' => 'http://localhost:8081/',
    'id_token_hint'            => $idToken,
]);

header('Location: ' . KC_LOGOUT_URL . '?' . $params);
exit;
