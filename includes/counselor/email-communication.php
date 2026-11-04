<?php
/**
 * Page-scoped email helpers for View Assessment and Monitoring.
 * Does not replace or modify config/email.php / PHPMailer settings.
 */

/** Read the signed-in counselor's latest stored full name (Profile may have been edited). */
function ptcCounselorMailSenderName(mysqli $conn): string
{
    $fallback = trim((string)($_SESSION['fullname'] ?? ''));
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId > 0) {
        $stmt = mysqli_prepare($conn, "SELECT fullname FROM user_data WHERE user_id = ? AND role_type = 'counselor' LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $userId);
            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                $storedName = trim((string)($row['fullname'] ?? ''));
                if ($storedName !== '') $fallback = $storedName;
            }
            mysqli_stmt_close($stmt);
        }
    }
    return $fallback !== '' ? $fallback : 'PTC Wellness Guidance Counselor';
}

/**
 * Content-only message composer; greeting and signature come from the server,
 * never from a user-editable hidden field or browser-submitted name.
 * Existing PTC email branding/logo and PHPMailer transport are reused.
 * @return array{html:string,plain:string,logo:string}
 */
function ptcCounselorMailContent(string $alias, string $body, string $counselor): array
{
    require_once __DIR__ . '/../../config/email.php';
    $alias = trim($alias) !== '' ? trim($alias) : 'Student';
    $counselor = trim($counselor) !== '' ? trim($counselor) : 'PTC Wellness Guidance Counselor';
    $html = buildPTCEmailTemplate($alias, $body, 'ptc_wellness_logo', $counselor);
    // This one counselor-only communication uses the requested closing phrase.
    // The existing global config/email.php template is not edited.
    $html = str_replace('Sincerely,', 'Best regards,', $html);
    $plain = "Dear {$alias},\n\n" . trim($body) .
        "\n\nBest regards,\n{$counselor}\nGuidance Counselor\nPTC Wellness Guidance Office";
    return [
        'html' => $html,
        'plain' => $plain,
        'logo' => __DIR__ . '/../../assets/images/brainalter.png'
    ];
}
