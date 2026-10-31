
<?php
require_once __DIR__ . '/../includes/counselor_session.php';
require_once __DIR__ . '/../config/database.php';

if (
    !isset($_SESSION['role_type']) ||
    $_SESSION['role_type'] !== 'counselor'
) {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/system_monitoring.php";

mysqli_report(MYSQLI_REPORT_OFF);

function ref_table_exists($conn, $table) {
    $table = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $result && mysqli_num_rows($result) > 0;
}


function ref_column_exists($conn, $table, $column) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }
    $column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && mysqli_num_rows($result) > 0;
}

function ref_first_column($conn, $table, $candidates) {
    foreach ($candidates as $column) {
        if (ref_column_exists($conn, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function ref_escape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ref_redirect($month = null) {
    $url = "referral.php";
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $url .= "?month=" . urlencode($month);
    }
    header("Location: " . $url);
    exit();
}

/* Detect the same assessment table used by the queue and review pages. */
$assessment_table = null;

foreach ([
    'assessment_data',
    'needs_assessment',
    'needs_assessment_responses',
    'assessment_submissions',
    'assessment_records',
    'assessments',
    'assessment',
    'assessment_results'
] as $candidate) {
    if (ref_table_exists($conn, $candidate)) {
        $assessment_table = $candidate;
        break;
    }
}

$referral_table = ref_table_exists($conn, 'referral_data')
    ? 'referral_data' : null;

$schedule_table = ref_table_exists($conn, 'schedule_data')
    ? 'schedule_data' : null;

/* Sync overdue pending appointments to Completed in the appointment status. */
if ($schedule_table && ref_column_exists($conn, $schedule_table, 'meeting_date')
    && ref_column_exists($conn, $schedule_table, 'meeting_time')
    && ref_column_exists($conn, $schedule_table, 'status')) {
    mysqli_query(
        $conn,
        "UPDATE `$schedule_table`
         SET status = 'Completed'
         WHERE status = 'Pending'
           AND CONCAT(meeting_date, ' ', COALESCE(meeting_time, '00:00:00')) < NOW()"
    );
}

$counselor_id = (int)($_SESSION['user_id'] ?? 0);

/* Active counselors available for authorized case assignment during scheduling. */
$active_counselors = [];
$counselor_result = mysqli_query(
    $conn,
    "SELECT user_id, fullname FROM user_data
     WHERE role_type = 'counselor' AND status = 'active'
     ORDER BY fullname ASC"
);
if ($counselor_result) {
    while ($counselor_row = mysqli_fetch_assoc($counselor_result)) {
        $active_counselors[] = $counselor_row;
    }
}

function ref_is_active_counselor($conn, $candidate_id) {
    if ((int)$candidate_id < 1) return false;
    $stmt = mysqli_prepare(
        $conn,
        "SELECT user_id FROM user_data
         WHERE user_id = ? AND role_type = 'counselor' AND status = 'active'
         LIMIT 1"
    );
    if (!$stmt) return false;
    $candidate_id = (int)$candidate_id;
    mysqli_stmt_bind_param($stmt, 'i', $candidate_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $valid = $result && mysqli_num_rows($result) === 1;
    mysqli_stmt_close($stmt);
    return $valid;
}

$message = '';
$error = '';

$requested_month = $_GET['month'] ?? date('Y-m');
$month_start = DateTimeImmutable::createFromFormat(
    '!Y-m-d',
    $requested_month . '-01'
);

if (
    !$month_start ||
    $month_start->format('Y-m') !== $requested_month
) {
    $month_start = new DateTimeImmutable(date('Y-m-01'));
}

$month_end = $month_start->modify('first day of next month');
$previous_month = $month_start->modify('-1 month')->format('Y-m');
$next_month = $month_start->modify('+1 month')->format('Y-m');

$calendar_start = $month_start->modify(
    '-' . $month_start->format('w') . ' days'
);

$calendar_days = [];
$calendar_day = $calendar_start;

while (
    $calendar_day < $month_end ||
    count($calendar_days) % 7 !== 0
) {
    $calendar_days[] = $calendar_day;
    $calendar_day = $calendar_day->modify('+1 day');
}

/* CREATE REFERRAL — detect the actual assessment columns before validating. */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'create_referral'
) {
    $assessment_id = filter_input(
        INPUT_POST,
        'assessment_id',
        FILTER_VALIDATE_INT
    ) ?: 0;

    $notes = trim($_POST['counselor_notes'] ?? '');

    if (!$assessment_table || !$referral_table) {
        $error = "The assessment or referral table is unavailable.";
    } elseif ($assessment_id < 1 || $counselor_id < 1) {
        $error = "Please select a valid assessment.";
    } elseif (strlen($notes) > 10000) {
        $error = "Referral notes are too long.";
    } else {
        $assessment_id_col = ref_first_column(
            $conn,
            $assessment_table,
            ['assessment_id', 'id', 'submission_id', 'record_id']
        );
        $assessment_status_col = ref_first_column(
            $conn,
            $assessment_table,
            ['status', 'review_status', 'assessment_status', 'submission_status']
        );
        $assessment_type_col = ref_first_column(
            $conn,
            $assessment_table,
            ['assessment_type', 'assessment_name', 'survey_type', 'type']
        );
        $referral_assessment_col = ref_first_column(
            $conn,
            $referral_table,
            ['assessment_id', 'assessment_record_id', 'submission_id']
        );
        $referral_id_col = ref_first_column(
            $conn,
            $referral_table,
            ['referral_id', 'id']
        );

        if (!$assessment_id_col || !$assessment_status_col) {
            $error = "Unable to identify the assessment ID or review status column.";
        } elseif (!$referral_assessment_col || !$referral_id_col) {
            $error = "Unable to identify the assessment and referral ID columns.";
        } else {
            $assessment_sql = "SELECT a.`$assessment_id_col` AS assessment_id
                               FROM `$assessment_table` a
                               WHERE a.`$assessment_id_col` = ?
                                 AND LOWER(TRIM(COALESCE(a.`$assessment_status_col`, ''))) = 'reviewed'";

            /* If the table stores multiple assessment types, verify this is a Needs Assessment. */
            $assessment_sql .= " LIMIT 1";

            $stmt = mysqli_prepare($conn, $assessment_sql);

            if (!$stmt) {
                $error = "Unable to verify the assessment.";
            } else {
                mysqli_stmt_bind_param($stmt, 'i', $assessment_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $verified_assessment = $result
                    ? mysqli_fetch_assoc($result)
                    : null;
                mysqli_stmt_close($stmt);

                if (!$verified_assessment) {
                    $error = "Only reviewed Needs Assessments can be referred.";
                } else {
                    $stmt = mysqli_prepare(
                        $conn,
                        "SELECT `$referral_id_col` AS referral_id
                         FROM `$referral_table`
                         WHERE `$referral_assessment_col` = ?
                         LIMIT 1"
                    );

                    if (!$stmt) {
                        $error = "Unable to check existing referrals.";
                    } else {
                        mysqli_stmt_bind_param($stmt, 'i', $assessment_id);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        $existing = $result
                            ? mysqli_fetch_assoc($result)
                            : null;
                        mysqli_stmt_close($stmt);

                        if ($existing) {
                            $error = "A referral already exists for this assessment.";
                        } else {
                    /* CREATE REFERRAL — use the detected assessment column */
                    $insert_columns = [
                        $referral_assessment_col,
                        'counselor_id',
                        'referral_status',
                        'referral_date',
                        'remarks'
                    ];

                    $required_columns = [
                        'counselor_id',
                        'referral_status',
                        'referral_date',
                        'remarks'
                    ];

                    $missing_columns = [];

                    foreach ($required_columns as $column) {
                        if (!ref_column_exists($conn, $referral_table, $column)) {
                            $missing_columns[] = $column;
                        }
                    }

                    if (!empty($missing_columns)) {
                        $error = "Referral table is missing required columns: "
                            . implode(', ', $missing_columns);
                    } else {
                        $assessment_column_sql = "`" . $referral_assessment_col . "`";

                        $insert_sql = "INSERT INTO `$referral_table`
                            ($assessment_column_sql, `counselor_id`,
                            `referral_status`, `referral_date`, `remarks`)
                            VALUES (?, ?, 'Pending', NOW(), ?)";

                        $stmt = mysqli_prepare($conn, $insert_sql);

                        if (!$stmt) {
                            error_log('Referral prepare failed: ' . mysqli_error($conn));
                            $error = "Unable to prepare referral creation. Please check the database columns.";
                        } else {
                            mysqli_stmt_bind_param(
                                $stmt,
                                'iis',
                                $assessment_id,
                                $counselor_id,
                                $notes
                            );

                                if (mysqli_stmt_execute($stmt)) {
                                    mysqli_stmt_close($stmt);

                                    system_monitor_log(
                                        $conn,
                                        'referral_created',
                                        $counselor_id,
                                        $assessment_id,
                                        'Counselor created a referral'
                                    );

                                    if (
                                        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                                        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
                                    ) {
                                        header('Content-Type: application/json; charset=utf-8');
                                        echo json_encode([
                                            'success' => true,
                                            'action' => 'create_referral',
                                            'message' => 'Referral Created Successfully!'
                                        ]);
                                        exit;
                                    }

                                    $_SESSION['success_message'] = 'Referral created successfully!';
                                    ref_redirect($month_start->format('Y-m'));
                                } else {
                                    error_log(
                                        'Create referral failed: ' . mysqli_stmt_error($stmt)
                                    );

                                    $error = "Unable to create referral. Check the PHP error log for the database error.";
                                    mysqli_stmt_close($stmt);
                                }
                            }
                            }
                        }
                    }
                }
            }
        }
    }
}

/* SCHEDULE OR RESCHEDULE APPOINTMENT */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'schedule_referral'
) {
    $referral_id = filter_input(
        INPUT_POST,
        'referral_id',
        FILTER_VALIDATE_INT
    ) ?: 0;

    $meeting_date = trim($_POST['meeting_date'] ?? '');
    $meeting_time = trim($_POST['meeting_time'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $assigned_counselor_id = filter_input(INPUT_POST, 'assigned_counselor_id', FILTER_VALIDATE_INT) ?: $counselor_id;

    $date = DateTime::createFromFormat('!Y-m-d', $meeting_date);
    $time = DateTime::createFromFormat('!H:i', $meeting_time);

    if (!$schedule_table || !$referral_table || !$assessment_table) {
        $error = "The required appointment tables are unavailable.";
    } elseif (
        $referral_id < 1 ||
        !$date || $date->format('Y-m-d') !== $meeting_date ||
        !$time || $time->format('H:i') !== $meeting_time
    ) {
        $error = "Enter a valid appointment date and time.";
    } elseif ($meeting_date < date('Y-m-d')) {
        $error = "Appointment dates cannot be in the past.";
    } elseif ($location === '' || strlen($location) > 255) {
        $error = "Enter a valid meeting location.";
    } elseif (!ref_is_active_counselor($conn, $assigned_counselor_id)) {
        $error = "Choose an active counselor for this referral.";
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT r.referral_id
             FROM `$referral_table` r
             JOIN `$assessment_table` a
               ON a.assessment_id = r.assessment_id
             WHERE r.referral_id = ?
               AND r.counselor_id = ?
               AND a.status = 'Reviewed'
             LIMIT 1"
        );

        if (!$stmt) {
            $error = "Unable to verify referral ownership.";
        } else {
            mysqli_stmt_bind_param(
                $stmt,
                'ii',
                $referral_id,
                $counselor_id
            );
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $owned = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);

            if (!$owned) {
                $error = "Referral not found or not assigned to your account.";
            } else {
                $stmt = mysqli_prepare(
                    $conn,
                    "SELECT schedule_id
                     FROM `$schedule_table`
                     WHERE referral_id = ?
                     ORDER BY schedule_id DESC
                     LIMIT 1"
                );

                $existing = null;

                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'i', $referral_id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    $existing = $result
                        ? mysqli_fetch_assoc($result)
                        : null;
                    mysqli_stmt_close($stmt);
                }

                if ($existing) {
                    $schedule_id = (int)$existing['schedule_id'];

                    $stmt = mysqli_prepare(
                        $conn,
                        "UPDATE `$schedule_table`
                         SET meeting_date = ?,
                             meeting_time = ?,
                             location = ?,
                             status = 'Pending'
                         WHERE schedule_id = ?"
                    );

                    if ($stmt) {
                        mysqli_stmt_bind_param(
                            $stmt,
                            'sssi',
                            $meeting_date,
                            $meeting_time,
                            $location,
                            $schedule_id
                        );
                    }
                } else {
                    $stmt = mysqli_prepare(
                        $conn,
                        "INSERT INTO `$schedule_table`
                            (referral_id, meeting_date, meeting_time,
                             location, status)
                         VALUES (?, ?, ?, ?, 'Pending')"
                    );

                    if ($stmt) {
                        mysqli_stmt_bind_param(
                            $stmt,
                            'isss',
                            $referral_id,
                            $meeting_date,
                            $meeting_time,
                            $location
                        );
                    }
                }

                if (!$stmt) {
                    $error = "Unable to prepare the appointment.";
                } elseif (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);

                    /* A successfully scheduled appointment automatically accepts the referral. */
                    $assignment_stmt = mysqli_prepare(
                        $conn,
                        "UPDATE `$referral_table`
                         SET counselor_id = ?,
                             referral_status = 'Accepted'
                         WHERE referral_id = ? AND counselor_id = ?"
                    );
                    $assignment_saved = false;
                    if ($assignment_stmt) {
                        mysqli_stmt_bind_param(
                            $assignment_stmt,
                            'iii',
                            $assigned_counselor_id,
                            $referral_id,
                            $counselor_id
                        );
                        $assignment_saved = mysqli_stmt_execute($assignment_stmt);
                        mysqli_stmt_close($assignment_stmt);
                    }

                    if (!$assignment_saved) {
                        error_log('Appointment saved but referral assignment update failed for referral ' . $referral_id);
                        $error = "Appointment saved, but the referral status/assignment could not be updated. Please check the database.";
                    } else {
                    if (
                        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
                    ) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => true,
                            'action' => 'schedule_referral',
                            'message' => 'Appointment Scheduled Successfully!'
                        ]);
                        exit;
                    }

                    $_SESSION['appointment_success_message'] =
                        'Appointment Scheduled Successfully!';

                    ref_redirect($month_start->format('Y-m'));
                    }
                } else {
                    $error = "Unable to save the appointment.";
                    mysqli_stmt_close($stmt);
                }
            }
        }
    }
}

