
<?php
require_once __DIR__ . '/session.php';

if (!isset($_SESSION['assessment_id'])) {
    header("Location: welcome.php");
    exit();
}

$assessmentId = (int) $_SESSION['assessment_id'];

// Read the email delivery status from the submission process.
$emailStatus = $_SESSION['confirmation_email_status'] ?? 'unknown';

$emailMessage = '';
$emailHeading = 'Confirmation Email';

switch ($emailStatus) {
    case 'sent':
        $emailHeading = 'Confirmation Email Sent';
        $emailMessage = 'A confirmation email has been sent to your registered email address.';
        break;

    case 'failed':
        $emailHeading = 'Email Confirmation';
        $emailMessage = 'Your assessment was submitted successfully, but we could not send your confirmation email. Please contact the Guidance Office if you need assistance.';
        break;

    case 'already_submitted':
        $emailHeading = 'Previous Submission';
        $emailMessage = 'Your assessment was previously submitted. Please check your registered email for any confirmation message.';
        break;

    case 'pending':
    case 'sending':
        $emailHeading = 'Confirmation Email';
        $emailMessage = 'Your assessment has been submitted. Your confirmation email is being processed.';
        break;

    default:
        $emailHeading = 'Confirmation Email';
        $emailMessage = 'Please check your registered email address for your assessment confirmation message.';
        break;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Thank You | PTC-GCVEO</title>

    <link rel="stylesheet" href="../assets/students/css/student-base.css">
    <link rel="stylesheet" href="../assets/students/css/thank-you.css">

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet"
    >
</head>

<body class="thankyou-page">
    <main class="thankyou-container">
        <div class="check-circle" aria-hidden="true">✓</div>

        <h1>Thank you!</h1>
        <h2>Your assessment has been submitted.</h2>

        <div class="heart-divider" aria-hidden="true">
            <span>❤</span>
        </div>

        <p class="thankyou-text">
            Your responses are important to us.
            A Guidance Counselor will review your assessment
            and send you an update once the review is completed.
        </p>

        <!-- Confirmation Email Notice -->
        <div class="thankyou-email-notice">
        <div class="email-notice-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"
                width="26"
                height="26"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
                stroke-linecap="round"
                stroke-linejoin="round">
                <rect x="3" y="5" width="18" height="14" rx="2"/>
                <path d="m3 7 9 6 9-6"/>
            </svg>
        </div>


            <div class="email-notice-content">
                <h3><?= htmlspecialchars($emailHeading) ?></h3>
                <p><?= htmlspecialchars($emailMessage) ?></p>
            </div>
        </div>

        <div class="thankyou-reminder">
        <div class="mail-circle" aria-hidden="true">
            <svg viewBox="0 0 24 24"
                width="27"
                height="27"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
                stroke-linejoin="round">
                <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/>
                <path d="m9 12 2 2 4-4"/>
            </svg>
        </div>

            <div>
                <h3>Please remember</h3>
                <p>
                    Your responses are confidential and securely
                    submitted to the Guidance Office.
                </p>
            </div>
        </div>

        <a href="welcome.php" class="return-btn">
            Return to Home
        </a>
    </main>
</body>
</html>