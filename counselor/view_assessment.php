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

function va_table_exists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
    return $result && mysqli_num_rows($result) > 0;
}

function va_column_exists($conn, $table, $column)
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    $column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"
    );

    return $result && mysqli_num_rows($result) > 0;
}

function va_first_column($conn, $table, $candidates)
{
    foreach ($candidates as $column) {
        if (va_column_exists($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function va_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* Keep only basic formatting tags in saved counselor notes. */
function va_sanitize_notes($value)
{
    $value = (string)$value;
    $allowed = ['b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'p', 'br'];
    $value = strip_tags($value, '<' . implode('><', $allowed) . '>');
    return preg_replace_callback(
        '/<\s*(\/?)\s*(b|strong|i|em|u|ul|ol|li|p|br)\b[^>]*>/i',
        function ($matches) {
            $tag = strtolower($matches[2]);
            if ($tag === 'br') return '<br>';
            return !empty($matches[1]) ? '</' . $tag . '>' : '<' . $tag . '>';
        },
        $value
    );
}

function va_status($status)
{
    return strtolower(trim((string)$status)) === 'reviewed'
        ? 'reviewed'
        : 'pending';
}

function va_response_score($numeric, $text)
{
    $text = trim((string)$text);

    if (
        $numeric !== null &&
        $numeric !== '' &&
        is_numeric($numeric) &&
        (float)$numeric >= 1 &&
        (float)$numeric <= 5 &&
        floor((float)$numeric) == (float)$numeric
    ) {
        return (int)$numeric;
    }

    if (preg_match('/^[1-5]$/', $text)) {
        return (int)$text;
    }

    $labels = [
        'strongly disagree' => 1,
        'disagree' => 2,
        'neutral' => 3,
        'neither agree nor disagree' => 3,
        'agree' => 4,
        'strongly agree' => 5
    ];

    return $labels[strtolower($text)] ?? null;
}

function va_calculate_priority($responses)
{
    $scores = [];

    foreach ($responses as $response) {
        $score = va_response_score(
            $response['answer_score'] ?? null,
            $response['answer_text'] ?? ''
        );

        if ($score !== null) {
            $scores[] = $score;
        }
    }

    if (!$scores) {
        return [
            'priority' => null,
            'average' => null,
            'count' => 0
        ];
    }

    $average = array_sum($scores) / count($scores);

    if ($average >= 4.00) {
        $priority = 'High';
    } elseif ($average >= 3.00) {
        $priority = 'Moderate';
    } else {
        $priority = 'Low';
    }

    return [
        'priority' => $priority,
        'average' => $average,
        'count' => count($scores)
    ];
}

function va_priority_class($priority)
{
    switch ($priority) {
        case 'Low':
            return 'priority-low';
        case 'Moderate':
            return 'priority-moderate';
        case 'High':
            return 'priority-high';
        default:
            return 'priority-unrated';
    }
}

$assessment_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$assessment_id = $assessment_id ?: 0;

if ($assessment_id < 1) {
    header("Location: assessment_queue.php");
    exit();
}

$assessment_table = null;

foreach ([
    'assessment_data',
    'assessments',
    'assessment',
    'assessment_results',
    'assessment_submissions',
    'assessment_records'
] as $candidate) {
    if (va_table_exists($conn, $candidate)) {
        $assessment_table = $candidate;
        break;
    }
}

$assessment = null;
$student = [];
$responses = [];
$notes = '';
$message = '';
$error = '';

$id_col = null;
$user_col = null;
$date_col = null;
$status_col = null;
$review_date_col = null;

if ($assessment_table) {
    $id_col = va_first_column(
        $conn,
        $assessment_table,
        ['assessment_id', 'id', 'submission_id', 'record_id']
    );

    $user_col = va_first_column(
        $conn,
        $assessment_table,
        ['student_id']
    );

    $date_col = va_first_column(
        $conn,
        $assessment_table,
        ['assessment_date', 'submitted_at', 'created_at', 'date_submitted']
    );

    $status_col = va_first_column(
        $conn,
        $assessment_table,
        ['status', 'review_status', 'submission_status']
    );

    // Detect an existing review timestamp column without changing the schema.
    $review_date_col = va_first_column(
        $conn,
        $assessment_table,
        ['reviewed_at', 'review_date', 'date_reviewed', 'review_timestamp']
    );

    if (!$id_col || !$user_col) {
        $error = 'The assessment table is missing an identifiable record ID or student ID.';
    } else {
        $sql = "SELECT a.*,
                       u.fullname AS student_fullname,
                       u.email AS student_email,
                       u.student_number AS student_number,
                       u.alias AS student_alias,
                       u.program AS student_program,
                       u.year_level AS student_year_level
                FROM `{$assessment_table}` a
                LEFT JOIN student_profiles u
                    ON u.student_id = a.`{$user_col}`
                WHERE a.`{$id_col}` = ?
                LIMIT 1";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $assessment_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $assessment = $result
                ? (mysqli_fetch_assoc($result) ?: null)
                : null;

            mysqli_stmt_close($stmt);
        }

        if (!$assessment && $error === '') {
            $error = 'This assessment could not be found.';
        }
    }
} else {
    $error = 'No recognized assessment table was found.';
}

if ($assessment) {
    $student = [
        'name' => $assessment['student_alias']
            ?: ($assessment['student_fullname'] ?? 'Student'),
        'fullname' => $assessment['student_fullname'] ?? '',
        'email' => $assessment['student_email'] ?? '',
        'number' => $assessment['student_number'] ?? '',
        'program' => $assessment['student_program'] ?? '',
        'year' => $assessment['student_year_level'] ?? ''
    ];
}

/* Load response and question data */
if (
    $assessment &&
    va_table_exists($conn, 'response_data') &&
    va_table_exists($conn, 'question_data') &&
    va_column_exists($conn, 'response_data', 'assessment_id') &&
    va_column_exists($conn, 'response_data', 'question_id') &&
    va_column_exists($conn, 'question_data', 'question_id')
) {
    $answer_text_col = va_first_column(
        $conn,
        'response_data',
        ['answer_text', 'response_text', 'answer']
    );

    $answer_score_col = va_first_column(
        $conn,
        'response_data',
        ['answer_score', 'answer_value', 'score']
    );

    $question_col = va_first_column(
        $conn,
        'question_data',
        ['question_text', 'question', 'item_text']
    );

    $number_col = va_first_column(
        $conn,
        'question_data',
        ['question_number', 'question_order', 'item_number']
    );

    $category_col = va_first_column(
        $conn,
        'question_data',
        ['assessment_type', 'category', 'section']
    );

    if ($question_col && ($answer_text_col || $answer_score_col)) {
        $text_select = $answer_text_col
            ? "r.`{$answer_text_col}`"
            : 'NULL';

        $score_select = $answer_score_col
            ? "r.`{$answer_score_col}`"
            : 'NULL';

        $category_select = $category_col
            ? "q.`{$category_col}`"
            : "'Needs Assessment'";

        $number_select = $number_col
            ? "q.`{$number_col}`"
            : 'q.question_id';

        $sql = "SELECT
                    {$category_select} AS section_name,
                    {$number_select} AS question_number,
                    q.`{$question_col}` AS question_text,
                    {$text_select} AS answer_text,
                    {$score_select} AS answer_score
                FROM response_data r
                INNER JOIN question_data q
                    ON q.question_id = r.question_id
                WHERE r.assessment_id = ?
                ORDER BY {$category_select}, {$number_select}";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $assessment_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $text = trim((string)($row['answer_text'] ?? ''));

                    $row['answer_value'] = $text !== ''
                        ? $text
                        : (
                            $row['answer_score'] !== null
                                ? (string)$row['answer_score']
                                : 'No answer recorded'
                        );

                    $responses[] = $row;
                }
            }

            mysqli_stmt_close($stmt);
        }
    }
}

/* Use the same provisional scoring rules as the queue. */
$scoring = va_calculate_priority($responses);
$assessment_priority = $scoring['priority'];
$priority_average = $scoring['average'];
$valid_score_count = $scoring['count'];
$priority_class = va_priority_class($assessment_priority);

/*
 * Counselor notes are stored independently of referrals so an assessment
 * can have notes even when no referral record exists.
 */
$notes_table_ready = false;

if ($assessment) {
    $create_notes_table_sql = "
        CREATE TABLE IF NOT EXISTS counselor_notes_data (
            note_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            assessment_id INT NOT NULL,
            counselor_id INT NULL,
            notes LONGTEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (note_id),
            UNIQUE KEY uq_counselor_notes_assessment (assessment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $notes_table_ready = (bool) mysqli_query($conn, $create_notes_table_sql);

    if ($notes_table_ready) {
        $notes_stmt = mysqli_prepare(
            $conn,
            "SELECT notes FROM counselor_notes_data WHERE assessment_id = ? LIMIT 1"
        );

        if ($notes_stmt) {
            mysqli_stmt_bind_param($notes_stmt, 'i', $assessment_id);
            mysqli_stmt_execute($notes_stmt);
            $notes_result = mysqli_stmt_get_result($notes_stmt);

            if ($notes_result && ($notes_row = mysqli_fetch_assoc($notes_result))) {
                $notes = (string)($notes_row['notes'] ?? '');
            } else {
                /*
                 * Compatibility fallback: show existing notes from referral_data
                 * if this assessment has not yet been saved in the new notes table.
                 */
                if (va_table_exists($conn, 'referral_data')) {
                    $legacy_assessment_col = va_first_column($conn, 'referral_data', ['assessment_id']);
                    $legacy_notes_col = va_first_column($conn, 'referral_data', ['remarks', 'counselor_notes', 'notes']);
                    $legacy_date_col = va_first_column($conn, 'referral_data', ['referral_date', 'created_at', 'date_created']);

                    if ($legacy_assessment_col && $legacy_notes_col) {
                        $legacy_sql = "SELECT `{$legacy_notes_col}` AS counselor_notes
                                       FROM referral_data
                                       WHERE `{$legacy_assessment_col}` = ?";
                        if ($legacy_date_col) {
                            $legacy_sql .= " ORDER BY `{$legacy_date_col}` DESC";
                        }
                        $legacy_sql .= " LIMIT 1";

                        $legacy_stmt = mysqli_prepare($conn, $legacy_sql);
                        if ($legacy_stmt) {
                            mysqli_stmt_bind_param($legacy_stmt, 'i', $assessment_id);
                            mysqli_stmt_execute($legacy_stmt);
                            $legacy_result = mysqli_stmt_get_result($legacy_stmt);
                            if ($legacy_result && ($legacy_row = mysqli_fetch_assoc($legacy_result))) {
                                $notes = (string)($legacy_row['counselor_notes'] ?? '');
                            }
                            mysqli_stmt_close($legacy_stmt);
                        }
                    }
                }
            }

            mysqli_stmt_close($notes_stmt);
        }
    }
}

/* Preserve existing counselor actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $assessment) {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_assessment_email') {
        header('Content-Type: application/json; charset=utf-8');

        $recipient = trim((string)($_POST['recipient_email'] ?? ''));
        $subject = trim((string)($_POST['email_subject'] ?? ''));
        $email_message = trim((string)($_POST['email_message'] ?? ''));

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Please enter a valid recipient email address.']);
            exit();
        }
        if ($subject === '' || $email_message === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Subject and message are required.']);
            exit();
        }
        if (strlen($subject) > 250 || strlen($email_message) > 15000) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Subject or message is too long.']);
            exit();
        }

        $recipient_name = trim((string)($assessment['student_alias'] ?? '')) ?: 'Student';
        $counselor_name_for_email = ptcCounselorMailSenderName($conn);
        $email_content = ptcCounselorMailContent($recipient_name, $email_message, $counselor_name_for_email);
        $send_result = sendSystemEmail(
            $recipient,
            $recipient_name,
            $subject,
            $email_content['plain'],
            $email_content['html'],
            $email_content['logo']
        );

        if (!empty($send_result['success'])) {
            echo json_encode(['success' => true, 'message' => 'Email sent successfully to ' . $recipient . '.']);
            exit();
        }

        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $send_result['message'] ?? 'Unable to send email. Check the email configuration.']);
        exit();
    }

    if ($action === 'save_notes') {
        $notes = va_sanitize_notes(trim($_POST['counselor_notes'] ?? ''));

        $is_ajax_notes_save =
            !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $create_notes_table_sql = "
            CREATE TABLE IF NOT EXISTS counselor_notes_data (
                note_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                assessment_id INT NOT NULL,
                counselor_id INT NULL,
                notes LONGTEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (note_id),
                UNIQUE KEY uq_counselor_notes_assessment (assessment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";

        if (!mysqli_query($conn, $create_notes_table_sql)) {
            $error = 'Unable to prepare counselor notes storage. Please check the database table and permissions.';

            if ($is_ajax_notes_save) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => $error
                ]);
                exit();
            }
        } else {
            $counselor_id = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])
                ? (int) $_SESSION['user_id']
                : null;

            $save_sql = "
                INSERT INTO counselor_notes_data (assessment_id, counselor_id, notes)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    counselor_id = VALUES(counselor_id),
                    notes = VALUES(notes),
                    updated_at = CURRENT_TIMESTAMP
            ";

            $save_stmt = mysqli_prepare($conn, $save_sql);

            if (!$save_stmt) {
                $error = 'Unable to prepare counselor notes for saving.';

                if ($is_ajax_notes_save) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'message' => $error
                    ]);
                    exit();
                }
            } else {
                mysqli_stmt_bind_param($save_stmt, 'iis', $assessment_id, $counselor_id, $notes);

                if (mysqli_stmt_execute($save_stmt)) {
                    mysqli_stmt_close($save_stmt);

                    if ($is_ajax_notes_save) {
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'success' => true,
                            'message' => 'Your counselor notes have been saved.'
                        ]);
                        exit();
                    }

                    // Keep the regular form fallback for browsers without AJAX.
                    header(
                        "Location: view_assessment.php?id=" .
                        (int)$assessment_id .
                        "&notes_saved=1"
                    );
                    exit();
                } else {
                    mysqli_stmt_close($save_stmt);
                    $error = 'Unable to save counselor notes. Please try again.';

                    if ($is_ajax_notes_save) {
                        header('Content-Type: application/json; charset=utf-8');
                        http_response_code(500);
                        echo json_encode([
                            'success' => false,
                            'message' => $error
                        ]);
                        exit();
                    }
                }
            }
        }
    }

    if ($action === 'mark_reviewed') {

        $lock_token = trim($_POST['lock_token'] ?? '');
        $counselor_id = (int)($_SESSION['user_id'] ?? 0);

        if (!$status_col) {
            $error = 'The assessment status column could not be identified.';

        } elseif (va_status($assessment[$status_col] ?? '') === 'reviewed') {

            header(
                "Location: view_assessment.php?id=" .
                (int)$assessment_id . "&reviewed=1"
            );
            exit();

        } elseif ($lock_token === '') {

            $error = 'You do not currently hold the review lock. Please refresh the assessment activity.';

        } else {

            mysqli_begin_transaction($conn);

            try {

                // Verify that this counselor owns the active review lock.
                $lock_stmt = mysqli_prepare(
                    $conn,
                    "SELECT lock_id
                    FROM assessment_review_locks
                    WHERE assessment_id = ?
                    AND counselor_id = ?
                    AND lock_token = ?
                    AND expires_at > NOW()
                    LIMIT 1
                    FOR UPDATE"
                );

                if (!$lock_stmt) {
                    throw new Exception('Unable to verify review lock.');
                }

                mysqli_stmt_bind_param(
                    $lock_stmt,
                    'iis',
                    $assessment_id,
                    $counselor_id,
                    $lock_token
                );

                mysqli_stmt_execute($lock_stmt);

                $lock_result = mysqli_stmt_get_result($lock_stmt);
                $valid_lock = $lock_result
                    ? mysqli_fetch_assoc($lock_result)
                    : null;

                mysqli_stmt_close($lock_stmt);

                if (!$valid_lock) {
                    throw new Exception(
                        'Your review lock has expired or belongs to another counselor. Please refresh the page.'
                    );
                }

                // Update assessment status only after lock verification.
                if ($review_date_col) {
                    $update_sql = "UPDATE `{$assessment_table}`
                                SET `{$status_col}` = 'Reviewed',
                                    `{$review_date_col}` = NOW()
                                WHERE `{$id_col}` = ?";
                } else {
                    $update_sql = "UPDATE `{$assessment_table}`
                                SET `{$status_col}` = 'Reviewed'
                                WHERE `{$id_col}` = ?";
                }

                $update_stmt = mysqli_prepare($conn, $update_sql);

                if (!$update_stmt) {
                    throw new Exception('Unable to prepare assessment update.');
                }

                mysqli_stmt_bind_param(
                    $update_stmt,
                    'i',
                    $assessment_id
                );

                if (!mysqli_stmt_execute($update_stmt)) {
                    mysqli_stmt_close($update_stmt);
                    throw new Exception('Unable to update assessment status.');
                }

                mysqli_stmt_close($update_stmt);

                // Release the review lock after successfully marking as Reviewed.
                $release_lock = mysqli_prepare(
                    $conn,
                    "DELETE FROM assessment_review_locks
                    WHERE assessment_id = ?
                    AND counselor_id = ?
                    AND lock_token = ?"
                );

                if (!$release_lock) {
                    throw new Exception('Unable to prepare review lock release.');
                }

                mysqli_stmt_bind_param(
                    $release_lock,
                    'iis',
                    $assessment_id,
                    $counselor_id,
                    $lock_token
                );

                if (!mysqli_stmt_execute($release_lock)) {
                    mysqli_stmt_close($release_lock);
                    throw new Exception('Unable to release review lock.');
                }

                mysqli_stmt_close($release_lock);

                mysqli_commit($conn);

                header(
                    "Location: view_assessment.php?id=" .
                    (int)$assessment_id . "&reviewed=1"
                );
                exit();

            } catch (Throwable $e) {

                mysqli_rollback($conn);

                $error = $e->getMessage();
            }
        }
    }
}

$status = $status_col
    ? ($assessment[$status_col] ?? 'Pending')
    : 'Pending';

$is_reviewed = va_status($status) === 'reviewed';

$has_existing_referral = false;
$has_scheduled_appointment = false;

if (
    $assessment &&
    $is_reviewed &&
    va_table_exists($conn, 'referral_data') &&
    va_column_exists($conn, 'referral_data', 'assessment_id') &&
    va_column_exists($conn, 'referral_data', 'referral_id')
) {
    $referral_lookup = mysqli_prepare(
        $conn,
        "SELECT referral_id
         FROM referral_data
         WHERE assessment_id = ?
         ORDER BY referral_id DESC
         LIMIT 1"
    );

    if ($referral_lookup) {
        mysqli_stmt_bind_param(
            $referral_lookup,
            'i',
            $assessment_id
        );
        mysqli_stmt_execute($referral_lookup);

        $referral_result = mysqli_stmt_get_result($referral_lookup);
        $existing_referral = $referral_result
            ? mysqli_fetch_assoc($referral_result)
            : null;

        mysqli_stmt_close($referral_lookup);

        if ($existing_referral && !empty($existing_referral['referral_id'])) {
            $has_existing_referral = true;
            $referral_id_for_schedule =
                (int) $existing_referral['referral_id'];

            if (
                va_table_exists($conn, 'schedule_data') &&
                va_column_exists($conn, 'schedule_data', 'referral_id')
            ) {
            $schedule_lookup = mysqli_prepare(
                $conn,
                "SELECT schedule_id
                 FROM schedule_data
                 WHERE referral_id = ?
                 ORDER BY schedule_id DESC
                 LIMIT 1"
            );

            if ($schedule_lookup) {
                mysqli_stmt_bind_param(
                    $schedule_lookup,
                    'i',
                    $referral_id_for_schedule
                );
                mysqli_stmt_execute($schedule_lookup);

                $schedule_result = mysqli_stmt_get_result($schedule_lookup);
                $existing_schedule = $schedule_result
                    ? mysqli_fetch_assoc($schedule_result)
                    : null;

                mysqli_stmt_close($schedule_lookup);

                $has_scheduled_appointment = (bool) $existing_schedule;
            }
            }
        }
    }
}


$submitted_at = $date_col
    ? ($assessment[$date_col] ?? '')
    : '';

$submitted_label = $submitted_at && strtotime($submitted_at)
    ? date('M d, Y · h:i A', strtotime($submitted_at))
    : 'Not available';



$counselor_name = ptcCounselorMailSenderName($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Review | PTC Wellness</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/counselor/counselor-base.css">
    <link rel="stylesheet" href="../assets/css/counselor/counselor-layout.css?v=<?= (int) (@filemtime(__DIR__ . '/../assets/css/counselor/counselor-layout.css') ?: 0) ?>">
    <link rel="stylesheet" href="../assets/css/counselor/view-assessment.css?v=<?= (int) (@filemtime(__DIR__ . '/../assets/css/counselor/view-assessment.css') ?: 0) ?>">
    <link rel="stylesheet" href="../assets/css/counselor/view-assessment-mobile.css?v=<?= (int) (@filemtime(__DIR__ . '/../assets/css/counselor/view-assessment-mobile.css') ?: 0) ?>" media="(max-width: 900px)">
    <link rel="stylesheet" href="../assets/css/counselor/email-sending.css?v=<?= (int) (@filemtime(__DIR__ . '/../assets/css/counselor/email-sending.css') ?: 0) ?>">
</head>
<body class="counselor-page assessment-review-page">
<?php include __DIR__ . '/../includes/counselor/sidebar.php'; ?>
<div class="counselor-workspace">
    <?php include __DIR__ . '/../includes/counselor/topbar.php'; ?>
    <main class="main-content">
        <header class="page-header">
            <div>
                <p class="eyebrow">PTC WELLNESS · GUIDANCE OFFICE</p>
                <h1 class="page-title">Assessment Review</h1>
                <p class="page-subtitle">Review the student's responses and determine appropriate next steps.</p>
            </div>

            <div class="header-right">
                <div class="datetime-card">
                    <span class="datetime-item"><span class="datetime-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span><span id="current-date">Loading date...</span></span>
                    <span class="datetime-divider" aria-hidden="true"></span>
                    <span class="datetime-item"><span class="datetime-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><span id="current-time">Loading time...</span></span>
                </div>
                <div class="header-status"><span class="status-dot"></span>Counselor Portal</div>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <div class="review-alert review-alert-error"><?= va_escape($error) ?></div>
        <?php endif; ?>

        <?php if ($message !== ''): ?>
            <div class="review-alert review-alert-success" role="status">
                <?= va_escape($message) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['reviewed']) && $_GET['reviewed'] === '1'): ?>
            <div class="review-success-card" role="status">
                <div class="review-success-icon">
                    <svg viewBox="0 0 52 52" aria-hidden="true">
                        <circle class="success-circle" cx="26" cy="26" r="23"></circle>
                        <path class="success-check" d="M14 27 L22 35 L38 18"></path>
                    </svg>
                </div>

                <div class="review-success-content">
                    <h2>Assessment Marked as Reviewed</h2>
                    <p>
                        This assessment has been successfully reviewed.
                        You may now proceed to referral if appropriate.
                    </p>
                </div>

                <button
                    type="button"
                    class="review-success-dismiss"
                    aria-label="Dismiss notification"
                    onclick="this.closest('.review-success-card').remove()"
                >&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!$assessment): ?>
            <section class="review-card">
                <h2>Assessment unavailable</h2>
                <p><?= va_escape($error ?: 'The selected assessment could not be found.') ?></p>
                <a href="assessment_queue.php" class="review-button">Back to Queue</a>
            </section>
        <?php else: ?>

            <section class="student-overview review-card">
                <div class="student-overview-main">
                    <div class="student-avatar">
                        <?= va_escape(strtoupper(substr((string)$student['name'], 0, 1))) ?>
                    </div>

                    <div>
                        <p class="section-eyebrow">STUDENT SUBMISSION</p>
                        <h2><?= va_escape($student['name']) ?></h2>
                        <p class="student-subtitle">
                            <?= va_escape($student['program'] ?: 'Program not available') ?>
                            <?= $student['year'] !== '' ? ' · Year ' . va_escape($student['year']) : '' ?>
                        </p>
                    </div>
                </div>

                <div class="student-overview-actions">
                    <span id="assessmentReviewStatus" class="review-status <?= $is_reviewed ? 'review-status-done' : 'review-status-waiting' ?>">
                        <span class="queue-status-dot"></span><?= va_escape($status) ?>
                    </span>

                    <?php if (!empty($student['email'])): ?>
                    <button
                        type="button"
                        class="email-send-button"
                        id="openAssessmentEmail"
                        data-recipient="<?= va_escape($student['email']) ?>"
                        data-student-name="<?= va_escape($student['fullname'] ?: $student['name']) ?>"
                        data-student-alias="<?= va_escape(trim((string)($assessment['student_alias'] ?? '')) ?: 'Student') ?>"
                        data-counselor-name="<?= va_escape($counselor_name) ?>"
                    >Send Email</button>
                    <?php endif; ?>

                <span id="assessmentWorkflowIndicator"
                    data-reviewed="<?= $is_reviewed ? '1' : '0' ?>"
                    data-referral="<?= $has_existing_referral ? '1' : '0' ?>"
                    data-scheduled="<?= $has_scheduled_appointment ? '1' : '0' ?>">
                    <?php if ($is_reviewed && $has_scheduled_appointment): ?>
                        <span class="review-button review-button-outline schedule-referral-top-button" aria-label="Student appointment scheduled" aria-disabled="true" style="cursor:default"><span aria-hidden="true">✓</span> Scheduled</span>
                    <?php elseif ($is_reviewed && $has_existing_referral): ?>
                        <span class="review-button review-button-outline schedule-referral-top-button" aria-label="Referral already created" aria-disabled="true" style="cursor:default"><span aria-hidden="true">✓</span> Referral Already Created</span>
                    <?php elseif ($is_reviewed): ?>
                        <button type="button" id="openAssessmentReferralModal" class="review-button review-button-outline schedule-referral-top-button"><span aria-hidden="true">▦</span> Schedule for Referral</button>
                    <?php endif; ?>
                </span>

                </div>
                
                <div class="assessment-live-presence" id="assessmentLivePresence"
                    aria-live="polite">
                    <div class="live-presence-heading">
                        <span class="live-eye-icon" aria-hidden="true">◉</span>
                        <strong>Live Assessment Activity</strong>
                        <span class="live-connection-status" id="liveConnectionStatus">
                            Connecting...
                        </span>
                    </div>

                    <p id="liveViewersList">Checking active counselors...</p>
                    <p id="liveReviewerStatus">Checking review lock...</p>
                </div>

                <div class="student-meta-grid">
                    <div>
                        <span class="meta-label">Assessment ID</span>
                        <strong>#<?= (int)$assessment_id ?></strong>
                    </div>
                    <div>
                        <span class="meta-label">Student Number</span>
                        <strong><?= va_escape($student['number'] ?: 'Not available') ?></strong>
                    </div>
                    <div>
                        <span class="meta-label">Email</span>
                        <strong class="meta-email"><?= va_escape($student['email'] ?: 'Not available') ?></strong>
                    </div>
                    <div>
                        <span class="meta-label">Submitted</span>
                        <strong><?= va_escape($submitted_label) ?></strong>
                    </div>
                </div>
            </section>

            <section class="review-card priority-review-card">
                <div class="priority-review-heading">
                    <div>
                        <p class="section-eyebrow">PROVISIONAL WORKFLOW INDICATOR</p>
                        <h2>Assessment Priority</h2>
                        <p class="priority-review-description">
                            Based on valid Likert responses recorded for this submission.
                        </p>
                    </div>

                    <span class="priority-badge <?= va_escape($priority_class) ?>">
                        <?= va_escape($assessment_priority ?? 'Not Rated') ?>
                    </span>
                </div>

                <div class="priority-metrics">
                    <div class="priority-metric">
                        <span class="meta-label">Average Score</span>
                        <strong><?= $priority_average !== null ? number_format($priority_average, 2) . ' / 5.00' : 'Not available' ?></strong>
                    </div>

                    <div class="priority-metric">
                        <span class="meta-label">Valid Likert Responses</span>
                        <strong><?= number_format($valid_score_count) ?></strong>
                    </div>
                </div>

                <div class="priority-scale">
                    <div>
                        <span class="priority-scale-dot priority-low-dot"></span>
                        <span><strong>Low</strong><small>Average below 3.00</small></span>
                    </div>
                    <div>
                        <span class="priority-scale-dot priority-moderate-dot"></span>
                        <span><strong>Moderate</strong><small>3.00 to below 4.00</small></span>
                    </div>
                    <div>
                        <span class="priority-scale-dot priority-high-dot"></span>
                        <span><strong>High</strong><small>4.00 to 5.00</small></span>
                    </div>
                </div>

                <p class="priority-disclaimer">
                    These thresholds are provisional and have not been validated as clinical scoring.
                    The Needs Assessment Survey is not a medical diagnosis. The Guidance Office should
                    confirm the interpretation of the survey items before operational use.
                </p>
            </section>

            <section class="review-card responses-launch-card" aria-labelledby="responsesLaunchHeading">
                <div class="review-section-heading">
                    <div class="section-icon" aria-hidden="true">☷</div>
                    <div>
                        <h2 id="responsesLaunchHeading">Needs Assessment Responses</h2>
                        <p>Open the full questionnaire in a separate, scrollable window.</p>
                    </div>
                    <span class="response-count"><?= count($responses) ?> responses</span>
                </div>
                <div class="responses-launch-actions">
                    <p>Read the student's questions and answers without losing your place in the assessment review.</p>
                    <button type="button" id="openNeedsAssessmentResponses" class="review-button responses-open-button" aria-haspopup="dialog" aria-controls="needsAssessmentResponsesDialog">
                        View All Responses <span aria-hidden="true">↗</span>
                    </button>
                </div>
            </section>

            <section class="review-card">
                <div class="review-section-heading">
                    <div class="section-icon">✎</div>
                    <div>
                        <h2>Counselor Notes</h2>
                        <p>Record observations, decisions, and follow-up plans.</p>
                    </div>
                </div>

                <form id="counselorNotesForm" method="POST" action="view_assessment.php?id=<?= (int)$assessment_id ?>">
                    <input type="hidden" name="action" value="save_notes">
                    <input type="hidden" id="counselor_notes" name="counselor_notes" value="">
                    <label class="notes-label" for="counselorNotesEditor">Review notes</label>
                    <div class="notes-editor-wrap">
                        <div class="notes-toolbar" role="toolbar" aria-label="Counselor notes formatting">
                            <button type="button" data-command="bold" aria-label="Bold" title="Bold"><strong>B</strong></button>
                            <button type="button" data-command="italic" aria-label="Italic" title="Italic"><em>I</em></button>
                            <button type="button" data-command="underline" aria-label="Underline" title="Underline"><u>U</u></button>
                            <button type="button" data-command="insertUnorderedList" aria-label="Bulleted list" title="Bulleted list">• List</button>
                            <button type="button" data-command="insertOrderedList" aria-label="Numbered list" title="Numbered list">1. List</button>
                            
                        </div>
                        <div id="counselorNotesEditor" class="notes-editor" contenteditable="true"
                             role="textbox" aria-multiline="true"
                             data-placeholder="Document relevant observations, the student's stated needs, agreed next steps, and any referral considerations..."><?= va_sanitize_notes($notes) ?></div>
                    </div>
                    <div class="notes-footer">
                        <span>Keep notes factual, respectful, and relevant to student support.</span>
                        <button type="submit" class="review-button review-button-secondary">Save Notes</button>
                    </div>
                </form>
            </section>

            <section class="review-card action-card">
                <div>
                    <h2>Review Actions</h2>
                    <p>Complete the assessment review before proceeding to referral.</p>
                </div>

                <div class="review-actions">
                    <?php if (!$is_reviewed): ?>
                <form
                    method="POST"
                    action="view_assessment.php?id=<?= (int)$assessment_id ?>"
                    id="markReviewedForm"
                >
                    <input type="hidden" name="action" value="mark_reviewed">

                    <input
                        type="hidden"
                        name="lock_token"
                        id="reviewLockToken"
                        value=""
                    >

                    <button type="submit" class="review-button" id="markReviewedButton">
                        <span>✓</span>
                        Mark as Reviewed
                    </button>
                </form>
                    <?php else: ?>
                        <span class="review-complete">
                            ✓ Assessment Reviewed
                        </span>
                    <?php endif; ?>

                    <a
                        href="assessment_queue.php"
                        class="review-button review-button-muted"
                    >
                        Back to Queue
                    </a>
                </div>

                <p class="review-disclaimer">
                    The Needs Assessment Survey is not a medical diagnosis.
                    Final support and referral decisions remain with the counselor.
                </p>
            </section>

        <?php endif; ?>
    </main>
    <?php include __DIR__ . '/../includes/counselor/footer.php'; ?>
</div>

<?php if ($assessment): ?>
<dialog id="needsAssessmentResponsesDialog" class="va-responses-dialog" aria-labelledby="vaResponsesDialogTitle" aria-describedby="vaResponsesDialogDescription">
    <div class="va-responses-dialog-shell">
        <div class="va-responses-dialog-header">
            <div class="va-responses-dialog-title">
                <p class="va-responses-dialog-eyebrow">PTC WELLNESS · ASSESSMENT REVIEW</p>
                <h2 id="vaResponsesDialogTitle">Needs Assessment Responses</h2>
                <p id="vaResponsesDialogDescription">Scroll through the complete questionnaire while keeping your place on the review page.</p>
            </div>
            <button type="button" id="closeNeedsAssessmentResponses" class="va-responses-dialog-close" aria-label="Close assessment responses">&times;</button>
        </div>
        <div class="va-responses-dialog-toolbar">
            <span class="va-responses-dialog-count"><?= count($responses) ?> responses</span>
            <span class="va-responses-dialog-hint">Scroll within this window to see every answer</span>
        </div>
        <div class="va-responses-dialog-body" id="vaResponsesDialogScroll" tabindex="0" aria-label="Scrollable assessment responses">
                <?php if (!$responses): ?>
                    <div class="review-empty">
                        <h3>No response details found</h3>
                        <p>The current response/question tables did not return compatible responses. Verify the actual database tables and columns.</p>
                    </div>
                <?php else: ?>
                    <?php
                    $grouped_responses = [];

                    foreach ($responses as $response) {
                        $section = trim((string)($response['section_name'] ?? ''));

                        if ($section === '') {
                            $section = 'Needs Assessment';
                        }

                        $grouped_responses[$section][] = $response;
                    }

                    $likert_labels = [
                        1 => 'Strongly Disagree',
                        2 => 'Disagree',
                        3 => 'Neutral',
                        4 => 'Agree',
                        5 => 'Strongly Agree'
                    ];
                    ?>

                    <div class="response-sections">
                        <?php foreach ($grouped_responses as $section => $items): ?>
                            <section class="response-section">
                                <h3><?= va_escape($section) ?></h3>

                                <div class="response-list">
                                    <?php foreach ($items as $index => $response): ?>
                                        <?php
                                        $answer_text = trim((string)($response['answer_text'] ?? ''));
                                        $answer_score = $response['answer_score'] ?? null;

                                        $effective_score = va_response_score(
                                            $answer_score,
                                            $answer_text
                                        );

                                        if (
                                            $effective_score !== null &&
                                            (
                                                preg_match('/^[1-5]$/', $answer_text) ||
                                                $answer_text === '' ||
                                                isset($likert_labels[strtolower($answer_text)])
                                            )
                                        ) {
                                            $display_answer = $likert_labels[$effective_score];
                                            $answer_kind = 'likert';
                                        } elseif ($answer_text !== '') {
                                            $display_answer = $answer_text;
                                            $answer_kind = 'written';
                                        } else {
                                            $display_answer = 'No answer recorded';
                                            $answer_kind = 'empty';
                                        }
                                        ?>

                                        <article class="response-row">
                                            <div class="response-question">
                                                <span class="question-number">
                                                    <?= va_escape($response['question_number'] ?: ($index + 1)) ?>
                                                </span>
                                                <p><?= va_escape($response['question_text'] ?? '') ?></p>
                                            </div>

                                            <div class="<?= 'response-answer response-answer-' . va_escape($answer_kind) ?>">
                                                <?php if ($answer_kind === 'written'): ?>
                                                    <div class="written-response-full"><?= nl2br(va_escape($display_answer)) ?></div>
                                                <?php else: ?>
                                                    <span class="answer-pill <?= $answer_kind === 'likert' ? 'answer-pill-likert' : 'answer-pill-empty' ?>">
                                                        <?= va_escape($display_answer) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
        </div>
        <div class="va-responses-dialog-footer">
            <span>Responses are shown as submitted by the student.</span>
            <button type="button" id="closeNeedsAssessmentResponsesFooter" class="review-button review-button-secondary">Close Responses</button>
        </div>
    </div>
</dialog>

<div class="email-modal-overlay" id="assessmentEmailModal" aria-hidden="true">
    <div class="email-modal-card" role="dialog" aria-modal="true" aria-labelledby="assessmentEmailTitle">
        <h2 id="assessmentEmailTitle">Send Email to Student</h2>
        <p>Send a message directly to the student. This does not create a referral.</p>
        <form id="assessmentEmailForm" method="POST">
            <input type="hidden" name="action" value="send_assessment_email">
            <div class="email-modal-field">
                <label for="assessmentEmailRecipient">Recipient Email</label>
                <input type="email" id="assessmentEmailRecipient" name="recipient_email" required maxlength="254">
            </div>
            <div class="email-modal-field">
                <label for="assessmentEmailTemplate">Message Template</label>
                <select id="assessmentEmailTemplate">
                    <option value="general">General Follow-up</option>
                    <option value="appointment_invitation">Appointment Invitation</option>
                    <option value="appointment_reminder">Appointment Reminder</option>
                    <option value="additional_information">Additional Information</option>
                    <option value="student_support">Student Support</option>
                    <option value="custom">Custom Message</option>
                </select>
                <small class="email-template-help">Choose a professional format. The student's alias and your counselor name are added automatically as the greeting and signature. Edit only the message body.</small>
            </div>
            <div class="email-modal-field">
                <label for="assessmentEmailSubject">Subject</label>
                <input type="text" id="assessmentEmailSubject" name="email_subject" required maxlength="250">
            </div>
            <div class="email-modal-field">
                <label for="assessmentEmailMessage">Message</label>
                <textarea id="assessmentEmailMessage" name="email_message" required maxlength="15000" placeholder="Write your message to the student..."></textarea>
            </div>
            <div id="assessmentEmailFeedback" role="status" aria-live="polite"></div>
            <div class="email-modal-actions">
                <button type="button" class="email-cancel" id="cancelAssessmentEmail">Cancel</button>
                <button type="submit" id="submitAssessmentEmail">Send Email</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['notes_saved']) && $_GET['notes_saved'] === '1'): ?>
<div class="notes-save-modal" id="notesSavedModal" role="dialog" aria-modal="true" aria-labelledby="notesSavedTitle">
    <div class="notes-save-dialog">
        <div class="notes-save-icon" aria-hidden="true">✓</div>
        <h2 id="notesSavedTitle">Notes Saved Successfully</h2>
        <p>Your counselor notes have been saved.</p>
        <button type="button" id="closeNotesSavedModal">Done</button>
    </div>
</div>
<?php endif; ?>


<script src="../assets/js/counselor/email-sending.js?v=<?= (int) (@filemtime(__DIR__ . '/../assets/js/counselor/email-sending.js') ?: 0) ?>"></script>
<script>
(function () {
    const openButton = document.getElementById('openAssessmentEmail');
    const modal = document.getElementById('assessmentEmailModal');
    const form = document.getElementById('assessmentEmailForm');
    if (!openButton || !modal || !form) return;

    const recipient = document.getElementById('assessmentEmailRecipient');
    const subject = document.getElementById('assessmentEmailSubject');
    const message = document.getElementById('assessmentEmailMessage');
    const template = document.getElementById('assessmentEmailTemplate');
    const feedback = document.getElementById('assessmentEmailFeedback');
    const submit = document.getElementById('submitAssessmentEmail');
    const emailUI = window.PTCEmailSending?.attach({form, message, feedback, submit, student: openButton.dataset.studentAlias, counselor: openButton.dataset.counselorName});
    let sending = false;
    let closeAfterSuccess = null;

    // Messages contain the body only. The shared email layout adds
    // the personalized student greeting and counselor signature.
    const templates = {
        general: {
            subject: 'Follow-up Regarding Your PTC Wellness Assessment',
            message: 'I hope you are doing well.\n\nI am contacting you on behalf of the PTC Wellness Guidance Office following your recent Needs Assessment submission. We would like to check in and ask whether there is any support or guidance you would like to discuss.\n\nIf you would like to speak with a counselor, please reply to this email so we can coordinate an appropriate time. You may also visit the Guidance Office during its office hours.\n\nThank you for your time.'
        },
        appointment_invitation: {
            subject: 'Invitation to Arrange a Counseling Appointment | PTC Wellness',
            message: 'The PTC Wellness Guidance Office would like to invite you to arrange a counseling appointment. The purpose of the meeting is to provide an opportunity for you to discuss any concerns, questions, or support needs in a private and respectful setting.\n\nIf you would like to meet, please reply with your preferred days and times. We will confirm the details before any appointment is considered scheduled.\n\nThank you, and we look forward to hearing from you.'
        },
        appointment_reminder: {
            subject: 'Reminder: PTC Wellness Counseling Appointment',
            message: 'This is a courteous reminder regarding your counseling appointment with the PTC Wellness Guidance Office.\n\nIf you have already confirmed your schedule, no further action is needed. If you need to confirm, reschedule, or clarify your appointment details, please reply to this email or contact the Guidance Office directly.\n\nThank you for your attention.'
        },
        additional_information: {
            subject: 'Follow-up: Additional Information | PTC Wellness',
            message: 'To help us understand how best to assist you, we would appreciate the opportunity to clarify a few points related to your request for support.\n\nYou do not need to share private or sensitive details by email. If you prefer, we can discuss any additional information in a private conversation with a counselor. Please reply to let us know how you would feel most comfortable proceeding.\n\nThank you for your cooperation.'
        },
        student_support: {
            subject: 'Available Student Support | PTC Wellness',
            message: 'The PTC Wellness Guidance Office would like to remind you that support and guidance are available to students. If you would like to discuss an academic, career, personal, social, or other concern, you are welcome to contact the Guidance Office.\n\nYou may reply to this email if you would like us to coordinate a conversation with a counselor. You may share only what you feel comfortable discussing at this stage.\n\nThank you, and please know that your concerns will be treated with respect.'
        },
        custom: {
            subject: '',
            message: ''
        }
    };
    function applyTemplate() {
        const selected = templates[template.value] || templates.general;
        subject.value = selected.subject;
        message.value = selected.message;
        if (template.value === 'custom') {
            subject.focus();
        } else {
            message.focus();
        }
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
    openButton.addEventListener('click', function () {
        recipient.value = openButton.dataset.recipient || '';
        emailUI?.setNames(openButton.dataset.studentAlias || 'Student', openButton.dataset.counselorName || 'PTC Wellness Guidance Counselor');
        emailUI?.reset();
        template.value = 'general';
        applyTemplate();
        feedback.textContent = '';
        feedback.style.color = '';
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        recipient.focus();
    });
    document.getElementById('cancelAssessmentEmail').addEventListener('click', closeModal);
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
            const response = await fetch(window.location.href, {
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

<script>
(function () {
    const editor = document.getElementById('counselorNotesEditor');
    const notesField = document.getElementById('counselor_notes');
    const notesForm = document.getElementById('counselorNotesForm');
    const toolbar = document.querySelector('.notes-toolbar');

    function showNotesSavedModal(message, isError = false) {
        document.getElementById('notesSavedModal')?.remove();

        const modal = document.createElement('div');
        modal.className = 'notes-save-modal';
        modal.id = 'notesSavedModal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'notesSavedTitle');

        const dialog = document.createElement('div');
        dialog.className = 'notes-save-dialog';

        const icon = document.createElement('div');
        icon.className = 'notes-save-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = isError ? '!' : '✓';

        const title = document.createElement('h2');
        title.id = 'notesSavedTitle';
        title.textContent = isError
            ? 'Unable to Save Notes'
            : 'Notes Saved Successfully';

        const description = document.createElement('p');
        description.textContent = message;

        const done = document.createElement('button');
        done.type = 'button';
        done.textContent = 'Done';

        function closeModal() {
            modal.remove();
        }

        done.addEventListener('click', closeModal);

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        dialog.append(icon, title, description, done);
        modal.appendChild(dialog);
        document.body.appendChild(modal);
        done.focus();
    }

    if (editor && notesField && notesForm && toolbar) {
        const formatButtons = Array.from(
            toolbar.querySelectorAll('[data-command]')
        );

        const stateCommands = [
            'bold',
            'italic',
            'underline',
            'insertUnorderedList',
            'insertOrderedList'
        ];

        function selectionIsInsideEditor() {
            const selection = window.getSelection();

            return !!selection &&
                selection.rangeCount > 0 &&
                editor.contains(selection.anchorNode);
        }

        function updateToolbarState() {
            const inside = selectionIsInsideEditor();

            formatButtons.forEach(function (button) {
                const command = button.dataset.command;
                let active = false;

                if (inside && stateCommands.includes(command)) {
                    try {
                        active = document.queryCommandState(command);
                    } catch (error) {
                        active = false;
                    }
                }

                button.classList.toggle('is-active', active);
                button.setAttribute(
                    'aria-pressed',
                    active ? 'true' : 'false'
                );
            });
        }

        formatButtons.forEach(function (button) {
            button.setAttribute('aria-pressed', 'false');

            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });

            button.addEventListener('click', function () {
                const command = button.dataset.command;
                editor.focus();

                if (command === 'removeFormat') {
                    const plainText =
                        editor.innerText || editor.textContent || '';

                    const escapeHtml = function (value) {
                        return value.replace(/[&<>"']/g, function (character) {
                            return {
                                '&': '&amp;',
                                '<': '&lt;',
                                '>': '&gt;',
                                '"': '&quot;',
                                "'": '&#39;'
                            }[character];
                        });
                    };

                    editor.innerHTML = plainText
                        .split(/\r?\n/)
                        .map(function (line) {
                            return line.trim() === ''
                                ? '<p><br></p>'
                                : '<p>' + escapeHtml(line) + '</p>';
                        })
                        .join('');
                } else {
                    document.execCommand(command, false, null);
                }

                updateToolbarState();
            });
        });

        ['keyup', 'mouseup', 'input', 'click'].forEach(function (eventName) {
            editor.addEventListener(eventName, updateToolbarState);
        });

        document.addEventListener('selectionchange', updateToolbarState);

        notesForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            notesField.value = editor.innerHTML;

            const submitButton = notesForm.querySelector(
                'button[type="submit"]'
            );

            const originalButtonText = submitButton
                ? submitButton.textContent
                : '';

            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Saving...';
            }

            try {
                const formData = new FormData(notesForm);

                /*
                 * IMPORTANT:
                 * Use getAttribute('action'), not notesForm.action.
                 * The hidden input named "action" can override that property.
                 */
                const formAction =
                    notesForm.getAttribute('action') ||
                    window.location.href;

                const response = await fetch(formAction, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                });

                const responseText = await response.text();
                let result;

                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    console.error(
                        'Unexpected response while saving counselor notes:',
                        responseText
                    );

                    throw new Error(
                        'The server returned an unexpected response. ' +
                        'Check the PHP error log and database table permissions, then try again.'
                    );
                }

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message ||
                        'Unable to save counselor notes. Please try again.'
                    );
                }

                showNotesSavedModal(
                    result.message || 'Your counselor notes have been saved.'
                );
            } catch (error) {
                console.error('Counselor notes save error:', error);

                showNotesSavedModal(
                    error.message ||
                    'Unable to save counselor notes. Please try again.',
                    true
                );
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = originalButtonText;
                }
            }
        });

        updateToolbarState();
    }

    /*
     * Handle the server-rendered success modal used by the regular
     * non-AJAX form fallback.
     */
    const savedModal = document.getElementById('notesSavedModal');
    const closeSavedModal = document.getElementById('closeNotesSavedModal');

    if (savedModal && closeSavedModal) {
        function closeSavedModalAndCleanUrl() {
            savedModal.remove();

            const url = new URL(window.location.href);
            url.searchParams.delete('notes_saved');

            window.history.replaceState(
                {},
                document.title,
                url.pathname + url.search + url.hash
            );
        }

        closeSavedModal.addEventListener(
            'click',
            closeSavedModalAndCleanUrl
        );

        savedModal.addEventListener('click', function (event) {
            if (event.target === savedModal) {
                closeSavedModalAndCleanUrl();
            }
        });

        closeSavedModal.focus();
    }
})();
</script>