/* UPDATE REFERRAL STATUS */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update_status'
) {
    $referral_id = filter_input(
        INPUT_POST,
        'referral_id',
        FILTER_VALIDATE_INT
    ) ?: 0;

    $status = $_POST['status'] ?? '';
    $notes = trim($_POST['update_notes'] ?? '');

    if (!$referral_table || $referral_id < 1) {
        $error = "Invalid referral record.";
    } elseif (!in_array(
        $status,
        ['Pending', 'Accepted', 'Completed'],
        true
    )) {
        $error = "Select a valid referral status.";
    } elseif (strlen($notes) > 10000) {
        $error = "Update notes are too long.";
    } else {
        if ($notes !== '') {
            $updated_at = date('Y-m-d H:i');
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE `$referral_table`
                 SET referral_status = ?,
                     remarks = CONCAT(
                         COALESCE(remarks, ''),
                         '\n---\n',
                         ?,
                         ' (Updated: $updated_at)'
                     )
                 WHERE referral_id = ?
                   AND counselor_id = ?"
            );

            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    'ssii',
                    $status,
                    $notes,
                    $referral_id,
                    $counselor_id
                );
            }
        } else {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE `$referral_table`
                 SET referral_status = ?
                 WHERE referral_id = ?
                   AND counselor_id = ?"
            );

            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    'sii',
                    $status,
                    $referral_id,
                    $counselor_id
                );
            }
        }

                if (!$stmt) {
                    $error = "Unable to prepare the status update.";
                } elseif (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);

                    if (
                        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
                    ) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => true,
                            'action' => 'update_status',
                            'message' => 'Changes Saved Successfully!'
                        ]);
                        exit;
                    }

                    $_SESSION['status_success_message'] =
                        'Changes Saved Successfully!';

                    ref_redirect($month_start->format('Y-m'));
                } else {
                    $error = "Unable to update the referral status.";
                    mysqli_stmt_close($stmt);
                }
    }
}

