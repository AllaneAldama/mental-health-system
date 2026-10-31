<?php
require_once __DIR__ . '/session.php';

$errors = $_SESSION['errors'] ?? [];
$old = $_SESSION['old'] ?? [];

// If there are no validation-error values,
// show the student's existing session information.
if (empty($old) && isset($_SESSION['user_id'])) {
    $old = [
        'alias' => $_SESSION['alias'] ?? '',
        'fullname' => $_SESSION['fullname'] ?? '',
        'student_number' => $_SESSION['student_number'] ?? '',
        'program' => $_SESSION['program'] ?? '',
        'year_level' => $_SESSION['year_level'] ?? '',
        'email' => $_SESSION['email'] ?? ''
    ];
}

// Clear one-time validation messages.
unset($_SESSION['errors'], $_SESSION['old']);
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Student Information</title>

<link rel="stylesheet" href="../assets/css/student.css">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

</head>

<body class="student-info-page">

<div class="student-wrapper">

    <div class="progress-wrapper">

        <span class="progress-text">
            Step 2 of 6
        </span>

        <div class="progress-bar">

            <div class="progress-fill progress-two"></div>

        </div>

    </div>

    <div class="student-card">
    
        <a href="disclaimer.php"
        class="student-back-btn"
        aria-label="Back to Disclaimer"
        title="Back">
        <span aria-hidden="true">‹</span>
        </a>

    

        <h1>Student Information</h1>


        <p class="student-intro">
            Please provide the following information before starting your assessment.
        </p>

        <!-- Error message container: dito lalabas ang animated error -->
        <div
            class="duplicate-error"
            id="duplicateError"
            role="alert"
            aria-live="polite"
            <?= isset($errors['general']) ? '' : 'hidden' ?>
        >
            <span class="error-icon" aria-hidden="true">!</span>

            <div class="error-content">
                <strong class="error-title">
                    Student Information Already Registered
                </strong>

                <p id="duplicateErrorMessage"><?=
                    htmlspecialchars(
                        $errors['general'] ??
                        'This student number or email is already registered.'
                    )
                ?></p>

                <p class="error-help">
                    Please check your information or use your registered
                    details. If you need assistance, contact the Guidance Office.
                </p>
            </div>
        </div>

        <form
            action="process_student_information.php"
            method="POST"
            id="studentInfoForm"
        >

            <div class="form-group">

                <label>Student Alias</label>

              <input
               type="text"
               name="alias"
               value="<?= htmlspecialchars($old['alias'] ?? '') ?>"
               required>

            </div>

            <div class="form-group">

            <label>Full Name</label>

            <input
            type="text"
            name="fullname"
            value="<?= htmlspecialchars($old['fullname'] ?? '') ?>"
            required>

            </div>

            <div class="form-group">

                <label>Student Number</label>

            <input
            type="text"
            name="student_number"
            value="<?= htmlspecialchars($old['student_number'] ?? '') ?>"
            class="<?= isset($errors['student_number']) ? 'input-error' : '' ?>"
            required>

            <?php if(isset($errors['student_number'])): ?>

            <p class="field-error">
                <?= htmlspecialchars($errors['student_number']) ?>
            </p>

            <?php endif; ?>

            </div>

            <div class="form-row">

                <div class="form-group">

                    <label>Program</label>

                    <select name="program" required>

                        <option value="">Select Program</option>

                        <option value="BSIT"
                        <?= (($old['program'] ?? '') == 'BSIT') ? 'selected' : '' ?>>
                        BSIT
                        </option>
                        <option value="BSBA"
                        <?= (($old['program'] ?? '') == 'BSBA') ? 'selected' : '' ?>>
                        BSBA
                        </option>
                        <option value="BSED"
                        <?= (($old['program'] ?? '') == 'BSED') ? 'selected' : '' ?>>
                        BSED
                        </option>
                    
                    </select>

                </div>

                <div class="form-group">

                    <label>Year Level</label>

                    <select name="year_level" required>

                        <option value="">Select Year</option>

                        <option value="1st Year"
                        <?= (($old['year_level'] ?? '') == '1st Year') ? 'selected' : '' ?>>
                        1st Year
                        </option>
                        <option value="2nd Year"
                        <?= (($old['year_level'] ?? '') == '2nd Year') ? 'selected' : '' ?>>
                        2nd Year
                        </option>
                        <option value="3rd Year"
                        <?= (($old['year_level'] ?? '') == '3rd Year') ? 'selected' : '' ?>>
                        3rd Year
                        </option>
                        <option value="4th Year"
                        <?= (($old['year_level'] ?? '') == '4th Year') ? 'selected' : '' ?>>
                        4th Year
                        </option>

                    </select>

                </div>

            </div>

            <div class="form-group">

                <label>Email Address</label>

            <input
            type="email"
            name="email"
            value="<?= htmlspecialchars($old['email'] ?? '') ?>"
            class="<?= isset($errors['email']) ? 'input-error' : '' ?>"
            required>

            <?php if(isset($errors['email'])): ?>

            <p class="field-error">
                <?= htmlspecialchars($errors['email']) ?>
            </p>

            <?php endif; ?>

            </div>

            <button class="next-btn">

                Next →

            </button>

        </form>

    </div>

</div>


<script>
const form = document.getElementById('studentInfoForm');
const errorBox = document.getElementById('duplicateError');
const errorMessage = document.getElementById('duplicateErrorMessage');
const nextButton = form.querySelector('button[type="submit"], button:not([type])');

let checking = false;

form.addEventListener('submit', async function (event) {
    event.preventDefault();

    if (checking) return;

    // Let the browser handle required fields and email format.
    if (!form.reportValidity()) return;

    checking = true;
    nextButton.disabled = true;

    try {
        const formData = new FormData(form);

        const response = await fetch('check_student_information.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (!response.ok) {
            throw new Error('Unable to check student information.');
        }

        const result = await response.json();

        if (result.error) {
            throw new Error('Unable to check student information.');
        }

        if (!result.available) {
            errorMessage.textContent =
                'This student number or email is already registered.';

            errorBox.hidden = false;
            errorBox.classList.remove('error-show');

            // Restart the entrance animation.
            void errorBox.offsetWidth;
            errorBox.classList.add('error-show');

            errorBox.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });

            return;
        }

        // No duplicate found: continue with the existing PHP process.
        form.submit();

    } catch (error) {
        // Don't block a legitimate submission just because the
        // pre-check endpoint is unavailable. The PHP process still
        // performs the authoritative duplicate check.
        form.submit();

    } finally {
        checking = false;
        nextButton.disabled = false;
    }
});
</script>


</body>

</html>