```php
<?php
require_once __DIR__ . '/session.php';

if (!isset($_SESSION['student_number'])) {
    header("Location: student_information.php");
    exit();
}

require_once "../config/database.php";

/** @var mysqli $conn */

$sql = "SELECT question_id
        FROM question_data
        WHERE assessment_type = 'Academic Development'
          AND question_number BETWEEN 1 AND 10
        ORDER BY question_number ASC";

$result = mysqli_query($conn, $sql);

if (!$result) {
    http_response_code(500);
    exit("Unable to validate assessment questions.");
}

$expected_ids = [];

while ($row = mysqli_fetch_assoc($result)) {
    $expected_ids[] = (int) $row['question_id'];
}

if (count($expected_ids) !== 10) {
    http_response_code(500);
    exit("The Academic Development questionnaire is incomplete.");
}

$answers = [];

foreach ($expected_ids as $question_id) {
    $key = "question_" . $question_id;

    if (
        !isset($_POST[$key]) ||
        !is_scalar($_POST[$key]) ||
        !in_array((string) $_POST[$key], ['1', '2', '3', '4', '5'], true)
    ) {
        http_response_code(400);
        exit("Please answer all questions using the provided scale.");
    }

    $answers[$question_id] = (int) $_POST[$key];
}

$_SESSION['needs_assessment_answers']['Academic Development'] = $answers;

header("Location: career_development.php");
exit();
?>