/* LOAD REFERRALS AND ASSESSMENTS */
$referrals = [];
$ready_assessments = [];
$schedules_by_referral = [];
$calendar_events = [];
$calendar_error = '';

if ($assessment_table && $referral_table) {
    $sql = "SELECT
                r.referral_id,
                r.assessment_id,
                r.referral_status,
                r.referral_date,
                r.remarks,
                a.user_id,
                a.assessment_type,
                a.assessment_date,
                a.status AS assessment_status,
                u.fullname,
                u.email
            FROM `$referral_table` r
            LEFT JOIN `$assessment_table` a
              ON a.assessment_id = r.assessment_id
            LEFT JOIN user_data u
              ON u.user_id = a.user_id
            WHERE r.counselor_id = ?
            ORDER BY r.referral_date DESC";

    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $counselor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while ($result && $row = mysqli_fetch_assoc($result)) {
            $referrals[] = $row;
        }

        mysqli_stmt_close($stmt);
    }
}

/* Load reviewed assessments that do not have a referral yet. */
$id_column = null;
$user_column = null;
$date_column = null;
$status_column = null;

if ($assessment_table && $referral_table) {
    foreach (['assessment_id', 'id', 'submission_id', 'record_id'] as $column) {
        if (ref_table_exists($conn, $assessment_table)) {
            $check = mysqli_query(
                $conn,
                "SHOW COLUMNS FROM `$assessment_table` LIKE '" .
                mysqli_real_escape_string($conn, $column) . "'"
            );

            if ($check && mysqli_num_rows($check) > 0) {
                $id_column = $column;
                break;
            }
        }
    }

    foreach (['user_id', 'student_id'] as $column) {
        $check = mysqli_query(
            $conn,
            "SHOW COLUMNS FROM `$assessment_table` LIKE '" .
            mysqli_real_escape_string($conn, $column) . "'"
        );

        if ($check && mysqli_num_rows($check) > 0) {
            $user_column = $column;
            break;
        }
    }

    foreach (['assessment_date', 'submitted_at', 'created_at', 'date_submitted'] as $column) {
        $check = mysqli_query(
            $conn,
            "SHOW COLUMNS FROM `$assessment_table` LIKE '" .
            mysqli_real_escape_string($conn, $column) . "'"
        );

        if ($check && mysqli_num_rows($check) > 0) {
            $date_column = $column;
            break;
        }
    }

    foreach (['status', 'review_status', 'submission_status'] as $column) {
        $check = mysqli_query(
            $conn,
            "SHOW COLUMNS FROM `$assessment_table` LIKE '" .
            mysqli_real_escape_string($conn, $column) . "'"
        );

        if ($check && mysqli_num_rows($check) > 0) {
            $status_column = $column;
            break;
        }
    }

    if ($id_column && $user_column && $status_column) {
        $date_select = $date_column
            ? "a.`$date_column`"
            : "NULL";

        $sql = "SELECT
                    a.`$id_column` AS assessment_id,
                    a.`$user_column` AS user_id,
                    $date_select AS assessment_date,
                    a.`$status_column` AS status,
                    u.fullname,
                    u.email
                FROM `$assessment_table` a
                LEFT JOIN user_data u
                    ON u.user_id = a.`$user_column`
                LEFT JOIN `$referral_table` r
                    ON r.assessment_id = a.`$id_column`
                WHERE LOWER(TRIM(COALESCE(a.`$status_column`, ''))) = 'reviewed'
                  AND r.assessment_id IS NULL
                ORDER BY " .
                ($date_column
                    ? "a.`$date_column` DESC"
                    : "a.`$id_column` DESC");

        $result = mysqli_query($conn, $sql);

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $ready_assessments[] = $row;
            }
        }
    }
}

if ($schedule_table && $referral_table) {
    $sql = "SELECT s.schedule_id, s.referral_id,
                   s.meeting_date, s.meeting_time,
                   s.location, s.status
            FROM `$schedule_table` s
            JOIN `$referral_table` r
              ON r.referral_id = s.referral_id
            WHERE r.counselor_id = ?
            ORDER BY s.schedule_id DESC";

    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $counselor_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while ($result && $row = mysqli_fetch_assoc($result)) {
            if (!isset($schedules_by_referral[$row['referral_id']])) {
                $schedules_by_referral[$row['referral_id']] = $row;
            }
        }

        mysqli_stmt_close($stmt);
    }

    $sql = "SELECT
                s.schedule_id,
                s.referral_id,
                s.meeting_date,
                s.meeting_time,
                s.location,
                s.status AS schedule_status,
                r.referral_status,
                r.assessment_id,
                u.fullname
            FROM `$schedule_table` s
            JOIN `$referral_table` r
              ON r.referral_id = s.referral_id
            JOIN `$assessment_table` a
              ON a.assessment_id = r.assessment_id
            LEFT JOIN user_data u
              ON u.user_id = a.user_id
            WHERE r.counselor_id = ?
              AND s.meeting_date >= ?
              AND s.meeting_date < ?
            ORDER BY s.meeting_date, s.meeting_time";

    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {
        $start = $month_start->format('Y-m-d');
        $end = $month_end->format('Y-m-d');

        mysqli_stmt_bind_param(
            $stmt,
            'iss',
            $counselor_id,
            $start,
            $end
        );

        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while ($result && $row = mysqli_fetch_assoc($result)) {
            $calendar_events[$row['meeting_date']][] = $row;
        }

        mysqli_stmt_close($stmt);
    } else {
        $calendar_error = "Unable to load appointment calendar.";
    }
}

