
<?php
require_once __DIR__ . '/../includes/counselor_session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/counselor/email-communication.php';

if (
    !isset($_SESSION['role_type']) ||
    $_SESSION['role_type'] !== 'counselor'
) {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";

function mon_table_exists($conn, $table) {
    $table = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    return $result && mysqli_num_rows($result) > 0;
}

function mon_column_exists($conn, $table, $column) {
    $table = str_replace('`', '', $table);
    $column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM `$table` LIKE '$column'"
    );
    return $result && mysqli_num_rows($result) > 0;
}

function mon_find_column($conn, $table, $candidates) {
    foreach ($candidates as $column) {
        if (mon_column_exists($conn, $table, $column)) {
            return $column;
        }
    }
    return null;
}

function mon_escape($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function mon_json_response($success, $message, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra));
    exit();
}

/* Detect existing database tables and columns. */
$referral_table = null;

foreach (['referral_data', 'referrals', 'referral', 'referral_records'] as $table) {
    if (mon_table_exists($conn, $table)) {
        $referral_table = $table;
        break;
    }
}

$assessment_table = null;

foreach (['assessment_data', 'assessments', 'assessment', 'assessment_submissions'] as $table) {
    if (mon_table_exists($conn, $table)) {
        $assessment_table = $table;
        break;
    }
}

$referral_id_col = $referral_table
    ? mon_find_column($conn, $referral_table, ['referral_id', 'id'])
    : null;

$referral_assessment_col = $referral_table
    ? mon_find_column($conn, $referral_table, ['assessment_id', 'submission_id'])
    : null;

$referral_status_col = $referral_table
    ? mon_find_column($conn, $referral_table, ['referral_status', 'status'])
    : null;

$referral_date_col = $referral_table
    ? mon_find_column($conn, $referral_table, ['referral_date', 'created_at', 'date_created'])
    : null;

$notes_col = $referral_table
    ? mon_find_column($conn, $referral_table, ['remarks', 'counselor_notes', 'notes'])
    : null;

$followup_col = $referral_table
    ? mon_find_column($conn, $referral_table, ['follow_up_date', 'followup_date', 'next_follow_up'])
    : null;

$assessment_id_col = $assessment_table
    ? mon_find_column($conn, $assessment_table, ['assessment_id', 'id', 'submission_id'])
    : null;

$assessment_user_col = $assessment_table
    ? mon_find_column($conn, $assessment_table, ['student_id'])
    : null;

$assessment_date_col = $assessment_table
    ? mon_find_column($conn, $assessment_table, ['assessment_date', 'submitted_at', 'created_at'])
    : null;

$user_id_col = mon_table_exists($conn, 'user_data')
    ? mon_find_column($conn, 'user_data', ['user_id', 'id'])
    : null;

$student_id_col = mon_table_exists($conn, 'student_profiles')
    ? mon_find_column($conn, 'student_profiles', ['student_id'])
    : null;

$referral_counselor_col = $referral_table
    ? mon_find_column($conn, $referral_table, ['counselor_id', 'assigned_counselor_id'])
    : null;

$schedule_table = mon_table_exists($conn, 'schedule_data') ? 'schedule_data' : null;
$schedule_id_col = $schedule_table
    ? mon_find_column($conn, $schedule_table, ['schedule_id', 'id'])
    : null;
$schedule_referral_col = $schedule_table
    ? mon_find_column($conn, $schedule_table, ['referral_id'])
    : null;
$schedule_date_col = $schedule_table
    ? mon_find_column($conn, $schedule_table, ['meeting_date', 'appointment_date'])
    : null;
$schedule_time_col = $schedule_table
    ? mon_find_column($conn, $schedule_table, ['meeting_time', 'appointment_time'])
    : null;
$schedule_location_col = $schedule_table
    ? mon_find_column($conn, $schedule_table, ['location', 'meeting_location'])
    : null;
$schedule_status_col = $schedule_table
    ? mon_find_column($conn, $schedule_table, ['status', 'schedule_status'])
    : null;

/* Keep appointment status in sync: overdue Pending meetings become Completed. */
if ($schedule_table && $schedule_date_col && $schedule_time_col && $schedule_status_col) {
    mysqli_query(
        $conn,
        "UPDATE `$schedule_table`
         SET `$schedule_status_col` = 'Completed'
         WHERE `$schedule_status_col` = 'Pending'
           AND CONCAT(`$schedule_date_col`, ' ', COALESCE(`$schedule_time_col`, '00:00:00')) < NOW()"
    );
}

/* Send a follow-up email using the shared PHPMailer configuration. */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'send_followup_email'
) {
    header('Content-Type: application/json; charset=utf-8');

    $referral_id_for_email = filter_input(INPUT_POST, 'referral_id', FILTER_VALIDATE_INT) ?: 0;
    $recipient = trim((string)($_POST['recipient_email'] ?? ''));
    $subject = trim((string)($_POST['email_subject'] ?? ''));
    $email_message = trim((string)($_POST['email_message'] ?? ''));

    if ($referral_id_for_email < 1 || !$referral_table || !$referral_id_col ||
        !$referral_assessment_col || !$assessment_table || !$assessment_id_col ||
        !$assessment_user_col || !$student_id_col) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unable to identify this monitoring case.']);
        exit();
    }

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || $subject === '' || $email_message === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid recipient email, subject, and message are required.']);
        exit();
    }

    if (strlen($subject) > 250 || strlen($email_message) > 15000) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Subject or message is too long.']);
        exit();
    }

    $verify_sql = "SELECT u.email AS student_email, u.fullname AS student_name, u.alias AS student_alias
                   FROM `$referral_table` r
                   INNER JOIN `$assessment_table` a
                       ON a.`$assessment_id_col` = r.`$referral_assessment_col`
                   INNER JOIN student_profiles u
                       ON u.`$student_id_col` = a.`$assessment_user_col`
                   WHERE r.`$referral_id_col` = ?
                   LIMIT 1";
    $verify_stmt = mysqli_prepare($conn, $verify_sql);
    if (!$verify_stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to verify the monitoring case.']);
        exit();
    }

    mysqli_stmt_bind_param($verify_stmt, 'i', $referral_id_for_email);
    mysqli_stmt_execute($verify_stmt);
    $verify_result = mysqli_stmt_get_result($verify_stmt);
    $verified_case = $verify_result ? mysqli_fetch_assoc($verify_result) : null;
    mysqli_stmt_close($verify_stmt);

    if (!$verified_case || !filter_var((string)($verified_case['student_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'No valid student email is available for this case.']);
        exit();
    }

    /* Prevent changing the recipient to an unrelated address. */
    if (strtolower($recipient) !== strtolower((string)$verified_case['student_email'])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'The recipient must match the student email on this monitoring record.']);
        exit();
    }

    require_once __DIR__ . '/../includes/counselor/email-communication.php';
    $recipient_alias = trim((string)($verified_case['student_alias'] ?? ''));
    if ($recipient_alias === '') $recipient_alias = 'Student';
    $counselor_name_for_email = ptcCounselorMailSenderName($conn);
    $email_content = ptcCounselorMailContent($recipient_alias, $email_message, $counselor_name_for_email);
    $send_result = sendSystemEmail(
        $recipient,
        $recipient_alias,
        $subject,
        $email_content['plain'],
        $email_content['html'],
        $email_content['logo']
    );

    if (!empty($send_result['success'])) {
        echo json_encode(['success' => true, 'message' => 'Follow-up email sent successfully to ' . $recipient . '.']);
        exit();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $send_result['message'] ?? 'Unable to send follow-up email. Check the email configuration.']);
    exit();
}

