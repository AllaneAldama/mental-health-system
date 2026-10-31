```php
<?php
require_once __DIR__ . '/session.php';
require_once "../config/database.php";

/** @var mysqli $conn */

if (!isset($_SESSION['student_number'])) {
    header("Location: student_information.php");
    exit();
}

$sql = "SELECT question_id, question_number, question_text
        FROM question_data
        WHERE assessment_type = 'Academic Development'
          AND question_number BETWEEN 1 AND 10
        ORDER BY question_number ASC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Unable to load assessment questions.");
}

$questions = [];

while ($row = mysqli_fetch_assoc($result)) {
    $questions[] = $row;
}

if (count($questions) !== 10) {
    die("The Academic Development questionnaire is incomplete. Please contact the system administrator.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Academic Development</title>

    <link rel="stylesheet" href="../assets/css/student.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="assessment-page">

<div class="assessment-wrapper">

    <div class="progress-wrapper">
        <span class="progress-text">Step 5 of 11</span>

        <div class="progress-bar">
            <div class="progress-fill" style="width:45.45%;"></div>
        </div>
    </div>

    <div class="assessment-card">

        <h1>Academic Development</h1>

        <p class="assessment-description">
            Read each statement carefully and indicate the extent to which
            you agree or disagree with it. Choose the number that best
            reflects your response. There are no right or wrong answers.
        </p>

        <p>
            <strong>Scale:</strong>
            5 - Strongly Agree,
            4 - Agree,
            3 - Neither Agree or Disagree,
            2 - Disagree,
            1 - Strongly Disagree
        </p>

        <form action="process_academic_development.php" method="POST">

            <?php foreach ($questions as $question): ?>

                <div class="question-card">

                    <h3>
                        <?php
                        echo (int) $question['question_number'] . ". "
                            . htmlspecialchars($question['question_text'], ENT_QUOTES, 'UTF-8');
                        ?>
                    </h3>

                    <div class="option-group">

                        <?php
                        $options = [
                            5 => 'Strongly Agree',
                            4 => 'Agree',
                            3 => 'Neither Agree or Disagree',
                            2 => 'Disagree',
                            1 => 'Strongly Disagree'
                        ];
                        ?>

                        <?php foreach ($options as $score => $label): ?>

                            <label class="option-item">
                                <input
                                    type="radio"
                                    name="question_<?php echo (int) $question['question_id']; ?>"
                                    value="<?php echo $score; ?>"
                                    required
                                >

                                <span>
                                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </label>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php endforeach; ?>

            <button type="submit" class="continue-btn">
                Continue →
            </button>

        </form>

    </div>

</div>

</body>
</html>