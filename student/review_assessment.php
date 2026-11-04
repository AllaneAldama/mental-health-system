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

foreach ($categories as $category) {
    if (!isset($allAnswers[$category]) || count($allAnswers[$category]) !== 11) {
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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/students/css/review-assessment.css">
    <link rel="stylesheet" href="../assets/students/css/student-progress.css">
    <script src="../assets/js/student-progress.js" defer></script>
    <script src="../assets/js/assessment-submission.js" defer></script>
</head>
<body class="review-assessment-page">
<?php
$studentProgressStep = 5;
$studentProgressTotal = 6;
include __DIR__ . '/student-progress.php';
?>

<div class="review-wrapper needs-review">
    <h1>Review Your Assessment</h1>
    <p>Please review your answers before submitting your Needs Assessment Survey.</p>

    <?php foreach ($categories as $category): ?>
        <section class="review-card">
            <h2><?= htmlspecialchars($category) ?></h2>
            <?php foreach (($questionsByCategory[$category] ?? []) as $question): ?>
                <?php
                $questionId = (int) $question['question_id'];
                $answer = $allAnswers[$category][$questionId] ?? null;
                if (!$answer) continue;
                $questionText = $question['question_text'];
                $questionNumber = (int) $question['question_number'];
                ?>
                <div class="review-question">
                    <p><strong><?= $questionNumber ?>.</strong> <?= htmlspecialchars($questionText) ?></p>
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
                            <?= nl2br(htmlspecialchars($answer['answer_text'] ?? 'No response provided')) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <div class="review-actions">
        <a class="back-btn" href="needs_assessment.php?category=4">Back to Survey</a>
        <form id="assessment-submit-form" action="submit_assessment.php" method="POST">
            <button class="submit-btn" id="assessment-submit-button" type="submit">
                Submit Assessment
            </button>
        </form>
    </div>
</div>

<!-- Submission progress modal -->
<div class="submission-modal" id="submission-modal" aria-hidden="true">
    <div class="submission-modal__backdrop"></div>
    <section class="submission-modal__panel" role="dialog" aria-modal="true" aria-labelledby="submission-modal-title">
        <div class="submission-mail" id="submission-mail" aria-hidden="true">
            <div class="submission-mail__glow"></div>
            <div class="submission-mail__envelope">
                <span class="submission-mail__flap"></span>
                <span class="submission-mail__letter"></span>
                <span class="submission-mail__plane">➤</span>
            </div>
            <div class="submission-mail__success">✓</div>
        </div>

        <p class="submission-modal__eyebrow">PTC WELLNESS</p>
        <h2 id="submission-modal-title">Submitting Your Assessment</h2>
        <p class="submission-modal__description" id="submission-modal-description">
            Please wait while we save your responses and process your confirmation email.
        </p>

        <div class="submission-stages" aria-live="polite">
            <div class="submission-stage is-active" data-stage="save">
                <span class="submission-stage__icon">1</span>
                <span class="submission-stage__label">Saving assessment</span>
                <span class="submission-stage__state">In progress</span>
            </div>
            <div class="submission-stage" data-stage="email">
                <span class="submission-stage__icon">2</span>
                <span class="submission-stage__label">Sending confirmation email</span>
                <span class="submission-stage__state">Waiting</span>
            </div>
            <div class="submission-stage" data-stage="finish">
                <span class="submission-stage__icon">3</span>
                <span class="submission-stage__label">Finalizing submission</span>
                <span class="submission-stage__state">Waiting</span>
            </div>
        </div>

        <div class="submission-progress" aria-hidden="true">
            <span id="submission-progress-fill"></span>
        </div>
        <p class="submission-modal__footnote" id="submission-modal-footnote">
            Please keep this page open until the process is complete.
        </p>
        <button class="submission-continue" id="submission-continue" type="button" hidden>
            Continue to Thank You
        </button>
    </section>
</div>
</body>
</html>
