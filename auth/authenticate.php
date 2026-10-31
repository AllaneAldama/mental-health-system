
<?php

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/system_monitoring.php";

/** @var mysqli $conn */

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

function login_response(bool $success, string $message, ?string $redirect_url = null): void
{
    global $is_ajax;

    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        echo json_encode([
            'success' => $success,
            'message' => $message,
            'redirect' => $redirect_url
        ]);

        exit;
    }

    if ($success && $redirect_url !== null) {
        redirect($redirect_url);
    }

    redirect('login.php');
}

// Only accept POST requests.
if (!isPostRequest()) {
    login_response(false, 'Invalid request. Please try again.');
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    login_response(
        false,
        'Please enter your email address and password.'
    );
}

// Find the account by email.
$sql = "SELECT user_id, fullname, email, password_hash, role_type, status
        FROM user_data
        WHERE email = ?
        LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    error_log("Login query preparation failed: " . mysqli_error($conn));
    login_response(false, 'Unable to sign in right now. Please try again.');
}

mysqli_stmt_bind_param($stmt, "s", $email);

if (!mysqli_stmt_execute($stmt)) {
    error_log("Login query execution failed: " . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);

    login_response(false, 'Unable to sign in right now. Please try again.');
}

$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

// Use one generic message for incorrect credentials.
if (!$user || !password_verify($password, $user['password_hash'])) {
    system_monitor_log(
        $conn,
        'login_failure',
        null,
        $user ? (int) $user['user_id'] : null,
        'Failed login attempt'
    );

    login_response(
        false,
        'Email or password is incorrect. Please try again.'
    );
}

// Only Admin and Counselor accounts can use this login.
if (!in_array($user['role_type'], ['admin', 'counselor'], true)) {
    system_monitor_log(
        $conn,
        'login_failure',
        null,
        (int) $user['user_id'],
        'Login rejected for unsupported account role'
    );

    login_response(
        false,
        'Email or password is incorrect. Please try again.'
    );
}

// Reject inactive or pending accounts.
if (strtolower(trim($user['status'] ?? '')) !== 'active') {
    system_monitor_log(
        $conn,
        'login_failure',
        null,
        (int) $user['user_id'],
        'Login rejected because account is not active'
    );

    login_response(
        false,
        'Your account is not active. Please contact the administrator.'
    );
}

// Start the correct independent session based on account role.
if ($user['role_type'] === 'admin') {

    require_once __DIR__ . '/../config/admin_session.php';

} elseif ($user['role_type'] === 'counselor') {

    require_once __DIR__ . '/../config/counselor_session.php';

} else {

    login_response(
        false,
        'Email or password is incorrect. Please try again.'
    );
}

// Regenerate session ID after successful authentication.
session_regenerate_id(true);

// Store authenticated account information.
$_SESSION['user_id'] = (int) $user['user_id'];
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['role_type'] = $user['role_type'];

// Record successful login.
system_monitor_log(
    $conn,
    'login_success',
    (int) $user['user_id'],
    (int) $user['user_id'],
    'Successful login'
);

// Return the appropriate dashboard URL.
if ($user['role_type'] === 'admin') {
    login_response(
        true,
        'Login successful.',
        '../admin/dashboard.php'
    );
}

if ($user['role_type'] === 'counselor') {
    login_response(
        true,
        'Login successful.',
        '../counselor/dashboard.php'
    );
}

login_response(false, 'Email or password is incorrect. Please try again.');
