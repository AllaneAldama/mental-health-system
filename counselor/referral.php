<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'counselor') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/system_monitoring.php";
mysqli_report(MYSQLI_REPORT_OFF);

/** Helper: check whether a table exists */
function table_exists($conn, $name) {
    $name_esc = mysqli_real_escape_string($conn, $name);
    $sql = "SHOW TABLES LIKE '$name_esc'";
    $res = mysqli_query($conn, $sql);
    return ($res && mysqli_num_rows($res) > 0);
}

/** Helper: check whether a column exists on a table */
function column_exists($conn, $table, $column) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $column);
    $sql = "SHOW COLUMNS FROM `$t` LIKE '$c'";
    $res = mysqli_query($conn, $sql);
    return ($res && mysqli_num_rows($res) > 0);
}

// Detect tables using the actual schema for this project
$assessment_table_candidates = [
    'assessment_data', 'assessments', 'assessment', 'assessment_results', 'assessment_submissions',
    'assessment_records', 'responses', 'survey_responses', 'phq9_results', 'gad7_results'
];
$assessment_table = null;
foreach ($assessment_table_candidates as $cand) {
    if (table_exists($conn, $cand)) { $assessment_table = $cand; break; }
}

$referral_table_candidates = ['referral_data', 'referrals', 'referral', 'referral_records'];
$referral_table = null;
foreach ($referral_table_candidates as $cand) {
    if (table_exists($conn, $cand)) { $referral_table = $cand; break; }
}

$schedule_table = table_exists($conn, 'schedule_data') ? 'schedule_data' : null;

$message = '';
$error = '';
$action = isset($_POST['action']) ? $_POST['action'] : '';
$requested_month = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
$month_start = preg_match('/^\d{4}-\d{2}$/', $requested_month)
    ? DateTimeImmutable::createFromFormat('!Y-m-d', $requested_month . '-01')
    : false;
if (!$month_start || $month_start->format('Y-m') !== $requested_month) {
    $month_start = new DateTimeImmutable(date('Y-m-01'));
}
$month_end = $month_start->modify('first day of next month');
$calendar_grid_start = $month_start->modify('-' . $month_start->format('w') . ' days');
$calendar_days = [];
for ($day = $calendar_grid_start; $day < $month_end || count($calendar_days) % 7 !== 0; $day = $day->modify('+1 day')) {
    $calendar_days[] = $day;
}
$previous_month = $month_start->modify('-1 month')->format('Y-m');
$next_month = $month_start->modify('+1 month')->format('Y-m');

