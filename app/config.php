<?php

// Keycloak raggiungibile dal browser (per redirect)
define('KC_PUBLIC',   'http://localhost:8080');

// Keycloak raggiungibile dal container PHP (chiamate server-to-server)
define('KC_INTERNAL', 'http://keycloak:8080');

define('KC_REALM',         'Fonarcom');
define('KC_CLIENT_ID',     'local-client-1');
define('KC_CLIENT_SECRET', 'ycEceXmtLZFikLCv2raCEYp4gTHwGC0G');
define('REDIRECT_URI',     'http://localhost:8081/callback.php');

define('KC_AUTH_URL',  KC_PUBLIC  . '/realms/' . KC_REALM . '/protocol/openid-connect/auth');
define('KC_TOKEN_URL', KC_INTERNAL . '/realms/' . KC_REALM . '/protocol/openid-connect/token');
define('KC_JWKS_URL',  KC_INTERNAL . '/realms/' . KC_REALM . '/protocol/openid-connect/certs');
define('KC_LOGOUT_URL', KC_PUBLIC . '/realms/' . KC_REALM . '/protocol/openid-connect/logout');
