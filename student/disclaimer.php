<?php
require_once __DIR__ . '/session.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Needs Assessment Survey - Disclaimer</title>

    <link rel="stylesheet" href="../assets/css/student.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="disclaimer-page">

<div class="disclaimer-wrapper">

    <div class="progress-wrapper">
        <span class="progress-text">Step 1 of 6</span>

        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>
    </div>

    <div class="disclaimer-card">
        <a href="welcome.php"
        class="student-back-btn"
        aria-label="Back to Disclaimer"
        title="Back">
            <span aria-hidden="true">‹</span>
        </a>

        <h1>Needs Assessment Survey</h1>

        <p class="disclaimer-intro">
            Good day!
        </p>

        <div class="disclaimer-box">

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

        <h3>DIRECTION</h3>

        <p>
            Read each statement carefully and indicate the extent to which
            you agree or disagree with it. Choose the number that best
            reflects your response. There are no right or wrong answers.
        </p>

        <p>
            <strong>Scale:</strong><br>
            5 - Strongly Agree<br>
            4 - Agree<br>
            3 - Neither Agree or Disagree<br>
            2 - Disagree<br>
            1 - Strongly Disagree
        </p>

        <label class="agree-box">
            <input type="checkbox" id="agree">

            <span>
                I have read and understood the information above and agree to continue.
            </span>
        </label>

        <a href="student_information.php"
           id="continueBtn"
           class="continue-btn disabled">
            Continue →
        </a>

    </div>

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
    } else {
        button.classList.add("disabled");
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