/* Handle AJAX save requests before rendering HTML. */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'save_monitoring_update'
) {
    $referral_id = filter_input(INPUT_POST, 'referral_id', FILTER_VALIDATE_INT) ?: 0;
    $status = trim($_POST['monitoring_status'] ?? '');
    $followup = trim($_POST['follow_up_date'] ?? '');
    $note = trim($_POST['note'] ?? '');

    /*
     * Match the ENUM currently shown in referral_data:
     * Pending, Accepted, Completed.
     */
    $allowed_statuses = ['Pending', 'Accepted', 'Completed'];

    if (!$referral_table || !$referral_id_col || $referral_id < 1) {
        mon_json_response(false, 'Unable to identify this referral record.');
    }

    if (!$referral_status_col || !in_array($status, $allowed_statuses, true)) {
        mon_json_response(false, 'Please select a valid referral status.');
    }

    if ($followup !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $followup);

        if (!$date || $date->format('Y-m-d') !== $followup) {
            mon_json_response(false, 'Please enter a valid follow-up date.');
        }
    }

    if (strlen($note) > 10000) {
        mon_json_response(false, 'Your monitoring note is too long.');
    }

    if ($followup !== '' && !$followup_col) {
        mon_json_response(false, 'The follow-up date column is missing.');
    }

    if ($note !== '' && !$notes_col) {
        mon_json_response(false, 'No notes or remarks column was found.');
    }

    $check_sql = "SELECT `$referral_id_col`
                  FROM `$referral_table`
                  WHERE `$referral_id_col` = ?
                  LIMIT 1";

    $check_stmt = mysqli_prepare($conn, $check_sql);

    if (!$check_stmt) {
        mon_json_response(false, 'Unable to check the referral record.');
    }

    mysqli_stmt_bind_param($check_stmt, 'i', $referral_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $record_exists = $check_result && mysqli_num_rows($check_result) > 0;
    mysqli_stmt_close($check_stmt);

    if (!$record_exists) {
        mon_json_response(false, 'Referral record not found. Please reload the page.');
    }

    $updates = [];
    $types = '';
    $values = [];

    $updates[] = "`$referral_status_col` = ?";
    $types .= 's';
    $values[] = $status;

    if ($followup_col) {
        $updates[] = "`$followup_col` = ?";
        $types .= 's';
        $values[] = ($followup !== '') ? $followup : null;
    }

    if ($notes_col && $note !== '') {
        $counselor_name = 'Counselor';
        $counselor_stmt = mysqli_prepare($conn, "SELECT fullname FROM user_data WHERE user_id = ? LIMIT 1");
        if ($counselor_stmt) {
            $session_counselor_id = (int)($_SESSION['user_id'] ?? 0);
            mysqli_stmt_bind_param($counselor_stmt, 'i', $session_counselor_id);
            mysqli_stmt_execute($counselor_stmt);
            $counselor_result = mysqli_stmt_get_result($counselor_stmt);
            if ($counselor_result && ($counselor_row = mysqli_fetch_assoc($counselor_result))) {
                $counselor_name = (string)$counselor_row['fullname'];
            }
            mysqli_stmt_close($counselor_stmt);
        }
        $timestamped_note = '[' . date('Y-m-d H:i:s') . ' | ' . $counselor_name . " | Monitoring Update]\n" . $note;

        $updates[] = "`$notes_col` =
            CASE
                WHEN COALESCE(`$notes_col`, '') = '' THEN ?
                ELSE CONCAT(`$notes_col`, '\n---\n', ?)
            END";

        $types .= 'ss';
        $values[] = $timestamped_note;
        $values[] = $timestamped_note;
    }

    $update_sql = "UPDATE `$referral_table`
                   SET " . implode(', ', $updates) . "
                   WHERE `$referral_id_col` = ?";

    $types .= 'i';
    $values[] = $referral_id;

    $stmt = mysqli_prepare($conn, $update_sql);

    if (!$stmt) {
        mon_json_response(false, 'Unable to prepare the monitoring update.');
    }

    mysqli_stmt_bind_param($stmt, $types, ...$values);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        mon_json_response(false, 'Unable to save the update. Check the database.');
    }

    mysqli_stmt_close($stmt);

    /*
     * Return the saved record's latest data so the table can update
     * without refreshing the entire page.
     */
    $fetch_sql = "SELECT
                    r.`$referral_status_col` AS referral_status" .
                    ($followup_col ? ", r.`$followup_col` AS follow_up_date" : ", NULL AS follow_up_date") .
                    ($notes_col ? ", r.`$notes_col` AS last_note" : ", '' AS last_note") . "
                  FROM `$referral_table` r
                  WHERE r.`$referral_id_col` = ?
                  LIMIT 1";

    $fetch_stmt = mysqli_prepare($conn, $fetch_sql);
    $saved = [];

    if ($fetch_stmt) {
        mysqli_stmt_bind_param($fetch_stmt, 'i', $referral_id);
        mysqli_stmt_execute($fetch_stmt);
        $fetch_result = mysqli_stmt_get_result($fetch_stmt);
        $saved = $fetch_result ? (mysqli_fetch_assoc($fetch_result) ?: []) : [];
        mysqli_stmt_close($fetch_stmt);
    }

    mon_json_response(true, 'Monitoring update saved successfully.', [
        'referral_id' => $referral_id,
        'status' => $saved['referral_status'] ?? $status,
        'follow_up_date' => $saved['follow_up_date'] ?? '',
        'last_note' => $saved['last_note'] ?? ''
    ]);
}

