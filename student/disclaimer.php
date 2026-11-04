<?php
require_once __DIR__ . '/session.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Needs Assessment Survey - Disclaimer</title>

    <link rel="stylesheet" href="../assets/students/css/student-base.css">
    <link rel="stylesheet" href="../assets/students/css/disclaimer.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="disclaimer-page">

<div class="disclaimer-wrapper">

    <div class="progress-wrapper" aria-label="Assessment progress">
        <div class="progress-heading">
            <span class="progress-text">Step 1 of 6</span>
            <span class="progress-percentage">17% Complete</span>
        </div>

        <div class="progress-bar"
             role="progressbar"
             aria-label="Assessment progress"
             aria-valuemin="0"
             aria-valuemax="100"
             aria-valuenow="17">
            <div class="progress-fill"></div>
        </div>
    </div>

    <main class="disclaimer-card">
        <a href="welcome.php"
           class="student-back-btn"
           aria-label="Back to Welcome"
           title="Back">
            <span aria-hidden="true">‹</span>
        </a>

        <header class="disclaimer-header">
            <h1>Needs Assessment Survey</h1>
            <p class="disclaimer-intro">Good day!</p>
        </header>

        <section class="disclaimer-box" aria-label="Survey information">
            <div class="disclaimer-box-label">
                <span class="information-mark" aria-hidden="true">i</span>
                <span>ABOUT THIS SURVEY</span>
            </div>

            <div class="disclaimer-copy">
                <p>
                    The Needs Assessment Survey is one of the services offered by
                    PTC-Guidance, Counseling and Values Enrichment Office (GCVEO).
                    The goal of this service is to help GCVEO better understand
                    the concerns, needs, and preferences of students.
                </p>

                <p>
                    The results gathered will serve as a basis for planning and
                    improving guidance and counseling programs, services, and
                    activities that address academic, personal, social, and
                    career development.
                </p>

                <p>
                    Your responses will remain confidential and will only be used
                    for program planning and service enhancement. We encourage
                    you to answer honestly and thoughtfully.
                </p>
            </div>
        </section>

        <section class="direction-section" aria-labelledby="direction-title">
            <h2 id="direction-title" class="section-heading">DIRECTION</h2>

            <p class="direction-copy">
                Read each statement carefully and indicate the extent to which
                you agree or disagree with it. Choose the number that best
                reflects your response. There are no right or wrong answers.
            </p>
        </section>

        <section class="scale-card" aria-labelledby="scale-title">
            <h2 id="scale-title" class="scale-title">
                <span class="scale-icon" aria-hidden="true">
                    <span></span><span></span><span></span>
                </span>
                Scale
            </h2>

            <ul class="scale-list">
                <li class="scale-item">
                    <span class="scale-number">5</span>
                    <span class="scale-description">Strongly Agree</span>
                </li>
                <li class="scale-item">
                    <span class="scale-number">4</span>
                    <span class="scale-description">Agree</span>
                </li>
                <li class="scale-item">
                    <span class="scale-number">3</span>
                    <span class="scale-description">Neither Agree or Disagree</span>
                </li>
                <li class="scale-item">
                    <span class="scale-number">2</span>
                    <span class="scale-description">Disagree</span>
                </li>
                <li class="scale-item">
                    <span class="scale-number">1</span>
                    <span class="scale-description">Strongly Disagree</span>
                </li>
            </ul>
        </section>

        <label class="agree-box" for="agree">
            <input type="checkbox" id="agree">

            <span>
                I have read and understood the information above and agree to continue.
            </span>
        </label>

        <a href="student_information.php"
           id="continueBtn"
           class="continue-btn disabled"
           aria-disabled="true">
            <span>Continue</span>
            <span class="continue-arrow" aria-hidden="true">→</span>
        </a>
    </main>

</div>

<script>
const checkbox = document.getElementById("agree");
const button = document.getElementById("continueBtn");

const consentKey = "needsAssessmentDisclaimerConsent";

// Restore checkbox state kapag bumalik sa page
checkbox.checked = sessionStorage.getItem(consentKey) === "true";

// Update button state based on checkbox
function updateButton() {
    if (checkbox.checked) {
        button.classList.remove("disabled");
        button.setAttribute("aria-disabled", "false");
    } else {
        button.classList.add("disabled");
        button.setAttribute("aria-disabled", "true");
    }
}

updateButton();

// Save checkbox state kapag nag-check o nag-uncheck
checkbox.addEventListener("change", function () {
    sessionStorage.setItem(consentKey, this.checked);
    updateButton();
});

// Prevent continuing without consent
button.addEventListener("click", function (e) {
    if (!checkbox.checked) {
        e.preventDefault();
    }
});
</script>

</body>
</html>
