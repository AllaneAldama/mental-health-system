
<?php
require_once __DIR__ . '/session.php';

if (!isset($_SESSION['student_number'])) {
    header("Location: student_information.php");
    exit();
}

$alias = htmlspecialchars(
    $_SESSION['alias'] ?? 'Student',
    ENT_QUOTES,
    'UTF-8'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Assessment Guide | PTC-GCVEO</title>

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/student.css">
</head>

<body class="guide-page">

<main class="guide-wrapper">

    <div class="progress-wrapper">
        <span class="progress-text">Step 3 of 9</span>

        <div class="progress-bar">
            <div class="progress-fill progress-three"></div>
        </div>
    </div>

    <section class="guide-card">
        
        <a href="student_information.php"
        class="guide-back-btn"
        aria-label="Back to Student Information"
        title="Back">
            <span aria-hidden="true">‹</span>
        </a>

        <header class="guide-welcome">
            <span class="guide-eyebrow">PTC-GCVEO</span>

            <h1>Assessment Guide</h1>

            <p class="guide-greeting">
                Welcome, <strong><?= $alias ?></strong>!
            </p>
        </header>

        <div class="guide-message">
            <h2>You're ready to begin!</h2>

            <p>
                Before starting the Needs Assessment Survey, take a moment
                to review these simple reminders. They will help you answer
                each statement with confidence.
            </p>
        </div>

        <h3 class="guide-section-title">
            <span aria-hidden="true">📝</span>
            How to answer
        </h3>

        <div class="guide-list">

            <article class="guide-item">
                <span class="guide-number">01</span>

                <div>
                    <h4>Read carefully</h4>
                    <p>
                        Take your time to understand each statement
                        before answering.
                    </p>
                </div>
            </article>

            <article class="guide-item">
                <span class="guide-number">02</span>

                <div>
                    <h4>Choose your level of agreement</h4>
                    <p>
                        Select the number from 1 to 5 that best reflects
                        how much you agree or disagree with the statement.
                    </p>
                </div>
            </article>

            <article class="guide-item">
                <span class="guide-number">03</span>

                <div>
                    <h4>There are no right or wrong answers</h4>
                    <p>
                        Answer based on your own experiences
                        and point of view.
                    </p>
                </div>
            </article>

            <article class="guide-item">
                <span class="guide-number">04</span>

                <div>
                    <h4>Your answers matter</h4>
                    <p>
                        Your responses will help GCVEO understand student
                        needs and improve its services.
                    </p>
                </div>
            </article>

        </div>

        <section class="guide-scale">

            <div class="guide-scale-heading">
                <span aria-hidden="true">📊</span>

                <div>
                    <h3>Response Scale</h3>
                    <p>
                        Use these options for statements 1–10 in each category.
                    </p>
                </div>
            </div>

            <div class="guide-scale-list">

                <div class="guide-scale-option">
                    <span>5</span>
                    <p>Strongly Agree</p>
                </div>

                <div class="guide-scale-option">
                    <span>4</span>
                    <p>Agree</p>
                </div>

                <div class="guide-scale-option">
                    <span>3</span>
                    <p>Neither Agree nor Disagree</p>
                </div>

                <div class="guide-scale-option">
                    <span>2</span>
                    <p>Disagree</p>
                </div>

                <div class="guide-scale-option">
                    <span>1</span>
                    <p>Strongly Disagree</p>
                </div>

            </div>
        </section>

        <aside class="guide-reminder">
            <span aria-hidden="true">💡</span>

            <p>
                <strong>One last reminder:</strong>
                Please answer honestly. The written-response question
                at the end of each category is optional.
            </p>
        </aside>

        <a href="needs_assessment.php" class="guide-start-btn">
            <span>Start Needs Assessment</span>
            <span aria-hidden="true">→</span>
        </a>

        <p class="guide-footer">
            Take your time. Your responses help GCVEO better understand
            student needs.
        </p>

    </section>

</main>

</body>
</html>