$pending_count = count(array_filter(
    $referrals,
    fn($r) => ($r['referral_status'] ?? '') === 'Pending'
));

$scheduled_count = count($schedules_by_referral);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Management | PTC Wellness</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/counselor.css">
    <link rel="stylesheet" href="../assets/css/referral.css">
</head>
<body class="counselor-page">
<div class="app-shell">

    <aside class="sidebar">
        <a class="brand" href="dashboard.php">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 32 32">
                    <path d="M16 27V15"></path>
                    <path d="M16 17C8 17 5 12 5 5c7 0 12 4 11 12Z"></path>
                    <path d="M16 21c0-7 4-11 11-11 0 7-4 11-11 11Z"></path>
                </svg>
            </span>
            <span class="brand-copy">
                <strong class="brand-name">PTC Wellness</strong>
                <small class="brand-tagline">Mental Health &amp; Well-being</small>
            </span>
        </a>

        <nav class="nav" aria-label="Counselor navigation">
            <a class="nav-link" href="dashboard.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24">
                    <rect x="3" y="3" width="7" height="7" rx="1"></rect>
                    <rect x="14" y="3" width="7" height="7" rx="1"></rect>
                    <rect x="3" y="14" width="7" height="7" rx="1"></rect>
                    <rect x="14" y="14" width="7" height="7" rx="1"></rect>
                </svg></span>
                Dashboard
            </a>

            <a class="nav-link" href="assessment_queue.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24">
                    <path d="M8 6h13M8 12h13M8 18h13"></path>
                    <path d="m3 6 1 1 2-2M3 12l1 1 2-2M3 18l1 1 2-2"></path>
                </svg></span>
                Assessments Queue
            </a>

            <a class="nav-link" href="monitoring.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24">
                    <path d="M3 12s3-7 9-7 9 7 9 7-3 7-9 7-9-7-9-7Z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                </svg></span>
                Monitoring
            </a>

            <a class="nav-link active" href="referral.php" aria-current="page">
                <span class="nav-icon"><svg viewBox="0 0 24 24">
                    <path d="M7 17 17 7M7 7h10v10"></path>
                    <path d="M5 5v14h14"></path>
                </svg></span>
                Referral
            </a>

            <a class="nav-link" href="assessment_history.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="9"></circle>
                    <path d="M12 7v5l3 2"></path>
                </svg></span>
                Assessment History
            </a>

            <a class="nav-link" href="profile.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24">
                    <circle cx="12" cy="8" r="4"></circle>
                    <path d="M4 21a8 8 0 0 1 16 0"></path>
                </svg></span>
                Profile
            </a>

            <a class="nav-link logout" href="../auth/logout.php?portal=counselor">
                <span class="nav-icon"><svg viewBox="0 0 24 24">
                    <path d="M10 17l5-5-5-5M15 12H3"></path>
                    <path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"></path>
                </svg></span>
                Logout
            </a>
        </nav>

        <div class="sidebar-footer">
            <strong>Well-being matters.</strong>
            <span>Supporting a healthier campus, one step at a time.</span>
        </div>
    </aside>

    <main class="main-content referral-content">

        <header class="page-header referral-header">
            <div>
                <p class="referral-eyebrow">PTC WELLNESS · GUIDANCE OFFICE</p>
                <h1>Referral Management</h1>
                <p class="referral-subtitle">
                    Create referrals, schedule appointments, and assign case responsibility.
                </p>
            </div>
            <span class="referral-portal-tag">Counselor Portal</span>
        </header>

        <?php if ($message !== ''): ?>
            <div class="referral-alert success"><?= ref_escape($message) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="referral-alert error"><?= ref_escape($error) ?></div>
        <?php endif; ?>

        <!-- REFERRAL SUCCESS MODAL -->
        <?php if (!empty($_SESSION['success_message'])): ?>
            <div class="success-modal-overlay"
                id="referralSuccessModal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="referralSuccessTitle">

                <div class="success-modal-card">
                    <div class="success-modal-icon" aria-hidden="true">✓</div>

                    <h2 id="referralSuccessTitle">
                        Referral Created Successfully!
                    </h2>

                    <p>
                        The referral has been created and saved successfully.
                    </p>

                    <button type="button"
                            class="success-modal-close"
                            id="closeReferralSuccess">
                        Close
                    </button>
                </div>
            </div>

            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>


        <!-- APPOINTMENT SUCCESS MODAL -->
        <?php if (!empty($_SESSION['appointment_success_message'])): ?>
            <div class="success-modal-overlay"
                id="appointmentSuccessModal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="appointmentSuccessTitle">

                <div class="success-modal-card">
                    <div class="success-modal-icon" aria-hidden="true">✓</div>

                    <h2 id="appointmentSuccessTitle">
                        Appointment Scheduled Successfully!
                    </h2>

                    <p>
                        The appointment details have been saved successfully.
                    </p>

                    <button type="button"
                            class="success-modal-close"
                            id="closeAppointmentSuccess">
                        Close
                    </button>
                </div>
            </div>

            <?php unset($_SESSION['appointment_success_message']); ?>
        <?php endif; ?>
        
        <!-- UPDATE STATUS SUCCESS MODAL -->
        <?php if (!empty($_SESSION['status_success_message'])): ?>
            <div class="success-modal-overlay"
                id="statusSuccessModal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="statusSuccessTitle">

                <div class="success-modal-card">
                    <div class="success-modal-icon" aria-hidden="true">✓</div>

                    <h2 id="statusSuccessTitle">
                        <?= ref_escape($_SESSION['status_success_message']) ?>
                    </h2>

                    <p>
                        The referral status has been updated successfully.
                    </p>

                    <button type="button"
                            class="success-modal-close"
                            id="closeStatusSuccess">
                        Close
                    </button>
                </div>
            </div>

            <?php unset($_SESSION['status_success_message']); ?>
        <?php endif; ?>

        <?php if (!$assessment_table || !$referral_table): ?>
            <div class="referral-alert error">
                Required database tables were not found.
                Please verify assessment_data and referral_data in phpMyAdmin.
            </div>
        <?php endif; ?>

        <section class="referral-stats" id="referral-stats">
            <article class="referral-stat">
                <span>Pending Referrals</span>
                <strong><?= (int)$pending_count ?></strong>
                <small>Awaiting the next action</small>
            </article>
            <article class="referral-stat">
                <span>Needs Assessments Ready</span>
                <strong><?= count($ready_assessments) ?></strong>
                <small>Reviewed, without an existing referral</small>
            </article>
            <article class="referral-stat">
                <span>Appointments on Record</span>
                <strong><?= (int)$scheduled_count ?></strong>
                <small>Referral appointments recorded</small>
            </article>
        </section>

        <section class="referral-panel" id="referral-calendar">
            <div class="calendar-toolbar">
                <div>
                    <h2>Appointment Calendar</h2>
                    <p class="referral-section-description">
                        Monthly overview of scheduled student appointments.
                    </p>
                </div>

                <div class="calendar-navigation">
                    <a class="calendar-nav-btn"
                       href="?month=<?= ref_escape($previous_month) ?>#referral-calendar"
                       aria-label="Previous month">&lsaquo;</a>

                    <strong><?= ref_escape($month_start->format('F Y')) ?></strong>

                    <a class="calendar-nav-btn"
                       href="?month=<?= date('Y-m') ?>#referral-calendar">Today</a>

                    <a class="calendar-nav-btn"
                       href="?month=<?= ref_escape($next_month) ?>#referral-calendar"
                       aria-label="Next month">&rsaquo;</a>
                </div>
            </div>

            <?php if ($calendar_error !== ''): ?>
                <div class="referral-alert error"><?= ref_escape($calendar_error) ?></div>
            <?php elseif (!$schedule_table): ?>
                <div class="referral-empty">
                    The schedule_data table is not available.
                </div>
            <?php else: ?>
                <div class="calendar-weekdays" aria-hidden="true">
                    <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
                        <div class="calendar-weekday"><?= $weekday ?></div>
                    <?php endforeach; ?>
                </div>

                <div class="calendar-grid">
                    <?php foreach ($calendar_days as $day): ?>
                        <?php
                            $date_key = $day->format('Y-m-d');
                            $classes = ['calendar-day'];

                            if ($day->format('Y-m') !== $month_start->format('Y-m')) {
                                $classes[] = 'outside-month';
                            }

                            if ($date_key === date('Y-m-d')) {
                                $classes[] = 'today';
                            }
                        ?>
                        <div class="<?= implode(' ', $classes) ?>">
                            <span class="calendar-day-number">
                                <?= $day->format('j') ?>
                            </span>

                            <?php foreach ($calendar_events[$date_key] ?? [] as $event): ?>
                                <a class="calendar-event"
                                   href="#referral-<?= (int)$event['referral_id'] ?>"
                                   data-calendar-event
                                   data-referral-id="<?= (int)$event['referral_id'] ?>"
                                   data-student="<?= ref_escape($event['fullname'] ?? 'Student') ?>"
                                   data-date="<?= ref_escape($event['meeting_date'] ?? $date_key) ?>"
                                   data-time="<?= ref_escape($event['meeting_time'] ?? '') ?>"
                                   data-location="<?= ref_escape($event['location'] ?? '') ?>"
                                   data-status="<?= ref_escape($event['schedule_status'] ?? 'Pending') ?>"
                                   title="View appointment details for <?= ref_escape($event['fullname'] ?? 'Student') ?>">
                                    <span class="calendar-event-time">
                                        <?= ref_escape(date('g:i A', strtotime($event['meeting_time']))) ?>
                                    </span>
                                    <span class="calendar-event-name">
                                        <?= ref_escape($event['fullname'] ?? 'Student') ?>
                                    </span>
                                    <span class="calendar-event-location">
                                        <?= ref_escape($event['location'] ?? '') ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($calendar_events)): ?>
                    <p class="calendar-empty">
                        No appointments scheduled for this month.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="referral-panel" id="ready-assessments">
            <div class="referral-panel-heading">
                <div>
                    <h2>Needs Assessments Ready for Referral</h2>
                    <p class="referral-section-description">
                        Reviewed Needs Assessments without an existing referral.
                    </p>
                </div>
                <span class="record-count"><?= count($ready_assessments) ?> records</span>
            </div>

            <div class="referral-table-wrap">
                <table class="referral-table">
                    <thead>
                    <tr>
                        <th>Student</th>
                        <th>Assessment</th>
                        <th>Review Status</th>
                        <th>Submitted</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($ready_assessments)): ?>
                        <tr>
                            <td colspan="5" class="referral-empty-cell">
                                <strong>No assessments ready for referral</strong>
                                <span>Reviewed Needs Assessments will appear here.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ready_assessments as $assessment): ?>
                            <tr>
                                <td>
                                    <strong class="student-name">
                                        <?= ref_escape($assessment['fullname'] ?? 'Student') ?>
                                    </strong>
                                    <small class="student-detail">
                                        <?= ref_escape($assessment['email'] ?? '') ?>
                                    </small>
                                </td>
                                <td>
                                    <strong>#<?= (int)$assessment['assessment_id'] ?></strong>
                                    <small class="student-detail">Needs Assessment</small>
                                </td>
                                <td><span class="status-badge accepted">Reviewed</span></td>
                                <td>
                                    <?= !empty($assessment['assessment_date'])
                                        ? ref_escape(date('M d, Y', strtotime($assessment['assessment_date'])))
                                        : '—' ?>
                                </td>
                                <td>
                                    <button type="button"
                                        class="referral-button primary open-referral-modal"
                                        data-assessment-id="<?= (int)$assessment['assessment_id'] ?>"
                                        data-student="<?= ref_escape($assessment['fullname'] ?? 'Student') ?>">
                                        Create Referral
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="referral-panel" id="referrals-list">
            <div class="referral-panel-heading">
                <div>
                    <h2>Referrals &amp; Appointments</h2>
                    <p class="referral-section-description">
                        Manage referral progress and appointment details.
                    </p>
                </div>
                <span class="record-count"><?= count($referrals) ?> records</span>
            </div>

            <div class="referral-table-wrap">
                <table class="referral-table">
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
                    <?php if (empty($referrals)): ?>
                        <tr>
                            <td colspan="6" class="referral-empty-cell">
                                <strong>No referrals yet</strong>
                                <span>New referrals will appear here.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($referrals as $referral): ?>
                            <?php
                                $rid = (int)$referral['referral_id'];
                                $appointment = $schedules_by_referral[$rid] ?? null;
                                $status = $referral['referral_status'] ?? 'Pending';
                                $status_class = strtolower($status);
                                $status_class = preg_replace('/[^a-z-]/', '', $status_class);
                            ?>
                            <tr id="referral-<?= $rid ?>">
                                <td>
                                    <strong class="student-name">
                                        <?= ref_escape($referral['fullname'] ?? 'Student') ?>
                                    </strong>
                                    <small class="student-detail">
                                        <?= ref_escape($referral['email'] ?? '') ?>
                                    </small>
                                </td>
                                <td>
                                    <strong>#<?= (int)$referral['assessment_id'] ?></strong>
                                    <small class="student-detail">Needs Assessment</small>
                                </td>
                                <td>
                                    <span class="status-badge <?= ref_escape($status_class) ?>">
                                        <?= ref_escape($status) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= !empty($referral['referral_date'])
                                        ? ref_escape(date('M d, Y', strtotime($referral['referral_date'])))
                                        : '—' ?>
                                </td>
                                <td>
                                    <?php if ($appointment): ?>
                                        <strong>
                                            <?= ref_escape(date('M d, Y', strtotime($appointment['meeting_date']))) ?>
                                        </strong>
                                        <small class="student-detail">
                                            <?= ref_escape(date('g:i A', strtotime($appointment['meeting_time']))) ?>
                                            · <?= ref_escape($appointment['location']) ?>
                                        </small>
                                        <small class="student-detail">
                                            Appointment: <?= ref_escape($appointment['status']) ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="student-detail">Not scheduled</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="referral-actions">
                                        <?php if ($schedule_table): ?>
                                            <button type="button"
                                                class="referral-button secondary open-schedule-modal"
                                                data-referral-id="<?= $rid ?>"
                                                data-student="<?= ref_escape($referral['fullname'] ?? 'Student') ?>"
                                                data-date="<?= ref_escape($appointment['meeting_date'] ?? '') ?>"
                                                data-time="<?= ref_escape(isset($appointment['meeting_time']) ? substr($appointment['meeting_time'], 0, 5) : '') ?>"
                                                data-location="<?= ref_escape($appointment['location'] ?? '') ?>">
                                                <?= $appointment ? 'Reschedule' : 'Schedule' ?>
                                            </button>
                                        <?php endif; ?>

                                        <button type="button"
                                            class="referral-button secondary open-status-modal"
                                            data-referral-id="<?= $rid ?>"
                                            data-status="<?= ref_escape($status) ?>">
                                            Update
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$schedule_table): ?>
                <div class="referral-alert info">
                    Appointment scheduling is unavailable because schedule_data was not found.
                </div>
            <?php endif; ?>
        </section>

        <footer class="referral-footer">
            <span>PTC Wellness · Counselor Portal</span>
            <span>Confidential student information — handle with care.</span>
        </footer>
    </main>
