<?php

// Independent session for Counselor Portal.

if (session_status() === PHP_SESSION_NONE) {

    session_name('PTC_COUNSELOR_SESSION');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}