if ($action === 'create_referral' && $assessment_table && $referral_table) {
    $assessment_id = isset($_POST['assessment_id']) ? (int)$_POST['assessment_id'] : 0;
    $remarks = isset($_POST['counselor_notes']) ? mysqli_real_escape_string($conn, $_POST['counselor_notes']) : '';
    $counselor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    if ($assessment_id > 0 && $counselor_id > 0) {
        $assessment_stmt = mysqli_prepare($conn, "SELECT status FROM `$assessment_table` WHERE assessment_id = ? LIMIT 1");
        if (!$assessment_stmt) {
            $error = "Unable to verify assessment review status: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($assessment_stmt, 'i', $assessment_id);
            mysqli_stmt_execute($assessment_stmt);
            $assessment_result = mysqli_stmt_get_result($assessment_stmt);
            $assessment_row = $assessment_result ? mysqli_fetch_assoc($assessment_result) : null;
            mysqli_stmt_close($assessment_stmt);

            if (!$assessment_row || $assessment_row['status'] !== 'Reviewed') {
                $error = "Only reviewed assessments can be referred.";
            } else {
                $duplicate_stmt = mysqli_prepare(
                    $conn,
                    "SELECT referral_id FROM `$referral_table` WHERE assessment_id = ? LIMIT 1"
                );
                if (!$duplicate_stmt) {
                    $error = "Unable to check for an existing referral: " . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param($duplicate_stmt, 'i', $assessment_id);
                    mysqli_stmt_execute($duplicate_stmt);
                    $duplicate_result = mysqli_stmt_get_result($duplicate_stmt);
                    $existing_referral = $duplicate_result ? mysqli_fetch_assoc($duplicate_result) : null;
                    mysqli_stmt_close($duplicate_stmt);

                    if ($existing_referral) {
                        $error = "A referral already exists for this assessment.";
                    } else {
                        $referral_stmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO `$referral_table` (assessment_id, counselor_id, referral_status, referral_date, remarks)
                             VALUES (?, ?, 'Pending', NOW(), ?)"
                        );
                        if (!$referral_stmt) {
                            $error = "Unable to prepare referral: " . mysqli_error($conn);
                        } else {
                            mysqli_stmt_bind_param($referral_stmt, 'iis', $assessment_id, $counselor_id, $remarks);
                            if (mysqli_stmt_execute($referral_stmt)) {
                                $message = "Referral created. You can now schedule the appointment.";
                                system_monitor_log(
                                    $conn,
                                    'referral_created',
                                    $counselor_id,
                                    $assessment_id,
                                    'Counselor created a referral'
                                );
                            } else {
                                $error = "Error creating referral: " . mysqli_stmt_error($referral_stmt);
                                system_monitor_log($conn, 'application_error', $counselor_id, null, 'Referral creation failed');
                            }
                            mysqli_stmt_close($referral_stmt);
                        }
                    }
                }
            }
        }
    } else {
        $error = "Assessment and counselor details are required to create a referral.";
    }
} elseif ($action === 'schedule_referral' && $assessment_table && $referral_table && $schedule_table) {
    $referral_id = isset($_POST['referral_id']) ? (int)$_POST['referral_id'] : 0;
    $meeting_date = isset($_POST['meeting_date']) ? trim($_POST['meeting_date']) : '';
    $meeting_time = isset($_POST['meeting_time']) ? trim($_POST['meeting_time']) : '';
    $location = isset($_POST['location']) ? trim($_POST['location']) : '';
    $counselor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $valid_date = DateTime::createFromFormat('Y-m-d', $meeting_date);
    $valid_time = DateTime::createFromFormat('H:i', $meeting_time);

    if (
        $referral_id < 1
        || $counselor_id < 1
        || !$valid_date
        || $valid_date->format('Y-m-d') !== $meeting_date
        || !$valid_time
        || $valid_time->format('H:i') !== $meeting_time
    ) {
        $error = "Enter a valid appointment date and time.";
    } elseif ($location === '') {
        $error = "Enter a meeting location.";
    } else {
        $ownership_stmt = mysqli_prepare(
            $conn,
            "SELECT r.referral_id
             FROM `$referral_table` r
             JOIN `$assessment_table` a ON a.assessment_id = r.assessment_id
             WHERE r.referral_id = ? AND r.counselor_id = ? AND a.status = 'Reviewed'
             LIMIT 1"
        );
        if (!$ownership_stmt) {
            $error = "Unable to verify referral: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($ownership_stmt, 'ii', $referral_id, $counselor_id);
            mysqli_stmt_execute($ownership_stmt);
            $ownership_result = mysqli_stmt_get_result($ownership_stmt);
            $owned_referral = $ownership_result ? mysqli_fetch_assoc($ownership_result) : null;
            mysqli_stmt_close($ownership_stmt);

            if (!$owned_referral) {
                $error = "This referral is unavailable or its assessment has not been reviewed.";
            } else {
                $existing_schedule_stmt = mysqli_prepare(
                    $conn,
                    "SELECT schedule_id FROM `$schedule_table`
                     WHERE referral_id = ? ORDER BY schedule_id DESC LIMIT 1"
                );
                if (!$existing_schedule_stmt) {
                    $error = "Unable to check for an existing appointment: " . mysqli_error($conn);
                } else {
                    $existing_schedule = null;
                    mysqli_stmt_bind_param($existing_schedule_stmt, 'i', $referral_id);
                    if (mysqli_stmt_execute($existing_schedule_stmt)) {
                        $existing_schedule_result = mysqli_stmt_get_result($existing_schedule_stmt);
                        $existing_schedule = $existing_schedule_result ? mysqli_fetch_assoc($existing_schedule_result) : null;
                    } else {
                        $error = "Unable to check for an existing appointment: " . mysqli_stmt_error($existing_schedule_stmt);
                    }
                    mysqli_stmt_close($existing_schedule_stmt);

                    if ($error !== '') {
                        $schedule_stmt = null;
                    } elseif ($existing_schedule) {
                        $schedule_id = (int)$existing_schedule['schedule_id'];
                        $schedule_stmt = mysqli_prepare(
                            $conn,
                            "UPDATE `$schedule_table`
                             SET meeting_date = ?, meeting_time = ?, location = ?, status = 'Pending'
                             WHERE schedule_id = ?"
                        );
                        if ($schedule_stmt) {
                            mysqli_stmt_bind_param($schedule_stmt, 'sssi', $meeting_date, $meeting_time, $location, $schedule_id);
                        }
                    } else {
                        $schedule_stmt = mysqli_prepare(
                            $conn,
                            "INSERT INTO `$schedule_table` (referral_id, meeting_date, meeting_time, location, status)
                             VALUES (?, ?, ?, ?, 'Pending')"
                        );
                        if ($schedule_stmt) {
                            mysqli_stmt_bind_param($schedule_stmt, 'isss', $referral_id, $meeting_date, $meeting_time, $location);
                        }
                    }

                    if ($error === '' && !$schedule_stmt) {
                        $error = "Unable to prepare appointment: " . mysqli_error($conn);
                    } elseif ($error === '' && mysqli_stmt_execute($schedule_stmt)) {
                        $message = $existing_schedule
                            ? "Referral appointment rescheduled successfully."
                            : "Referral appointment scheduled successfully.";
                        mysqli_stmt_close($schedule_stmt);
                    } elseif ($error === '') {
                        $error = "Unable to save the referral appointment: " . mysqli_stmt_error($schedule_stmt);
                        mysqli_stmt_close($schedule_stmt);
                    }
                }
            }
        }
    }
} elseif ($action === 'update_status' && $referral_table) {
    $referral_id = isset($_POST['referral_id']) ? (int)$_POST['referral_id'] : 0;
    $status = isset($_POST['status']) ? $_POST['status'] : '';
    $notes = isset($_POST['update_notes']) ? trim($_POST['update_notes']) : '';
    $counselor_id = (int)($_SESSION['user_id'] ?? 0);

    if ($referral_id < 1 || !in_array($status, ['Pending', 'Accepted', 'Completed'], true)) {
        $error = "Select a valid referral status.";
    } else {
        $updated_at = date('Y-m-d H:i');
        if ($notes !== '') {
            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE `$referral_table`
                 SET referral_status = ?, remarks = CONCAT(COALESCE(remarks, ''), '\n---\n', ?, ' (Updated: $updated_at)')
                 WHERE referral_id = ? AND counselor_id = ?"
            );
            if ($update_stmt) {
                mysqli_stmt_bind_param($update_stmt, 'ssii', $status, $notes, $referral_id, $counselor_id);
            }
        } else {
            $update_stmt = mysqli_prepare(
                $conn,
                "UPDATE `$referral_table` SET referral_status = ? WHERE referral_id = ? AND counselor_id = ?"
            );
            if ($update_stmt) {
                mysqli_stmt_bind_param($update_stmt, 'sii', $status, $referral_id, $counselor_id);
            }
        }

        if (!$update_stmt) {
            $error = "Unable to prepare referral update: " . mysqli_error($conn);
        } elseif (mysqli_stmt_execute($update_stmt)) {
            $message = "Referral status updated successfully.";
            system_monitor_log($conn, 'referral_updated', $counselor_id, $referral_id, 'Counselor updated referral status');
            mysqli_stmt_close($update_stmt);
        } else {
            $error = "Error updating referral: " . mysqli_stmt_error($update_stmt);
            system_monitor_log($conn, 'application_error', $counselor_id, null, 'Referral update failed');
            mysqli_stmt_close($update_stmt);
        }
    }
} elseif ($action === 'accept_referral' && $referral_table) {
    $referral_id = isset($_POST['referral_id']) ? (int)$_POST['referral_id'] : 0;
    $counselor_id = (int)($_SESSION['user_id'] ?? 0);
    $accept_stmt = mysqli_prepare(
        $conn,
        "UPDATE `$referral_table` SET referral_status = 'Accepted' WHERE referral_id = ? AND counselor_id = ?"
    );
    if (!$accept_stmt) {
        $error = "Unable to prepare referral acceptance: " . mysqli_error($conn);
    } else {
        mysqli_stmt_bind_param($accept_stmt, 'ii', $referral_id, $counselor_id);
        if (mysqli_stmt_execute($accept_stmt)) {
            $message = "Referral accepted.";
            system_monitor_log($conn, 'referral_accepted', $counselor_id, $referral_id, 'Counselor accepted a referral');
        } else {
            $error = "Error accepting referral: " . mysqli_stmt_error($accept_stmt);
            system_monitor_log($conn, 'application_error', $counselor_id, null, 'Referral acceptance failed');
        }
        mysqli_stmt_close($accept_stmt);
    }
}

