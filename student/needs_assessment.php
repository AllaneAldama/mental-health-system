
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

    <link rel="stylesheet" href="../assets/students/css/needs-assessment.css">
    <script src="../assets/js/student-progress.js" defer></script>

</head>

<body class="needs-assessment-page">

<main class="needs-wrapper">
    <?php $studentProgressStep = 4; $studentProgressTotal = 6; include __DIR__ . '/student-progress.php'; ?>


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

    <div class="category-progress">
        <div class="category-progress-heading">
            <span>Category <?= $current + 1 ?> of <?= count($categories) ?></span>
            <span><?= (int) round((($current + 1) / count($categories)) * 100) ?>% Complete</span>
        </div>
        <progress
            class="category-progress-bar"
            value="<?= $current + 1 ?>"
            max="<?= count($categories) ?>"
            aria-label="Assessment category progress"
        ></progress>
    </div>

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
                <strong><?= $number ?></strong>
                <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
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

            <div class="back-to-top-row">
                <button
                    type="button"
                    class="back-to-top"
                    aria-label="Back to top"
                    title="Back to top"
                    aria-hidden="true"
                    tabindex="-1"
                >
                    <span class="back-to-top__icon" aria-hidden="true">↑</span>
                    <span>Back to Top</span>
                </button>
            </div>

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

<script>
document.addEventListener('DOMContentLoaded', function () {
    /* Animate the category progress bar to its current category value. */
    const categoryProgress = document.querySelector('.category-progress-bar');
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (categoryProgress && !reduceMotion) {
        const targetValue = Number(categoryProgress.value);
        const maxValue = Number(categoryProgress.max) || 5;
        categoryProgress.value = 0;

        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                categoryProgress.value = Math.min(targetValue, maxValue);
            });
        });
    }

    /* Fade the Back to Top control in after scrolling; keep it clear of navigation. */
    const backToTop = document.querySelector('.back-to-top');

    if (backToTop) {
        const updateBackToTop = function () {
            const isVisible = window.scrollY >= 420;
            backToTop.classList.toggle('is-visible', isVisible);
            backToTop.setAttribute('aria-hidden', String(!isVisible));
            backToTop.tabIndex = isVisible ? 0 : -1;
        };

        window.addEventListener('scroll', updateBackToTop, { passive: true });
        updateBackToTop();

        backToTop.addEventListener('click', function () {
            window.scrollTo({
                top: 0,
                behavior: reduceMotion ? 'auto' : 'smooth'
            });
        });
    }
});
</script>

</body>
</html>