<script>
(function () {
    const dateNode = document.getElementById('current-date');
    const timeNode = document.getElementById('current-time');

    if (!dateNode || !timeNode) return;

    const dateFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });

    const timeFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });

    function updateClock() {
        const now = new Date();
        dateNode.textContent = dateFormatter.format(now);
        timeNode.textContent = timeFormatter.format(now);
    }

    updateClock();
    setInterval(updateClock, 1000);
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
    const assessmentId = <?= (int)$assessment_id ?>;
    const counselorId = <?= (int)($_SESSION['user_id'] ?? 0) ?>;

    const panel = document.getElementById('assessmentLivePresence');
    const viewersList = document.getElementById('liveViewersList');
    const reviewerStatus = document.getElementById('liveReviewerStatus');
    const connectionStatus = document.getElementById('liveConnectionStatus');

    const reviewForm = document.getElementById('markReviewedForm');
    const reviewButton = document.getElementById('markReviewedButton');
    const lockTokenField = document.getElementById('reviewLockToken');

    if (!panel) return;

    const registerUrl = 'register_assessment_view.php';
    const heartbeatUrl = 'heartbeat_assessment_view.php';
    const checkUrl = 'check_assessment_viewers.php';
    const releaseUrl = 'release_assessment_view.php';
    const workflowStatusUrl = 'assessment_workflow_status.php';
    const workflowIndicator = document.getElementById('assessmentWorkflowIndicator');

    function renderWorkflowIndicator(data) {
        if (!workflowIndicator) return;
        const reviewed = !!data.is_reviewed;
        const referred = !!data.has_referral;
        const scheduled = !!data.is_scheduled;
        const reviewStatus = document.getElementById('assessmentReviewStatus');
        if (reviewStatus) {
            reviewStatus.classList.toggle('review-status-done', reviewed);
            reviewStatus.classList.toggle('review-status-waiting', !reviewed);
            reviewStatus.lastChild.textContent = reviewed ? 'Reviewed' : 'Pending';
        }
        workflowIndicator.dataset.reviewed = reviewed ? '1' : '0';
        workflowIndicator.dataset.referral = referred ? '1' : '0';
        workflowIndicator.dataset.scheduled = scheduled ? '1' : '0';

        if (!reviewed) {
            workflowIndicator.replaceChildren();
            return;
        }

        const item = document.createElement(scheduled || referred ? 'span' : 'button');
        item.className = 'review-button review-button-outline schedule-referral-top-button';
        if (scheduled || referred) {
            item.setAttribute('aria-disabled', 'true');
            item.style.cursor = 'default';
            const check = document.createElement('span');
            check.setAttribute('aria-hidden', 'true');
            check.textContent = '✓';
            item.append(check, document.createTextNode(scheduled ? ' Scheduled' : ' Referral Already Created'));
            item.setAttribute('aria-label', scheduled ? 'Student appointment scheduled' : 'Referral already created');
        } else {
            item.type = 'button';
            item.id = 'openAssessmentReferralModal';
            const icon = document.createElement('span');
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = '▦';
            item.append(icon, document.createTextNode(' Schedule for Referral'));
        }
        workflowIndicator.replaceChildren(item);
        const referralModal = document.getElementById('assessmentReferralModal');
        if (referralModal && (referred || scheduled)) {
            referralModal.classList.remove('is-open');
            referralModal.setAttribute('aria-hidden', 'true');
        }
    }

    async function refreshWorkflowStatus() {
        const response = await fetch(workflowStatusUrl + '?assessment_id=' + encodeURIComponent(assessmentId), {
            credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Unable to refresh workflow status.');
        renderWorkflowIndicator(data);
        isReviewed = !!data.is_reviewed;
    }

    let lockToken = '';
    let isReviewer = false;
    let isReviewed = <?= $is_reviewed ? 'true' : 'false' ?>;
    let leavingForReviewSubmission = false;
    let refreshRunning = false;

    async function postData(url, data) {
        const formData = new FormData();

        Object.keys(data).forEach(function (key) {
            formData.append(key, data[key]);
        });

        const response = await fetch(url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        });

        const result = await response.json();

        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Request failed.');
        }

        return result;
    }

    function updateReviewButton() {
        if (!reviewButton || isReviewed) return;

        const disabled = !isReviewer;

        reviewButton.disabled = disabled;
        reviewButton.style.opacity = disabled ? '0.55' : '1';
        reviewButton.style.cursor = disabled ? 'not-allowed' : 'pointer';

        reviewButton.title = disabled
            ? 'Only the counselor holding the review lock can mark this assessment as reviewed.'
            : 'You currently hold the review lock.';
    }

    function updateReviewerDisplay(data) {
        const reviewer = data.reviewer || null;

        const reviewerId = reviewer
            ? Number(reviewer.counselor_id)
            : (data.reviewer_id ? Number(data.reviewer_id) : null);

        const reviewerName = reviewer
            ? reviewer.fullname
            : (data.reviewer_name || '');

        if (reviewerId === counselorId && lockToken !== '') {
            isReviewer = true;
            reviewerStatus.textContent =
                'You currently hold the exclusive review lock.';

        } else if (reviewerId !== null) {
            isReviewer = false;
            reviewerStatus.textContent =
                'Currently being reviewed by ' +
                (reviewerName || 'another counselor') + '.';

        } else {
            isReviewer = false;
            reviewerStatus.textContent =
                'No counselor currently holds the review lock.';
        }

        updateReviewButton();
    }

    function updateViewers(data) {
        const viewers = Array.isArray(data.viewers)
            ? data.viewers
            : [];

        if (viewers.length === 0) {
            viewersList.textContent = 'You are the only active viewer.';
            return;
        }

        const names = viewers.map(function (viewer) {
            const name = viewer.fullname || 'Counselor';

            return Number(viewer.counselor_id) === counselorId
                ? name + ' (You)'
                : name;
        });

        viewersList.textContent =
            'Currently viewing (' + viewers.length + '): ' +
            names.join(', ');
    }

    async function registerViewer() {
        const result = await postData(registerUrl, {
            assessment_id: assessmentId
        });

        if (result.is_reviewer && result.lock_token) {
            lockToken = result.lock_token;
            lockTokenField && (lockTokenField.value = lockToken);
            isReviewer = true;
        }

        updateReviewerDisplay(result);
    }

    async function sendHeartbeat() {
        const result = await postData(heartbeatUrl, {
            assessment_id: assessmentId,
            lock_token: lockToken
        });

        if (!result.is_reviewer) {
            lockToken = '';

            if (lockTokenField) {
                lockTokenField.value = '';
            }

            isReviewer = false;

        } else {
            isReviewer = true;
        }

        updateReviewButton();
    }

    async function refreshActivity() {
        if (refreshRunning) return;

        refreshRunning = true;

        try {
            const response = await fetch(
                checkUrl + '?assessment_id=' + encodeURIComponent(assessmentId),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Unable to check activity.');
            }

            updateViewers(data);
            updateReviewerDisplay(data);
            await refreshWorkflowStatus();

            // If no counselor currently owns the lock,
            // attempt to acquire it.
            if (!isReviewed && !data.reviewer && !lockToken) {
            await registerViewer();
            }

            connectionStatus.textContent = 'Live';
            connectionStatus.style.color = '#287345';

        } catch (error) {
            console.error('Assessment activity error:', error);

            connectionStatus.textContent = 'Reconnecting...';
            connectionStatus.style.color = '#b42318';

        } finally {
            refreshRunning = false;
        }
    }

    async function releaseAssessmentView() {
        if (leavingForReviewSubmission) return;

        const formData = new FormData();
        formData.append('assessment_id', assessmentId);
        formData.append('lock_token', lockToken);

        if (navigator.sendBeacon) {
            navigator.sendBeacon(releaseUrl, formData);
        } else {
            fetch(releaseUrl, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                keepalive: true
            }).catch(function () {});
        }
    }

    // Do not release the lock before the Mark as Reviewed
    // form reaches the PHP handler.
    if (reviewForm) {
        reviewForm.addEventListener('submit', function (event) {
            if (!isReviewer || !lockToken) {
                event.preventDefault();
                alert('You do not currently hold the review lock.');
                return;
            }

            lockTokenField.value = lockToken;
            leavingForReviewSubmission = true;
            reviewButton.disabled = true;
        });
    }

    // Initial registration and activity check.
    registerViewer()
        .then(refreshActivity)
        .catch(function (error) {
            console.error('Initial registration failed:', error);
            connectionStatus.textContent = 'Connection error';
        });

    // Refresh the active counselor list every 3 seconds.
    setInterval(refreshActivity, 3000);

    // The PHP endpoint only extends the review lock when the token is valid.
    // Keep the viewer active even after the assessment is reviewed.
    setInterval(function () {
        sendHeartbeat().catch(function (error) {
            console.error('Heartbeat failed:', error);
        });
    }, 10000);

    // Release the viewer and lock when leaving the page.
    window.addEventListener('pagehide', releaseAssessmentView);

})();
</script>



