<?php
require_once __DIR__ . '/../includes/counselor_session.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function workflow_json($success, $message, $extra = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') workflow_json(false, 'Method not allowed.', [], 405);
if ((int)($_SESSION['user_id'] ?? 0) < 1 || ($_SESSION['role_type'] ?? '') !== 'counselor') {
    workflow_json(false, 'Unauthorized access.', [], 403);
}
$assessment_id = filter_input(INPUT_GET, 'assessment_id', FILTER_VALIDATE_INT);
if (!$assessment_id || $assessment_id < 1) workflow_json(false, 'Invalid assessment ID.', [], 422);
if (!$conn || !mysqli_ping($conn)) workflow_json(false, 'Database connection unavailable.', [], 500);

try {
    $stmt = mysqli_prepare($conn, "SELECT status FROM assessment_data WHERE assessment_id = ? LIMIT 1");
    if (!$stmt) throw new Exception('Unable to prepare assessment status query.');
    mysqli_stmt_bind_param($stmt, 'i', $assessment_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $assessment = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    if (!$assessment) workflow_json(false, 'Assessment not found.', [], 404);

    $stmt = mysqli_prepare($conn, "SELECT referral_id, referral_status FROM referral_data WHERE assessment_id = ? ORDER BY referral_id DESC LIMIT 1");
    if (!$stmt) throw new Exception('Unable to prepare referral status query.');
    mysqli_stmt_bind_param($stmt, 'i', $assessment_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $referral = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    $schedule = null;
    if ($referral) {
        $referral_id = (int)$referral['referral_id'];
        $stmt = mysqli_prepare($conn, "SELECT schedule_id, meeting_date, meeting_time, location, status FROM schedule_data WHERE referral_id = ? ORDER BY schedule_id DESC LIMIT 1");
        if (!$stmt) throw new Exception('Unable to prepare appointment status query.');
        mysqli_stmt_bind_param($stmt, 'i', $referral_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $schedule = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
    }

    $is_scheduled = $schedule && strtolower((string)($schedule['status'] ?? '')) !== 'cancelled';
    workflow_json(true, 'Workflow status retrieved.', [
        'assessment_id' => (int)$assessment_id,
        'is_reviewed' => strtolower(trim((string)$assessment['status'])) === 'reviewed',
        'has_referral' => (bool)$referral,
        'referral_id' => $referral ? (int)$referral['referral_id'] : null,
        'referral_status' => $referral['referral_status'] ?? null,
        'is_scheduled' => (bool)$is_scheduled,
        'schedule' => $is_scheduled ? $schedule : null
    ]);
} catch (Throwable $e) {
    error_log('Assessment workflow status error: ' . $e->getMessage());
    workflow_json(false, 'Unable to retrieve assessment workflow status.', [], 500);
}
