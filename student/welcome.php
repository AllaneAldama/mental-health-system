<?php
require_once __DIR__ . '/session.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Welcome | Mental Health Assessment</title>

    <link rel="stylesheet" href="../assets/css/student.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

</head>

<body class="welcome-page">

    <div class="welcome-container">

        <div class="welcome-left">

            <span class="welcome-title">
                Welcome!
            </span>

            <h1>
                Your well-being matters.<br>
                We're here for you.
            </h1>

            <p class="welcome-description">
                This self-assessment will help us understand how you've been feeling
                and connect you with the support you may need.
            </p>

            <div class="info-card">

                <div class="info-icon">
                    🔒
                </div>

                <div>

                    <h3>Your privacy is our priority.</h3>

                    <p>
                        Your responses are confidential and will only be used
                        by the Guidance Office for assessment purposes.
                    </p>

                </div>

            </div>

            <div class="info-card">

                <div class="info-icon">
                    💚
                </div>

                <div>

                    <h3>Please answer honestly.</h3>

                    <p>
                        There are no right or wrong answers.
                        This is a safe space for you.
                    </p>

                </div>

            </div>

        </div>

       <div class="welcome-right">

    <div class="brain-wrapper">

        <img
            src="../assets/images/brainalter.png"
            alt="Mental Health Illustration"
            class="brain-image">

    </div>

    <a href="disclaimer.php" class="start-btn">

        Start Assessment →

    </a>

</div>

    </div>

</body>

</html>