<?php

session_start();

require_once "../config/database.php";

/** @var mysqli $conn */

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit();
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    die("Email and password are required.");
}

$sql = "SELECT user_id, fullname, email, password, role_type, status
        FROM user_data
        WHERE email = ?
        LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$user) {
    die("No account found with this email.");
}

if (!password_verify($password, $user['password'])) {
    die("Incorrect password.");
}

if ($user['status'] !== 'active') {
    die("This account is inactive.");
}

$_SESSION['user_id'] = $user['user_id'];
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['role_type'] = $user['role_type'];

if ($user['role_type'] === 'admin') {

    header("Location: ../admin/dashboard.php");

} elseif ($user['role_type'] === 'counselor') {

    header("Location: ../counselor/dashboard.php");

} else {

    header("Location: ../student/dashboard.php");
}

exit();
?>