<!-- Referral creation stays on View Assessment until a successful save. -->
<?php if ($assessment): ?>
<div id="assessmentReferralModal" class="assessment-referral-overlay" aria-hidden="true">
  <section class="assessment-referral-dialog" role="dialog" aria-modal="true" aria-labelledby="assessmentReferralTitle">
    <div class="assessment-referral-heading">
      <div><small>NEW REFERRAL</small><h2 id="assessmentReferralTitle">Create Referral</h2></div>
      <button type="button" id="closeAssessmentReferral" class="assessment-referral-close" aria-label="Close referral form">×</button>
    </div>
    <form id="assessmentReferralForm" action="referral.php" method="POST">
      <input type="hidden" name="action" value="create_referral">
      <input type="hidden" name="assessment_id" value="<?= (int)$assessment_id ?>">
      <div class="assessment-referral-student"><small>Selected student</small><strong><?= va_escape($student['fullname'] ?: $student['name']) ?></strong><small>Assessment type: Needs Assessment</small></div>
      <label for="assessmentReferralReason">Referral Reason <span aria-hidden="true">*</span></label>
      <textarea id="assessmentReferralReason" name="referral_reason" maxlength="2000" required placeholder="Explain why this student is being referred..."></textarea>
      <label for="assessmentReferralNotes">Counselor Notes</label>
      <textarea id="assessmentReferralNotes" name="counselor_notes" maxlength="10000" placeholder="Add additional notes relevant to this referral..."></textarea>
      <p id="assessmentReferralError" class="assessment-referral-error" role="alert" hidden></p>
      <div class="assessment-referral-actions"><button type="button" id="cancelAssessmentReferral">Cancel</button><button type="submit" id="submitAssessmentReferral">Create Referral</button></div>
    </form>
  </section>