$pending_referrals = [];
$for_referral_assessments = [];
$schedules_by_referral = [];
$calendar_events = [];
$calendar_error = '';

if ($assessment_table && $referral_table) {
    $sql = "SELECT r.referral_id, r.assessment_id, r.counselor_id, r.referral_status, r.referral_date, r.remarks,
                   a.user_id, a.phq9_score, a.gad7_score, a.risk_level, a.assessment_date,
                   u.fullname, u.email
            FROM `$referral_table` r
            LEFT JOIN `$assessment_table` a ON r.assessment_id = a.assessment_id
            LEFT JOIN user_data u ON u.user_id = a.user_id
            WHERE r.counselor_id = " . (int)($_SESSION['user_id'] ?? 0) . "
            ORDER BY r.referral_date DESC";

    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $pending_referrals[] = $row;
        }
    }

    $sql = "SELECT a.assessment_id, a.user_id, a.phq9_score, a.gad7_score, a.risk_level, a.assessment_date,
                   u.fullname, u.email
            FROM `$assessment_table` a
            LEFT JOIN user_data u ON u.user_id = a.user_id
            LEFT JOIN `$referral_table` r ON r.assessment_id = a.assessment_id
            WHERE a.status = 'Reviewed' AND r.referral_id IS NULL
            ORDER BY a.assessment_date DESC";

    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $for_referral_assessments[] = $row;
        }
    }

    if ($schedule_table && $referral_table) {
        $schedule_sql = "SELECT s.schedule_id, s.referral_id, s.meeting_date, s.meeting_time, s.location, s.status
                         FROM `$schedule_table` s
                         JOIN `$referral_table` r ON r.referral_id = s.referral_id
                         WHERE r.counselor_id = " . (int)($_SESSION['user_id'] ?? 0) . "
                         ORDER BY s.schedule_id DESC";
        $schedule_res = mysqli_query($conn, $schedule_sql);
        if ($schedule_res) {
            while ($schedule_row = mysqli_fetch_assoc($schedule_res)) {
                if (!isset($schedules_by_referral[$schedule_row['referral_id']])) {
                    $schedules_by_referral[$schedule_row['referral_id']] = $schedule_row;
                }
            }
        }
    }

    if ($schedule_table && $referral_table && $assessment_table) {
        $calendar_sql = "SELECT s.schedule_id, s.referral_id, s.meeting_date, s.meeting_time, s.location,
                                s.status AS schedule_status, r.referral_status, r.assessment_id, u.fullname
                         FROM `$schedule_table` s
                         JOIN `$referral_table` r ON r.referral_id = s.referral_id
                         JOIN `$assessment_table` a ON a.assessment_id = r.assessment_id
                         LEFT JOIN user_data u ON u.user_id = a.user_id
                         WHERE r.counselor_id = ?
                           AND s.meeting_date >= ? AND s.meeting_date < ?
                         ORDER BY s.meeting_date ASC, s.meeting_time ASC, s.schedule_id ASC";
        $calendar_stmt = mysqli_prepare($conn, $calendar_sql);
        if ($calendar_stmt) {
            $counselor_id = (int)($_SESSION['user_id'] ?? 0);
            $month_start_value = $month_start->format('Y-m-d');
            $month_end_value = $month_end->format('Y-m-d');
            mysqli_stmt_bind_param($calendar_stmt, 'iss', $counselor_id, $month_start_value, $month_end_value);
            if (mysqli_stmt_execute($calendar_stmt)) {
                $calendar_result = mysqli_stmt_get_result($calendar_stmt);
                if ($calendar_result) {
                    while ($calendar_event = mysqli_fetch_assoc($calendar_result)) {
                        $calendar_events[$calendar_event['meeting_date']][] = $calendar_event;
                    }
                }
            } else {
                $calendar_error = 'Unable to load scheduled referrals: ' . mysqli_stmt_error($calendar_stmt);
            }
            mysqli_stmt_close($calendar_stmt);
        } else {
            $calendar_error = 'Unable to prepare the referral calendar: ' . mysqli_error($conn);
        }
    }
}

