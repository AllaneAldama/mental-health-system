<?php
/**
 * Assessment Queue-only workflow revision endpoint.
 * This file is called only by assessment_queue.php and has no side effects.
 */
require_once __DIR__ . '/../includes/counselor_session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role_type']) ||
    $_SESSION['role_type'] !== 'counselor'
) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once __DIR__ . '/../config/database.php';

if (!isset($conn) || !$conn || !mysqli_ping($conn)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection unavailable']);
    exit();
}

function queue_revision_table_exists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
    return $result && mysqli_num_rows($result) > 0;
}

function queue_revision_columns($conn, $table, $columns)
{
    if (!queue_revision_table_exists($conn, $table)) {
        return [];
    }

    $available = [];
    foreach ($columns as $column) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            continue;
        }
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '" . mysqli_real_escape_string($conn, $column) . "'");
        if ($result && mysqli_num_rows($result) > 0) {
            $available[] = $column;
        }
    }
    return $available;
}

function queue_revision_rows($conn, $table, $columns, $order_column)
{
    $available = queue_revision_columns($conn, $table, $columns);
    if (!$available) {
        return [];
    }

    $select = implode(', ', array_map(function ($column) {
        return '`' . $column . '`';
    }, $available));

    $order = in_array($order_column, $available, true)
        ? " ORDER BY `{$order_column}` ASC"
        : '';

    $result = mysqli_query($conn, "SELECT {$select} FROM `{$table}`{$order}");
    if (!$result) {
        return ['query_error' => mysqli_error($conn)];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    return $rows;
}

$revision_data = [
    'assessments' => queue_revision_rows(
        $conn,
        'assessment_data',
        ['assessment_id', 'status', 'reviewed_at', 'assessment_date'],
        'assessment_id'
    ),
    'referrals' => queue_revision_rows(
        $conn,
        'referral_data',
        ['referral_id', 'assessment_id', 'referral_status', 'referral_date', 'follow_up_date'],
        'referral_id'
    ),
    'schedules' => queue_revision_rows(
        $conn,
        'schedule_data',
        ['schedule_id', 'referral_id', 'meeting_date', 'meeting_time', 'location', 'status'],
        'schedule_id'
    )
];

if (
    isset($revision_data['assessments']['query_error']) ||
    isset($revision_data['referrals']['query_error']) ||
    isset($revision_data['schedules']['query_error'])
) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to read queue workflow status']);
    exit();
}

$encoded = json_encode($revision_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$revision = hash('sha256', $encoded ?: '');

$latest_id = 0;
foreach ($revision_data['assessments'] as $row) {
    $latest_id = max($latest_id, (int)($row['assessment_id'] ?? 0));
}

echo json_encode([
    'success' => true,
    'latest_id' => $latest_id,
    'revision' => $revision
]);
exit();