</div>

<!-- Appointment Details Modal -->
<div class="appointment-details-overlay" id="appointmentDetailsModal" aria-hidden="true">
    <div class="appointment-details-card" role="dialog" aria-modal="true" aria-labelledby="appointmentDetailsTitle">
        <div class="appointment-details-heading">
            <div>
                <p class="referral-eyebrow">APPOINTMENT DETAILS</p>
                <h2 id="appointmentDetailsTitle">Scheduled Appointment</h2>
            </div>
            <button type="button" class="appointment-details-close" data-close-appointment aria-label="Close appointment details">×</button>
        </div>
        <div class="appointment-details-body">
            <div class="appointment-detail-item"><span>Student</span><strong id="appointmentDetailStudent">—</strong></div>
            <div class="appointment-detail-item"><span>Date</span><strong id="appointmentDetailDate">—</strong></div>
            <div class="appointment-detail-item"><span>Time</span><strong id="appointmentDetailTime">—</strong></div>
            <div class="appointment-detail-item"><span>Location</span><strong id="appointmentDetailLocation">—</strong></div>
            <div class="appointment-detail-item"><span>Appointment Status</span><strong id="appointmentDetailStatus">—</strong></div>
            <div class="appointment-detail-item"><span>Referral ID</span><strong id="appointmentDetailReferral">—</strong></div>
        </div>
        <div class="appointment-details-actions">
            <button type="button" class="referral-button secondary" data-close-appointment>Close</button>
            <button type="button" class="referral-button primary" id="viewAppointmentReferral">View Referral</button>
        </div>
    </div>