/* Load existing monitoring cases. */
$cases = [];
$error = '';

if (
    $referral_table &&
    $assessment_table &&
    $referral_id_col &&
    $referral_assessment_col &&
    $referral_status_col &&
    $assessment_id_col &&
    $assessment_user_col &&
    $student_id_col
) {
    $followup_select = $followup_col
        ? "r.`$followup_col` AS follow_up_date"
        : "NULL AS follow_up_date";

    $notes_select = $notes_col
        ? "r.`$notes_col` AS last_note"
        : "'' AS last_note";

    $refdate_select = $referral_date_col
        ? "r.`$referral_date_col` AS referral_created"
        : "NULL AS referral_created";

    $assessmentdate_select = $assessment_date_col
        ? "a.`$assessment_date_col` AS assessment_date"
        : "NULL AS assessment_date";

    $order_by = $followup_col
        ? "r.`$followup_col` ASC, r.`$referral_id_col` DESC"
        : (
            $referral_date_col
                ? "r.`$referral_date_col` DESC"
                : "r.`$referral_id_col` DESC"
        );

    $counselor_select = $referral_counselor_col
        ? "c.fullname AS assigned_counselor, c.email AS counselor_email"
        : "'Not assigned' AS assigned_counselor, '' AS counselor_email";

    $counselor_join = ($referral_counselor_col && $user_id_col)
        ? "LEFT JOIN user_data c ON c.`$user_id_col` = r.`$referral_counselor_col`"
        : "";

    $appointment_select = ",
        NULL AS meeting_date,
        NULL AS meeting_time,
        '' AS meeting_location,
        '' AS schedule_status";

    if (
        $schedule_table &&
        $schedule_id_col &&
        $schedule_referral_col &&
        $schedule_date_col
    ) {
        $appointment_select = ",
            (SELECT s.`$schedule_date_col`
             FROM `$schedule_table` s
             WHERE s.`$schedule_referral_col` = r.`$referral_id_col`
             ORDER BY s.`$schedule_id_col` DESC LIMIT 1) AS meeting_date";

        $appointment_select .= $schedule_time_col
            ? ", (SELECT s.`$schedule_time_col` FROM `$schedule_table` s
                 WHERE s.`$schedule_referral_col` = r.`$referral_id_col`
                 ORDER BY s.`$schedule_id_col` DESC LIMIT 1) AS meeting_time"
            : ", NULL AS meeting_time";

        $appointment_select .= $schedule_location_col
            ? ", (SELECT s.`$schedule_location_col` FROM `$schedule_table` s
                 WHERE s.`$schedule_referral_col` = r.`$referral_id_col`
                 ORDER BY s.`$schedule_id_col` DESC LIMIT 1) AS meeting_location"
            : ", '' AS meeting_location";

        $appointment_select .= $schedule_status_col
            ? ", (SELECT s.`$schedule_status_col` FROM `$schedule_table` s
                 WHERE s.`$schedule_referral_col` = r.`$referral_id_col`
                 ORDER BY s.`$schedule_id_col` DESC LIMIT 1) AS schedule_status"
            : ", '' AS schedule_status";
    }

    $sql = "SELECT
                r.`$referral_id_col` AS referral_id,
                r.`$referral_assessment_col` AS assessment_id,
                r.`$referral_status_col` AS referral_status,
                $followup_select,
                $notes_select,
                $refdate_select,
                $assessmentdate_select,
                u.fullname AS fullname,
                u.alias AS student_alias,
                u.email AS email,
                u.student_number AS student_number,
                $counselor_select
                $appointment_select
            FROM `$referral_table` r
            LEFT JOIN `$assessment_table` a
                ON a.`$assessment_id_col` = r.`$referral_assessment_col`
            LEFT JOIN student_profiles u
                ON u.`$student_id_col` = a.`$assessment_user_col`
            $counselor_join
            WHERE LOWER(COALESCE(r.`$referral_status_col`, 'pending'))
                NOT IN ('cancelled', 'rejected')
            ORDER BY $order_by";

    $result = mysqli_query($conn, $sql);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $cases[] = $row;
        }
    } else {
        $error = 'Unable to load monitoring records. Check the database relationships.';
    }
} else {
    $error = 'The referral, assessment, or student database relationship could not be identified.';
}

