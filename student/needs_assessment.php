
<?php
require_once __DIR__ . '/session.php';

if (!isset($_SESSION['student_number'])) {
    header("Location: student_information.php");
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

$current = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);

if ($current === null || $current === false) {
    $current = 0;
}

if ($current < 0 || $current >= count($categories)) {
    $current = 0;
}

$category = $categories[$current];

// Load answers previously saved in the session.
$savedAnswers =
    $_SESSION['needs_assessment_answers'][$category] ?? [];

$stmt = $conn->prepare(
    "SELECT question_id, question_number, question_text
     FROM question_data
     WHERE assessment_type = ?
     ORDER BY question_number ASC"
);

if (!$stmt) {
    error_log($conn->error);
    exit("Unable to load assessment questions.");
}

$stmt->bind_param("s", $category);

if (!$stmt->execute()) {
    error_log($stmt->error);
    $stmt->close();
    exit("Unable to load assessment questions.");
}

$result = $stmt->get_result();
$questions = [];

while ($row = $result->fetch_assoc()) {
    $questions[] = $row;
}

$stmt->close();

if (count($questions) !== 11) {
    exit(
        "The questions for this category are incomplete. " .
        "Please contact the administrator."
    );
}

$scale = [
    5 => 'Strongly Agree',
    4 => 'Agree',
    3 => 'Neither Agree or Disagree',
    2 => 'Disagree',
    1 => 'Strongly Disagree'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Needs Assessment Survey</title>

    <link rel="stylesheet" href="../assets/css/student.css">

    <style>
        .needs-wrapper {
            width: min(900px, 92%);
            margin: 30px auto;
        }

        .needs-card {
            background: white;
            border-radius: 14px;
            padding: 24px;
            margin: 20px 0;
            box-shadow: 0 3px 12px rgba(0,0,0,.07);
        }

        .scale-note {
            background: #f1f6ef;
            padding: 14px;
            border-radius: 8px;
            margin: 16px 0;
            line-height: 1.8;
        }

        .question {
            padding: 18px 0;
            border-bottom: 1px solid #eee;
        }

        .question:last-child {
            border-bottom: none;
        }

        .question-title {
            font-weight: 600;
            margin-bottom: 12px;
        }

        .rating-options {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 8px;
        }

        .rating-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px 4px;
            text-align: center;
            font-size: 13px;
            cursor: pointer;
        }

        .rating-option input {
            accent-color: #5E8B59;
        }

        .rating-option:has(input:checked) {
            border: 2px solid #5E8B59;
            background: #eaf3e6;
        }

        .needs-card textarea {
            box-sizing: border-box;
            width: 100%;
            min-height: 100px;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font: inherit;
        }

        .needs-actions {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin: 20px 0 35px;
        }

        .needs-actions button,
        .needs-actions a {
            border: none;
            border-radius: 8px;
            padding: 12px 20px;
            font: inherit;
            text-decoration: none;
            cursor: pointer;
        }

        .next-btn {
            background: #5E8B59;
            color: white;
        }

        .back-btn {
            background: #eee;
            color: #222;
        }

        @media (max-width: 600px) {
            .needs-card {
                padding: 16px;
            }

            .rating-options {
                grid-template-columns: 1fr;
            }

            .rating-option {
                flex-direction: row;
                justify-content: flex-start;
                text-align: left;
                gap: 12px;
            }
        }
    </style>
</head>

<body>

<main class="needs-wrapper">

    <p>Category <?= $current + 1 ?> of <?= count($categories) ?></p>

    <progress
        value="<?= $current + 1 ?>"
        max="<?= count($categories) ?>"
        style="width:100%;"
    ></progress>

    <a
        href="<?= $current > 0
            ? 'needs_assessment.php?category=' . ($current - 1)
            : 'assessment_guide.php' ?>"
        class="student-back-btn"
        aria-label="Back"
        title="Back"
    >
        <span aria-hidden="true">‹</span>
    </a>

    <h1>Needs Assessment Survey</h1>
    <h2><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></h2>

    <p>
        Read each statement carefully and indicate the extent to which
        you agree or disagree with it. Choose the number that best
        reflects your response. There are no right or wrong answers.
    </p>

    <div class="scale-note">
        <?php foreach ($scale as $number => $label): ?>
            <div>
                <strong><?= $number ?></strong> -
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endforeach; ?>
    </div>

    <form action="process_needs_assessment.php" method="POST">

        <input
            type="hidden"
            name="category_index"
            value="<?= $current ?>"
        >

        <section class="needs-card">

            <?php foreach ($questions as $question): ?>
                <?php
                $questionId = (int) $question['question_id'];
                $questionNumber = (int) $question['question_number'];

                $savedAnswer = $savedAnswers[$questionId] ?? [];
                ?>

                <div class="question">

                    <p class="question-title">
                        <?= $questionNumber ?>.
                        <?= htmlspecialchars(
                            $question['question_text'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </p>

                    <?php if ($questionNumber <= 10): ?>

                        <div class="rating-options">

                            <?php foreach ($scale as $score => $label): ?>

                                <label class="rating-option">

                                    <input
                                        type="radio"
                                        name="<?= $questionId ?>"
                                        value="<?= $score ?>"
                                        <?= (
                                            isset($savedAnswer['answer_score']) &&
                                            (int) $savedAnswer['answer_score'] === (int) $score
                                        ) ? 'checked' : '' ?>
                                        required
                                    >

                                    <span>
                                        <strong><?= $score ?></strong><br>
                                        <?= htmlspecialchars(
                                            $label,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>

                                </label>

                            <?php endforeach; ?>

                        </div>

                    <?php else: ?>

                        <textarea
                            name="<?= $questionId ?>"
                            maxlength="5000"
                            placeholder="You may write your response here (optional)."
                        ><?= htmlspecialchars(
                            $savedAnswer['answer_text'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?></textarea>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        </section>

        <div class="needs-actions">

            <?php if ($current > 0): ?>

                <button
                    class="back-btn"
                    type="submit"
                    name="navigation"
                    value="previous"
                    formnovalidate
                >
                    ← Previous
                </button>

            <?php else: ?>

                <a class="back-btn" href="assessment_guide.php">
                    ← Back to Guide
                </a>

            <?php endif; ?>

            <button class="next-btn" type="submit">
                <?= $current === 4
                    ? 'Review Assessment →'
                    : 'Next Category →' ?>
            </button>

        </div>

    </form>

</main>

</body>
</html>
