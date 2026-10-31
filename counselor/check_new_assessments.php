
<?php

require_once __DIR__ . '/../includes/counselor_session.php';


header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (
    !isset($_SESSION['role_type']) ||
    $_SESSION['role_type'] !== 'counselor'
) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access.'
    ]);
    exit();
}

require_once __DIR__ . '/../config/database.php';

if (
    !function_exists('mysqli_connect_errno') ||
    !$conn ||
    !mysqli_ping($conn)
) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Database connection unavailable.'
    ]);
    exit();
}

$sql = "
    SELECT COALESCE(MAX(assessment_id), 0) AS latest_id
    FROM assessment_data
";

$result = mysqli_query($conn, $sql);

if (!$result) {
    error_log('Assessment polling error: ' . mysqli_error($conn));

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to check assessments.'
    ]);
    exit();
}

$row = mysqli_fetch_assoc($result);

echo json_encode([
    'success' => true,
    'latest_id' => (int) ($row['latest_id'] ?? 0)
]);

exit();
