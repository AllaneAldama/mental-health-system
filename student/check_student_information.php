
<?php
require_once __DIR__ . '/session.php';

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['available' => false]);
    exit();
}

$studentNumber = trim($_POST['student_number'] ?? '');
$email = trim($_POST['email'] ?? '');

if ($studentNumber === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['available' => true]);
    exit();
}

try {
    $userId = 0;
    $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);

    // Identify the current student, if their submitted
    // student number matches their existing session record.
    if ($sessionUserId > 0) {
        $stmt = $conn->prepare(
            "SELECT user_id, student_number
             FROM user_data
             WHERE user_id = ?
               AND role_type = 'student'
             LIMIT 1"
        );

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('i', $sessionUserId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            $student &&
            $student['student_number'] === $studentNumber
        ) {
            $userId = (int) $student['user_id'];
        }
    }

    if ($userId > 0) {
        $stmt = $conn->prepare(
            "SELECT user_id
             FROM user_data
             WHERE (student_number = ? OR email = ?)
               AND user_id <> ?
             LIMIT 1"
        );

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('ssi', $studentNumber, $email, $userId);
    } else {
        $stmt = $conn->prepare(
            "SELECT user_id
             FROM user_data
             WHERE student_number = ? OR email = ?
             LIMIT 1"
        );

        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param('ss', $studentNumber, $email);
    }

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    echo json_encode([
        'available' => !$duplicate
    ]);

} catch (Throwable $e) {
    error_log('Student duplicate check failed: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'available' => false,
        'error' => 'check_failed'
    ]);
}