</div>

<!-- Create Referral Modal -->
<div class="referral-modal" id="createReferralModal" aria-hidden="true">
    <div class="referral-modal-card" role="dialog" aria-modal="true"
         aria-labelledby="createReferralTitle">
        <div class="referral-modal-heading">
            <div>
                <p class="referral-eyebrow">NEW REFERRAL</p>
                <h2 id="createReferralTitle">Create Referral</h2>
            </div>
            <button type="button" class="referral-modal-close" data-close-modal>×</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="create_referral">
            <input type="hidden" name="assessment_id" id="createAssessmentId">

            <div class="referral-student-info">
                <span>Selected student</span>
                <strong id="createStudentName">Student</strong>
                <small>Assessment type: Needs Assessment</small>
            </div>

            <div class="referral-field">
                <label for="counselorNotes">Counselor Notes</label>
                <textarea name="counselor_notes" id="counselorNotes"
                    maxlength="10000"
                    placeholder="Add relevant referral notes..."></textarea>
            </div>

            <div class="referral-modal-actions">
                <button type="button" class="referral-button secondary" data-close-modal>
                    Cancel
                </button>
                <button type="submit" class="referral-button primary">
                    Create Referral
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Schedule Appointment Modal -->
<div class="referral-modal" id="scheduleReferralModal" aria-hidden="true">
    <div class="referral-modal-card" role="dialog" aria-modal="true"
         aria-labelledby="scheduleReferralTitle">
        <div class="referral-modal-heading">
            <div>
                <p class="referral-eyebrow">APPOINTMENT</p>
                <h2 id="scheduleReferralTitle">Schedule Appointment</h2>
                <p id="scheduleStudentName" class="referral-modal-subtitle"></p>
            </div>
            <button type="button" class="referral-modal-close" data-close-modal>×</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="schedule_referral">
            <input type="hidden" name="referral_id" id="scheduleReferralId">

            <div class="referral-field">
                <label for="assignedCounselorId">Assigned Counselor</label>
                <select name="assigned_counselor_id" id="assignedCounselorId" required>
                    <?php foreach ($active_counselors as $active_counselor): ?>
                        <option value="<?= (int)$active_counselor['user_id'] ?>" <?= (int)$active_counselor['user_id'] === $counselor_id ? 'selected' : '' ?>>
                            <?= ref_escape($active_counselor['fullname']) ?><?= (int)$active_counselor['user_id'] === $counselor_id ? ' (You)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="student-detail">The selected counselor becomes responsible for this referral and its follow-ups.</small>
            </div>

            <div class="referral-field">
                <label for="meetingDate">Meeting Date</label>
                <input type="date" name="meeting_date" id="meetingDate"
                    min="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="referral-field">
                <label for="meetingTime">Meeting Time</label>
                <input type="time" name="meeting_time" id="meetingTime" required>
            </div>

            <div class="referral-field">
                <label for="meetingLocation">Meeting Location</label>
                <input type="text" name="location" id="meetingLocation"
                    maxlength="255" required>
            </div>

            <div class="referral-modal-actions">
                <button type="button" class="referral-button secondary" data-close-modal>
                    Cancel
                </button>
                <button type="submit" class="referral-button primary">
                    Save Appointment
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Update Status Modal -->
<div class="referral-modal" id="updateReferralModal" aria-hidden="true">
    <div class="referral-modal-card" role="dialog" aria-modal="true"
         aria-labelledby="updateReferralTitle">
        <div class="referral-modal-heading">
            <div>
                <p class="referral-eyebrow">REFERRAL PROGRESS</p>
                <h2 id="updateReferralTitle">Update Status</h2>
            </div>
            <button type="button" class="referral-modal-close" data-close-modal>×</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="referral_id" id="updateReferralId">

            <div class="referral-field">
                <label for="referralStatus">Referral Status</label>
                <select name="status" id="referralStatus" required>
                    <option value="Pending">Pending</option>
                    <option value="Accepted">Accepted</option>
                    <option value="Completed">Completed</option>
                </select>
            </div>

            <div class="referral-field">
                <label for="updateNotes">Update Notes</label>
                <textarea name="update_notes" id="updateNotes"
                    maxlength="10000"
                    placeholder="Add notes about this status update..."></textarea>
            </div>

            <div class="referral-modal-actions">
                <button type="button" class="referral-button secondary" data-close-modal>
                    Cancel
                </button>
                <button type="submit" class="referral-button primary">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;

        document.querySelectorAll('.referral-modal.show').forEach(function (openModal) {
            if (openModal !== modal) {
                openModal.classList.remove('show');
                openModal.setAttribute('aria-hidden', 'true');
            }
        });

        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('referral-modal-open');
    }

    function closeModal(modal) {
        if (!modal) return;

        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');

        if (!document.querySelector('.referral-modal.show')) {
            document.body.classList.remove('referral-modal-open');
            document.body.style.removeProperty('overflow');
        }
    }

    /* Event delegation keeps buttons working after AJAX replaces page sections. */
    document.addEventListener('click', function (event) {
        const createButton = event.target.closest('.open-referral-modal');
        if (createButton) {
            const assessmentInput = document.getElementById('createAssessmentId');
            const studentName = document.getElementById('createStudentName');
            if (assessmentInput) assessmentInput.value = createButton.dataset.assessmentId || '';
            if (studentName) studentName.textContent = createButton.dataset.student || 'Student';
            openModal('createReferralModal');
            return;
        }

        const scheduleButton = event.target.closest('.open-schedule-modal');
        if (scheduleButton) {
            const referralInput = document.getElementById('scheduleReferralId');
            const studentName = document.getElementById('scheduleStudentName');
            const meetingDate = document.getElementById('meetingDate');
            const meetingTime = document.getElementById('meetingTime');
            const meetingLocation = document.getElementById('meetingLocation');
            if (referralInput) referralInput.value = scheduleButton.dataset.referralId || '';
            if (studentName) studentName.textContent = scheduleButton.dataset.student || 'Student';
            if (meetingDate) meetingDate.value = scheduleButton.dataset.date || '';
            if (meetingTime) meetingTime.value = scheduleButton.dataset.time || '';
            if (meetingLocation) meetingLocation.value = scheduleButton.dataset.location || '';
            openModal('scheduleReferralModal');
            return;
        }

        const statusButton = event.target.closest('.open-status-modal');
        if (statusButton) {
            const referralInput = document.getElementById('updateReferralId');
            const statusSelect = document.getElementById('referralStatus');
            const notes = document.getElementById('updateNotes');
            if (referralInput) referralInput.value = statusButton.dataset.referralId || '';
            if (statusSelect) statusSelect.value = statusButton.dataset.status || 'Pending';
            if (notes) notes.value = '';
            openModal('updateReferralModal');
            return;
        }

        const closeButton = event.target.closest('[data-close-modal]');
        if (closeButton) {
            closeModal(closeButton.closest('.referral-modal'));
            return;
        }

        const backdrop = event.target.closest('.referral-modal');
        if (backdrop && event.target === backdrop) {
            closeModal(backdrop);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.referral-modal.show').forEach(closeModal);
        }
    });
})();
</script>


