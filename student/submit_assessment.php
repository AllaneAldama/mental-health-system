
<?php
require_once __DIR__ . '/session.php';

header('Content-Type: application/json; charset=utf-8');

function submissionJson(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit();
}

if (
    !isset($_SESSION['student_id'], $_SESSION['student_number']) ||
    $_SERVER['REQUEST_METHOD'] !== 'POST'
) {
    submissionJson(401, ['success' => false, 'message' => 'Your session has expired. Please sign in again.']);
}

require_once __DIR__ . '/../config/database.php';

$categories = [
    'Academic Development',
    'Career Development',
    'Personal Development',
    'Social Development',
    'Spiritual Development'
];

$studentId = (int) $_SESSION['student_id'];
$allAnswers = $_SESSION['needs_assessment_answers'] ?? [];

// Verify that all five categories have 11 answers.
foreach ($categories as $category) {
    if (
        !isset($allAnswers[$category]) ||
        !is_array($allAnswers[$category]) ||
        count($allAnswers[$category]) !== 11
    ) {
        submissionJson(422, ['success' => false, 'message' => 'Please complete all five survey categories before submitting.']);
    }
}

// Load the expected survey questions.
$stmt = $conn->prepare(
    "SELECT question_id, assessment_type, question_number
     FROM question_data
     WHERE assessment_type IN (
         'Academic Development',
         'Career Development',
         'Personal Development',
         'Social Development',
         'Spiritual Development'
     )
     ORDER BY assessment_type, question_number"
);

if (!$stmt) {
    error_log($conn->error);
    submissionJson(500, ['success' => false, 'message' => 'Unable to verify the survey questions.']);
}

if (!$stmt->execute()) {
    error_log($stmt->error);
    $stmt->close();
    submissionJson(500, ['success' => false, 'message' => 'Unable to verify the survey questions.']);
}

$result = $stmt->get_result();
$questions = [];

while ($row = $result->fetch_assoc()) {
    $questions[(int) $row['question_id']] = [
        'category' => $row['assessment_type'],
        'number' => (int) $row['question_number']
    ];
}

$stmt->close();

if (count($questions) !== 55) {
    submissionJson(500, ['success' => false, 'message' => 'The survey question configuration is incomplete.']);
}

// Validate all answers before saving.
$validatedAnswers = [];

foreach ($categories as $category) {
    for ($number = 1; $number <= 11; $number++) {
        $matches = [];

        foreach ($questions as $questionId => $question) {
            if (
                $question['category'] === $category &&
                $question['number'] === $number
            ) {
                $matches[] = $questionId;
            }
        }

        if (count($matches) !== 1) {
            submissionJson(422, ['success' => false, 'message' => 'A survey question is missing or duplicated.']);
        }

        $questionId = $matches[0];

        if (!isset($allAnswers[$category][$questionId])) {
            submissionJson(422, ['success' => false, 'message' => 'Some answers are missing. Please review your survey.']);
        }

        $answer = $allAnswers[$category][$questionId];

        if ($number <= 10) {
            $score = $answer['answer_score'] ?? null;

            if (
                !is_numeric($score) ||
                (int) $score < 1 ||
                (int) $score > 5
            ) {
                submissionJson(422, ['success' => false, 'message' => 'An invalid rating was found.']);
            }

            $validatedAnswers[] = [
                'question_id' => $questionId,
                'score' => (int) $score,
                'text' => null
            ];

        } else {
            $text = $answer['answer_text'] ?? null;

            if (
                $text !== null &&
                (!is_string($text) || strlen($text) > 5000)
            ) {
                submissionJson(422, ['success' => false, 'message' => 'The written response is invalid.']);
            }

            $validatedAnswers[] = [
                'question_id' => $questionId,
                'score' => null,
                'text' => $text
            ];
        }
    }
}

if (count($validatedAnswers) !== 55) {
    submissionJson(422, ['success' => false, 'message' => 'The assessment must contain all 55 answers.']);
}

