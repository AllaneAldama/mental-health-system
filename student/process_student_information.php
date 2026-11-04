<?php
require_once __DIR__ . '/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Invalid request.'], 405);
}

$fullname = trim($_POST['fullname'] ?? '');
$alias = trim($_POST['alias'] ?? '');
$student_number = trim($_POST['student_number'] ?? '');
$program = trim($_POST['program'] ?? '');
$year_level = trim($_POST['year_level'] ?? '');
$email = trim($_POST['email'] ?? '');

$errors = [];

if ($alias === '') $errors['alias'] = 'Please enter your student alias.';
if ($fullname === '') $errors['fullname'] = 'Please enter your full name.';
if ($student_number === '') $errors['student_number'] = 'Please enter your student number.';
if ($program === '') $errors['program'] = 'Please select your program.';
if ($year_level === '') $errors['year_level'] = 'Please select your year level.';
if ($email === '') {
    $errors['email'] = 'Please enter your email address.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Please enter a valid email address.';
}

$allowedPrograms = [
    'BSIT',
    'BSOA',
    'BSAIS',
    'CCS',
    'COA',
    'CHRM',
    'AHRT',
    'AHRD',
    'AAIS'
];

if ($program !== '' && !in_array($program, $allowedPrograms, true)) {
    $errors['program'] = 'Please select a valid program.';
}
if ($year_level !== '' && !in_array(
    $year_level,
    ['1st Year', '2nd Year', '3rd Year', '4th Year'],
    true
)) {
    $errors['year_level'] = 'Please select a valid year level.';
}
if ($errors) {
    jsonResponse(['success' => false, 'errors' => $errors]);
}

try {
    // Student records belong to student_profiles, not login accounts.
    // An in-progress legacy session can be mapped once using the Phase 1 link.
    $sessionStudentId = (int)($_SESSION['student_id'] ?? 0);
    $studentId = 0;
    if ($sessionStudentId > 0) {
        $stmt = $conn->prepare(
            "SELECT student_id, student_number FROM student_profiles WHERE student_id = ? LIMIT 1"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('i', $sessionStudentId);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($current && (string)$current['student_number'] === $student_number) {
            $studentId = (int)$current['student_id'];
        }
    } elseif (!empty($_SESSION['user_id'])) {
        // Temporary compatibility with student sessions started before Phase 2.
        $legacyId = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare(
            "SELECT student_id, student_number FROM student_profiles WHERE legacy_user_id = ? LIMIT 1"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('i', $legacyId);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($current && (string)$current['student_number'] === $student_number) {
            $studentId = (int)$current['student_id'];
        }
    }

    // Preserve existing "already submitted" redirect behavior.
    if ($studentId > 0) {
        $stmt = $conn->prepare(
            "SELECT assessment_id FROM assessment_data
             WHERE student_id = ? AND assessment_type = 'Needs Assessment' LIMIT 1"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('i', $studentId);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $existingAssessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existingAssessment) {
            $_SESSION['assessment_id'] = (int)$existingAssessment['assessment_id'];
            $_SESSION['confirmation_email_status'] = 'already_submitted';
            unset(
                $_SESSION['needs_assessment_answers'],
                $_SESSION['student_id'], $_SESSION['user_id'],
                $_SESSION['fullname'], $_SESSION['alias'],
                $_SESSION['student_number'], $_SESSION['program'],
                $_SESSION['year_level'], $_SESSION['email']
            );
            jsonResponse(['success' => true, 'redirect' => 'thank_you.php']);
        }
    }

    $duplicateErrors = [];
    // Both fields remain independently validated as in the original flow.
    if ($studentId > 0) {
        $stmt = $conn->prepare(
            "SELECT student_id FROM student_profiles WHERE student_number = ? AND student_id <> ? LIMIT 1"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('si', $student_number, $studentId);
    } else {
        $stmt = $conn->prepare(
            "SELECT student_id FROM student_profiles WHERE student_number = ? LIMIT 1"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('s', $student_number);
    }
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $numberExists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($numberExists) $duplicateErrors['student_number'] = 'This student number is already used.';

    if ($studentId > 0) {
        $stmt = $conn->prepare(
            "SELECT student_id FROM student_profiles WHERE email = ? AND student_id <> ? LIMIT 1"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('si', $email, $studentId);
    } else {
        $stmt = $conn->prepare("SELECT student_id FROM student_profiles WHERE email = ? LIMIT 1");
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('s', $email);
    }
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $emailExists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    // Preserve the existing rule that an admin/counselor email cannot be reused.
    $stmt = $conn->prepare(
        "SELECT user_id FROM user_data WHERE email = ? AND role_type IN ('admin','counselor') LIMIT 1"
    );
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('s', $email);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $staffEmailExists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($emailExists || $staffEmailExists) $duplicateErrors['email'] = 'This email address is already used.';
    if ($duplicateErrors) jsonResponse(['success' => false, 'errors' => $duplicateErrors]);

    $isUpdate = $studentId > 0;
    if ($isUpdate) {
        $stmt = $conn->prepare(
            "UPDATE student_profiles SET fullname = ?, email = ?, program = ?, year_level = ?, alias = ?
             WHERE student_id = ? AND student_number = ?"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('sssssis', $fullname, $email, $program, $year_level, $alias, $studentId, $student_number);
    } else {
        // No random password, no dummy role_type='student' login account.
        $stmt = $conn->prepare(
            "INSERT INTO student_profiles
                (student_number, fullname, email, program, year_level, alias)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('ssssss', $student_number, $fullname, $email, $program, $year_level, $alias);
    }
    if (!$stmt->execute()) {
        if ($stmt->errno === 1062) {
            $stmt->close();
            jsonResponse([
                'success' => false,
                'errors' => ['student_number' => 'This student number or email address is already used.']
            ], 409);
        }
        throw new Exception($stmt->error);
    }
    if (!$isUpdate) $studentId = (int)$conn->insert_id;
    $stmt->close();

    // Keep the student-only session; never expose an account user_id for students.
    $_SESSION['student_id'] = $studentId;
    unset($_SESSION['user_id']);
    $_SESSION['fullname'] = $fullname;
    $_SESSION['alias'] = $alias;
    $_SESSION['student_number'] = $student_number;
    $_SESSION['program'] = $program;
    $_SESSION['year_level'] = $year_level;
    $_SESSION['email'] = $email;
    unset(
        $_SESSION['needs_assessment_answers'],
        $_SESSION['assessment_id'],
        $_SESSION['old'],
        $_SESSION['errors']
    );
    jsonResponse([
        'success' => true,
        'redirect' => 'assessment_guide.php',
        'modal_type' => $isUpdate ? 'updated' : 'created'
    ]);

} catch (Throwable $e) {
    error_log('Student information save failed: ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'We could not save your information right now. Please try again.'
    ], 500);
}
?>
