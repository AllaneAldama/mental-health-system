<?php

session_start();

require_once "../config/database.php";

/** @var mysqli $conn */

if (!isset($_SESSION['student_number'])) {

    header("Location: student_information.php");
    exit();

}

$sql = "SELECT *
        FROM question_data
        WHERE assessment_type = 'GAD-7'
        ORDER BY question_number ASC";

$result = mysqli_query($conn, $sql);

if (!$result) {

    die(mysqli_error($conn));

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>GAD-7 Assessment</title>

<link rel="stylesheet" href="../assets/css/student.css">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

</head>

<body class="assessment-page">

<div class="assessment-wrapper">

    <div class="progress-wrapper">

        <span class="progress-text">
            Step 5 of 6
        </span>

        <div class="progress-bar">

            <div class="progress-fill" style="width:83.3%;"></div>

        </div>

    </div>

    <div class="assessment-card">

        <h1>GAD-7 Assessment</h1>

        <p class="assessment-description">

            Over the last <strong>2 weeks</strong>, how often have you been bothered by the following problems?

        </p>

        <form action="process_gad7.php" method="POST">

        <?php

        $number = 1;

        while($row = mysqli_fetch_assoc($result)){

        ?>

        <div class="question-card">

            <h3>

                <?php echo $number.". ".$row['question_text']; ?>

            </h3>

            <div class="option-group">

                <label class="option-item">

                    <input type="radio"
                           name="question_<?php echo $row['question_id']; ?>"
                           value="0"
                           required>

                    <span>Not at all</span>

                </label>

                <label class="option-item">

                    <input type="radio"
                           name="question_<?php echo $row['question_id']; ?>"
                           value="1">

                    <span>Several days</span>

                </label>

                <label class="option-item">

                    <input type="radio"
                           name="question_<?php echo $row['question_id']; ?>"
                           value="2">

                    <span>More than half the days</span>

                </label>

                <label class="option-item">

                    <input type="radio"
                           name="question_<?php echo $row['question_id']; ?>"
                           value="3">

                    <span>Nearly every day</span>

                </label>

            </div>

        </div>

        <?php

        $number++;

        }

        ?>

        <button type="submit" class="continue-btn">

            Submit Assessment →

        </button>

        </form>

    </div>

</div>

</body>

</html>