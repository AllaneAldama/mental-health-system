
<?php

require_once __DIR__ . '/../includes/counselor_session.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function heartbeat_response($success, $message, $extra = [], $http_code = 200)
{
    http_response_code($http_code);

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));

    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    heartbeat_response(false, 'Method not allowed.', [], 405);
}

$counselor_id = (int) ($_SESSION['user_id'] ?? 0);
$assessment_id = filter_input(INPUT_POST, 'assessment_id', FILTER_VALIDATE_INT);

if (
    $counselor_id < 1 ||
    ($_SESSION['role_type'] ?? '') !== 'counselor'
) {
    heartbeat_response(false, 'Unauthorized access.', [], 403);
}

if (!$assessment_id || $assessment_id < 1) {
    heartbeat_response(false, 'Invalid assessment ID.', [], 422);
}

if (!$conn || !mysqli_ping($conn)) {
    heartbeat_response(false, 'Database connection unavailable.', [], 500);
}

$token = trim((string) ($_POST['lock_token'] ?? ''));

try {

    mysqli_begin_transaction($conn);

    // Update this counselor's active viewing record.
    $viewer_stmt = mysqli_prepare(
        $conn,
        "UPDATE assessment_viewers
         SET last_seen_at = NOW()
         WHERE assessment_id = ?
           AND counselor_id = ?"
    );

    if (!$viewer_stmt) {
        throw new Exception('Unable to prepare viewer heartbeat.');
    }

    mysqli_stmt_bind_param(
        $viewer_stmt,
        'ii',
        $assessment_id,
        $counselor_id
    );

    if (!mysqli_stmt_execute($viewer_stmt)) {
        throw new Exception('Unable to update viewer heartbeat.');
    }

    $viewer_updated = mysqli_stmt_affected_rows($viewer_stmt);
    mysqli_stmt_close($viewer_stmt);

    if ($viewer_updated === 0) {
        // Re-register if the viewing record expired or was removed.
        $viewer_insert = mysqli_prepare(
            $conn,
            "INSERT INTO assessment_viewers
                (assessment_id, counselor_id, last_seen_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_seen_at = NOW()"
        );

        if (!$viewer_insert) {
            throw new Exception('Unable to restore viewing record.');
        }

        mysqli_stmt_bind_param(
            $viewer_insert,
            'ii',
            $assessment_id,
            $counselor_id
        );

        if (!mysqli_stmt_execute($viewer_insert)) {
            throw new Exception('Unable to restore viewing record.');
        }

        mysqli_stmt_close($viewer_insert);
    }

    // Extend the review lock only if this counselor owns it.
    $is_reviewer = false;

    if ($token !== '') {

        $lock_stmt = mysqli_prepare(
            $conn,
            "UPDATE assessment_review_locks
             SET expires_at = DATE_ADD(NOW(), INTERVAL 30 SECOND)
             WHERE assessment_id = ?
               AND counselor_id = ?
               AND lock_token = ?
               AND expires_at > NOW()"
        );

        if (!$lock_stmt) {
            throw new Exception('Unable to prepare review lock heartbeat.');
        }

        mysqli_stmt_bind_param(
            $lock_stmt,
            'iis',
            $assessment_id,
            $counselor_id,
            $token
        );

        if (!mysqli_stmt_execute($lock_stmt)) {
            throw new Exception('Unable to extend review lock.');
        }

        $is_reviewer = mysqli_stmt_affected_rows($lock_stmt) > 0;

        mysqli_stmt_close($lock_stmt);
    }

    mysqli_commit($conn);

    heartbeat_response(true, 'Heartbeat updated.', [
        'assessment_id' => (int) $assessment_id,
        'is_reviewer' => $is_reviewer
    ]);

} catch (Throwable $error) {

    mysqli_rollback($conn);

    error_log(
        'Assessment heartbeat error: ' . $error->getMessage()
    );

    heartbeat_response(
        false,
        'Unable to update assessment heartbeat.',
        [],
        500
    );
}
