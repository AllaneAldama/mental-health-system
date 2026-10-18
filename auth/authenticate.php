<?php

session_start();

require_once "../config/database.php";
require_once "../includes/functions.php";
require_once "../includes/system_monitoring.php";
mysqli_report(MYSQLI_REPORT_OFF);

/** @var mysqli $conn */

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit();
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($email) || empty($password)) {
    system_monitor_log($conn, 'login_failure', null, null, 'Missing login credentials');
    redirect("login.php");
}

$sql = "SELECT * FROM user_data WHERE email = ?";
try {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new mysqli_sql_exception(mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmt, "s", $email);
    if (!mysqli_stmt_execute($stmt)) {
        throw new mysqli_sql_exception(mysqli_stmt_error($stmt));
    }
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
} catch (mysqli_sql_exception $exception) {
    error_log('Login account lookup failed: ' . $exception->getMessage());
    system_monitor_log($conn, 'application_error', null, null, 'Login account lookup failed');
    redirect("login.php");
}

mysqli_stmt_close($stmt);

if (!$user) {
    system_monitor_log($conn, 'login_failure', null, null, 'Invalid credentials');
    redirect("login.php");
}

if (!password_verify($password, $user['password_hash'])) {
    system_monitor_log($conn, 'login_failure', null, (int)$user['user_id'], 'Invalid credentials');
    redirect("login.php");
}

if (strtolower($user['status'] ?? '') !== 'active') {
    system_monitor_log($conn, 'login_failure', null, (int)$user['user_id'], 'Account is pending or inactive');
    redirect("login.php");
}

$_SESSION['user_id'] = $user['user_id'];
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['role_type'] = $user['role_type'];
system_monitor_log($conn, 'login_success', (int)$user['user_id'], (int)$user['user_id'], 'Successful sign-in');

if ($user['role_type'] === 'admin') {

    header("Location: ../admin/dashboard.php");

} elseif ($user['role_type'] === 'counselor') {

    header("Location: ../counselor/dashboard.php");

} else {

    header("Location: ../student/dashboard.php");
}

exit();
?>