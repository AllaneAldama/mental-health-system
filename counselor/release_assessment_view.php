<?php
require_once __DIR__ . '/../includes/counselor_session.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

function sendResponse($success, $message, $extra = []) {
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

// POST requests only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    sendResponse(false, 'Invalid request method.');
}

// Check counselor session
if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_type']) ||
    $_SESSION['role_type'] !== 'counselor'
) {
    http_response_code(401);
    sendResponse(false, 'Unauthorized access.');
}

// Check database connection
if (!$conn) {
    http_response_code(500);
    sendResponse(false, 'Database connection failed.');
}

$counselor_id = (int) $_SESSION['user_id'];
$assessment_id = isset($_POST['assessment_id'])
    ? (int) $_POST['assessment_id']
    : 0;

$lock_token = isset($_POST['lock_token'])
    ? trim($_POST['lock_token'])
    : '';

if ($assessment_id <= 0) {
    http_response_code(400);
    sendResponse(false, 'Invalid assessment ID.');
}

// Verify that the account is an active counselor
$checkCounselor = mysqli_prepare(
    $conn,
    "SELECT user_id
     FROM user_data
     WHERE user_id = ?
       AND role_type = 'counselor'
       AND status = 'active'
     LIMIT 1"
);

mysqli_stmt_bind_param($checkCounselor, "i", $counselor_id);
mysqli_stmt_execute($checkCounselor);

$counselorResult = mysqli_stmt_get_result($checkCounselor);
$isCounselor = mysqli_fetch_assoc($counselorResult);

mysqli_stmt_close($checkCounselor);

if (!$isCounselor) {
    http_response_code(403);
    sendResponse(false, 'Active counselor account required.');
}

mysqli_begin_transaction($conn);

try {

    $lockReleased = false;

    // Release the exclusive review lock only if
    // this counselor owns the matching lock token.
    if ($lock_token !== '') {

        $releaseLock = mysqli_prepare(
            $conn,
            "DELETE FROM assessment_review_locks
             WHERE assessment_id = ?
               AND counselor_id = ?
               AND lock_token = ?"
        );

        mysqli_stmt_bind_param(
            $releaseLock,
            "iis",
            $assessment_id,
            $counselor_id,
            $lock_token
        );

        mysqli_stmt_execute($releaseLock);

        $lockReleased = mysqli_stmt_affected_rows($releaseLock) > 0;

        mysqli_stmt_close($releaseLock);
    }

    // Remove this counselor from the active viewers list.
    $releaseViewer = mysqli_prepare(
        $conn,
        "DELETE FROM assessment_viewers
         WHERE assessment_id = ?
           AND counselor_id = ?"
    );

    mysqli_stmt_bind_param(
        $releaseViewer,
        "ii",
        $assessment_id,
        $counselor_id
    );

    mysqli_stmt_execute($releaseViewer);
    mysqli_stmt_close($releaseViewer);

    mysqli_commit($conn);

    sendResponse(true, 'Assessment view released.', [
        'lock_released' => $lockReleased
    ]);

} catch (Throwable $e) {

    mysqli_rollback($conn);

    http_response_code(500);
    sendResponse(false, 'Unable to release assessment view.');
}