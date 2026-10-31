
<?php
require_once __DIR__ . '/session.php';

if (!isset($_SESSION['assessment_id'])) {
    header("Location: welcome.php");
    exit();
}

$assessmentId = (int) $_SESSION['assessment_id'];
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Thank You | PTC-GCVEO</title>

    <link rel="stylesheet" href="../assets/css/student.css">

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

        <div class="thankyou-reminder">
            <div class="mail-circle" aria-hidden="true">✉</div>

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