/* Include saved assessment-review counselor notes when the existing table has compatible columns. */
if (!empty($cases) && mon_table_exists($conn, 'counselor_notes_data')) {
    $review_note_assessment_col = mon_find_column($conn, 'counselor_notes_data', ['assessment_id', 'submission_id']);
    $review_note_text_col = mon_find_column($conn, 'counselor_notes_data', ['counselor_notes', 'note_content', 'notes', 'note', 'content', 'review_notes']);
    $review_note_date_col = mon_find_column($conn, 'counselor_notes_data', ['created_at', 'updated_at', 'note_date', 'created_on']);
    $review_note_author_col = mon_find_column($conn, 'counselor_notes_data', ['counselor_id', 'user_id', 'created_by']);
    if ($review_note_assessment_col && $review_note_text_col) {
        foreach ($cases as &$case_for_notes) {
            $case_assessment_id = (int)($case_for_notes['assessment_id'] ?? 0);
            if ($case_assessment_id < 1) continue;
            $select_review_note = "SELECT `$review_note_text_col` AS note_text";
            $select_review_note .= $review_note_date_col ? ", `$review_note_date_col` AS note_date" : ", NULL AS note_date";
            $select_review_note .= $review_note_author_col ? ", `$review_note_author_col` AS note_author" : ", NULL AS note_author";
            $review_note_sql = "$select_review_note FROM counselor_notes_data WHERE `$review_note_assessment_col` = ?";
            if ($review_note_date_col) $review_note_sql .= " ORDER BY `$review_note_date_col` ASC";
            $review_note_stmt = mysqli_prepare($conn, $review_note_sql);
            if (!$review_note_stmt) continue;
            mysqli_stmt_bind_param($review_note_stmt, 'i', $case_assessment_id);
            mysqli_stmt_execute($review_note_stmt);
            $review_note_result = mysqli_stmt_get_result($review_note_stmt);
            $assessment_note_chunks = [];
            while ($review_note_result && ($review_note_row = mysqli_fetch_assoc($review_note_result))) {
                $review_text = trim((string)($review_note_row['note_text'] ?? ''));
                if ($review_text === '') continue;
                $review_date = trim((string)($review_note_row['note_date'] ?? ''));
                $review_author = trim((string)($review_note_row['note_author'] ?? ''));
                $meta_parts = [];
                if ($review_date !== '') $meta_parts[] = $review_date;
                if ($review_author !== '') $meta_parts[] = 'Counselor ID ' . $review_author;
                $meta_parts[] = 'Assessment Review';
                $assessment_note_chunks[] = '[' . implode(' | ', $meta_parts) . "]\n" . $review_text;
            }
            mysqli_stmt_close($review_note_stmt);
            if ($assessment_note_chunks) {
                $existing_remarks = trim((string)($case_for_notes['last_note'] ?? ''));
                $case_for_notes['last_note'] = implode("\n---\n", $assessment_note_chunks)
                    . ($existing_remarks !== '' ? "\n---\n" . $existing_remarks : '');
            }
        }
        unset($case_for_notes);
    }
}

/* Calculate dashboard counts from database records. */
$today = date('Y-m-d');

$stats = [
    'total' => count($cases),
    'pending' => 0,
    'followup' => 0,
    'today' => 0
];

