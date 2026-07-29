<?php
session_start();

require_once "../config/database.php";
require_once "../includes/functions.php";

/** @var mysqli $conn */

if (!isPostRequest()) {
    redirect("login.php");
}

$email = sanitizeInput($_POST['email']);
$password = sanitizeInput($_POST['password']);

if (empty($email) || empty($password)) {
    redirect("login.php");
}

$sql = "SELECT * FROM user_data WHERE email = ?";

$stmt = mysqli_prepare($conn, $sql);

mysqli_stmt_bind_param($stmt, "s", $email);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$user = mysqli_fetch_assoc($result);

if (!$user) {
    redirect("login.php");
}

if (!password_verify($password, $user['password_hash'])) {
    redirect("login.php");
}

$_SESSION['user_id'] = $user['user_id'];
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['role_type'] = $user['role_type'];

if ($user['role_type'] === 'admin') {
    redirect("../admin/dashboard.php");
} elseif ($user['role_type'] === 'counselor') {
    redirect("../counselor/dashboard.php");
} else {
    redirect("../student/dashboard.php");
}