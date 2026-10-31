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
        ['user_id', 'student_id']
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
                LEFT JOIN user_data u
                    ON u.user_id = a.`{$user_col}`
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

        require_once __DIR__ . '/../config/email.php';

        $recipient_name = trim(
            (string)($assessment['student_alias'] ?? '')
        );

        if ($recipient_name === '') {
            $recipient_name = 'Student';
        }

        // Get the currently logged-in counselor's name.
        $counselor_name = trim(
            (string)($_SESSION['fullname'] ?? 'PTC Wellness Guidance Office')
        );

        // Build the shared PTC WELLNESS branded HTML email.
        $email_html = buildPTCEmailTemplate(
            $recipient_name,
            $email_message,
            'ptc_wellness_logo',
            $counselor_name
        );

        // Plain-text alternative includes the same personalized greeting and signature.
        $plain_email_message = "Dear {$recipient_name},\n\n" .
            $email_message .
            "\n\nBest regards,\n{$counselor_name}\nPTC Wellness Guidance Office";

        // Logo is stored in the project's assets/images directory.
        $email_logo_path = __DIR__ . '/../assets/images/brainalter.png';

        $send_result = sendSystemEmail(
            $recipient,
            $recipient_name,
            $subject,
            $plain_email_message,
            $email_html,
            $email_logo_path
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
                mysqli_stmt_close($update_stmt);

                // Release the review lock after successfully marking as Reviewed.
                $release_lock = mysqli_prepare(
                    $conn,
                    "DELETE FROM assessment_review_locks
                    WHERE assessment_id = ?
                    AND counselor_id = ?
                    AND lock_token = ?"
                );

                mysqli_stmt_bind_param(
                    $release_lock,
                    'iis',
                    $assessment_id,
                    $counselor_id,
                    $lock_token
                );

                mysqli_stmt_execute($release_lock);
                mysqli_stmt_close($release_lock);

                mysqli_commit($conn);

                header(
                    "Location: view_assessment.php?id=" .
                    (int)$assessment_id . "&reviewed=1"
                );
                exit();

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

$has_scheduled_appointment = false;

if (
    $assessment &&
    $is_reviewed &&
    va_table_exists($conn, 'referral_data') &&
    va_table_exists($conn, 'schedule_data') &&
    va_column_exists($conn, 'referral_data', 'assessment_id') &&
    va_column_exists($conn, 'referral_data', 'referral_id') &&
    va_column_exists($conn, 'schedule_data', 'referral_id')
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
            $referral_id_for_schedule =
                (int) $existing_referral['referral_id'];

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


$submitted_at = $date_col
    ? ($assessment[$date_col] ?? '')
    : '';

$submitted_label = $submitted_at && strtotime($submitted_at)
    ? date('M d, Y · h:i A', strtotime($submitted_at))
    : 'Not available';



$counselor_name = $_SESSION['fullname'] ?? 'Counselor';
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

    <link rel="stylesheet" href="../assets/css/counselor.css">
    <link rel="stylesheet" href="../assets/css/assessment-review.css">

    <style>
.student-overview-actions {
    display: flex;
    flex-direction: row-reverse;
    align-items: center;
    justify-content: flex-start;
    flex-wrap: wrap;
    gap: 12px;
    margin-left: auto;
    align-self: center;
}

.student-overview-actions .review-status,
.student-overview-actions .schedule-referral-top-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 48px;
    box-sizing: border-box;
    margin: 0;
    line-height: 1.4;
}

.schedule-referral-top-button {
    gap: 8px;
    white-space: nowrap;
    text-decoration: none;
}

@media (max-width: 700px) {
    .student-overview-actions {
        width: 100%;
        margin: 18px 0 0;
        justify-content: flex-start;
        align-items: center;
        flex-direction: row-reverse;
        gap: 10px;
    }
}

@media (max-width: 420px) {
    .student-overview-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .student-overview-actions .review-status,
    .student-overview-actions .schedule-referral-top-button {
        width: 100%;
    }
}

.notes-editor-wrap{border:1px solid #d5e4d0;border-radius:14px;overflow:hidden;background:#fff}
.notes-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:7px;padding:10px 12px;border-bottom:1px solid #e3ebdf;background:#f5f8f2}
.notes-toolbar button{min-width:36px;min-height:34px;padding:6px 10px;border:1px solid #d5e4d0;border-radius:8px;background:#fff;color:#285d3c;font:inherit;font-weight:600;cursor:pointer}
.notes-toolbar button:hover,.notes-toolbar button:focus-visible{background:#eaf3e5;outline:2px solid #6d9874;outline-offset:1px}
.notes-toolbar button.is-active,.notes-toolbar button[aria-pressed="true"]{background:#d7ead0;border-color:#39764d;color:#174b2e;box-shadow:inset 0 0 0 1px #39764d}
.notes-toolbar button[data-command="bold"].is-active{font-weight:900}
.notes-toolbar button[data-command="italic"].is-active{font-style:italic}
.notes-toolbar button[data-command="underline"].is-active{text-decoration:underline;text-decoration-thickness:2px}
.notes-editor{min-height:240px;max-height:70vh;overflow-y:auto;padding:16px 18px;outline:none;color:#244236;font:inherit;line-height:1.7;resize:vertical}
.notes-editor:empty::before{content:attr(data-placeholder);color:#89968d;pointer-events:none}
.notes-editor ul,.notes-editor ol{padding-left:26px}
.notes-editor p{margin:0 0 10px}
.notes-save-modal{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(17,35,25,.45)}
.notes-save-dialog{width:min(100%,420px);padding:30px;border:1px solid #dce8d6;border-radius:20px;background:#fff;box-shadow:0 24px 70px rgba(0,0,0,.2);text-align:center}
.notes-save-icon{display:grid;place-items:center;width:58px;height:58px;margin:0 auto 14px;border-radius:50%;background:#e8f5e7;color:#287345;font-size:30px;font-weight:700}
.notes-save-dialog h2{margin:0 0 8px;color:#214b35;font-size:22px}
.notes-save-dialog p{margin:0 0 20px;color:#617268}
.notes-save-dialog button{min-width:110px;padding:11px 22px;border:0;border-radius:10px;background:#39764d;color:#fff;font:inherit;font-weight:700;cursor:pointer}

.email-modal-overlay{position:fixed;inset:0;z-index:100000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(17,35,25,.48)}
.email-modal-overlay.is-open{display:flex}
.email-modal-card{width:min(100%,720px);max-height:92vh;overflow:auto;padding:30px;border:1px solid #dce8d6;border-radius:20px;background:#fff;box-shadow:0 24px 70px rgba(0,0,0,.2)}
.email-modal-card h2{margin:0 0 8px;color:#214b35;font-size:22px}
.email-modal-card p{color:#617268}
.email-modal-field{display:grid;gap:6px;margin:14px 0}
.email-modal-field label{font-weight:600;color:#244b37}
.email-modal-field input,.email-modal-field textarea{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #cbd9c8;border-radius:9px;font:inherit;color:#244236}
.email-modal-field textarea{min-height:190px;resize:vertical}
.email-modal-field select{width:100%;box-sizing:border-box;padding:12px;border:1px solid #cbd9c8;border-radius:9px;background:#fff;color:#244236;font:inherit}
.email-template-help{margin:4px 0 14px;color:#68796e;font-size:13px;line-height:1.5}
@media(max-width:600px){.email-modal-overlay{padding:12px}.email-modal-card{padding:20px;width:100%}.email-modal-field textarea{min-height:160px}}
.email-modal-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:18px}
.email-modal-actions button{padding:11px 18px;border:0;border-radius:9px;background:#39764d;color:#fff;font:inherit;font-weight:700;cursor:pointer}
.email-modal-actions .email-cancel{background:#edf2eb;color:#244b37}
.email-send-button{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:10px 16px;border:0;border-radius:10px;background:#39764d;color:#fff;font:inherit;font-weight:600;cursor:pointer}
.email-send-button:disabled{opacity:.65;cursor:wait}
            </style>
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
                <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span>
                Dashboard
            </a>

            <a class="nav-link active" href="assessment_queue.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"/><path d="m3 6 1 1 2-2m-3 7 1 1 2-2m-3 7 1 1 2-2"/></svg></span>
                Assessments Queue
            </a>

            <a class="nav-link" href="monitoring.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 12s3.2-7 9-7 9 7 9 7-3.2 7-9 7-9-7-9-7Z"/><circle cx="12" cy="12" r="3"/></svg></span>
                Monitoring
            </a>

            <a class="nav-link" href="referral.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M7 17 17 7M7 7h10v10M5 5v14h14"/></svg></span>
                Referral
            </a>

            <a class="nav-link" href="assessment_history.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
                Assessment History
            </a>

            <a class="nav-link" href="profile.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>
                Profile
            </a>

            <a class="nav-link logout" href="../auth/logout.php?portal=counselor">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="m10 17 5-5-5-5M15 12H3"/><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg></span>
                Logout
            </a>
        </nav>

        <div class="sidebar-footer">
            <strong>Well-being matters.</strong>
            <span>Supporting a healthier campus, one step at a time.</span>
        </div>
    </aside>

    <main class="main-content">
        <header class="page-header">
            <div>
                <p class="eyebrow">PTC WELLNESS · GUIDANCE OFFICE</p>
                <h1 class="page-title">Assessment Review</h1>
                <p class="page-subtitle">Review the student's responses and determine appropriate next steps.</p>
            </div>

            <div class="header-right">
                <div class="datetime-card">
                    <span id="current-date">Loading date...</span>
                    <span class="datetime-divider"></span>
                    <span id="current-time">Loading time...</span>
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
                    <span class="review-status <?= $is_reviewed ? 'review-status-done' : 'review-status-waiting' ?>">
                        <span class="queue-status-dot"></span><?= va_escape($status) ?>
                    </span>

                    <?php if (!empty($student['email'])): ?>
                    <button
                        type="button"
                        class="email-send-button"
                        id="openAssessmentEmail"
                        data-recipient="<?= va_escape($student['email']) ?>"
                        data-student-name="<?= va_escape($student['fullname'] ?: $student['name']) ?>"
                        data-counselor-name="<?= va_escape($counselor_name) ?>"
                    >Send Email</button>
                    <?php endif; ?>

                <?php if ($is_reviewed && $has_scheduled_appointment): ?>
                    <span
                        class="review-button review-button-outline schedule-referral-top-button"
                        aria-label="Appointment already scheduled"
                        aria-disabled="true"
                        style="cursor: default;"
                    >
                        <span aria-hidden="true">✓</span>
                        Scheduled
                    </span>
                <?php elseif ($is_reviewed): ?>
                    <a
                        href="referral.php?assessment_id=<?= (int)$assessment_id ?>"
                        class="review-button review-button-outline schedule-referral-top-button"
                    >
                        <span aria-hidden="true">▦</span>
                        Schedule for Referral
                    </a>
                <?php endif; ?>

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

            <section class="review-card">
                <div class="review-section-heading">
                    <div class="section-icon">☷</div>
                    <div>
                        <h2>Needs Assessment Responses</h2>
                        <p>Review each response and its associated question.</p>
                    </div>
                    <span class="response-count"><?= count($responses) ?> responses</span>
                </div>

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

        <footer class="page-footer">
            <span>PTC Wellness · Counselor Portal</span>
            <span>Confidential student information — handle with care.</span>
        </footer>
    </main>
</div>

<?php if ($assessment): ?>
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
                <small class="email-template-help">Choose a professional starting format. The student's name and counselor signature are added automatically to the email. You can edit the subject and message before sending.</small>
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

    // Messages contain the body only. The shared email layout adds
    // the personalized student greeting and counselor signature.
    const templates = {
        general: {
            subject: 'Follow-up Regarding Your PTC Wellness Assessment',
            message: 'I hope you are doing well.\n\nI am contacting you on behalf of the PTC Wellness Guidance Office following your recent Needs Assessment submission. We would like to check in and ask whether there is any support or guidance you would like to discuss.\n\nIf you would like to speak with a counselor, please reply to this email so we can coordinate an appropriate time. You may also visit the Guidance Office during its office hours.\n\nThank you for your time.'
        },
        appointment_invitation: {
            subject: 'Invitation to Arrange a Counseling Appointment | PTC Wellness',
            message: 'The PTC Wellness Guidance Office would like to invite you to arrange a counseling appointment. The purpose of the meeting is to provide an opportunity for you to discuss any concerns, questions, or support needs in a private and respectful setting.\n\nPlease reply to this email with your available days and times. Our office will coordinate with you to confirm a suitable schedule.\n\nThank you, and we look forward to hearing from you.'
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
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }
    openButton.addEventListener('click', function () {
        recipient.value = openButton.dataset.recipient || '';
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
        submit.disabled = true;
        submit.textContent = 'Sending...';
        feedback.textContent = '';
        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: new FormData(form),
                headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
                credentials: 'same-origin'
            });
            const raw = await response.text();
            let result;
            try { result = JSON.parse(raw); }
            catch (e) { throw new Error('Unexpected server response. Check the PHP error log.'); }
            if (!response.ok || !result.success) throw new Error(result.message || 'Unable to send email.');
            feedback.style.color = '#287345';
            feedback.textContent = result.message || 'Email sent successfully.';
            form.reset();
            subject.value = 'Follow-up from PTC Wellness Guidance Office';
            window.setTimeout(closeModal, 1400);
        } catch (error) {
            feedback.style.color = '#b42318';
            feedback.textContent = error.message || 'Unable to send email.';
        } finally {
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
<style>
    .assessment-live-presence {
    width: 100%;
    box-sizing: border-box;
    margin-top: 16px;
    padding: 14px 16px;
    border: 1px solid #d5e4d0;
    border-radius: 12px;
    background: #f5f9f2;
    color: #244236;
    }

    .live-presence-heading {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 9px;
    }

    .live-eye-icon {
        color: #39764d;
        font-size: 18px;
    }

    .live-connection-status {
        margin-left: auto;
        color: #68796e;
        font-size: 12px;
    }

    .assessment-live-presence p {
        margin: 8px 0 0;
        font-size: 13px;
        line-height: 1.5;
    }

    @media (max-width: 480px) {
        .live-connection-status {
            margin-left: 0;
        }
    }
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

    // Keep the viewer and review lock active.
    setInterval(function () {
        if (!isReviewed) {
            sendHeartbeat().catch(function (error) {
                console.error('Heartbeat failed:', error);
            });
        }
    }, 10000);

    // Release the viewer and lock when leaving the page.
    window.addEventListener('pagehide', releaseAssessmentView);

})();
</script>


</body>
</html>