foreach ($cases as $case) {
    $status = strtolower(trim((string)($case['referral_status'] ?? 'pending')));

    if (in_array($status, ['pending', 'accepted'], true)) {
        $stats['pending']++;
    }

    if ($status === 'pending') {
        $stats['followup']++;
    }

    $date = $case['follow_up_date'] ?? '';

    if ($date && strtotime($date) && date('Y-m-d', strtotime($date)) === $today) {
        $stats['today']++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring | PTC Wellness</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/counselor/counselor-base.css">
    <link rel="stylesheet" href="../assets/css/counselor/counselor-layout.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/counselor/counselor-layout.css') ?>">
    <link rel="stylesheet" href="../assets/css/counselor/monitoring.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/counselor/monitoring.css') ?>">
    <link rel="stylesheet" href="../assets/css/counselor/monitoring-mobile.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/counselor/monitoring-mobile.css') ?>" media="(max-width: 900px)">
    <link rel="stylesheet" href="../assets/css/counselor/email-sending.css?v=<?= (int) (@filemtime(__DIR__ . '/../assets/css/counselor/email-sending.css') ?: 0) ?>">

    
</head>

<body class="counselor-page monitoring-page">
<?php include __DIR__ . "/../includes/counselor/sidebar.php"; ?>
<div class="counselor-workspace">
<?php include __DIR__ . "/../includes/counselor/topbar.php"; ?>
<main class="main-content monitoring-content">
        <header class="page-header monitoring-header">
            <div class="page-heading">
                <p class="eyebrow monitoring-eyebrow">PTC WELLNESS · GUIDANCE OFFICE</p>
                <h1 class="page-title monitoring-title">Monitoring</h1>
                <p class="page-subtitle monitoring-subtitle">Track referral follow-ups and document ongoing student support.</p>
            </div>
            <div class="header-right">
                <div class="datetime-card" aria-label="Current date and time in Manila">
                    <span class="datetime-item"><span class="datetime-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span><span id="monitoring-current-date">Loading date...</span></span>
                    <span class="datetime-divider" aria-hidden="true"></span>
                    <span class="datetime-item"><span class="datetime-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><span id="monitoring-current-time">Loading time...</span></span>
                </div>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <div class="monitoring-alert error"><?= mon_escape($error) ?></div>
        <?php endif; ?>

        <?php if ($referral_table && !$followup_col): ?>
            <div class="monitoring-alert info">
                Follow-up dates need the <code>follow_up_date</code> column in
                <code>referral_data</code>. Run the ALTER TABLE query provided above.
            </div>
        <?php endif; ?>

        <section class="monitoring-stats">
            <article class="monitoring-stat">
                <span class="monitoring-stat-label">Total Monitored</span>
                <strong id="statTotal"><?= (int)$stats['total'] ?></strong>
                <small>Referral cases in monitoring</small>
            </article>

            <article class="monitoring-stat">
                <span class="monitoring-stat-label">Pending Follow-ups</span>
                <strong id="statPending"><?= (int)$stats['pending'] ?></strong>
                <small>Cases awaiting next steps</small>
            </article>

            <article class="monitoring-stat">
                <span class="monitoring-stat-label">Needs Follow-up</span>
                <strong id="statNeeds"><?= (int)$stats['followup'] ?></strong>
                <small>Cases with pending status</small>
            </article>

            <article class="monitoring-stat">
                <span class="monitoring-stat-label">Follow-ups Today</span>
                <strong id="statToday"><?= (int)$stats['today'] ?></strong>
                <small>Scheduled for today</small>
            </article>
        </section>

        <section class="monitoring-panel">
            <div class="monitoring-panel-heading">
                <div>
                    <h2>Monitoring Overview</h2>
                    <p>Review each case and update its follow-up plan.</p>
                </div>
                <span class="monitoring-record-count" id="recordCount">
                    <?= count($cases) ?> records
                </span>
            </div>

            <div class="monitoring-table-wrap">
                <table class="monitoring-table">
                    <thead>
                    <tr>
                        <th>Student</th>
                        <th>Assessment</th>
                        <th>Assigned Counselor</th>
                        <th>Referral Status</th>
                        <th>Appointment</th>
                        <th>Follow-up Date</th>
                        <th>Counselor Notes</th>
                        <th>Actions</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($cases)): ?>
                        <tr>
                            <td colspan="8" class="monitoring-empty">
                                <strong>No monitoring records yet</strong>
                                <span>Cases with referral records will appear here.</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cases as $case): ?>
                            <?php
                            $student_name = trim((string)($case['fullname'] ?? '')) ?: 'Student';
                            $student_email = trim((string)($case['email'] ?? ''));
                            $case_status = trim((string)($case['referral_status'] ?? 'Pending')) ?: 'Pending';
                            $followup_date = $case['follow_up_date'] ?? '';
                            $date_value = ($followup_date && strtotime($followup_date))
                                ? date('Y-m-d', strtotime($followup_date))
                                : '';
                            $last_note = trim((string)($case['last_note'] ?? ''));
                            $has_notes = $last_note !== '';
                            $badge = strtolower($case_status);
                            $badge = in_array($badge, ['pending'], true) ? 'warning' : 'success';
                            ?>
                            <tr
                                data-row-referral="<?= (int)$case['referral_id'] ?>"
                                data-status-value="<?= mon_escape($case_status) ?>"
                                data-followup-value="<?= mon_escape($date_value) ?>"
                            >
                                <td data-label="Student">
                                    <strong class="monitoring-student-name">
                                        <?= mon_escape($student_name) ?>
                                    </strong>
                                    <small class="monitoring-student-email">
                                        <?= mon_escape($student_email ?: ($case['student_number'] ?? '')) ?>
                                    </small>
                                </td>
                                <td data-label="Assessment">
                                    <a
                                        class="monitoring-assessment-link"
                                        href="view_assessment.php?id=<?= (int)$case['assessment_id'] ?>"
                                    >
                                        Assessment #<?= (int)$case['assessment_id'] ?>
                                    </a>
                                </td>
                                <td data-label="Assigned Counselor">
                                    <strong class="monitoring-counselor-name">
                                        <?= mon_escape(trim((string)($case['assigned_counselor'] ?? '')) ?: 'Not assigned') ?>
                                    </strong>
                                    <?php if (!empty($case['counselor_email'])): ?>
                                        <small class="monitoring-student-email"><?= mon_escape($case['counselor_email']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Referral Status">
                                    <span class="monitoring-badge badge-<?= $badge ?>">
                                        <?= mon_escape($case_status) ?>
                                    </span>
                                </td>
                                <td data-label="Appointment" class="monitoring-appointment-cell">
                                    <?php
                                    $meeting_date = trim((string)($case['meeting_date'] ?? ''));
                                    $meeting_time = trim((string)($case['meeting_time'] ?? ''));
                                    $schedule_status = trim((string)($case['schedule_status'] ?? ''));
                                    $meeting_timestamp = $meeting_date !== '' ? strtotime($meeting_date) : false;
                                    $time_timestamp = $meeting_time !== '' ? strtotime($meeting_time) : false;
                                    $schedule_status_style = strtolower($schedule_status) === 'pending' ? ' is-pending' : '';
                                    ?>
                                    <?php if ($meeting_timestamp !== false): ?>
                                        <?php if ($schedule_status !== ''): ?>
                                            <span class="monitoring-schedule-status<?= $schedule_status_style ?>"><?= mon_escape($schedule_status) ?></span>
                                        <?php endif; ?>
                                        <time class="monitoring-appointment-datetime" datetime="<?= mon_escape(date('Y-m-d', $meeting_timestamp) . ($time_timestamp !== false ? 'T' . date('H:i', $time_timestamp) : '')) ?>">
                                            <?= mon_escape(date('M d, Y', $meeting_timestamp)) ?><?php if ($time_timestamp !== false): ?> · <?= mon_escape(date('h:i A', $time_timestamp)) ?><?php endif; ?>
                                        </time>
                                    <?php else: ?>
                                        <span class="monitoring-muted">Not scheduled</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Follow-up Date" class="monitoring-followup-cell">
                                    <?= mon_escape(
                                        $date_value ? date('M d, Y', strtotime($date_value)) : 'Not set'
                                    ) ?>
                                </td>
                                <td data-label="Counselor Notes" class="monitoring-note-cell">
                                    <button type="button"
                                        class="monitoring-button secondary open-notes-modal"
                                        data-referral-id="<?= (int)$case['referral_id'] ?>"
                                        data-student="<?= mon_escape($student_name) ?>"
                                        data-notes="<?= mon_escape($last_note) ?>"
                                    >View Notes</button>
                                    <span class="monitoring-note-count"><?= $has_notes ? 'Notes available' : 'No notes yet' ?></span>
                                </td>
                                <td data-label="Actions">
                                    <div class="monitoring-action-group">
                                        <button
                                            type="button"
                                            class="monitoring-button open-monitoring-modal"
                                            data-referral-id="<?= (int)$case['referral_id'] ?>"
                                            data-student="<?= mon_escape($student_name) ?>"
                                            data-status="<?= mon_escape($case_status) ?>"
                                            data-followup="<?= mon_escape($date_value) ?>"
                                        >Update</button>
                                        <?php if (filter_var($student_email, FILTER_VALIDATE_EMAIL)): ?>
                                        <button
                                            type="button"
                                            class="monitoring-button open-followup-email"
                                            data-referral-id="<?= (int)$case['referral_id'] ?>"
                                            data-student="<?= mon_escape($student_name) ?>"
                                            data-student-alias="<?= mon_escape(trim((string)($case['student_alias'] ?? '')) ?: 'Student') ?>"
                                            data-email="<?= mon_escape($student_email) ?>"
                                            data-followup="<?= mon_escape($date_value) ?>"
                                        >Send Follow-up Email</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        </main>
<?php include __DIR__ . "/../includes/counselor/footer.php"; ?>
</div>

<!-- Counselor Notes History Modal -->
<div class="monitoring-modal" id="counselorNotesModal" aria-hidden="true">
    <div class="monitoring-modal-card notes-history-card" role="dialog" aria-modal="true" aria-labelledby="counselorNotesTitle">
        <div class="monitoring-modal-heading">
            <div>
                <p class="monitoring-eyebrow">CASE HISTORY</p>
                <h2 id="counselorNotesTitle">Counselor Notes</h2>
                <p id="notesModalStudent" class="modal-student-name"></p>
            </div>
            <button type="button" class="monitoring-modal-close" id="closeCounselorNotesModal" aria-label="Close">×</button>
        </div>
        <div id="counselorNotesList" class="counselor-notes-list"></div>
        <div class="monitoring-form-actions">
            <button type="button" class="monitoring-button" id="doneCounselorNotesModal">Close</button>
        </div>
    </div>
</div>

<!-- Update Monitoring Modal -->
<div class="monitoring-modal" id="monitoringModal" aria-hidden="true">
    <div
        class="monitoring-modal-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="monitoringModalTitle"
    >
        <div class="monitoring-modal-heading">
            <div>
                <p class="monitoring-eyebrow">CASE UPDATE</p>
                <h2 id="monitoringModalTitle">Update Monitoring Case</h2>
                <p id="modalStudentName" class="modal-student-name"></p>
            </div>
            <button
                type="button"
                class="monitoring-modal-close"
                id="closeMonitoringModal"
                aria-label="Close"
            >×</button>
        </div>

        <form method="POST" action="monitoring.php" class="monitoring-form" id="monitoringForm">
            <input type="hidden" name="action" value="save_monitoring_update">
            <input type="hidden" name="referral_id" id="modalReferralId">

            <div class="monitoring-form-grid">
                <div class="monitoring-field">
                    <label for="modalStatus">Referral Status</label>
                    <select id="modalStatus" name="monitoring_status" required>
                        <option value="Pending">Pending</option>
                        <option value="Accepted">Accepted</option>
                        <option value="Completed">Completed</option>
                    </select>
                </div>

                <div class="monitoring-field">
                    <label for="modalFollowup">Follow-up Date</label>
                    <input type="date" id="modalFollowup" name="follow_up_date">
                </div>

                <div class="monitoring-field full">
                    <label for="modalNote">Monitoring Note</label>
                    <textarea
                        id="modalNote"
                        name="note"
                        maxlength="10000"
                        placeholder="Add a progress update or follow-up note..."
                    ></textarea>
                    <small>
                        New notes are appended to the existing remarks.
                        Leave blank if you have no new note.
                    </small>
                </div>
            </div>

            <div class="monitoring-form-actions">
                <button
                    type="button"
                    class="monitoring-button secondary"
                    id="cancelMonitoringModal"
                >Cancel</button>
                <button type="submit" class="monitoring-button" id="saveMonitoringButton">
                    Save Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Save Confirmation Modal -->
<div id="monitoringToast" class="monitoring-toast-overlay" hidden>
    <div class="monitoring-toast-card" role="dialog" aria-modal="true" aria-labelledby="toastTitle">
        <div class="monitoring-toast-icon" id="toastIcon">✓</div>
        <h2 id="toastTitle">Update Saved</h2>
        <p id="toastMessage">Monitoring update saved successfully.</p>
        <button type="button" class="monitoring-toast-button" id="toastDone">Done</button>
    </div>
</div>

<!-- Follow-up Email Modal -->
<div class="followup-email-overlay" id="followupEmailModal" aria-hidden="true">
    <div class="followup-email-card" role="dialog" aria-modal="true" aria-labelledby="followupEmailTitle">
        <h2 id="followupEmailTitle">Send Follow-up Email</h2>
        <p id="followupEmailStudent"></p>
        <form id="followupEmailForm" method="POST">
            <input type="hidden" name="action" value="send_followup_email">
            <input type="hidden" name="referral_id" id="followupEmailReferralId">
            <div class="followup-email-field">
                <label for="followupEmailRecipient">Student Email</label>
                <input type="email" name="recipient_email" id="followupEmailRecipient" required readonly maxlength="254">
            </div>
            <div class="followup-email-field">
                <label for="followupEmailTemplate">Message Template</label>
                <select id="followupEmailTemplate">
                    <option value="general">General Follow-up</option>
                    <option value="appointment_invitation">Appointment Invitation</option>
                    <option value="appointment_reminder">Appointment Reminder</option>
                    <option value="additional_information">Additional Information</option>
                    <option value="student_support">Student Support</option>
                    <option value="custom">Custom Message</option>
                </select>
                <small class="followup-template-help">Choose a message for the student. The greeting uses their alias and the signature uses your counselor name automatically. Edit only the message body.</small>
            </div>
            <div class="followup-email-field">
                <label for="followupEmailSubject">Subject</label>
                <input type="text" name="email_subject" id="followupEmailSubject" required maxlength="250">
            </div>
            <div class="followup-email-field">
                <label for="followupEmailMessage">Message</label>
                <textarea name="email_message" id="followupEmailMessage" required maxlength="15000" placeholder="Write follow-up details or appointment information..."></textarea>
            </div>
            <div id="followupEmailFeedback" role="status" aria-live="polite"></div>
            <div class="followup-email-actions">
                <button type="button" class="secondary" id="cancelFollowupEmail">Cancel</button>
                <button type="submit" id="submitFollowupEmail">Send Email</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const modal = document.getElementById('monitoringModal');
    const form = document.getElementById('monitoringForm');
    const closeButton = document.getElementById('closeMonitoringModal');
    const cancelButton = document.getElementById('cancelMonitoringModal');
    const saveButton = document.getElementById('saveMonitoringButton');

    const referralId = document.getElementById('modalReferralId');
    const status = document.getElementById('modalStatus');
    const followup = document.getElementById('modalFollowup');
    const note = document.getElementById('modalNote');
    const studentName = document.getElementById('modalStudentName');
    const notesModal = document.getElementById('counselorNotesModal');
    const notesModalStudent = document.getElementById('notesModalStudent');
    const notesList = document.getElementById('counselorNotesList');

    const toast = document.getElementById('monitoringToast');
    const toastIcon = document.getElementById('toastIcon');
    const toastTitle = document.getElementById('toastTitle');
    const toastMessage = document.getElementById('toastMessage');
    const toastDone = document.getElementById('toastDone');

    let previousFocus = null;

    function openModal(button) {
        previousFocus = button;

        referralId.value = button.dataset.referralId || '';
        status.value = button.dataset.status || 'Pending';
        followup.value = button.dataset.followup || '';
        note.value = '';
        studentName.textContent = button.dataset.student || '';

        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');

        status.focus();
    }

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');

        if (previousFocus && document.contains(previousFocus)) {
            previousFocus.focus();
        }
    }

    function showToast(success, title, message) {
        toastIcon.textContent = success ? '✓' : '!';
        toastTitle.textContent = title;
        toastMessage.textContent = message;
        toast.hidden = false;
        toast.style.display = 'flex';
        toast.style.pointerEvents = 'auto';
        toastDone.focus();
    }

    function closeToast() {
        toast.hidden = true;
    }

    function escapeHTML(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function formatDate(value) {
        if (!value) return 'Not set';

        const parts = value.split('-');
        if (parts.length !== 3) return 'Not set';

        const date = new Date(
            Number(parts[0]),
            Number(parts[1]) - 1,
            Number(parts[2])
        );

        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: '2-digit',
            year: 'numeric'
        });
    }

    function updateStatistics() {
        const rows = Array.from(
            document.querySelectorAll('tr[data-row-referral]')
        );

        let pending = 0;
        let needsFollowup = 0;
        let today = 0;
        const todayString = [
            new Date().getFullYear(),
            String(new Date().getMonth() + 1).padStart(2, '0'),
            String(new Date().getDate()).padStart(2, '0')
        ].join('-');

        rows.forEach(function (row) {
            const currentStatus = (row.dataset.statusValue || '').toLowerCase();
            const currentDate = row.dataset.followupValue || '';

            if (currentStatus === 'pending' || currentStatus === 'accepted') {
                pending++;
            }

            if (currentStatus === 'pending') {
                needsFollowup++;
            }

            if (currentDate === todayString) {
                today++;
            }
        });

        document.getElementById('statTotal').textContent = rows.length;
        document.getElementById('statPending').textContent = pending;
        document.getElementById('statNeeds').textContent = needsFollowup;
        document.getElementById('statToday').textContent = today;
        document.getElementById('recordCount').textContent = rows.length + ' records';
    }

    function updateRow(data) {
        const row = document.querySelector(
            'tr[data-row-referral="' + Number(data.referral_id) + '"]'
        );

        if (!row) return;

        const newStatus = data.status || 'Pending';
        const newDate = data.follow_up_date || '';
        const newNote = data.last_note || '';
        const badgeClass = newStatus.toLowerCase() === 'pending'
            ? 'warning'
            : 'success';

        row.dataset.statusValue = newStatus;
        row.dataset.followupValue = newDate;

        const badge = row.querySelector('.monitoring-badge');
        badge.className = 'monitoring-badge badge-' + badgeClass;
        badge.textContent = newStatus;

        row.querySelector('.monitoring-followup-cell').textContent = formatDate(newDate);

        // Preserve the View Notes button. Replacing the whole cell text
        // destroyed the button and broke subsequent notes interactions.
        const noteCell = row.querySelector('.monitoring-note-cell');
        if (noteCell) noteCell.title = newNote;

        const notesButton = row.querySelector('.open-notes-modal');
        if (notesButton) {
            notesButton.dataset.notes = newNote;
            const countLabel = notesButton.parentElement.querySelector('.monitoring-note-count');
            if (countLabel) countLabel.textContent = newNote ? 'Notes available' : 'No notes yet';
        }

        const button = row.querySelector('.open-monitoring-modal');
        button.dataset.status = newStatus;
        button.dataset.followup = newDate;

        row.classList.remove('monitoring-row-updated');
        void row.offsetWidth;
        row.classList.add('monitoring-row-updated');

        updateStatistics();
    }

    function openNotesModal(button) {
        notesModalStudent.textContent = button.dataset.student || 'Student';
        notesList.replaceChildren();
        const rawNotes = button.dataset.notes || '';
        const chunks = rawNotes.split(/\n---\n/).map(part => part.trim()).filter(Boolean);
        if (!chunks.length) {
            const empty = document.createElement('div');
            empty.className = 'counselor-notes-empty';
            empty.textContent = 'No counselor notes have been recorded for this case yet.';
            notesList.appendChild(empty);
        } else {
            chunks.slice().reverse().forEach(chunk => {
                const entry = document.createElement('article');
                entry.className = 'counselor-note-entry';
                const lines = chunk.split('\n');
                const meta = document.createElement('div');
                meta.className = 'counselor-note-meta';
                let body = chunk;
                const firstLine = lines[0] || '';
                const monitoringMatch = firstLine.match(/^\[(.*?)\]$/);
                const referralMatch = chunk.match(/\(Updated: ([^)]+)\)\s*$/);
                if (monitoringMatch) {
                    meta.textContent = monitoringMatch[1];
                    body = lines.slice(1).join('\n').trim();
                } else if (referralMatch) {
                    meta.textContent = 'Status update · ' + referralMatch[1];
                    body = chunk.replace(/\s*\(Updated: [^)]+\)\s*$/, '').trim();
                } else {
                    meta.textContent = 'Initial referral / counselor note · Date not recorded';
                }
                const content = document.createElement('div');
                content.className = 'counselor-note-body';
                content.textContent = body || '(No note text)';
                entry.append(meta, content);
                notesList.appendChild(entry);
            });
        }
        notesModal.classList.add('show');
        notesModal.setAttribute('aria-hidden', 'false');
    }

    function closeNotesModal() {
        notesModal.classList.remove('show');
        notesModal.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('click', function (event) {
        const notesButton = event.target.closest('.open-notes-modal');
        if (notesButton) { openNotesModal(notesButton); return; }
        const button = event.target.closest('.open-monitoring-modal');
        if (button) openModal(button);
    });
    document.getElementById('closeCounselorNotesModal').addEventListener('click', closeNotesModal);
    document.getElementById('doneCounselorNotesModal').addEventListener('click', closeNotesModal);
    notesModal.addEventListener('click', function(event) { if (event.target === notesModal) closeNotesModal(); });

    closeButton.addEventListener('click', closeModal);
    cancelButton.addEventListener('click', closeModal);

    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });

