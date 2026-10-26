<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['user_id'])) { header('HTTP/1.1 403 Forbidden'); echo 'Forbidden'; exit(); }
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/system_monitoring.php";
mysqli_report(MYSQLI_REPORT_OFF);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('HTTP/1.1 405 Method Not Allowed'); echo 'Method not allowed'; exit(); }
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'change_password') {
    $current = isset($_POST['current_password']) ? $_POST['current_password'] : '';
    $new = isset($_POST['new_password']) ? $_POST['new_password'] : '';

    if (empty($current) || empty($new)) { header('HTTP/1.1 400 Bad Request'); echo 'Missing parameters'; exit(); }
    if (strlen($new) < 8) { header('HTTP/1.1 400 Bad Request'); echo 'New password too short'; exit(); }

    $user_id = intval($_SESSION['user_id']);
    // fetch user
    $sql = "SELECT password_hash FROM user_data WHERE user_id = ? LIMIT 1";
    $stmt = mysqli_prepare($conn,$sql);
    if (!$stmt) {
        system_monitor_log($conn, 'application_error', $user_id, $user_id, 'Password change account lookup could not be prepared');
        header('HTTP/1.1 500 Internal Server Error'); echo 'Could not verify account'; exit();
    }
    mysqli_stmt_bind_param($stmt,'i',$user_id);
    if (!mysqli_stmt_execute($stmt)) {
        system_monitor_log($conn, 'application_error', $user_id, $user_id, 'Password change account lookup failed');
        header('HTTP/1.1 500 Internal Server Error'); echo 'Could not verify account'; exit();
    }
    $res = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($res);
    if (!$user) { header('HTTP/1.1 404 Not Found'); echo 'User not found'; exit(); }

    if (!password_verify($current, $user['password_hash'])) {
        system_monitor_log($conn, 'password_change_failure', $user_id, $user_id, 'Incorrect current password');
        header('HTTP/1.1 403 Forbidden'); echo 'Current password incorrect'; exit();
    }

    $new_hash = password_hash($new, PASSWORD_DEFAULT);
    $usql = "UPDATE user_data SET password_hash = ? WHERE user_id = ?";
    $ustmt = mysqli_prepare($conn,$usql);
    if (!$ustmt) {
        system_monitor_log($conn, 'application_error', $user_id, $user_id, 'Password update could not be prepared');
        header('HTTP/1.1 500 Internal Server Error'); echo 'Could not update password'; exit();
    }
    mysqli_stmt_bind_param($ustmt,'si',$new_hash,$user_id);
    if (mysqli_stmt_execute($ustmt)) {
        system_monitor_log($conn, 'password_changed', $user_id, $user_id, 'User changed account password');
        echo 'OK'; exit();
    } else {
        system_monitor_log($conn, 'application_error', $user_id, $user_id, 'Password update failed');
        header('HTTP/1.1 500 Internal Server Error'); echo 'Update failed'; exit();
    }

} else {
    header('HTTP/1.1 400 Bad Request'); echo 'Unknown action'; exit();
}
