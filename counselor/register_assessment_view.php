
<?php

require_once __DIR__ . '/../includes/counselor_session.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function send_json($success, $message, $extra = [], $http_code = 200)
{
    http_response_code($http_code);

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));

    exit();
}

// Only accept POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(false, 'Method not allowed.', [], 405);
}

// Verify counselor session.
$counselor_id = (int) ($_SESSION['user_id'] ?? 0);

if (
    $counselor_id < 1 ||
    ($_SESSION['role_type'] ?? '') !== 'counselor'
) {
    send_json(false, 'Unauthorized access.', [], 403);
}

// Verify database connection.
if (!$conn || !mysqli_ping($conn)) {
    send_json(false, 'Database connection unavailable.', [], 500);
}

// Get assessment ID.
$assessment_id = filter_input(
    INPUT_POST,
    'assessment_id',
    FILTER_VALIDATE_INT
);

if (!$assessment_id || $assessment_id < 1) {
    send_json(false, 'Invalid assessment ID.', [], 422);
}

// Confirm that the assessment exists.
$check_stmt = mysqli_prepare(
    $conn,
    "SELECT assessment_id
     FROM assessment_data
     WHERE assessment_id = ?
     LIMIT 1"
);

if (!$check_stmt) {
    send_json(false, 'Unable to verify the assessment.', [], 500);
}

mysqli_stmt_bind_param($check_stmt, 'i', $assessment_id);
mysqli_stmt_execute($check_stmt);

$check_result = mysqli_stmt_get_result($check_stmt);
$assessment_exists = $check_result && mysqli_num_rows($check_result) > 0;

mysqli_stmt_close($check_stmt);

if (!$assessment_exists) {
    send_json(false, 'Assessment not found.', [], 404);
}

// Confirm that the counselor account is active.
$user_stmt = mysqli_prepare(
    $conn,
    "SELECT user_id
     FROM user_data
     WHERE user_id = ?
       AND role_type = 'counselor'
       AND status = 'active'
     LIMIT 1"
);

if (!$user_stmt) {
    send_json(false, 'Unable to verify counselor account.', [], 500);
}

mysqli_stmt_bind_param($user_stmt, 'i', $counselor_id);
mysqli_stmt_execute($user_stmt);

$user_result = mysqli_stmt_get_result($user_stmt);
$counselor_active = $user_result && mysqli_num_rows($user_result) > 0;

mysqli_stmt_close($user_stmt);

if (!$counselor_active) {
    send_json(false, 'Counselor account is inactive or unavailable.', [], 403);
}

// Generate a secure token for a possible review lock.
$new_lock_token = bin2hex(random_bytes(32));

