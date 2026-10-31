
<?php

require_once __DIR__ . '/../includes/counselor_session.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function viewers_response($success, $message, $extra = [], $http_code = 200)
{
    http_response_code($http_code);

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));

    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    viewers_response(false, 'Method not allowed.', [], 405);
}

$counselor_id = (int) ($_SESSION['user_id'] ?? 0);
$assessment_id = filter_input(INPUT_GET, 'assessment_id', FILTER_VALIDATE_INT);

if ($counselor_id < 1 || ($_SESSION['role_type'] ?? '') !== 'counselor') {
    viewers_response(false, 'Unauthorized access.', [], 403);
}

if (!$assessment_id || $assessment_id < 1) {
    viewers_response(false, 'Invalid assessment ID.', [], 422);
}

if (!$conn || !mysqli_ping($conn)) {
    viewers_response(false, 'Database connection unavailable.', [], 500);
}

// Remove stale viewers for this assessment.
$cleanup = mysqli_prepare(
    $conn,
    "DELETE FROM assessment_viewers
     WHERE assessment_id = ?
       AND last_seen_at < DATE_SUB(NOW(), INTERVAL 30 SECOND)"
);

if (!$cleanup) {
    viewers_response(false, 'Unable to check active viewers.', [], 500);
}

mysqli_stmt_bind_param($cleanup, 'i', $assessment_id);
mysqli_stmt_execute($cleanup);
mysqli_stmt_close($cleanup);

// Retrieve active viewers.
$viewer_stmt = mysqli_prepare(
    $conn,
    "SELECT
        v.counselor_id,
        u.fullname,
        v.last_seen_at
     FROM assessment_viewers v
     INNER JOIN user_data u
        ON u.user_id = v.counselor_id
     WHERE v.assessment_id = ?
       AND v.last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
       AND u.role_type = 'counselor'
       AND u.status = 'active'
     ORDER BY v.last_seen_at DESC"
);

if (!$viewer_stmt) {
    viewers_response(false, 'Unable to retrieve active viewers.', [], 500);
}

mysqli_stmt_bind_param($viewer_stmt, 'i', $assessment_id);
mysqli_stmt_execute($viewer_stmt);

$viewer_result = mysqli_stmt_get_result($viewer_stmt);

$viewers = [];

if ($viewer_result) {
    while ($row = mysqli_fetch_assoc($viewer_result)) {
        $viewers[] = [
            'counselor_id' => (int) $row['counselor_id'],
            'fullname' => $row['fullname'],
            'is_you' => (int) $row['counselor_id'] === $counselor_id
        ];
    }
}

mysqli_stmt_close($viewer_stmt);

// Retrieve current review lock owner.
$lock_stmt = mysqli_prepare(
    $conn,
    "SELECT
        l.counselor_id,
        u.fullname
     FROM assessment_review_locks l
     INNER JOIN user_data u
        ON u.user_id = l.counselor_id
     WHERE l.assessment_id = ?
       AND l.expires_at > NOW()
       AND u.role_type = 'counselor'
       AND u.status = 'active'
     LIMIT 1"
);

if (!$lock_stmt) {
    viewers_response(false, 'Unable to retrieve review lock.', [], 500);
}

mysqli_stmt_bind_param($lock_stmt, 'i', $assessment_id);
mysqli_stmt_execute($lock_stmt);

$lock_result = mysqli_stmt_get_result($lock_stmt);
$lock = $lock_result ? mysqli_fetch_assoc($lock_result) : null;

mysqli_stmt_close($lock_stmt);

viewers_response(true, 'Viewing information retrieved.', [
    'assessment_id' => (int) $assessment_id,
    'viewer_count' => count($viewers),
    'viewers' => $viewers,
    'reviewer' => $lock ? [
        'counselor_id' => (int) $lock['counselor_id'],
        'fullname' => $lock['fullname'],
        'is_you' => (int) $lock['counselor_id'] === $counselor_id
    ] : null
]);