</div>

<!-- Animated confirmation shown only after the server confirms the referral was saved. -->
<div id="referralSavedModal" class="referral-saved-overlay" aria-hidden="true">
  <section class="referral-saved-card" role="dialog" aria-modal="true" aria-labelledby="referralSavedTitle">
    <div class="referral-saved-check" aria-hidden="true">
      <svg viewBox="0 0 52 52"><path d="M14 27l8 8 17-18"/></svg>
    </div>
    <p class="referral-saved-eyebrow">PTC WELLNESS · GUIDANCE OFFICE</p>
    <h2 id="referralSavedTitle">Referral Created Successfully!</h2>
    <p class="referral-saved-copy">The referral details have been saved. Taking you to Referral Management...</p>
    <div class="referral-saved-progress"><span></span></div>
  </section>
</div>
<div id="referralPageTransition" class="referral-page-transition" aria-hidden="true">
  <div class="referral-transition-content"><span class="referral-transition-spinner"></span><strong>Opening Referral Management</strong><small>Please wait a moment...</small></div>
</div>
<script>
(function(){
 const modal=document.getElementById('assessmentReferralModal');
 if(!modal)return;
 const form=document.getElementById('assessmentReferralForm'), error=document.getElementById('assessmentReferralError'), submit=document.getElementById('submitAssessmentReferral');
 const close=()=>{modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');document.body.style.overflow='';const currentOpener=document.getElementById('openAssessmentReferralModal');if(currentOpener)currentOpener.focus();};
 document.getElementById('assessmentWorkflowIndicator')?.addEventListener('click',e=>{const opener=e.target.closest('#openAssessmentReferralModal');if(!opener)return;modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';document.getElementById('assessmentReferralReason').focus();});
 document.getElementById('closeAssessmentReferral').addEventListener('click',close);
 document.getElementById('cancelAssessmentReferral').addEventListener('click',close);
 modal.addEventListener('click',e=>{if(e.target===modal)close();});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('is-open'))close();});
 form.addEventListener('submit',async e=>{
  e.preventDefault();if(submit.disabled||!form.reportValidity())return;
  error.hidden=true;submit.disabled=true;submit.classList.add('is-saving');submit.innerHTML='<span class="referral-inline-spinner" aria-hidden="true"></span><span>Creating Referral...</span>';
  try{
   const response=await fetch(form.getAttribute('action')||'referral.php',{
    method:'POST',body:new FormData(form),credentials:'same-origin',
    headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
   });
   const responseText=await response.text();
   let result;
   try { result=JSON.parse(responseText); }
   catch(parseError) {
    console.error('Create Referral returned non-JSON response:',responseText);
    throw new Error('The server returned an unexpected page instead of JSON. Check the PHP error log and referral.php URL.');
   }
   if(!response.ok||!result.success)throw new Error(result.message||'Unable to create referral.');

   // Keep the counselor informed: show the success confirmation before navigating.
   modal.classList.add('is-closing');
   window.setTimeout(()=>{
    modal.classList.remove('is-open','is-closing');
    modal.setAttribute('aria-hidden','true');
    const saved=document.getElementById('referralSavedModal');
    saved.classList.add('is-visible');
    saved.setAttribute('aria-hidden','false');
    window.setTimeout(()=>{
     const transition=document.getElementById('referralPageTransition');
     transition.classList.add('is-visible');
     transition.setAttribute('aria-hidden','false');
     window.setTimeout(()=>window.location.assign('referral.php?created=1'),600);
    },2100);
   },220);
  }catch(err){
   error.textContent=err.message||'Unable to create referral. Please try again.';
   error.hidden=false;
   submit.disabled=false;submit.classList.remove('is-saving');submit.textContent='Create Referral';
  }
 });
})();
</script>
<?php endif; ?>

