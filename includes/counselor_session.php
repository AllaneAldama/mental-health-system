<?php

require_once __DIR__ . '/../config/counselor_session.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_type']) ||
    $_SESSION['role_type'] !== 'counselor'
) {
    header("Location: ../auth/login.php");
    exit();
}