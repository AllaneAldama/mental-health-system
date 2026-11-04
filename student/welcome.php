<?php
require_once __DIR__ . '/session.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Welcome | Mental Health Assessment</title>

    <link rel="stylesheet" href="../assets/students/css/student-base.css">
    <link rel="stylesheet" href="../assets/students/css/welcome.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="welcome-page">

    <!-- DESKTOP LAYOUT: retained from the existing design -->
    <main class="welcome-container welcome-desktop">

        <section class="welcome-left">

            <span class="welcome-title">Welcome!</span>

            <h1>
                Your well-being matters.<br>
                We're here for you.
            </h1>

            <p class="welcome-description">
                This self-assessment will help us understand how you've been feeling
                and connect you with the support you may need.
            </p>

            <div class="info-card">
                <div class="info-icon" aria-hidden="true">🔒</div>
                <div>
                    <h3>Your privacy is our priority.</h3>
                    <p>
                        Your responses are confidential and will only be used
                        by the Guidance Office for assessment purposes.
                    </p>
                </div>
            </div>

            <div class="info-card">
                <div class="info-icon" aria-hidden="true">💚</div>
                <div>
                    <h3>Please answer honestly.</h3>
                    <p>
                        There are no right or wrong answers.
                        This is a safe space for you.
                    </p>
                </div>
            </div>

        </section>

        <section class="welcome-right">
            <div class="brain-wrapper">
                <img
                    src="../assets/images/brainalter.png"
                    alt="Mental Health Illustration"
                    class="brain-image">
            </div>

            <a href="disclaimer.php" class="start-btn">
                Start Assessment →
            </a>
        </section>

    </main>

    <!-- MOBILE LAYOUT: separate presentation; same content and destination -->
    <main class="welcome-mobile">

        <header class="mobile-welcome-heading">
            <span class="welcome-title">Welcome!</span>
            <h1>Your well-being matters.</h1>
            <p class="welcome-description">
                We're here to help you understand your needs and connect you
                with the support you may need.
            </p>
        </header>

        <div class="mobile-brain-wrapper">
            <img
                src="../assets/images/brainalter.png"
                alt="Mental Health Illustration"
                class="mobile-brain-image">
        </div>

        <section class="mobile-info-list" aria-label="Important information">

            <article class="mobile-info-card">
                <div class="mobile-info-icon" aria-hidden="true">🔒</div>
                <div>
                    <h2>Your privacy is our priority.</h2>
                    <p>
                        Your responses are confidential and will only be used
                        by the Guidance Office for assessment purposes.
                    </p>
                </div>
            </article>

            <article class="mobile-info-card">
                <div class="mobile-info-icon" aria-hidden="true">💚</div>
                <div>
                    <h2>Please answer honestly.</h2>
                    <p>
                        There are no right or wrong answers.
                        This is a safe space for you.
                    </p>
                </div>
            </article>

        </section>

        <a href="disclaimer.php" class="start-btn mobile-start-btn">
            Start Assessment <span aria-hidden="true">→</span>
        </a>

        <p class="mobile-footer">PTC-GCVEO</p>

    </main>

</body>
</html>
