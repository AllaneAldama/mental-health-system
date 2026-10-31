
<?php
require_once __DIR__ . '/session.php';

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: student_information.php');
    exit();
}

// Get submitted values.
$fullname = trim($_POST['fullname'] ?? '');
$alias = trim($_POST['alias'] ?? '');
$student_number = trim($_POST['student_number'] ?? '');
$program = trim($_POST['program'] ?? '');
$year_level = trim($_POST['year_level'] ?? '');
$email = trim($_POST['email'] ?? '');

// Preserve form values if validation fails.
$_SESSION['old'] = [
    'alias' => $alias,
    'fullname' => $fullname,
    'student_number' => $student_number,
    'program' => $program,
    'year_level' => $year_level,
    'email' => $email
];

$errors = [];

if (
    $fullname === '' ||
    $alias === '' ||
    $student_number === '' ||
    $program === '' ||
    $year_level === '' ||
    $email === ''
) {
    $errors['general'] = 'Please complete all required fields.';
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
}

if (!in_array($program, ['BSIT', 'BSBA', 'BSED'], true)) {
    $errors['program'] = 'Please select a valid program.';
}

if (!in_array(
    $year_level,
    ['1st Year', '2nd Year', '3rd Year', '4th Year'],
    true
)) {
    $errors['year_level'] = 'Please select a valid year level.';
}

if (!empty($errors)) {
    $_SESSION['errors'] = $errors;
    header('Location: student_information.php');
    exit();
}

/*
 * Reuse the current student record only if the submitted
 * student number matches the student already in this session.
 *
 * If the user enters a different student number, treat it
 * as a new registration and validate it independently.
 */
$sessionUserId = isset($_SESSION['user_id'])
    ? (int) $_SESSION['user_id']
    : 0;

$userId = 0;

if ($sessionUserId > 0) {
    $stmt = $conn->prepare(
        "SELECT user_id, student_number
         FROM user_data
         WHERE user_id = ?
           AND role_type = 'student'
         LIMIT 1"
    );

    if (!$stmt) {
        error_log($conn->error);
        exit('Unable to verify your student record.');
    }

    $stmt->bind_param('i', $sessionUserId);
    $stmt->execute();
    $existingStudent = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (
        $existingStudent &&
        $existingStudent['student_number'] === $student_number
    ) {
        $userId = (int) $existingStudent['user_id'];
    }
}

// If this is the same student, check whether they already submitted.
if ($userId > 0) {
    $stmt = $conn->prepare(
        "SELECT assessment_id
         FROM assessment_data
         WHERE user_id = ?
           AND assessment_type = 'Needs Assessment'
         LIMIT 1"
    );

    if (!$stmt) {
        error_log($conn->error);
        exit('Unable to verify your assessment status.');
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $existingAssessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existingAssessment) {
        $_SESSION['assessment_id'] =
            (int) $existingAssessment['assessment_id'];

        unset(
            $_SESSION['old'],
            $_SESSION['errors'],
            $_SESSION['needs_assessment_answers'],
            $_SESSION['user_id'],
            $_SESSION['fullname'],
            $_SESSION['alias'],
            $_SESSION['student_number'],
            $_SESSION['program'],
            $_SESSION['year_level'],
            $_SESSION['email']
        );

        header('Location: thank_you.php');
        exit();
    }
}

// Check if another student already owns this student number or email.
if ($userId > 0) {
    $stmt = $conn->prepare(
        "SELECT user_id
         FROM user_data
         WHERE (student_number = ? OR email = ?)
           AND user_id <> ?
         LIMIT 1"
    );

    if (!$stmt) {
        error_log($conn->error);
        exit('Unable to validate your information.');
    }

    $stmt->bind_param('ssi', $student_number, $email, $userId);
} else {
    $stmt = $conn->prepare(
        "SELECT user_id
         FROM user_data
         WHERE student_number = ? OR email = ?
         LIMIT 1"
    );

    if (!$stmt) {
        error_log($conn->error);
        exit('Unable to validate your information.');
    }

    $stmt->bind_param('ss', $student_number, $email);
}

$stmt->execute();
$duplicate = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($duplicate) {
    $_SESSION['errors'] = [
        'general' =>
            'This student number or email is already registered. ' .
            'Please use your registered information.'
    ];

    header('Location: student_information.php');
    exit();
}

if ($userId > 0) {
    // Update the existing, in-progress student's information.
    $stmt = $conn->prepare(
        "UPDATE user_data
         SET fullname = ?,
             email = ?,
             program = ?,
             year_level = ?,
             alias = ?
         WHERE user_id = ?
           AND role_type = 'student'"
    );

    if (!$stmt) {
        error_log($conn->error);
        exit('Unable to update your information.');
    }

    $stmt->bind_param(
        'sssssi',
        $fullname,
        $email,
        $program,
        $year_level,
        $alias,
        $userId
    );

    if (!$stmt->execute()) {
        error_log($stmt->error);
        $stmt->close();
        exit('Unable to update your information.');
    }

    $stmt->close();
} else {
    // New student: create a new record.
    $passwordHash = password_hash(
        bin2hex(random_bytes(16)),
        PASSWORD_DEFAULT
    );

    $stmt = $conn->prepare(
        "INSERT INTO user_data
            (student_number, fullname, email, password_hash,
             role_type, program, year_level, alias)
         VALUES (?, ?, ?, ?, 'student', ?, ?, ?)"
    );

    if (!$stmt) {
        error_log($conn->error);
        exit('Unable to save your information.');
    }

    $stmt->bind_param(
        'sssssss',
        $student_number,
        $fullname,
        $email,
        $passwordHash,
        $program,
        $year_level,
        $alias
    );

    if (!$stmt->execute()) {
        if ($stmt->errno === 1062) {
            $_SESSION['errors'] = [
                'general' =>
                    'This student number or email is already registered.'
            ];

            $stmt->close();
            header('Location: student_information.php');
            exit();
        }

        error_log($stmt->error);
        $stmt->close();
        exit('Unable to save your information.');
    }

    $userId = (int) $conn->insert_id;
    $stmt->close();
}

// Set session data for this student.
$_SESSION['user_id'] = $userId;
$_SESSION['fullname'] = $fullname;
$_SESSION['alias'] = $alias;
$_SESSION['student_number'] = $student_number;
$_SESSION['program'] = $program;
$_SESSION['year_level'] = $year_level;
$_SESSION['email'] = $email;

// A new student must not inherit another student's draft answers.
unset(
    $_SESSION['needs_assessment_answers'],
    $_SESSION['assessment_id'],
    $_SESSION['old'],
    $_SESSION['errors']
);

header('Location: assessment_guide.php');
exit();
