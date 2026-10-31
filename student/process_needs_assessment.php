
<?php
require_once __DIR__ . '/session.php';

if (!isset($_SESSION['student_number'])) {
    header("Location: student_information.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: needs_assessment.php");
    exit();
}

require_once __DIR__ . '/../config/database.php';

$categories = [
    'Academic Development',
    'Career Development',
    'Personal Development',
    'Social Development',
    'Spiritual Development'
];

// Validate the category index.
$categoryIndex = filter_input(
    INPUT_POST,
    'category_index',
    FILTER_VALIDATE_INT
);

if (
    $categoryIndex === false ||
    $categoryIndex === null ||
    $categoryIndex < 0 ||
    $categoryIndex >= count($categories)
) {
    exit("Invalid assessment category.");
}

$category = $categories[$categoryIndex];

$isPrevious = (
    ($_POST['navigation'] ?? '') === 'previous'
);

// Load previously saved answers for this category.
$previousAnswers =
    $_SESSION['needs_assessment_answers'][$category] ?? [];

if (!is_array($previousAnswers)) {
    $previousAnswers = [];
}

// Get the expected questions for this category.
$stmt = $conn->prepare(
    "SELECT question_id, question_number
     FROM question_data
     WHERE assessment_type = ?
     ORDER BY question_number ASC"
);

if (!$stmt) {
    error_log("Question prepare failed: " . $conn->error);
    exit("Unable to load assessment questions.");
}

$stmt->bind_param("s", $category);

if (!$stmt->execute()) {
    error_log("Question query failed: " . $stmt->error);
    $stmt->close();
    exit("Unable to load assessment questions.");
}

$result = $stmt->get_result();
$questions = [];

while ($row = $result->fetch_assoc()) {
    $number = (int) $row['question_number'];

    // Detect duplicate question numbers.
    if (isset($questions[$number])) {
        $stmt->close();
        exit("Duplicate question numbering was found.");
    }

    $questions[$number] = (int) $row['question_id'];
}

$stmt->close();

// Verify all 11 questions exist.
if (
    count($questions) !== 11 ||
    !isset($questions[1]) ||
    !isset($questions[10]) ||
    !isset($questions[11])
) {
    exit("The questions for this category are incomplete.");
}

$categoryAnswers = [];

// Validate and preserve the 10 rating questions.
for ($number = 1; $number <= 10; $number++) {
    if (!isset($questions[$number])) {
        exit("A survey question is missing.");
    }

    $questionId = $questions[$number];
    $key = (string) $questionId;

    // If no answer was submitted, retain the saved answer.
    if (!isset($_POST[$key])) {
        if (!$isPrevious) {
            exit("Please answer all 10 statements before continuing.");
        }

        if (isset($previousAnswers[$questionId])) {
            $categoryAnswers[$questionId] =
                $previousAnswers[$questionId];
        } else {
            $categoryAnswers[$questionId] = [
                'answer_score' => null,
                'answer_text' => null
            ];
        }

        continue;
    }

    if (!is_scalar($_POST[$key])) {
        exit("Invalid answer.");
    }

    $score = filter_var(
        $_POST[$key],
        FILTER_VALIDATE_INT
    );

    if ($score === false || $score < 1 || $score > 5) {
        exit("Invalid answer. Please select a score from 1 to 5.");
    }

    $categoryAnswers[$questionId] = [
        'answer_score' => $score,
        'answer_text' => null
    ];
}

// Handle the optional written-response question.
$openQuestionId = $questions[11];
$openKey = (string) $openQuestionId;

if (isset($_POST[$openKey])) {
    $openText = $_POST[$openKey];

    if (!is_string($openText)) {
        exit("Invalid response for the written question.");
    }

    $openText = trim($openText);
} elseif (isset($previousAnswers[$openQuestionId])) {
    // Preserve previously saved written response.
    $openText =
        $previousAnswers[$openQuestionId]['answer_text'] ?? '';
} else {
    $openText = '';
}

if (strlen($openText) > 5000) {
    exit("Your written response is too long. Maximum: 5000 bytes.");
}

$categoryAnswers[$openQuestionId] = [
    'answer_score' => null,
    'answer_text' => $openText !== '' ? $openText : null
];

// Initialize session storage if needed.
if (!isset($_SESSION['needs_assessment_answers'])) {
    $_SESSION['needs_assessment_answers'] = [];
}

// Save this category before navigating.
$_SESSION['needs_assessment_answers'][$category] =
    $categoryAnswers;

// Previous button: save answers, then go back.
if ($isPrevious) {
    if ($categoryIndex > 0) {
        header(
            "Location: needs_assessment.php?category=" .
            ($categoryIndex - 1)
        );
    } else {
        header("Location: assessment_guide.php");
    }

    exit();
}

// Next button: go to the next category.
if ($categoryIndex < count($categories) - 1) {
    header(
        "Location: needs_assessment.php?category=" .
        ($categoryIndex + 1)
    );

    exit();
}

// Final category: proceed to review.
header("Location: review_assessment.php");
exit();
?>