try {

    mysqli_begin_transaction($conn);

    // Remove expired viewing records for this assessment.
    $cleanup_viewers = mysqli_prepare(
        $conn,
        "DELETE FROM assessment_viewers
         WHERE assessment_id = ?
           AND last_seen_at < DATE_SUB(NOW(), INTERVAL 30 SECOND)"
    );

    if (!$cleanup_viewers) {
        throw new Exception('Unable to clean up expired viewing records.');
    }

    mysqli_stmt_bind_param($cleanup_viewers, 'i', $assessment_id);

    if (!mysqli_stmt_execute($cleanup_viewers)) {
        throw new Exception('Unable to clean up expired viewing records.');
    }

    mysqli_stmt_close($cleanup_viewers);

    // Register this counselor as an active viewer.
    $viewer_stmt = mysqli_prepare(
        $conn,
        "INSERT INTO assessment_viewers
            (assessment_id, counselor_id, last_seen_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            last_seen_at = NOW()"
    );

    if (!$viewer_stmt) {
        throw new Exception('Unable to register assessment viewer.');
    }

    mysqli_stmt_bind_param(
        $viewer_stmt,
        'ii',
        $assessment_id,
        $counselor_id
    );

    if (!mysqli_stmt_execute($viewer_stmt)) {
        throw new Exception('Unable to register assessment viewer.');
    }

    mysqli_stmt_close($viewer_stmt);
    // Check whether this assessment has already been reviewed.
    $status_stmt = mysqli_prepare(
        $conn,
        "SELECT status
        FROM assessment_data
        WHERE assessment_id = ?
        LIMIT 1"
    );

    if (!$status_stmt) {
        throw new Exception('Unable to check assessment status.');
    }

    mysqli_stmt_bind_param($status_stmt, 'i', $assessment_id);
    mysqli_stmt_execute($status_stmt);

    $status_result = mysqli_stmt_get_result($status_stmt);
    $status_row = $status_result
        ? mysqli_fetch_assoc($status_result)
        : null;

    mysqli_stmt_close($status_stmt);

    if (!$status_row) {
        throw new Exception('Assessment status not found.');
    }

    if (strtolower(trim($status_row['status'])) === 'reviewed') {

        // Remove any remaining lock for an already reviewed assessment.
        $remove_lock = mysqli_prepare(
            $conn,
            "DELETE FROM assessment_review_locks
            WHERE assessment_id = ?"
        );

        if (!$remove_lock) {
            throw new Exception('Unable to clear completed review lock.');
        }

        mysqli_stmt_bind_param($remove_lock, 'i', $assessment_id);
        mysqli_stmt_execute($remove_lock);
        mysqli_stmt_close($remove_lock);

        mysqli_commit($conn);

        send_json(true, 'Assessment has already been reviewed.', [
            'assessment_id' => (int) $assessment_id,
            'is_reviewer' => false,
            'reviewer_id' => null,
            'reviewer_name' => null,
            'lock_token' => null
        ]);
    }
    // Remove an expired review lock, if one exists.
    $expire_stmt = mysqli_prepare(
        $conn,
        "DELETE FROM assessment_review_locks
         WHERE assessment_id = ?
           AND expires_at <= NOW()"
    );

    if (!$expire_stmt) {
        throw new Exception('Unable to check expired review locks.');
    }

    mysqli_stmt_bind_param($expire_stmt, 'i', $assessment_id);

    if (!mysqli_stmt_execute($expire_stmt)) {
        throw new Exception('Unable to remove expired review lock.');
    }

    mysqli_stmt_close($expire_stmt);

    // Attempt to acquire the exclusive review lock.
    // INSERT IGNORE preserves an existing lock owned by another counselor.
    $lock_stmt = mysqli_prepare(
        $conn,
        "INSERT IGNORE INTO assessment_review_locks
            (assessment_id, counselor_id, lock_token, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 SECOND))"
    );

    if (!$lock_stmt) {
        throw new Exception('Unable to prepare review lock.');
    }

    mysqli_stmt_bind_param(
        $lock_stmt,
        'iis',
        $assessment_id,
        $counselor_id,
        $new_lock_token
    );

    if (!mysqli_stmt_execute($lock_stmt)) {
        throw new Exception('Unable to acquire review lock.');
    }

    mysqli_stmt_close($lock_stmt);

    // Read the current lock owner.
    $current_lock_stmt = mysqli_prepare(
        $conn,
        "SELECT
            l.counselor_id,
            l.lock_token,
            u.fullname
         FROM assessment_review_locks l
         INNER JOIN user_data u
            ON u.user_id = l.counselor_id
         WHERE l.assessment_id = ?
           AND l.expires_at > NOW()
         LIMIT 1"
    );

    if (!$current_lock_stmt) {
        throw new Exception('Unable to retrieve current reviewer.');
    }

    mysqli_stmt_bind_param(
        $current_lock_stmt,
        'i',
        $assessment_id
    );

    mysqli_stmt_execute($current_lock_stmt);

    $lock_result = mysqli_stmt_get_result($current_lock_stmt);
    $lock_data = $lock_result
        ? mysqli_fetch_assoc($lock_result)
        : null;

    mysqli_stmt_close($current_lock_stmt);

    if (!$lock_data) {
        throw new Exception('No active review lock was found.');
    }

    mysqli_commit($conn);

    $is_reviewer = (int) $lock_data['counselor_id'] === $counselor_id;

    send_json(true, 'Assessment viewing registered.', [
        'assessment_id' => (int) $assessment_id,
        'is_reviewer' => $is_reviewer,
        'reviewer_id' => (int) $lock_data['counselor_id'],
        'reviewer_name' => $lock_data['fullname'],
        'lock_token' => $is_reviewer
            ? $lock_data['lock_token']
            : null
    ]);

} catch (Throwable $error) {

    mysqli_rollback($conn);

    error_log(
        'Assessment viewing registration error: ' . $error->getMessage()
    );

    send_json(
        false,
        'Unable to register assessment viewing. Please try again.',
        [],
        500
    );
}