<script>
(function () {
    const calendarSelector = '#referral-calendar';
    let requestInProgress = false;

    async function loadCalendar(url, updateHistory = true) {
        if (requestInProgress) return;

        const currentCalendar = document.querySelector(calendarSelector);
        if (!currentCalendar) return;

        requestInProgress = true;
        currentCalendar.classList.add('calendar-loading');

        try {
            const response = await fetch(url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error('Unable to load calendar.');
            }

            const html = await response.text();
            const parser = new DOMParser();
            const documentFromResponse = parser.parseFromString(
                html,
                'text/html'
            );

            const newCalendar = documentFromResponse.querySelector(
                calendarSelector
            );

            if (!newCalendar) {
                throw new Error('Calendar section not found.');
            }

            // Replace only the calendar, not the entire page.
            currentCalendar.replaceWith(newCalendar);

            if (updateHistory) {
                history.pushState(
                    { calendar: true },
                    '',
                    url
                );
            }

        } catch (error) {
            console.error('Calendar navigation error:', error);

            // Fallback if the AJAX request fails.
            window.location.href = url;

        } finally {
            requestInProgress = false;
            document.querySelector(calendarSelector)
                ?.classList.remove('calendar-loading');
        }
    }

    // Event delegation keeps working after the calendar is replaced.
    document.addEventListener('click', function (event) {
        const link = event.target.closest(
            '#referral-calendar .calendar-navigation a'
        );

        if (!link) return;

        // Allow normal browser behavior for new-tab or modified clicks.
        if (
            event.ctrlKey ||
            event.metaKey ||
            event.shiftKey ||
            event.altKey ||
            event.button !== 0
        ) {
            return;
        }

        event.preventDefault();
        loadCalendar(link.href);
    });

    // Support browser Back and Forward navigation.
    window.addEventListener('popstate', function () {
        loadCalendar(window.location.href, false);
    });
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    function setupSuccessModal(modalId, buttonId) {
        const modal = document.getElementById(modalId);
        const closeButton = document.getElementById(buttonId);

        if (!modal || !closeButton) {
            return;
        }

        function closeModal() {
            modal.remove();
        }

        closeButton.addEventListener('click', closeModal);

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.isConnected) {
                closeModal();
            }
        });
    }

    setupSuccessModal(
        'referralSuccessModal',
        'closeReferralSuccess'
    );

    setupSuccessModal(
        'appointmentSuccessModal',
        'closeAppointmentSuccess'
    );
    setupSuccessModal(
    'statusSuccessModal',
    'closeStatusSuccess'
);

});
</script>

<script>
(function () {
    'use strict';

    const successTitles = {
        create_referral: 'Referral Created Successfully!',
        schedule_referral: 'Appointment Scheduled Successfully!',
        update_status: 'Changes Saved Successfully!'
    };

    function showSuccessModal(action, message) {
        document.getElementById('ajaxSuccessModal')?.remove();

        const overlay = document.createElement('div');
        overlay.className = 'success-modal-overlay';
        overlay.id = 'ajaxSuccessModal';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'ajaxSuccessTitle');

        const card = document.createElement('div');
        card.className = 'success-modal-card';

        const icon = document.createElement('div');
        icon.className = 'success-modal-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '✓';

        const title = document.createElement('h2');
        title.id = 'ajaxSuccessTitle';
        title.textContent = successTitles[action] || 'Saved Successfully!';

        const description = document.createElement('p');
        description.textContent = message || 'Your changes have been saved successfully.';

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'success-modal-close';
        closeButton.textContent = 'Close';

        function closeModal() {
            overlay.remove();
            overlay.style.display = 'none';
            overlay.style.pointerEvents = 'none';
            document.body.classList.remove('referral-modal-open');
            document.body.style.overflow = '';
        }
        closeButton.addEventListener('click', closeModal);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) closeModal();
        });

        card.append(icon, title, description, closeButton);
        overlay.appendChild(card);
        document.body.appendChild(overlay);
        document.body.classList.add('referral-modal-open');
        closeButton.focus();
    }

    async function refreshPageSections() {
        const response = await fetch(window.location.href, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            cache: 'no-store'
        });

        if (!response.ok) {
            throw new Error('Unable to refresh referral records.');
        }

        const html = await response.text();
        const parser = new DOMParser();
        const newDocument = parser.parseFromString(html, 'text/html');

        const selectors = [
            '.referral-stats',
            '#referral-calendar',
            '#ready-assessments',
            '#referrals-list'
        ];

        selectors.forEach(function (selector) {
            const current = document.querySelector(selector);
            const updated = newDocument.querySelector(selector);

            if (current && updated) {
                current.replaceWith(updated);
            }
        });
    }

    document.addEventListener('submit', async function (event) {
        const form = event.target;

        if (!(form instanceof HTMLFormElement)) return;

        const actionInput = form.querySelector('input[name="action"]');
        const action = actionInput?.value;

        if (!Object.prototype.hasOwnProperty.call(successTitles, action)) {
            return;
        }

        event.preventDefault();

        const submitButton = form.querySelector('button[type="submit"]');

        if (form.dataset.submitting === 'true') return;

        form.dataset.submitting = 'true';

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.dataset.originalText = submitButton.textContent;
            submitButton.textContent = 'Saving...';
        }

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'Unable to save your changes.'
                );
            }

            document.querySelectorAll('.referral-modal.show').forEach(function (modal) {
                modal.classList.remove('show');
                modal.setAttribute('aria-hidden', 'true');
            });

            document.body.classList.remove('referral-modal-open');

            try {
                await refreshPageSections();
            } catch (refreshError) {
                console.error(refreshError);
            }

            showSuccessModal(result.action || action, result.message);

            form.reset();
        } catch (error) {
            window.alert(error.message || 'An unexpected error occurred.');
        } finally {
            delete form.dataset.submitting;

            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent =
                    submitButton.dataset.originalText || 'Save';
                delete submitButton.dataset.originalText;
            }
        }
    });
})();
</script>