// Start an atomic database transaction.
if (!$conn->begin_transaction()) {
    error_log($conn->error);
    submissionJson(500, ['success' => false, 'message' => 'Unable to start submission. Please try again.']);
}

try {

    // Lock student record and retrieve required information.
    $stmt = $conn->prepare(
        "SELECT student_id, fullname, alias, email
         FROM student_profiles
         WHERE student_id = ?
           AND student_number = ?
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $studentNumber = $_SESSION['student_number'];

    $stmt->bind_param("is", $studentId, $studentNumber);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) {
        throw new Exception("Student profile verification failed.");
    }

    // Use alias instead of full name for the confirmation email.
    $studentAlias = trim($student['alias'] ?? '');
    $studentEmail = trim($student['email'] ?? '');

    if ($studentAlias === '') {
        $studentAlias = 'Student';
    }

    // Check existing Needs Assessment submission.
    $stmt = $conn->prepare(
        "SELECT assessment_id
         FROM assessment_data
         WHERE student_id = ?
           AND assessment_type = 'Needs Assessment'
         LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i", $studentId);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {

        $existingId = (int) $existing['assessment_id'];

        if (!$conn->commit()) {
            throw new Exception($conn->error);
        }

        $_SESSION['assessment_id'] = $existingId;
        $_SESSION['confirmation_email_status'] = 'already_submitted';
        unset(
            $_SESSION['needs_assessment_answers'],
            $_SESSION['old'],
            $_SESSION['errors'],
            $_SESSION['fullname'],
            $_SESSION['alias'],
            $_SESSION['student_number'],
            $_SESSION['program'],
            $_SESSION['year_level'],
            $_SESSION['email'],
            $_SESSION['student_id'],
            $_SESSION['user_id']
        );
        submissionJson(200, [
            'success' => true,
            'already_submitted' => true,
            'assessment_id' => $existingId,
            'email_status' => 'already_submitted'
        ]);
    }

    // Create a new Needs Assessment record.
    $stmt = $conn->prepare(
        "INSERT INTO assessment_data
            (student_id, assessment_type, phq9_score, gad7_score,
             risk_level, status)
         VALUES (?, 'Needs Assessment', NULL, NULL, NULL, 'Pending')"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param("i", $studentId);

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $assessmentId = (int) $conn->insert_id;
    $stmt->close();

    // Save all 55 responses.
    $stmt = $conn->prepare(
        "INSERT INTO response_data
            (assessment_id, question_id, answer_score, answer_text)
         VALUES (?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    foreach ($validatedAnswers as $answer) {

        $questionId = (int) $answer['question_id'];
        $score = $answer['score'];
        $text = $answer['text'];

        $stmt->bind_param(
            "iiis",
            $assessmentId,
            $questionId,
            $score,
            $text
        );

        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }
    }

    $stmt->close();

    // Commit the assessment first. Email delivery is handled by a separate request.
    if (!$conn->commit()) {
        throw new Exception($conn->error);
    }

    $_SESSION['assessment_id'] = $assessmentId;
    $_SESSION['confirmation_email_status'] = 'pending';
    $_SESSION['pending_assessment_email'] = [
        'assessment_id' => $assessmentId,
        'student_email' => $studentEmail,
        'student_alias' => $studentAlias
    ];

    // Existing v39 feature: notify active counselors only after a NEW committed assessment.
    // Email failure must not roll back the student's completed submission.
    try {
        require_once __DIR__ . '/../includes/emails/counselor-new-assessment.php';
        ptc_send_new_assessment_counselor_notifications($conn, $assessmentId, $studentAlias);
    } catch (Throwable $notificationError) {
        error_log('Counselor assessment notification failure for assessment ID '
            . $assessmentId . ': ' . $notificationError->getMessage());
    }

    submissionJson(200, [
        'success' => true,
        'already_submitted' => false,
        'assessment_id' => $assessmentId,
        'email_status' => 'pending'
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    error_log("Needs Assessment submission failed: " . $e->getMessage());
    submissionJson(500, [
        'success' => false,
        'message' => 'We could not save your assessment. Please contact the Guidance Office before trying again.'
    ]);
}
?>
