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
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) {
        header('HTTP/1.1 409 Conflict');
        echo 'Email already exists';
        exit();
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $status = 'Active';

    $insert = "INSERT INTO user_data (fullname, email, password_hash, role_type, status) VALUES (?, ?, ?, ?, ?)";
    $istmt = mysqli_prepare($conn, $insert);
    if (!$istmt) {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Prepare failed';
        exit();
    }
    mysqli_stmt_bind_param($istmt, 'sssss', $fullname, $email, $password_hash, $role, $status);
    if (mysqli_stmt_execute($istmt)) {
        echo 'OK';
        exit();
    } else {
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
    mysqli_stmt_bind_param($stmt, 'si', $email, $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    if (mysqli_stmt_num_rows($stmt) > 0) {
        header('HTTP/1.1 409 Conflict');
        echo 'Email already in use by another account';
        exit();
    }

    $update = "UPDATE user_data SET fullname = ?, email = ?, role_type = ? WHERE user_id = ?";
    $ustmt = mysqli_prepare($conn, $update);
    if (!$ustmt) {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Prepare failed';
        exit();
    }
    mysqli_stmt_bind_param($ustmt, 'sssi', $fullname, $email, $role, $user_id);
    if (mysqli_stmt_execute($ustmt)) {
        echo 'OK';
        exit();
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Update failed';
        exit();
    }

} elseif ($action === 'toggle') {
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $toggle_action = isset($_POST['toggle_action']) ? $_POST['toggle_action'] : '';

    if ($user_id <= 0 || !in_array($toggle_action, ['activate', 'deactivate'])) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Missing or invalid parameters';
        exit();
    }

    $new_status = $toggle_action === 'activate' ? 'Active' : 'Deactive';
    $update = "UPDATE user_data SET status = ? WHERE user_id = ?";
    $ustmt = mysqli_prepare($conn, $update);
    mysqli_stmt_bind_param($ustmt, 'si', $new_status, $user_id);
    if (mysqli_stmt_execute($ustmt)) {
        echo 'OK';
        exit();
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Status update failed';
        exit();
    }

} else {
    header('HTTP/1.1 400 Bad Request');
    echo 'Unknown action';
    exit();
}