function closeToast() {
    toast.hidden = true;
    toast.style.display = 'none';
    toast.style.pointerEvents = 'none';
}

    toastDone.addEventListener('click', closeToast);

    toast.addEventListener('click', function (event) {
        if (event.target === toast) closeToast();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (!toast.hidden) {
                closeToast();
            } else if (notesModal.classList.contains('show')) {
                closeNotesModal();
            } else if (modal.classList.contains('show')) {
                closeModal();
            }
        }
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        if (saveButton.disabled) return;

        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';

        try {
            const response = await fetch('monitoring.php', {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            const raw = await response.text();
            let result;

            try {
                result = JSON.parse(raw);
            } catch (parseError) {
                console.error('Unexpected server response:', raw);
                throw new Error(
                    'The server returned an unexpected response. Check the PHP error log.'
                );
            }

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Unable to save the monitoring update.');
            }

            updateRow(result);
            closeModal();

            showToast(
                true,
                'Update Saved Successfully',
                result.message || 'The monitoring update has been saved.'
            );
        } catch (error) {
            showToast(
                false,
                'Unable to Save Update',
                error.message || 'An unexpected error occurred. Please try again.'
            );
        } finally {
            saveButton.disabled = false;
            saveButton.textContent = 'Save Update';
        }
    });
})();
</script>

