<?php

function getJwks(): array
{
    $cacheFile = sys_get_temp_dir() . '/jwks_cache.json';
    $ttl = 3600;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $ch = curl_init(KC_JWKS_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $body = curl_exec($ch);
    curl_close($ch);

    $jwks = json_decode($body, true);
    file_put_contents($cacheFile, $body);

    return $jwks;
}
