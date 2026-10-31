
<?php
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../config/database.php';

if (
    !isset($_SESSION['role_type']) ||
    strtolower($_SESSION['role_type']) !== 'admin'
) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/functions.php";
require_once __DIR__ . "/../includes/system_monitoring.php";

mysqli_report(MYSQLI_REPORT_OFF);

function userActionFail($message, $statusCode = 400)
{
    http_response_code($statusCode);
    exit($message);
}

function userActionLog($conn, $event, $description, $subjectUserId = null)
{
    if (!function_exists('system_monitor_log')) {
        return false;
    }

    try {
        return system_monitor_log(
            $conn,
            $event,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            $subjectUserId !== null ? (int) $subjectUserId : null,
            $description
        );
    } catch (Throwable $e) {
        error_log('User action monitoring failed: ' . $e->getMessage());
        return false;
    }
}
function findManageableAccount($conn, $userId)
{
    $stmt = $conn->prepare(
        "SELECT user_id, fullname, email, role_type, status
         FROM user_data
         WHERE user_id = ?
           AND LOWER(role_type) IN ('admin', 'counselor')
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $account = $result ? $result->fetch_assoc() : false;

    $stmt->close();

    return $account;
}

if (!isPostRequest()) {
    userActionFail('Invalid request method.', 405);
}

$action = trim($_POST['action'] ?? '');

$allowedRoles = ['admin', 'counselor'];
$allowedStatuses = ['active', 'inactive'];

/*
|--------------------------------------------------------------------------
| ADD USER
|--------------------------------------------------------------------------
*/
if ($action === 'add') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $roleType = strtolower(trim($_POST['role_type'] ?? ''));

    if ($fullname === '' || $email === '' || $password === '' || $roleType === '') {
        userActionFail('Please complete all required fields.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        userActionFail('Please enter a valid email address.');
    }

    if (strlen($password) < 8) {
        userActionFail('Password must be at least 8 characters.');
    }

    if (!in_array($roleType, $allowedRoles, true)) {
        userActionFail('Only Admin and Counselor accounts are allowed.');
    }

    // Check for an existing email.
    $check = $conn->prepare(
        "SELECT user_id FROM user_data WHERE email = ? LIMIT 1"
    );

    if (!$check) {
        userActionFail('Could not validate the email address.', 500);
    }

    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();
        userActionFail('This email address is already registered.');
    }

    $check->close();

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($passwordHash === false) {
        userActionFail('Could not secure the password.', 500);
    }

    $status = 'active';

    $stmt = $conn->prepare(
        "INSERT INTO user_data
            (fullname, email, password_hash, role_type, status)
         VALUES (?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        userActionFail('Could not prepare the account creation request.', 500);
    }

    $stmt->bind_param(
        "sssss",
        $fullname,
        $email,
        $passwordHash,
        $roleType,
        $status
    );

    if (!$stmt->execute()) {
        $stmt->close();
        userActionFail('Account creation failed. Please check the database configuration.', 500);
    }

    $newUserId = $stmt->insert_id;
    $stmt->close();


    userActionLog(
        $conn,
        'account_registered',
        'Admin created a ' . $roleType . ' account.',
        $newUserId
    );


    exit('OK');
}

/*
|--------------------------------------------------------------------------
| EDIT USER
|--------------------------------------------------------------------------
*/
if ($action === 'edit') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $fullname = trim($_POST['fullname'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $roleType = strtolower(trim($_POST['role_type'] ?? ''));

    if (!$userId || $fullname === '' || $email === '' || $roleType === '') {
        userActionFail('Please provide valid account details.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        userActionFail('Please enter a valid email address.');
    }

    if (!in_array($roleType, $allowedRoles, true)) {
        userActionFail('Only Admin and Counselor roles are allowed.');
    }

    $target = findManageableAccount($conn, $userId);

    if (!$target) {
        userActionFail('Account not found or cannot be managed.', 404);
    }

    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

    if (
        $userId === $currentUserId &&
        strtolower($target['role_type']) === 'admin' &&
        $roleType !== 'admin'
    ) {
        userActionFail('You cannot remove your own Admin role.');
    }

    // Prevent duplicate emails belonging to another account.
    $check = $conn->prepare(
        "SELECT user_id
         FROM user_data
         WHERE email = ? AND user_id <> ?
         LIMIT 1"
    );

    if (!$check) {
        userActionFail('Could not validate the email address.', 500);
    }

    $check->bind_param("si", $email, $userId);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $check->close();
        userActionFail('That email address is already used by another account.');
    }

    $check->close();

    $stmt = $conn->prepare(
        "UPDATE user_data
         SET fullname = ?, email = ?, role_type = ?
         WHERE user_id = ?
           AND LOWER(role_type) IN ('admin', 'counselor')"
    );

    if (!$stmt) {
        userActionFail('Could not prepare the account update.', 500);
    }

    $stmt->bind_param("sssi", $fullname, $email, $roleType, $userId);

    if (!$stmt->execute()) {
        $stmt->close();
        userActionFail('Account update failed.', 500);
    }

    $stmt->close();

    userActionLog(
        $conn,
        'user_updated',
        'Admin updated account ID: ' . $userId
    );

    exit('OK');
}

/*
|--------------------------------------------------------------------------
| VERIFY USER
|--------------------------------------------------------------------------
*/
if ($action === 'verify') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

    if (!$userId) {
        userActionFail('Invalid account ID.');
    }

    $target = findManageableAccount($conn, $userId);

    if (!$target) {
        userActionFail('Account not found or cannot be managed.', 404);
    }

    $stmt = $conn->prepare(
        "UPDATE user_data
         SET status = 'active'
         WHERE user_id = ?
           AND LOWER(role_type) IN ('admin', 'counselor')"
    );

    if (!$stmt) {
        userActionFail('Could not prepare the account verification.', 500);
    }

    $stmt->bind_param("i", $userId);

    if (!$stmt->execute()) {
        $stmt->close();
        userActionFail('Account verification failed.', 500);
    }

    $stmt->close();

    userActionLog(
        $conn,
        'user_verified',
        'Admin activated account ID: ' . $userId
    );

    exit('OK');
}

/*
|--------------------------------------------------------------------------
| REJECT USER
|--------------------------------------------------------------------------
*/
if ($action === 'reject') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

    if (!$userId) {
        userActionFail('Invalid account ID.');
    }

    $target = findManageableAccount($conn, $userId);

    if (!$target) {
        userActionFail('Account not found or cannot be managed.', 404);
    }

    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId === $currentUserId) {
        userActionFail('You cannot deactivate your own account.');
    }

    $stmt = $conn->prepare(
        "UPDATE user_data
         SET status = 'inactive'
         WHERE user_id = ?
           AND LOWER(role_type) IN ('admin', 'counselor')"
    );

    if (!$stmt) {
        userActionFail('Could not prepare the account update.', 500);
    }

    $stmt->bind_param("i", $userId);

    if (!$stmt->execute()) {
        $stmt->close();
        userActionFail('Could not deactivate the account.', 500);
    }

    $stmt->close();

    userActionLog(
        $conn,
        'user_rejected',
        'Admin deactivated account ID: ' . $userId
    );

    exit('OK');
}