<script src="../assets/js/counselor/email-sending.js?v=<?= (int) (@filemtime(__DIR__ . '/../assets/js/counselor/email-sending.js') ?: 0) ?>"></script>
<script>
(function () {
    const modal = document.getElementById('followupEmailModal');
    const form = document.getElementById('followupEmailForm');
    if (!modal || !form) return;

    const referralId = document.getElementById('followupEmailReferralId');
    const recipient = document.getElementById('followupEmailRecipient');
    const subject = document.getElementById('followupEmailSubject');
    const message = document.getElementById('followupEmailMessage');
    const template = document.getElementById('followupEmailTemplate');
    const studentLabel = document.getElementById('followupEmailStudent');
    const feedback = document.getElementById('followupEmailFeedback');
    const submit = document.getElementById('submitFollowupEmail');

    const emailUI = window.PTCEmailSending?.attach({form, message, feedback, submit, student: 'Student', counselor: <?= json_encode(ptcCounselorMailSenderName($conn) ?? 'Counselor', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>});
    let sending = false;
    let closeAfterSuccess = null;

    // Body-only templates; PHP adds Dear <student alias> and the counselor signature.
    const templates = {
        general: {
            subject: 'A Message from PTC Wellness Guidance Office',
            message: 'I hope you are doing well. I am writing to check in and let you know that the Guidance Office is available should you wish to share an update or seek support.\n\nIf you would like to arrange a conversation, you may reply to this email at a time convenient for you. Please avoid including sensitive personal details in your reply.\n\nThank you for your time.'
        },
        appointment_invitation: {
            subject: 'Invitation to Arrange a Guidance Appointment | PTC Wellness',
            message: 'We would like to invite you to arrange a private conversation with a guidance counselor. This is an opportunity to ask questions, discuss your concerns, and explore available support.\n\nIf you would like to schedule a meeting, please reply with your preferred days and times. We will confirm the schedule with you.\n\nThank you, and we look forward to hearing from you.'
        },
        appointment_reminder: {
            subject: 'Guidance Appointment Reminder | PTC Wellness',
            message: 'This is a reminder regarding your guidance appointment. Please refer to your confirmed appointment details for the correct schedule.\n\nIf you would like to confirm or reschedule, please reply to this email or contact the Guidance Office.\n\nThank you for your attention.'
        },
        additional_information: {
            subject: 'Request for a Brief Follow-up | PTC Wellness',
            message: 'We would appreciate an opportunity to clarify a few details so that we can assist you appropriately.\n\nYou do not need to send private or sensitive information by email. You may instead request a confidential conversation with a counselor by replying to this message.\n\nThank you for your cooperation.'
        },
        student_support: {
            subject: 'Guidance and Student Support | PTC Wellness',
            message: 'The PTC Wellness Guidance Office is available to provide guidance and support to students. If you would like to speak with a counselor or discuss the next steps, you are welcome to contact us.\n\nYou may reply to arrange a private conversation, and you may share only what you feel comfortable discussing by email.\n\nWe appreciate your time.'
        },
        custom: { subject: '', message: '' }
    };
    function applyTemplate() {
        const selected = templates[template.value] || templates.general;
        subject.value = selected.subject;
        message.value = selected.message;
        if (template.value === 'custom') subject.focus();
        else message.focus();
    }

    template.addEventListener('change', applyTemplate);

    function closeModal() {
        if (sending) return;
        if (closeAfterSuccess !== null) window.clearTimeout(closeAfterSuccess);
        closeAfterSuccess = null;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        emailUI?.reset();
    }

    document.addEventListener('click', function (event) {
        const button = event.target.closest('.open-followup-email');
        if (!button) return;

        referralId.value = button.dataset.referralId || '';
        recipient.value = button.dataset.email || '';
        const currentStudent = button.dataset.studentAlias || 'Student';
        studentLabel.textContent = 'Student: ' + (button.dataset.student || currentStudent);
        emailUI?.setNames(currentStudent, <?= json_encode(ptcCounselorMailSenderName($conn), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
        emailUI?.reset();
        template.value = 'general';
        applyTemplate();

        feedback.textContent = '';
        feedback.style.color = '';
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        subject.focus();
    });

    document.getElementById('cancelFollowupEmail').addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (sending || submit.disabled) return;
        sending = true;
        submit.disabled = true;
        submit.textContent = 'Sending…';
        const payload = new FormData(form);
        feedback.textContent = '';
        emailUI?.start();
        try {
            const response = await fetch('monitoring.php', {
                method: 'POST',
                body: payload,
                headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
                credentials: 'same-origin'
            });
            const raw = await response.text();
            let result;
            try { result = JSON.parse(raw); }
            catch (e) { throw new Error('The server returned an unexpected response. Sending could not be confirmed.'); }
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to confirm the email was sent.');
            emailUI?.succeed();
            closeAfterSuccess = window.setTimeout(closeModal, 2600);
        } catch (error) {
            emailUI?.fail(error.message);
        } finally {
            sending = false;
            submit.disabled = false;
            submit.textContent = 'Send Email';
        }
    });
})();
</script>

<!-- Logout Confirmation Modal -->


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


<script>
(function () {
  const dateEl=document.getElementById('monitoring-current-date');
  const timeEl=document.getElementById('monitoring-current-time');
  if(!dateEl||!timeEl)return;
  const dateFmt=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',weekday:'long',month:'short',day:'numeric',year:'numeric'});
  const timeFmt=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',hour12:true});
  const update=()=>{const now=new Date();dateEl.textContent=dateFmt.format(now);timeEl.textContent=timeFmt.format(now);};
  update();window.setInterval(update,1000);
})();
</script>
<script src="../assets/js/counselor/counselor-layout.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/counselor/counselor-layout.js') ?>" defer></script>
</body>
</html>