$pending_referral_count = count(array_filter($pending_referrals, function ($referral) {
    return ($referral['referral_status'] ?? '') === 'Pending';
}));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Management - Counselor Dashboard</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #efeeeb;
            color: #1a1a1a;
        }
        a { text-decoration: none; color: inherit; }
        .app-shell { display: flex; min-height: 100vh; }
        .sidebar {
            width: 260px;
            background: #5c8a60;
            color: #fff;
            padding: 22px 18px 18px;
            display: flex;
            flex-direction: column;
        }
        .brand {
            font-size: 2rem;
            font-weight: 500;
            letter-spacing: -0.05em;
            padding: 6px 12px 20px;
            color: #f5f8f3;
        }
        .nav { display: flex; flex-direction: column; gap: 12px; margin-top: 24px; }
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 1.05rem;
            font-weight: 600;
            color: #f1f5ef;
            transition: all 0.2s ease;
        }
        .nav-link:hover, .nav-link.active {
            background: #edf2ee;
            color: #1f3d2a;
        }
        .logout {
            margin-top: auto;
            border-top: 1px solid rgba(255,255,255,0.25);
            padding-top: 16px;
        }
        .main-content { flex: 1; padding: 30px 32px 40px; }
        .header { margin-bottom: 20px; }
        .header h1 {
            margin: 0;
            font-size: 2.7rem;
            font-weight: 600;
            letter-spacing: -0.06em;
            color: #1a1a1a;
        }
        .header p {
            margin: 8px 0 0;
            font-size: 1.15rem;
            color: #4c4c4c;
        }
        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: rgba(255,255,255,0.28);
            border: 1px solid #dfe4dc;
            border-radius: 12px;
            padding: 18px 18px 14px;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .stat-card h3 {
            font-size: 0.98rem;
            color: #2e2e2e;
            font-weight: 700;
            margin: 0;
        }
        .stat-card .value {
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.05em;
            color: #1f3d2a;
        }
        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-weight: 600;
        }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #b71c1c; border: 1px solid #ffcdd2; }
        .card {
            background: rgba(255,255,255,0.28);
            border: 1px solid #dfe4dc;
            border-radius: 12px;
            padding: 18px 18px 12px;
            margin-bottom: 20px;
        }
        .card h2 {
            margin: 0 0 14px;
            font-size: 1.15rem;
            font-weight: 800;
            color: #1d1d1d;
        }
        .card h3 {
            color: #34495e;
            margin-top: 20px;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2a2a2a;
            font-weight: 700;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cdd4cf;
            border-radius: 8px;
            font: inherit;
            background: #fff;
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.2s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-primary { background: #5c8a60; color: white; }
        .btn-primary:hover { background: #496e4f; }
        .btn-secondary {
            background: #dfe4dc;
            color: #1f3d2a;
            margin-left: 5px;
        }
        .btn-secondary:hover { background: #d0d8cf; }
        .btn-success { background: #4fae6d; color: white; }
        .btn-success:hover { background: #3f8d5a; }
        .btn-info { background: #3d7cb3; color: white; }
        .btn-info:hover { background: #315f8d; }
        .table-container { overflow-x: auto; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; }
        thead {
            background: rgba(255,255,255,0.35);
            border-bottom: 1px solid #dfe4dc;
        }
        th {
            padding: 12px;
            text-align: left;
            color: #2d2d2d;
            font-weight: 700;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #edf2ee;
            color: #34495e;
        }
        tr:hover { background: rgba(255,255,255,0.18); }
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-pending { background: #fff2b2; color: #8a5b00; }
        .badge-accepted { background: #dff5e3; color: #1f6f3c; }
        .badge-completed { background: #e4e9ee; color: #4c5962; }
        .badge-high { background: #ffd8d4; color: #a8433b; }
        .badge-moderate { background: #ffe7b8; color: #ac6d00; }
        .badge-low { background: #dff5e3; color: #207e46; }
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal.show { display: flex; }
        .modal-content {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.18);
            padding: 22px;
            max-width: 600px;
            width: min(600px, 100%);
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #5c8a60;
        }
        .modal-header h2 {
            color: #2a2a2a;
            margin: 0;
            font-size: 1.5rem;
        }
        .modal-close {
            border: none;
            background: transparent;
            font-size: 1.5rem;
            cursor: pointer;
            color: #4c4c4c;
        }
        .assessment-info {
            background: #f5f7f5;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .assessment-info p { margin: 5px 0; color: #34495e; }
        .assessment-info strong { color: #2c3e50; }
        .risk-high { color: #c0392b; font-weight: bold; }
        .risk-moderate { color: #f39c12; font-weight: bold; }
        .risk-low { color: #27ae60; font-weight: bold; }
        .calendar-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }
        .calendar-toolbar h2 { margin: 0; }
        .calendar-navigation { display: flex; align-items: center; gap: 10px; }
        .calendar-navigation .btn { min-width: 42px; padding: 8px 12px; }
        .calendar-weekdays, .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
        }
        .calendar-weekday {
            padding: 10px 6px;
            color: #536354;
            font-size: 0.82rem;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
        }
        .calendar-day {
            min-height: 130px;
            padding: 8px;
            border: 1px solid #e0e6df;
            background: rgba(255,255,255,0.24);
        }
        .calendar-day.outside-month { background: rgba(230,232,228,0.38); color: #9a9f99; }
        .calendar-day.today { box-shadow: inset 0 0 0 2px #5c8a60; }
        .calendar-day-number {
            display: inline-flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            margin-bottom: 6px;
            border-radius: 50%;
            font-weight: 700;
        }
        .calendar-day.today .calendar-day-number { background: #5c8a60; color: #fff; }
        .calendar-event {
            display: block;
            margin: 4px 0;
            padding: 6px 7px;
            border-left: 3px solid #5c8a60;
            border-radius: 5px;
            background: #e8f1e7;
            color: #24462a;
            font-size: 0.78rem;
            line-height: 1.35;
            overflow-wrap: anywhere;
        }
        .calendar-event:hover { background: #d9e9d8; }
        .calendar-event-time { display: block; font-weight: 700; }
        .calendar-empty { padding: 20px; color: #686e68; text-align: center; }
        @media (max-width: 900px) {
            .app-shell { flex-direction: column; }
            .sidebar { width: 100%; min-height: auto; }
            .main-content { padding: 20px; }
            .stats-container { grid-template-columns: 1fr; }
            .calendar-day { min-height: 105px; padding: 5px; }
            .calendar-event { font-size: 0.7rem; padding: 5px; }
        }
        @media (max-width: 600px) {
            .calendar-weekday { font-size: 0.65rem; padding: 8px 2px; }
            .calendar-day { min-height: 78px; padding: 3px; }
            .calendar-day-number { width: 23px; height: 23px; margin-bottom: 2px; font-size: 0.8rem; }
            .calendar-event { padding: 3px; font-size: 0.65rem; }
            .calendar-event-location { display: none; }
            .calendar-toolbar { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="app-shell">
      <aside class="sidebar">
        <div class="brand">PTC Wellness</div>
        <nav class="nav" aria-label="Main navigation">
          <a class="nav-link" href="dashboard.php"><span class="nav-icon">🏠</span><span>Dashboard</span></a>
          <a class="nav-link" href="assessment_queue.php"><span class="nav-icon">📋</span><span>Assessments Queue</span></a>
          <a class="nav-link" href="monitoring.php"><span class="nav-icon">👁️</span><span>Monitoring</span></a>
          <a class="nav-link active" href="referral.php"><span class="nav-icon">📤</span><span>Referral</span></a>
          <a class="nav-link" href="assessment_history.php"><span class="nav-icon">📚</span><span>Assessment History</span></a>
          <a class="nav-link" href="profile.php"><span class="nav-icon">👤</span><span>Profile</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

        <main class="main-content">

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="stats-container">
            <div class="stat-card">
                <h3>Pending Referrals</h3>
                <div class="value"><?php echo $pending_referral_count; ?></div>
            </div>
            <div class="stat-card">
                <h3>Reviewed Assessments Ready</h3>
                <div class="value"><?php echo count($for_referral_assessments); ?></div>
            </div>
            <div class="stat-card">
                <h3>Appointments Scheduled</h3>
                <div class="value"><?php echo count($schedules_by_referral); ?></div>
            </div>
        </div>

        <?php if (!$assessment_table || !$referral_table): ?>
            <div class="alert alert-error">
                <strong>Database Configuration Issue:</strong>
                Assessment table: <?php echo $assessment_table ? '✓' : '✗'; ?> |
                Referral table: <?php echo $referral_table ? '✓' : '✗'; ?>
            </div>
        <?php endif; ?>

        <section class="card" id="referral-calendar">
            <div class="calendar-toolbar">
                <h2>Referral Calendar</h2>
                <div class="calendar-navigation">
                    <a class="btn btn-secondary" href="?month=<?php echo htmlspecialchars($previous_month, ENT_QUOTES, 'UTF-8'); ?>#referral-calendar" aria-label="Previous month">&lt;</a>
                    <strong><?php echo htmlspecialchars($month_start->format('F Y'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <a class="btn btn-secondary" href="?month=<?php echo htmlspecialchars(date('Y-m'), ENT_QUOTES, 'UTF-8'); ?>#referral-calendar">Today</a>
                    <a class="btn btn-secondary" href="?month=<?php echo htmlspecialchars($next_month, ENT_QUOTES, 'UTF-8'); ?>#referral-calendar" aria-label="Next month">&gt;</a>
                </div>
            </div>
            <?php if ($calendar_error !== ''): ?>
                <p class="alert alert-error"><?php echo htmlspecialchars($calendar_error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php elseif (!$schedule_table): ?>
                <p class="calendar-empty">The schedule_data table is not available, so no appointments can be displayed.</p>
            <?php else: ?>
                <div class="calendar-weekdays" aria-hidden="true">
                    <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $weekday): ?>
                        <div class="calendar-weekday"><?php echo $weekday; ?></div>
                    <?php endforeach; ?>
                </div>
                <div class="calendar-grid">
                    <?php foreach ($calendar_days as $calendar_day): ?>
                        <?php
                            $date_key = $calendar_day->format('Y-m-d');
                            $day_classes = ['calendar-day'];
                            if ($calendar_day->format('Y-m') !== $month_start->format('Y-m')) {
                                $day_classes[] = 'outside-month';
                            }
                            if ($date_key === date('Y-m-d')) {
                                $day_classes[] = 'today';
                            }
                        ?>
                        <div class="<?php echo implode(' ', $day_classes); ?>">
                            <span class="calendar-day-number"><?php echo $calendar_day->format('j'); ?></span>
                            <?php foreach ($calendar_events[$date_key] ?? [] as $calendar_event): ?>
                                <a class="calendar-event" href="#referral-<?php echo (int)$calendar_event['referral_id']; ?>" title="<?php echo htmlspecialchars(($calendar_event['fullname'] ?? 'Unknown student') . ' - ' . ($calendar_event['location'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="calendar-event-time"><?php echo htmlspecialchars(date('g:i A', strtotime($calendar_event['meeting_time'])), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span><?php echo htmlspecialchars($calendar_event['fullname'] ?? 'Unknown student', ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="calendar-event-location"><?php echo htmlspecialchars($calendar_event['location'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (empty($calendar_events)): ?>
                    <p class="calendar-empty">No referral appointments are scheduled for this month.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- Students Ready for Referral -->
        <div class="card">
            <h2>Reviewed Assessments Ready for Referral</h2>
            <?php if (!empty($for_referral_assessments)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Assessment ID</th>
                                <th>PHQ-9 / GAD-7</th>
                                <th>Risk Level</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($for_referral_assessments as $assessment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($assessment['fullname'] ?? 'Unknown Student'); ?></td>
                                    <td>#<?php echo htmlspecialchars($assessment['assessment_id'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($assessment['phq9_score'] ?? 'N/A'); ?> / <?php echo htmlspecialchars($assessment['gad7_score'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($assessment['risk_level'] ?? 'low'); ?>">
                                            <?php echo htmlspecialchars($assessment['risk_level'] ?? 'Unknown'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($assessment['assessment_date'] ?? 'now')); ?></td>
                                    <td>
                                        <button class="btn btn-primary" onclick="openReferralForm(<?php echo (int)($assessment['assessment_id'] ?? 0); ?>, <?php echo json_encode($assessment['fullname'] ?? 'Unknown', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>, <?php echo (int)($assessment['phq9_score'] ?? 0); ?>, <?php echo (int)($assessment['gad7_score'] ?? 0); ?>)">
                                            Create Referral
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>No reviewed assessments are currently awaiting a referral.</p>
            <?php endif; ?>
        </div>

        <!-- Pending Referrals -->
        <div class="card">
            <h2>Referrals &amp; Appointments</h2>
            <?php if (!empty($pending_referrals)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Assessment</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Appointment</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_referrals as $referral): ?>
                                <tr id="referral-<?php echo (int)($referral['referral_id'] ?? 0); ?>">
                                    <?php
                                        $referral_id = (int)($referral['referral_id'] ?? 0);
                                        $appointment = $schedules_by_referral[$referral_id] ?? null;
                                    ?>
                                    <td><?php echo htmlspecialchars($referral['fullname'] ?? 'Unknown Student'); ?></td>
                                    <td>#<?php echo htmlspecialchars($referral['assessment_id'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($referral['referral_status'] ?? 'pending'); ?>">
                                            <?php echo htmlspecialchars($referral['referral_status'] ?? 'Pending'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($referral['referral_date'] ?? 'now')); ?></td>
                                    <td>
                                        <?php if ($appointment): ?>
                                            <?php echo htmlspecialchars(date('M d, Y', strtotime($appointment['meeting_date'])) . ' ' . date('g:i A', strtotime($appointment['meeting_time']))); ?><br>
                                            <small><?php echo htmlspecialchars($appointment['location']); ?> · <?php echo htmlspecialchars($appointment['status']); ?></small>
                                        <?php else: ?>
                                            Not scheduled
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$appointment && $schedule_table): ?>
                                            <button class="btn btn-primary" onclick="openScheduleForm(<?php echo $referral_id; ?>, <?php echo json_encode($referral['fullname'] ?? 'Unknown', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)">Schedule</button>
                                        <?php elseif ($appointment && $schedule_table): ?>
                                            <button class="btn btn-primary" onclick="openScheduleForm(<?php echo $referral_id; ?>, <?php echo json_encode($referral['fullname'] ?? 'Unknown', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)">Reschedule</button>
                                        <?php endif; ?>
                                        <?php if (($referral['referral_status'] ?? '') === 'Pending'): ?>
                                            <button class="btn btn-success" onclick="acceptReferral(<?php echo $referral_id; ?>)">Accept</button>
                                        <?php endif; ?>
                                        <button class="btn btn-info" onclick="openUpdateStatus(<?php echo $referral_id; ?>, <?php echo json_encode($referral['referral_status'] ?? 'Pending', JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)">Update</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>No pending referrals to display.</p>
            <?php endif; ?>
            <?php if (!$schedule_table): ?>
                <p class="alert alert-error">The schedule_data table is unavailable, so appointments cannot be scheduled yet.</p>
            <?php endif; ?>
        </div>
    </main>
    </div>

    <!-- Create Referral Modal -->
    <div id="referralModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="modal-close" onclick="closeModal('referralModal')">&times;</button>
                <h2>Create New Referral</h2>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_referral">
                <input type="hidden" name="assessment_id" id="assessmentId">
                <div class="assessment-info">
                    <p><strong>Student:</strong> <span id="modalStudentName"></span></p>
                    <p><strong>Scores:</strong> <span id="modalScores"></span></p>
                </div>

                <div class="form-group">
                    <label for="counselorNotes">Counselor Notes</label>
                    <textarea name="counselor_notes" id="counselorNotes" placeholder="Add referral notes..."></textarea>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Create Referral</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('referralModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Schedule Referral Modal -->
    <div id="scheduleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="modal-close" type="button" onclick="closeModal('scheduleModal')">&times;</button>
                <h2>Schedule Referral</h2>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="schedule_referral">
                <input type="hidden" name="referral_id" id="scheduleReferralId">
                <div class="assessment-info">
                    <p><strong>Student:</strong> <span id="scheduleStudentName"></span></p>
                </div>
                <div class="form-group">
                    <label for="meetingDate">Meeting Date *</label>
                    <input type="date" name="meeting_date" id="meetingDate" min="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="form-group">
                    <label for="meetingTime">Meeting Time *</label>
                    <input type="time" name="meeting_time" id="meetingTime" required>
                </div>
                <div class="form-group">
                    <label for="meetingLocation">Location *</label>
                    <input type="text" name="location" id="meetingLocation" maxlength="255" required>
                </div>
                <button type="submit" class="btn btn-primary">Save Appointment</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('scheduleModal')">Cancel</button>
            </form>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="updateStatusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="modal-close" onclick="closeModal('updateStatusModal')">&times;</button>
                <h2>Update Referral Status</h2>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="referral_id" id="referralId">

                <div class="form-group">
                    <label for="statusSelect">Status *</label>
                    <select name="status" id="statusSelect" required>
                        <option value="Pending">Pending</option>
                        <option value="Accepted">Accepted</option>
                        <option value="Completed">Completed</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="updateNotes">Update Notes</label>
                    <textarea name="update_notes" id="updateNotes" placeholder="Add notes about this status update..."></textarea>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('updateStatusModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openReferralForm(assessmentId, studentName, phqScore, gadScore) {
            document.getElementById('assessmentId').value = assessmentId;
            document.getElementById('modalStudentName').textContent = studentName;
            document.getElementById('modalScores').textContent = 'PHQ-9: ' + phqScore + ' | GAD-7: ' + gadScore;
            document.getElementById('referralModal').classList.add('show');
        }

        function openScheduleForm(referralId, studentName) {
            document.getElementById('scheduleReferralId').value = referralId;
            document.getElementById('scheduleStudentName').textContent = studentName;
            document.getElementById('scheduleModal').classList.add('show');
        }

        function openUpdateStatus(referralId, status) {
            document.getElementById('referralId').value = referralId;
            document.getElementById('statusSelect').value = status;
            document.getElementById('updateStatusModal').classList.add('show');
        }

        function acceptReferral(referralId) {
            if (confirm('Are you sure you want to accept this referral?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="accept_referral"><input type="hidden" name="referral_id" value="' + referralId + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        window.onclick = function(event) {
            const referralModal = document.getElementById('referralModal');
            const updateModal = document.getElementById('updateStatusModal');
            const scheduleModal = document.getElementById('scheduleModal');
            if (event.target === referralModal) {
                referralModal.classList.remove('show');
            }
            if (event.target === updateModal) {
                updateModal.classList.remove('show');
            }
            if (event.target === scheduleModal) {
                scheduleModal.classList.remove('show');
            }
        };
    </script>
</body>
</html>