/*
|--------------------------------------------------------------------------
| TOGGLE ACCOUNT STATUS
|--------------------------------------------------------------------------
*/
if ($action === 'toggle') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

    if (!$userId) {
        userActionFail('Invalid account ID.');
    }

    $target = findManageableAccount($conn, $userId);

    if (!$target) {
        userActionFail('Account not found or cannot be managed.', 404);
    }

    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId === $currentUserId) {
        userActionFail('You cannot change your own account status.');
    }

    $currentStatus = strtolower(trim($target['status'] ?? ''));

    if (!in_array($currentStatus, $allowedStatuses, true)) {
        userActionFail('This account has an unsupported status.');
    }

    $newStatus = ($currentStatus === 'active') ? 'inactive' : 'active';

    $stmt = $conn->prepare(
        "UPDATE user_data
         SET status = ?
         WHERE user_id = ?
           AND LOWER(role_type) IN ('admin', 'counselor')"
    );

    if (!$stmt) {
        userActionFail('Could not prepare the status update.', 500);
    }

    $stmt->bind_param("si", $newStatus, $userId);

    if (!$stmt->execute()) {
        $stmt->close();
        userActionFail('Could not update the account status.', 500);
    }

    $stmt->close();

    userActionLog(
        $conn,
        'user_status_changed',
        'Admin changed account ID ' . $userId . ' status to ' . $newStatus
    );

    exit('OK');
}