<script>
(function () {
    'use strict';
    const modal = document.getElementById('appointmentDetailsModal');
    if (!modal) return;

    const fields = {
        student: document.getElementById('appointmentDetailStudent'),
        date: document.getElementById('appointmentDetailDate'),
        time: document.getElementById('appointmentDetailTime'),
        location: document.getElementById('appointmentDetailLocation'),
        status: document.getElementById('appointmentDetailStatus'),
        referral: document.getElementById('appointmentDetailReferral')
    };
    let activeReferralId = '';

    function formatDate(value) {
        if (!value) return '—';
        const parts = value.split('-').map(Number);
        if (parts.length !== 3 || parts.some(Number.isNaN)) return value;
        const date = new Date(parts[0], parts[1] - 1, parts[2]);
        return date.toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' });
    }

    function formatTime(value) {
        if (!value) return '—';
        const match = value.match(/^(\d{1,2}):(\d{2})/);
        if (!match) return value;
        const date = new Date();
        date.setHours(Number(match[1]), Number(match[2]), 0, 0);
        return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    }

    function closeDetails() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('appointment-details-open');
    }

    document.addEventListener('click', function (event) {
        const eventLink = event.target.closest('[data-calendar-event]');
        if (eventLink) {
            event.preventDefault();
            activeReferralId = eventLink.dataset.referralId || '';
            fields.student.textContent = eventLink.dataset.student || 'Student';
            fields.date.textContent = formatDate(eventLink.dataset.date || '');
            fields.time.textContent = formatTime(eventLink.dataset.time || '');
            fields.location.textContent = eventLink.dataset.location || 'Not specified';
            fields.status.textContent = eventLink.dataset.status || 'Pending';
            fields.referral.textContent = activeReferralId ? '#' + activeReferralId : '—';
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('appointment-details-open');
            modal.querySelector('[data-close-appointment]')?.focus();
            return;
        }

        if (event.target.closest('[data-close-appointment]')) {
            closeDetails();
            return;
        }

        if (event.target === modal) closeDetails();

        if (event.target.closest('#viewAppointmentReferral') && activeReferralId) {
            closeDetails();
            const row = document.getElementById('referral-' + activeReferralId);
            if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                row.classList.add('appointment-referral-highlight');
                window.setTimeout(() => row.classList.remove('appointment-referral-highlight'), 1800);
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('show')) closeDetails();
    });
})();
</script>

<!-- Logout Confirmation Modal -->
<style>
    .logout-overlay {
        position: fixed;
        inset: 0;
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(15, 35, 20, .58);
        backdrop-filter: blur(5px);
        opacity: 0;
        visibility: hidden;
        transition: opacity .25s ease, visibility .25s ease;
    }

    .logout-overlay.is-open {
        opacity: 1;
        visibility: visible;
    }

    .logout-dialog {
        width: 100%;
        max-width: 430px;
        padding: 32px 28px;
        border-radius: 20px;
        background: #fff;
        text-align: center;
        box-shadow: 0 24px 70px rgba(0, 0, 0, .22);
        transform: translateY(16px) scale(.96);
        transition: transform .25s ease;
    }

    .logout-overlay.is-open .logout-dialog {
        transform: translateY(0) scale(1);
    }

    .logout-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 64px;
        height: 64px;
        margin: 0 auto 20px;
        border-radius: 50%;
        background: #e8f3e7;
        color: #3f7840;
    }

    .logout-icon svg {
        width: 30px;
        height: 30px;
    }

    .logout-dialog h2 {
        margin: 0 0 12px;
        color: #243629;
        font-size: 21px;
        line-height: 1.4;
    }

    .logout-dialog p {
        margin: 0 0 25px;
        color: #68736b;
        font-size: 14px;
        line-height: 1.7;
    }

    .logout-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .logout-actions button {
        width: 100%;
        min-height: 46px;
        padding: 12px 18px;
        border: 0;
        border-radius: 10px;
        font: inherit;
        font-weight: 600;
        cursor: pointer;
        transition: background .2s ease, transform .2s ease;
    }

    .logout-confirm {
        background: #5e8b59;
        color: #fff;
    }

    .logout-confirm:hover {
        background: #497446;
    }

    .logout-cancel {
        background: #f0f3ef;
        color: #354438;
    }

    .logout-cancel:hover {
        background: #e3e9e1;
    }

    .logout-actions button:active {
        transform: scale(.98);
    }

    @media (max-width: 480px) {
        .logout-dialog {
            padding: 28px 20px;
        }

        .logout-dialog h2 {
            font-size: 19px;
        }
    }
</style>

<div class="logout-overlay" id="logout-modal" aria-hidden="true">
    <section class="logout-dialog"
             role="dialog"
             aria-modal="true"
             aria-labelledby="logout-title"
             aria-describedby="logout-description">

        <div class="logout-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"
                 fill="none"
                 stroke="currentColor"
                 stroke-width="1.8"
                 stroke-linecap="round"
                 stroke-linejoin="round">
                <path d="M10 17l5-5-5-5"></path>
                <path d="M15 12H3"></path>
                <path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"></path>
            </svg>
        </div>

        <h2 id="logout-title">Are you sure you want to log out?</h2>

        <p id="logout-description">
            You may lose any unsaved changes. Please make sure
            you've saved your work before leaving.
        </p>

        <div class="logout-actions">
            <button type="button" class="logout-confirm" id="confirm-logout">
                Yes, Log Out
            </button>

            <button type="button" class="logout-cancel" id="cancel-logout">
                Go Back
            </button>
        </div>
    </section>
</div>

<script>
(function () {
    const modal = document.getElementById('logout-modal');
    const cancelButton = document.getElementById('cancel-logout');
    const confirmButton = document.getElementById('confirm-logout');

    if (!modal || !cancelButton || !confirmButton) return;

    const logoutLinks = document.querySelectorAll(
        'a[href*="logout.php"]'
    );

    let previousFocus = null;

    function openModal(event) {
        event.preventDefault();
        previousFocus = document.activeElement;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        cancelButton.focus();
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';

        if (previousFocus) previousFocus.focus();
    }

    logoutLinks.forEach(function (link) {
        link.addEventListener('click', openModal);
    });

    cancelButton.addEventListener('click', closeModal);

    confirmButton.addEventListener('click', function () {
        window.location.href = '../auth/logout.php?portal=counselor';
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });
})();
</script>

</body>
</html>
