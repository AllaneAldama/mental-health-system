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
    jsonResponse(['error' => 'method_not_allowed'], 405);
}

$studentNumber = trim($_POST['student_number'] ?? '');
$email = trim($_POST['email'] ?? '');

$errors = [];

if ($studentNumber === '') {
    $errors['student_number'] = 'Please enter your student number.';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = $email === ''
        ? 'Please enter your email address.'
        : 'Please enter a valid email address.';
}
if ($errors) {
    jsonResponse(['errors' => $errors]);
}

try {
    $currentStudentId = 0;
    $sessionStudentId = (int)($_SESSION['student_id'] ?? 0);
    if ($sessionStudentId > 0) {
        $stmt = $conn->prepare(
            "SELECT student_id, student_number FROM student_profiles WHERE student_id = ? LIMIT 1"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('i', $sessionStudentId);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($current && (string)$current['student_number'] === $studentNumber) {
            $currentStudentId = (int)$current['student_id'];
        }
    } elseif (!empty($_SESSION['user_id'])) {
        // Map sessions created before the migration without creating new accounts.
        $legacyUserId = (int)$_SESSION['user_id'];
        $stmt = $conn->prepare(
            "SELECT student_id, student_number FROM student_profiles WHERE legacy_user_id = ? LIMIT 1"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('i', $legacyUserId);
        if (!$stmt->execute()) throw new Exception($stmt->error);
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($current && (string)$current['student_number'] === $studentNumber) {
            $currentStudentId = (int)$current['student_id'];
        }
    }

    // Duplicate checks use the student record table, not login accounts.
    if ($currentStudentId > 0) {
        $stmt = $conn->prepare(
            "SELECT student_id FROM student_profiles WHERE student_number = ? AND student_id <> ? LIMIT 1"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('si', $studentNumber, $currentStudentId);
    } else {
        $stmt = $conn->prepare("SELECT student_id FROM student_profiles WHERE student_number = ? LIMIT 1");
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('s', $studentNumber);
    }
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $numberExists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($numberExists) $errors['student_number'] = 'This student number is already used.';

    if ($currentStudentId > 0) {
        $stmt = $conn->prepare(
            "SELECT student_id FROM student_profiles WHERE email = ? AND student_id <> ? LIMIT 1"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('si', $email, $currentStudentId);
    } else {
        $stmt = $conn->prepare("SELECT student_id FROM student_profiles WHERE email = ? LIMIT 1");
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('s', $email);
    }
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $emailExists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT user_id FROM user_data WHERE email = ? AND role_type IN ('admin','counselor') LIMIT 1"
    );
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('s', $email);
    if (!$stmt->execute()) throw new Exception($stmt->error);
    $staffEmailExists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($emailExists || $staffEmailExists) $errors['email'] = 'This email address is already used.';

    jsonResponse(['errors' => $errors]);

} catch (Throwable $e) {
    error_log('Student duplicate check failed: ' . $e->getMessage());
    jsonResponse([
        'error' => 'check_failed',
        'message' => 'We could not verify your information right now. Please try again.'
    ], 500);
}
?>
