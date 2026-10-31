<?php

// Identify which portal is requesting logout.
$portal = $_GET['portal'] ?? '';

if (!in_array($portal, ['admin', 'counselor'], true)) {
    header("Location: login.php");
    exit();
}

// Start the correct portal session.
if ($portal === 'admin') {
    require_once __DIR__ . '/../config/admin_session.php';
} else {
    require_once __DIR__ . '/../config/counselor_session.php';
}

// Clear all session variables.
$_SESSION = [];

// Expire only the current portal's session cookie.
if (ini_get('session.use_cookies')) {

    $cookieParams = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $cookieParams['path'],
            'domain' => $cookieParams['domain'],
            'secure' => $cookieParams['secure'],
            'httponly' => $cookieParams['httponly'],
            'samesite' => $cookieParams['samesite'] ?? 'Lax'
        ]
    );
}

// Destroy only the selected portal session.
session_destroy();

// Return to login page.
header("Location: login.php");
exit();