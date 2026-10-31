
<?php
require_once __DIR__ . '/session.php';

if (!isset($_SESSION['student_number'])) {
    header("Location: student_information.php");
    exit();
}

$categories = [
    'Academic Development',
    'Career Development',
    'Personal Development',
    'Social Development',
    'Spiritual Development'
];

$allAnswers = $_SESSION['needs_assessment_answers'] ?? [];

// Require all five categories to be completed.
foreach ($categories as $category) {
    if (!isset($allAnswers[$category]) ||
        count($allAnswers[$category]) !== 11) {
        header("Location: needs_assessment.php");
        exit();
    }
}

require_once __DIR__ . '/../config/database.php';

$questionIds = [];

foreach ($allAnswers as $answers) {
    foreach ($answers as $questionId => $answer) {
        $questionIds[] = (int) $questionId;
    }
}

$questionIds = array_unique($questionIds);

if (count($questionIds) !== 55) {
    exit("Assessment answers are incomplete.");
}

$placeholders = implode(',', array_fill(0, count($questionIds), '?'));
$types = str_repeat('i', count($questionIds));

$sql = "SELECT question_id, assessment_type, question_number, question_text
        FROM question_data
        WHERE question_id IN ($placeholders)
        ORDER BY FIELD(assessment_type,
            'Academic Development',
            'Career Development',
            'Personal Development',
            'Social Development',
            'Spiritual Development'
        ), question_number ASC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    exit("Unable to load the review page.");
}

$stmt->bind_param($types, ...$questionIds);
$stmt->execute();
$result = $stmt->get_result();

$questionsByCategory = [];

while ($row = $result->fetch_assoc()) {
    $questionsByCategory[$row['assessment_type']][] = $row;
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Review Your Assessment</title>

    <link rel="stylesheet" href="../assets/css/student.css">

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>
        .review-wrapper {
            width: min(900px, 92%);
            margin: 35px auto;
        }

        .review-card {
            background: #fff;
            padding: 24px;
            margin-bottom: 22px;
            border-radius: 14px;
            box-shadow: 0 3px 12px rgba(0,0,0,.07);
        }

        .review-question {
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }

        .review-question:last-child {
            border-bottom: none;
        }

        .review-answer {
            margin-top: 8px;
            color: #426b40;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .review-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin: 24px 0;
        }

        .review-actions a,
        .review-actions button {
            display: inline-block;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font: inherit;
            cursor: pointer;
        }

        .back-btn {
            background: #eee;
            color: #222;
        }

        .submit-btn {
            background: #5E8B59;
            color: #fff;
        }
    </style>
</head>
<body>
<div class="review-wrapper needs-review">
    <h1>Review Your Assessment</h1>
    <p>
        Please review your answers before submitting your
        Needs Assessment Survey.
    </p>

    <?php foreach ($categories as $category): ?>
        <section class="review-card">
            <h2><?= htmlspecialchars($category) ?></h2>

            <?php foreach (($questionsByCategory[$category] ?? []) as $question): ?>
                <?php
                $questionId = (int) $question['question_id'];
                $answer = $allAnswers[$category][$questionId] ?? null;
                if (!$answer) {
                    continue;
                }

                $questionText = $question['question_text'];
                $questionNumber = (int) $question['question_number'];
                ?>
                <div class="review-question">
                    <p>
                        <strong><?= $questionNumber ?>.</strong>
                        <?= htmlspecialchars($questionText) ?>
                    </p>

                    <div class="review-answer">
                        <?php if ($questionNumber <= 10): ?>
                            <?php
                            $labels = [
                                1 => 'Strongly Disagree',
                                2 => 'Disagree',
                                3 => 'Neither Agree nor Disagree',
                                4 => 'Agree',
                                5 => 'Strongly Agree'
                            ];
                            $score = (int) $answer['answer_score'];
                            echo htmlspecialchars($labels[$score] ?? 'Invalid answer');
                            ?>
                        <?php else: ?>
                            <?= nl2br(htmlspecialchars(
                                $answer['answer_text'] ?? 'No response provided'
                            )) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <div class="review-actions">
        <a class="back-btn" href="needs_assessment.php?category=4">
            Back to Survey
        </a>

        <form action="submit_assessment.php" method="POST">
            <button class="submit-btn" type="submit">
                Submit Assessment
            </button>
        </form>
    </div>
</div>
</body>
</html>
