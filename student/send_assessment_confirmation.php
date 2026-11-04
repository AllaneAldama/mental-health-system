<?php
require_once __DIR__ . '/session.php';

header('Content-Type: application/json; charset=utf-8');

function emailJson(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

function clearStudentSubmissionSession(): void {
    unset(
        $_SESSION['pending_assessment_email'],
        $_SESSION['needs_assessment_answers'],
        $_SESSION['old'],
        $_SESSION['errors'],
        $_SESSION['fullname'],
        $_SESSION['alias'],
        $_SESSION['student_number'],
        $_SESSION['program'],
        $_SESSION['year_level'],
        $_SESSION['email'],
        $_SESSION['student_id'],
        $_SESSION['user_id']
    );
}

if (
    !isset($_SESSION['student_id'], $_SESSION['student_number'], $_SESSION['assessment_id']) ||
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {
    emailJson(401, ['success' => false, 'message' => 'Your session has expired.']);
}

$pending = $_SESSION['pending_assessment_email'] ?? null;
$assessmentId = (int) $_SESSION['assessment_id'];

if (
    !is_array($pending) ||
    (int)($pending['assessment_id'] ?? 0) !== $assessmentId
) {
    if (($_SESSION['confirmation_email_status'] ?? '') === 'sent') {
        emailJson(200, ['success' => true, 'email_status' => 'sent', 'already_sent' => true]);
    }
    emailJson(409, ['success' => false, 'message' => 'There is no pending confirmation email.']);
}

$status = $_SESSION['confirmation_email_status'] ?? 'pending';
if ($status === 'sent') {
    emailJson(200, ['success' => true, 'email_status' => 'sent', 'already_sent' => true]);
}
if ($status === 'sending') {
    emailJson(409, ['success' => false, 'message' => 'The confirmation email is already being processed.']);
}
if ($status === 'failed') {
    emailJson(200, ['success' => false, 'email_status' => 'failed', 'message' => 'Your assessment was saved, but the confirmation email could not be sent.']);
}

// Claim the one permitted send attempt before releasing the PHP session lock.
$_SESSION['confirmation_email_status'] = 'sending';
$studentEmail = trim((string)($pending['student_email'] ?? ''));
$studentAlias = trim((string)($pending['student_alias'] ?? 'Student'));
session_write_close();

try {
    require_once __DIR__ . '/../config/email.php';

    if (!filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Student email address is invalid.');
    }

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

                                <!-- What Happens Next -->
                                <table role="presentation"
                                    width="100%"
                                    cellpadding="0"
                                    cellspacing="0"
                                    border="0"
                                    style="
                                        width:100%;
                                        margin-top:24px;
                                        background-color:#f7f9f6;
                                        border:1px solid #e3eadd;
                                        border-radius:10px;
                                    ">
                                    <tr>
                                        <td style="padding:20px;">
                                            <p style="
                                                margin:0 0 10px;
                                                font-size:16px;
                                                line-height:1.5;
                                                font-weight:bold;
                                                color:#304d32;
                                            ">
                                                What Happens Next?
                                            </p>

                                            <p style="
                                                margin:0 0 12px;
                                                font-size:14px;
                                                line-height:1.7;
                                                color:#555555;
                                            ">
                                                Your assessment is now awaiting review by the PTC Guidance Office.
                                                Please allow the Guidance Office sufficient time to review your responses.
                                            </p>

                                            <p style="
                                                margin:0 0 8px;
                                                font-size:14px;
                                                line-height:1.7;
                                                color:#555555;
                                            ">
                                                Once the review has been completed, you will receive a separate email
                                                notification with any further instructions, if necessary.
                                            </p>

                                            <ul style="
                                                margin:0;
                                                padding-left:20px;
                                                font-size:14px;
                                                line-height:1.8;
                                                color:#555555;
                                            ">
                                                <li>Please check your registered email address regularly for updates.</li>
                                                <li>There is no need to retake or resubmit your assessment.</li>
                                                <li>Please wait for further instructions from the Guidance Office.</li>
                                            </ul>
                                        </td>
                                    </tr>
                                </table>

                                <p style="
                                    margin:22px 0 0;
                                    font-size:14px;
                                    line-height:1.7;
                                    color:#555555;
                                ">
                                    Thank you for your patience and cooperation. We appreciate the time you have taken
                                    to share your concerns and needs with the PTC Guidance Office. Your well-being
                                    remains important to us.
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


    $emailResult = sendSystemEmail(
        $studentEmail,
        $studentAlias,
        $subject,
        $message,
        $htmlMessage,
        $logoPath
    );

    session_start();
    if (!empty($emailResult['success'])) {
        $_SESSION['confirmation_email_status'] = 'sent';
        clearStudentSubmissionSession();
        emailJson(200, ['success' => true, 'email_status' => 'sent']);
    }

    $_SESSION['confirmation_email_status'] = 'failed';
    clearStudentSubmissionSession();
    emailJson(200, [
        'success' => false,
        'email_status' => 'failed',
        'message' => 'Your assessment was saved, but the confirmation email could not be sent.'
    ]);

} catch (Throwable $e) {
    error_log('Assessment confirmation email failed for assessment ID ' . $assessmentId . ': ' . $e->getMessage());
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['confirmation_email_status'] = 'failed';
    clearStudentSubmissionSession();
    emailJson(200, [
        'success' => false,
        'email_status' => 'failed',
        'message' => 'Your assessment was saved, but the confirmation email could not be sent.'
    ]);
}
?>
