<?php

require_once __DIR__ . '/../config/admin_session.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_type']) ||
    $_SESSION['role_type'] !== 'admin'
) {
    header("Location: ../auth/login.php");
    exit();
}