
<?php

require_once __DIR__ . '/../config/staff_session.php';

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_type']) ||
    !in_array($_SESSION['role_type'], ['admin', 'counselor'], true)
) {
    header("Location: ../auth/login.php");
    exit();
}