<script>
/* Assessment responses: presentation-only dialog. Does not modify answers or review state. */
(function () {
    'use strict';
    const dialog = document.getElementById('needsAssessmentResponsesDialog');
    const opener = document.getElementById('openNeedsAssessmentResponses');
    const closeTop = document.getElementById('closeNeedsAssessmentResponses');
    const closeBottom = document.getElementById('closeNeedsAssessmentResponsesFooter');
    if (!dialog || !opener || typeof dialog.showModal !== 'function') return;

    let originalOverflow = '';
    let isOpening = false;
    function openResponses() {
        if (dialog.open || isOpening) return;
        isOpening = true;
        originalOverflow = document.body.style.overflow;
        dialog.showModal();
        document.body.style.overflow = 'hidden';
        dialog.querySelector('.va-responses-dialog-body').scrollTop = 0;
        closeTop?.focus();
        isOpening = false;
    }
    function closeResponses() {
        if (dialog.open) dialog.close();
    }
    opener.addEventListener('click', openResponses);
    closeTop?.addEventListener('click', closeResponses);
    closeBottom?.addEventListener('click', closeResponses);
    dialog.addEventListener('click', function (event) {
        // Clicking outside the dialog card (on its backdrop area) closes it.
        if (event.target === dialog) closeResponses();
    });
    dialog.addEventListener('close', function () {
        document.body.style.overflow = originalOverflow;
        if (opener.isConnected) opener.focus({preventScroll: true});
    });
})();
</script>

<script>
/* Presentation-only: the Assessment Queue remains the parent navigation section. */
(function () {
    const link = document.querySelector('#counselorSidebar .counselor-nav-link[href="assessment_queue.php"]');
    if (link) { link.classList.add('is-active'); link.setAttribute('aria-current', 'location'); }
})();
</script>
<script src="../assets/js/counselor/counselor-layout.js?v=<?= (int) (@filemtime(__DIR__ . '/../assets/js/counselor/counselor-layout.js') ?: 0) ?>" defer></script>
</body>
</html>