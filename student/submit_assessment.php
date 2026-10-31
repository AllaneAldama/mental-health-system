
<?php
require_once __DIR__ . '/session.php';

if (
    !isset($_SESSION['user_id'], $_SESSION['student_number']) ||
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {
    header("Location: student_information.php");
    exit();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';

$categories = [
    'Academic Development',
    'Career Development',
    'Personal Development',
    'Social Development',
    'Spiritual Development'
];

$userId = (int) $_SESSION['user_id'];
$allAnswers = $_SESSION['needs_assessment_answers'] ?? [];

// Verify that all five categories have 11 answers.
foreach ($categories as $category) {
    if (
        !isset($allAnswers[$category]) ||
        !is_array($allAnswers[$category]) ||
        count($allAnswers[$category]) !== 11
    ) {
        header("Location: needs_assessment.php");
        exit();
    }
}

// Load the expected survey questions.
$stmt = $conn->prepare(
    "SELECT question_id, assessment_type, question_number
     FROM question_data
     WHERE assessment_type IN (
         'Academic Development',
         'Career Development',
         'Personal Development',
         'Social Development',
         'Spiritual Development'
     )
     ORDER BY assessment_type, question_number"
);

if (!$stmt) {
    error_log($conn->error);
    exit("Unable to verify the survey questions.");
}

if (!$stmt->execute()) {
    error_log($stmt->error);
    $stmt->close();
    exit("Unable to verify the survey questions.");
}

$result = $stmt->get_result();
$questions = [];

while ($row = $result->fetch_assoc()) {
    $questions[(int) $row['question_id']] = [
        'category' => $row['assessment_type'],
        'number' => (int) $row['question_number']
    ];
}

$stmt->close();

if (count($questions) !== 55) {
    exit("The survey question configuration is incomplete.");
}

// Validate all answers before saving.
$validatedAnswers = [];

foreach ($categories as $category) {
    for ($number = 1; $number <= 11; $number++) {
        $matches = [];

        foreach ($questions as $questionId => $question) {
            if (
                $question['category'] === $category &&
                $question['number'] === $number
            ) {
                $matches[] = $questionId;
            }
        }

        if (count($matches) !== 1) {
            exit("A survey question is missing or duplicated.");
        }

        $questionId = $matches[0];

        if (!isset($allAnswers[$category][$questionId])) {
            exit("Some answers are missing. Please review your survey.");
        }

        $answer = $allAnswers[$category][$questionId];

        if ($number <= 10) {
            $score = $answer['answer_score'] ?? null;

            if (
                !is_numeric($score) ||
                (int) $score < 1 ||
                (int) $score > 5
            ) {
                exit("An invalid rating was found.");
            }

            $validatedAnswers[] = [
                'question_id' => $questionId,
                'score' => (int) $score,
                'text' => null
            ];

        } else {
            $text = $answer['answer_text'] ?? null;

            if (
                $text !== null &&
                (!is_string($text) || strlen($text) > 5000)
            ) {
                exit("The written response is invalid.");
            }

            $validatedAnswers[] = [
                'question_id' => $questionId,
                'score' => null,
                'text' => $text
            ];
        }
    }
}

if (count($validatedAnswers) !== 55) {
    exit("The assessment must contain all 55 answers.");
}

// Start an atomic database transaction.
if (!$conn->begin_transaction()) {
    error_log($conn->error);
    exit("Unable to start submission. Please try again.");
}

try {

    // Lock student record and retrieve required information.
    $stmt = $conn->prepare(
        "SELECT user_id, fullname, alias, email
         FROM user_data
         WHERE user_id = ?
           AND student_number = ?
           AND role_type = 'student'
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $studentNumber = $_SESSION['student_number'];

    $stmt->bind_param("is", $userId, $studentNumber);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) {
        throw new Exception("Student account verification failed.");
    }

    // Use alias instead of full name for the confirmation email.
    $studentAlias = trim($student['alias'] ?? '');
    $studentEmail = trim($student['email'] ?? '');

    if ($studentAlias === '') {
        $studentAlias = 'Student';
    }

    // Check existing Needs Assessment submission.
    $stmt = $conn->prepare(
        "SELECT assessment_id
         FROM assessment_data
         WHERE user_id = ?
           AND assessment_type = 'Needs Assessment'
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i", $userId);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {

        $existingId = (int) $existing['assessment_id'];

        if (!$conn->commit()) {
            throw new Exception($conn->error);
        }

        $_SESSION['assessment_id'] = $existingId;
        $_SESSION['confirmation_email_status'] = 'already_submitted';

        unset(
            $_SESSION['needs_assessment_answers'],
            $_SESSION['old'],
            $_SESSION['errors'],
            $_SESSION['fullname'],
            $_SESSION['alias'],
            $_SESSION['student_number'],
            $_SESSION['program'],
            $_SESSION['year_level'],
            $_SESSION['email'],
            $_SESSION['user_id']
        );

        header("Location: thank_you.php");
        exit();
    }

    // Create a new Needs Assessment record.
    $stmt = $conn->prepare(
        "INSERT INTO assessment_data
            (user_id, assessment_type, phq9_score, gad7_score,
             risk_level, status)
         VALUES (?, 'Needs Assessment', NULL, NULL, NULL, 'Pending')"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i", $userId);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $assessmentId = (int) $conn->insert_id;
    $stmt->close();

    // Save all 55 responses.
    $stmt = $conn->prepare(
        "INSERT INTO response_data
            (assessment_id, question_id, answer_score, answer_text)
         VALUES (?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    foreach ($validatedAnswers as $answer) {

        $questionId = (int) $answer['question_id'];
        $score = $answer['score'];
        $text = $answer['text'];

        $stmt->bind_param(
            "iiis",
            $assessmentId,
            $questionId,
            $score,
            $text
        );

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
    }

    $stmt->close();

    // Commit database changes before sending email.
    if (!$conn->commit()) {
        throw new Exception($conn->error);
    }

    $_SESSION['assessment_id'] = $assessmentId;

    // Default email status.
    $_SESSION['confirmation_email_status'] = 'failed';

    // Send confirmation email after successful submission.
    if (filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {

        $subject = "PTC WELLNESS - Assessment Submission Confirmation";

        // Plain-text fallback.
        $message =
            "Hello " . $studentAlias . ",\n\n" .
            "Your PTC WELLNESS Needs Assessment has been successfully submitted.\n\n" .
            "Your submission is currently pending review by the Guidance Office.\n\n" .
            "Please wait for further instructions from the Guidance Office.\n\n" .
            "Thank you,\n" .
            "PTC WELLNESS\n" .
            "Guidance Office";

        // Escape alias before inserting it into HTML.
        $safeAlias = htmlspecialchars(
            $studentAlias,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        // Path to the existing PTC WELLNESS logo.
        $logoPath = __DIR__ . '/../assets/images/brainalter.png';

        // HTML email template.
        $htmlMessage = '
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>PTC WELLNESS - Submission Confirmation</title>
        </head>

        <body style="
            margin:0;
            padding:0;
            background-color:#eef3ec;
            font-family:Arial,Helvetica,sans-serif;
            color:#344238;
        ">

        <table role="presentation"
            width="100%"
            cellpadding="0"
            cellspacing="0"
            border="0"
            style="width:100%;background-color:#eef3ec;padding:32px 12px;">

            <tr>
                <td align="center">

                    <table role="presentation"
                        width="100%"
                        cellpadding="0"
                        cellspacing="0"
                        border="0"
                        style="
                            width:100%;
                            max-width:620px;
                            background-color:#ffffff;
                            border-radius:16px;
                            overflow:hidden;
                        ">

                        <!-- Branded Header -->
                        <tr>
                            <td style="
                                background-color:#416D48;
                                padding:25px 24px;
                                color:#ffffff;
                            ">

                                <table role="presentation"
                                    width="100%"
                                    cellpadding="0"
                                    cellspacing="0"
                                    border="0">
                                    <tr>
                                        <td width="100" valign="middle" align="center"
                                            style="width:100px;padding-right:16px;">

                                            <table role="presentation"
                                                cellpadding="0"
                                                cellspacing="0"
                                                border="0"
                                                style="background-color:#ffffff;border-radius:14px;">
                                                <tr>
                                                    <td align="center" valign="middle"
                                                        style="padding:5px;border-radius:14px;">
                                                        <img
                                                            src="cid:ptc_wellness_logo"
                                                            alt="PTC WELLNESS Logo"
                                                            width="86"
                                                            style="
                                                                display:block;
                                                                width:86px;
                                                                max-width:86px;
                                                                height:auto;
                                                                border:0;
                                                            "
                                                        >
                                                    </td>
                                                </tr>
                                            </table>

                                        </td>

                                        <td valign="middle" align="left">
                                            <h1 style="
                                                margin:0;
                                                font-family:Arial,sans-serif;
                                                font-size:25px;
                                                line-height:1.2;
                                                font-weight:700;
                                                letter-spacing:0.4px;
                                                color:#ffffff;
                                            ">
                                                PTC WELLNESS
                                            </h1>

                                            <p style="
                                                margin:8px 0 0;
                                                font-size:11px;
                                                line-height:1.5;
                                                font-weight:bold;
                                                letter-spacing:1.3px;
                                                color:#e6f0e4;
                                            ">
                                                PATEROS TECHNOLOGICAL COLLEGE
                                            </p>

                                            <p style="
                                                margin:3px 0 0;
                                                font-size:12px;
                                                line-height:1.5;
                                                color:#e6f0e4;
                                            ">
                                                GUIDANCE OFFICE
                                            </p>
                                        </td>
                                    </tr>
                                </table>

                                <table role="presentation"
                                    width="100%"
                                    cellpadding="0"
                                    cellspacing="0"
                                    border="0"
                                    style="margin-top:22px;">
                                    <tr>
                                        <td style="
                                            border-top:1px solid #78977b;
                                            padding-top:18px;
                                        ">
                                            <p style="
                                                margin:0 0 7px;
                                                font-size:10px;
                                                line-height:1.4;
                                                font-weight:bold;
                                                letter-spacing:1.5px;
                                                color:#dcebd9;
                                            ">
                                                SUBMISSION SUCCESSFUL
                                            </p>

                                            <h2 style="
                                                margin:0;
                                                font-family:Arial,sans-serif;
                                                font-size:25px;
                                                line-height:1.3;
                                                font-weight:700;
                                                color:#ffffff;
                                            ">
                                                Assessment Submitted!
                                            </h2>

                                            <p style="
                                                margin:7px 0 0;
                                                font-size:14px;
                                                line-height:1.6;
                                                color:#e8f1e6;
                                            ">
                                                Your well-being matters.
                                            </p>
                                        </td>
                                    </tr>
                                </table>

                            </td>
                        </tr>

                        <!-- Main Content -->
                        <tr>
                            <td style="padding:34px 32px 30px;">

                                <h2 style="
                                    margin:0 0 15px;
                                    font-family:Arial,sans-serif;
                                    font-size:23px;
                                    line-height:1.35;
                                    font-weight:700;
                                    color:#304d32;
                                ">
                                    Hello, ' . $safeAlias . '!
                                </h2>

                                <p style="
                                    margin:0 0 22px;
                                    font-size:15px;
                                    line-height:1.8;
                                    color:#536157;
                                ">
                                    Thank you for completing your
                                    PTC WELLNESS Needs Assessment.
                                    Your responses have been successfully
                                    submitted to the system.
                                </p>

                                <!-- Confirmation Status -->
                                <table role="presentation"
                                    width="100%"
                                    cellpadding="0"
                                    cellspacing="0"
                                    border="0"
                                    style="
                                        background-color:#edf5eb;
                                        border:1px solid #d8e8d5;
                                        border-radius:10px;
                                    ">

                                    <tr>
                                        <td style="padding:20px;">

                                            <p style="
                                                margin:0 0 9px;
                                                font-size:16px;
                                                font-weight:bold;
                                                color:#3d7540;
                                            ">
                                                &#10003; Submission Confirmed
                                            </p>

                                            <p style="
                                                margin:0;
                                                font-size:14px;
                                                line-height:1.7;
                                                color:#48634a;
                                            ">
                                                Your assessment has been
                                                recorded and is currently
                                                pending review by the
                                                Guidance Office.
                                            </p>

                                        </td>
                                    </tr>
                                </table>

                                <p style="
                                    margin:24px 0 0;
                                    font-size:14px;
                                    line-height:1.7;
                                    color:#555555;
                                ">
                                    Please wait for further instructions
                                    from the Guidance Office.
                                </p>

                                <p style="
                                    margin:25px 0 0;
                                    font-size:14px;
                                    line-height:1.7;
                                    color:#555555;
                                ">
                                    Thank you for taking the time to
                                    complete the assessment.
                                </p>

                                <p style="
                                    margin:20px 0 0;
                                    font-size:14px;
                                    line-height:1.7;
                                    color:#304d32;
                                    font-weight:bold;
                                ">
                                    PTC WELLNESS<br>
                                    Guidance Office
                                </p>

                            </td>
                        </tr>

                        <!-- Footer -->
                        <tr>
                            <td align="center"
                                style="
                                    background-color:#f7f9f6;
                                    border-top:1px solid #e5ebe3;
                                    padding:20px;
                                ">

                                <p style="
                                    margin:0;
                                    font-size:12px;
                                    line-height:1.6;
                                    color:#777777;
                                ">
                                    This is an automated confirmation
                                    email from PTC WELLNESS.
                                </p>

                                <p style="
                                    margin:8px 0 0;
                                    font-size:12px;
                                    color:#5E8B59;
                                    font-weight:bold;
                                ">
                                    Pateros Technological College
                                </p>

                            </td>
                        </tr>

                    </table>

                </td>
            </tr>

        </table>

        </body>
        </html>
        ';

        try {

            $emailResult = sendSystemEmail(
                $studentEmail,
                $studentAlias,
                $subject,
                $message,
                $htmlMessage,
                $logoPath
            );

            if (
                is_array($emailResult) &&
                !empty($emailResult['success'])
            ) {
                $_SESSION['confirmation_email_status'] = 'sent';
            } else {
                error_log(
                    "Assessment confirmation email was not sent. Assessment ID: " .
                    $assessmentId
                );
            }

        } catch (Throwable $emailError) {

            // Email failure must not undo the saved assessment.
            error_log(
                "Confirmation email error for assessment ID " .
                $assessmentId . ": " . $emailError->getMessage()
            );
        }

    } else {

        error_log(
            "Invalid student email for assessment ID: " . $assessmentId
        );
    }

    // Clear temporary student and survey session data.
    unset(
        $_SESSION['needs_assessment_answers'],
        $_SESSION['old'],
        $_SESSION['errors'],
        $_SESSION['fullname'],
        $_SESSION['alias'],
        $_SESSION['student_number'],
        $_SESSION['program'],
        $_SESSION['year_level'],
        $_SESSION['email'],
        $_SESSION['user_id']
    );

    header("Location: thank_you.php");
    exit();

} catch (Throwable $e) {

    $conn->rollback();

    error_log(
        "Needs Assessment submission failed: " . $e->getMessage()
    );

    http_response_code(500);

    exit(
        "We couldn't complete your submission. " .
        "Please contact the guidance office before trying again."
    );
}
?>
