<?php
require_once __DIR__ . "/../includes/session.php";
// allow only admin
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo 'Forbidden';
    exit();
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/functions.php"; // sanitizeInput
require_once __DIR__ . "/../includes/system_monitoring.php";
mysqli_report(MYSQLI_REPORT_OFF);

if (!isPostRequest()) {
    header('HTTP/1.1 405 Method Not Allowed');
    echo 'Method not allowed';
    exit();
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'add') {
    $fullname = isset($_POST['fullname']) ? trim($_POST['fullname']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $role = isset($_POST['role_type']) ? trim($_POST['role_type']) : 'student';

    if (empty($fullname) || empty($email) || empty($password)) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Missing required fields';
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Invalid email';
        exit();
    }

    // check existing email
    $sql = "SELECT user_id FROM user_data WHERE email = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], null, 'Account email lookup could not be prepared');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Could not check account email';
        exit();
    }
    mysqli_stmt_bind_param($stmt, 's', $email);
    if (!mysqli_stmt_execute($stmt)) {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], null, 'Account email lookup failed');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Could not check account email';
        exit();
    }
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) {
        header('HTTP/1.1 409 Conflict');
        echo 'Email already exists';
        exit();
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $status = 'active';

    $insert = "INSERT INTO user_data (fullname, email, password_hash, role_type, status) VALUES (?, ?, ?, ?, ?)";
    $istmt = mysqli_prepare($conn, $insert);
    if (!$istmt) {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], null, 'Account creation could not be prepared');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Prepare failed';
        exit();
    }
    mysqli_stmt_bind_param($istmt, 'sssss', $fullname, $email, $password_hash, $role, $status);
    if (mysqli_stmt_execute($istmt)) {
        system_monitor_log(
            $conn,
            'account_registered',
            (int)$_SESSION['user_id'],
            (int)mysqli_insert_id($conn),
            'Administrator created an account'
        );
        echo 'OK';
        exit();
    } else {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], null, 'Account creation failed');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Database insert failed';
        exit();
    }

} elseif ($action === 'edit') {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $fullname = isset($_POST['fullname']) ? trim($_POST['fullname']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $role = isset($_POST['role_type']) ? trim($_POST['role_type']) : '';

    if ($user_id <= 0 || empty($fullname) || empty($email)) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Missing required fields';
        exit();
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Invalid email';
        exit();
    }

    // ensure email is not used by another user
    $sql = "SELECT user_id FROM user_data WHERE email = ? AND user_id != ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], $user_id, 'Duplicate email check could not be prepared');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Could not check account email';
        exit();
    }
    mysqli_stmt_bind_param($stmt, 'si', $email, $user_id);
    if (!mysqli_stmt_execute($stmt)) {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], $user_id, 'Duplicate email check failed');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Could not check account email';
        exit();
    }
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) {
        header('HTTP/1.1 409 Conflict');
        echo 'Email already in use by another account';
        exit();
    }

    $update = "UPDATE user_data SET fullname = ?, email = ?, role_type = ? WHERE user_id = ?";
    $ustmt = mysqli_prepare($conn, $update);
    if (!$ustmt) {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], $user_id, 'Account update could not be prepared');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Prepare failed';
        exit();
    }
    mysqli_stmt_bind_param($ustmt, 'sssi', $fullname, $email, $role, $user_id);
    if (mysqli_stmt_execute($ustmt)) {
        system_monitor_log(
            $conn,
            'account_updated',
            (int)$_SESSION['user_id'],
            $user_id,
            'Administrator updated account details'
        );
        echo 'OK';
        exit();
    } else {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], $user_id, 'Account update failed');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Update failed';
        exit();
    }

} elseif ($action === 'verify' || $action === 'reject') {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    if ($user_id <= 0) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Missing or invalid user ID';
        exit();
    }

    $new_status = $action === 'verify' ? 'active' : 'inactive';
    $ustmt = mysqli_prepare(
        $conn,
        "UPDATE user_data SET status = ? WHERE user_id = ? AND LOWER(COALESCE(status, '')) = 'pending'"
    );
    if (!$ustmt) {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], $user_id, 'Account verification could not be prepared');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Could not prepare account verification';
        exit();
    }

    mysqli_stmt_bind_param($ustmt, 'si', $new_status, $user_id);
    if (!mysqli_stmt_execute($ustmt)) {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], $user_id, 'Account verification failed');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Account verification failed';
        exit();
    }
    if (mysqli_stmt_affected_rows($ustmt) !== 1) {
        header('HTTP/1.1 409 Conflict');
        echo 'Account is no longer pending verification';
        exit();
    }

    system_monitor_log(
        $conn,
        $action === 'verify' ? 'account_verified' : 'account_rejected',
        (int)$_SESSION['user_id'],
        $user_id,
        $action === 'verify' ? 'Administrator approved account' : 'Administrator rejected account'
    );
    echo 'OK';
    exit();

} elseif ($action === 'toggle') {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $toggle_action = isset($_POST['toggle_action']) ? $_POST['toggle_action'] : '';

    if ($user_id <= 0 || !in_array($toggle_action, ['activate', 'deactivate'])) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Missing or invalid parameters';
        exit();
    }

    $new_status = $toggle_action === 'activate' ? 'active' : 'inactive';
    $expected_status = $toggle_action === 'activate' ? 'inactive' : 'active';
    $update = "UPDATE user_data SET status = ? WHERE user_id = ? AND LOWER(COALESCE(status, '')) = ?";
    $ustmt = mysqli_prepare($conn, $update);
    if (!$ustmt) {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], $user_id, 'Account status change could not be prepared');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Could not prepare status update';
        exit();
    }
    mysqli_stmt_bind_param($ustmt, 'sis', $new_status, $user_id, $expected_status);
    if (!mysqli_stmt_execute($ustmt)) {
        system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], $user_id, 'Account status update failed');
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Status update failed';
        exit();
    }
    if (mysqli_stmt_affected_rows($ustmt) !== 1) {
        header('HTTP/1.1 409 Conflict');
        echo 'Account status has changed; refresh and try again';
        exit();
    }

    system_monitor_log(
        $conn,
        'account_status_changed',
        (int)$_SESSION['user_id'],
        $user_id,
        'Administrator ' . $toggle_action . 'd account'
    );
    echo 'OK';
    exit();

} else {
    header('HTTP/1.1 400 Bad Request');
    echo 'Unknown action';
    exit();
}
