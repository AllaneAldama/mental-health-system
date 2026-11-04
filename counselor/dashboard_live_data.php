<?php
/**
 * Dashboard-specific revision endpoint.
 * Read-only: returns a revision hash, never exposes student records.
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

function dashboard_live_table_exists($conn, $table)
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    $table = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
    return $result && mysqli_num_rows($result) > 0;
}

function dashboard_live_columns($conn, $table, $candidates)
{
    if (!dashboard_live_table_exists($conn, $table)) {
        return [];
    }

    $available = [];

    foreach ($candidates as $column) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
            continue;
        }

        $escaped = mysqli_real_escape_string($conn, $column);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$escaped}'");

        if ($result && mysqli_num_rows($result) > 0) {
            $available[] = $column;
        }
    }

    return $available;
}

function dashboard_live_snapshot($conn, $table, $candidates, $order_candidates)
{
    $columns = dashboard_live_columns($conn, $table, $candidates);

    if (!$columns) {
        return ['exists' => dashboard_live_table_exists($conn, $table), 'columns' => [], 'rows' => []];
    }

    $select = implode(', ', array_map(function ($column) {
        return '`' . $column . '`';
    }, $columns));

    $order = '';
    foreach ($order_candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $order = " ORDER BY `{$candidate}` ASC";
            break;
        }
    }

    $result = mysqli_query($conn, "SELECT {$select} FROM `{$table}`{$order}");

    if (!$result) {
        return false;
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }

    return [
        'exists' => true,
        'columns' => $columns,
        'rows' => $rows
    ];
}

/*
 * Include the records that affect dashboard totals, review progress,
 * recent submissions, and counselor/student account counts.
 * Referral and schedule snapshots are included so workflow changes can
 * trigger a dashboard refresh without coupling this endpoint to the Queue.
 */
$snapshots = [
    'users' => dashboard_live_snapshot(
        $conn,
        'user_data',
        ['user_id', 'role_type', 'status'],
        ['user_id']
    ),
    'student_profiles' => dashboard_live_snapshot(
        $conn,
        'student_profiles',
        ['student_id', 'updated_at'],
        ['student_id']
    ),
    'assessments' => dashboard_live_snapshot(
        $conn,
        'assessment_data',
        ['assessment_id', 'student_id', 'status', 'review_status', 'is_reviewed',
         'assessment_date', 'submitted_at', 'created_at', 'reviewed_at',
         'review_date', 'assessment_type'],
        ['assessment_id', 'id']
    ),
    'referrals' => dashboard_live_snapshot(
        $conn,
        'referral_data',
        ['referral_id', 'assessment_id', 'referral_status', 'referral_date',
         'follow_up_date', 'counselor_notes', 'referral_reason'],
        ['referral_id']
    ),
    'schedules' => dashboard_live_snapshot(
        $conn,
        'schedule_data',
        ['schedule_id', 'referral_id', 'meeting_date', 'meeting_time', 'location', 'status'],
        ['schedule_id']
    )
];

foreach ($snapshots as $snapshot) {
    if ($snapshot === false) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to read dashboard revision data']);
        exit();
    }
}

$encoded = json_encode($snapshots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($encoded === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to create dashboard revision']);
    exit();
}

echo json_encode([
    'success' => true,
    'revision' => hash('sha256', $encoded)
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit();