/*
|--------------------------------------------------------------------------
| RESET COUNSELOR PASSWORD
|--------------------------------------------------------------------------
*/
if ($action === 'reset_counselor_password') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $newPassword = $_POST['new_password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        userActionFail('Security validation failed. Refresh the page and try again.', 403);
    }

    if (!$userId || !is_string($newPassword)) {
        userActionFail('Please provide a valid Counselor account and password.');
    }

    $passwordLength = strlen($newPassword);
    if ($passwordLength < 8 || $passwordLength > 72) {
        userActionFail('Temporary password must be between 8 and 72 bytes long.');
    }

    // Restrict this action to Counselor accounts only, even if the request is forged.
    $stmt = $conn->prepare(
        "SELECT user_id, fullname
         FROM user_data
         WHERE user_id = ? AND LOWER(role_type) = 'counselor'
         LIMIT 1"
    );

    if (!$stmt) {
        userActionFail('Could not validate the Counselor account.', 500);
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        userActionFail('Could not validate the Counselor account.', 500);
    }

    $result = $stmt->get_result();
    $counselor = $result ? $result->fetch_assoc() : false;
    $stmt->close();

    if (!$counselor) {
        userActionFail('Counselor account not found. Admin passwords cannot be reset with this action.', 404);
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        userActionFail('Could not secure the temporary password.', 500);
    }

    $update = $conn->prepare(
        "UPDATE user_data
         SET password_hash = ?
         WHERE user_id = ? AND LOWER(role_type) = 'counselor'"
    );

    if (!$update) {
        userActionFail('Could not prepare the password reset.', 500);
    }

    $update->bind_param('si', $passwordHash, $userId);
    if (!$update->execute() || $update->affected_rows !== 1) {
        $update->close();
        userActionFail('Password reset failed. Please try again.', 500);
    }
    $update->close();

    userActionLog(
        $conn,
        'counselor_password_reset',
        'Admin reset the password for Counselor account ID: ' . $userId
    );

    exit('OK');
}


/*
|--------------------------------------------------------------------------
| RESET ADMIN PASSWORD
|--------------------------------------------------------------------------
*/
if ($action === 'reset_admin_password') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $newPassword = $_POST['new_password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    // Validate CSRF token.
    if (
        empty($_SESSION['csrf_token']) ||
        !is_string($csrfToken) ||
        !hash_equals($_SESSION['csrf_token'], $csrfToken)
    ) {
        userActionFail(
            'Security validation failed. Refresh the page and try again.',
            403
        );
    }

    if (!$userId || !is_string($newPassword)) {
        userActionFail('Please provide a valid Admin account and password.');
    }

    // Require a password between 8 and 72 bytes.
    $passwordLength = strlen($newPassword);

    if ($passwordLength < 8 || $passwordLength > 72) {
        userActionFail(
            'Temporary password must be between 8 and 72 bytes long.'
        );
    }

    // Prevent an Admin from resetting their own password here.
    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

    if ($userId === $currentUserId) {
        userActionFail(
            'You cannot reset your own password here. Use Settings instead.'
        );
    }

    // Confirm that the target account is an Admin.
    $stmt = $conn->prepare(
        "SELECT user_id
         FROM user_data
         WHERE user_id = ?
           AND LOWER(role_type) = 'admin'
         LIMIT 1"
    );

    if (!$stmt) {
        userActionFail('Could not validate the Admin account.', 500);
    }

    $stmt->bind_param('i', $userId);

    if (!$stmt->execute()) {
        $stmt->close();
        userActionFail('Could not validate the Admin account.', 500);
    }

    $result = $stmt->get_result();
    $admin = $result ? $result->fetch_assoc() : false;
    $stmt->close();

    if (!$admin) {
        userActionFail('Admin account not found.', 404);
    }

    // Hash the new password before saving it.
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    if ($passwordHash === false) {
        userActionFail('Could not secure the temporary password.', 500);
    }

    $update = $conn->prepare(
        "UPDATE user_data
         SET password_hash = ?
         WHERE user_id = ?
           AND LOWER(role_type) = 'admin'"
    );

    if (!$update) {
        userActionFail('Could not prepare the password reset.', 500);
    }

    $update->bind_param('si', $passwordHash, $userId);

    if (!$update->execute() || $update->affected_rows !== 1) {
        $update->close();
        userActionFail('Password reset failed. Please try again.', 500);
    }

    $update->close();

    // Record the administrative action without logging the password.
    userActionLog(
        $conn,
        'admin_password_reset',
        'Admin ID ' . $currentUserId .
        ' reset the password for Admin account ID: ' . $userId
    );

    exit('OK');
}


userActionFail('Unknown action.', 400);


