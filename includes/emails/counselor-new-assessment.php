<?php
/**
 * PTC Wellness: automated NEW Needs Assessment email for active counselors.
 * This helper is intentionally independent from the student's confirmation
 * email and does not modify the submission transaction or any counselor UI.
 * Template geometry, colors, logo and footer match the student confirmation.
 */

function ptc_counselor_assessment_email_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Absolute URL, built from a trusted configured origin for production.
 * Set PTC_APP_BASE_URL=https://your-domain.tld[/subfolder] in hosting settings.
 * Development fallback derives the path from the current student endpoint.
 */
function ptc_counselor_assessment_base_url(): string
{
    $configured = rtrim(trim((string)(getenv('PTC_APP_BASE_URL') ?: '')), '/');
    if ($configured !== '') {
        if (!filter_var($configured, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url($configured, PHP_URL_SCHEME)), ['https', 'http'], true)
        ) {
            throw new RuntimeException('PTC_APP_BASE_URL must be an absolute HTTP(S) URL.');
        }
        return $configured;
    }

    // SERVER_NAME (not the incoming Host header) avoids trusting a user-supplied host.
    $host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
    if (!preg_match('/^[a-z0-9.-]+$/i', $host)) {
        $host = 'localhost';
    }
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $scheme = $https ? 'https' : 'http';
    $port = (int)($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
    $portSuffix = ($port !== 80 && $port !== 443 && $port > 0 && $port <= 65535)
        ? ':' . $port : '';
    // e.g. /mental-health-system/student/submit_assessment.php -> /mental-health-system
    $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/student/submit_assessment.php');
    $sitePath = dirname(dirname(str_replace('\\', '/', $scriptName)));
    $sitePath = ($sitePath === '.' || $sitePath === '/') ? '' : '/' . trim($sitePath, '/');
    return $scheme . '://' . $host . $portSuffix . $sitePath;
}

/**
 * Inline, table-based HTML to mirror student/send_assessment_confirmation.php.
 * Only copy text/design; never include sensitive assessment responses or scores.
 */
function ptc_counselor_assessment_email_html(
    string $counselorName,
    string $studentAlias,
    int $assessmentId,
    string $submittedAt,
    string $reviewUrl
): string {
    $safeCounselor = ptc_counselor_assessment_email_escape($counselorName ?: 'Counselor');
    $safeAlias = ptc_counselor_assessment_email_escape($studentAlias ?: 'Student');
    $safeDate = ptc_counselor_assessment_email_escape($submittedAt);
    $safeUrl = ptc_counselor_assessment_email_escape($reviewUrl);
    $safeId = (string)$assessmentId;
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PTC WELLNESS - New Assessment Notification</title>
</head>
<body style="margin:0;padding:0;background-color:#eef3ec;font-family:Arial,Helvetica,sans-serif;color:#344238;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background-color:#eef3ec;padding:32px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:620px;background-color:#ffffff;border-radius:16px;overflow:hidden;">
    <!-- Same student-confirmation branded header -->
    <tr><td style="background-color:#416D48;padding:25px 24px;color:#ffffff;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
       <td width="100" valign="middle" align="center" style="width:100px;padding-right:16px;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="background-color:#ffffff;border-radius:14px;"><tr>
          <td align="center" valign="middle" style="padding:5px;border-radius:14px;">
           <img src="cid:ptc_wellness_logo" alt="PTC WELLNESS Logo" width="86" style="display:block;width:86px;max-width:86px;height:auto;border:0;">
          </td></tr></table>
       </td>
       <td valign="middle" align="left">
        <h1 style="margin:0;font-family:Arial,sans-serif;font-size:25px;line-height:1.2;font-weight:700;letter-spacing:0.4px;color:#ffffff;">PTC WELLNESS</h1>
        <p style="margin:8px 0 0;font-size:11px;line-height:1.5;font-weight:bold;letter-spacing:1.3px;color:#e6f0e4;">PATEROS TECHNOLOGICAL COLLEGE</p>
        <p style="margin:3px 0 0;font-size:12px;line-height:1.5;color:#e6f0e4;">GUIDANCE OFFICE</p>
       </td>
      </tr></table>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:22px;"><tr>
       <td style="border-top:1px solid #78977b;padding-top:18px;">
        <p style="margin:0 0 7px;font-size:10px;line-height:1.4;font-weight:bold;letter-spacing:1.5px;color:#dcebd9;">NEW ASSESSMENT RECEIVED</p>
        <h2 style="margin:0;font-family:Arial,sans-serif;font-size:25px;line-height:1.3;font-weight:700;color:#ffffff;">Assessment Ready for Review</h2>
        <p style="margin:7px 0 0;font-size:14px;line-height:1.6;color:#e8f1e6;">A new submission is awaiting your review.</p>
       </td>
      </tr></table>
    </td></tr>
    <!-- Counselor-specific content -->
    <tr><td style="padding:34px 32px 30px;">
      <h2 style="margin:0 0 15px;font-family:Arial,sans-serif;font-size:23px;line-height:1.35;font-weight:700;color:#304d32;">Dear {$safeCounselor},</h2>
      <p style="margin:0 0 22px;font-size:15px;line-height:1.8;color:#536157;">
       A student has successfully submitted a PTC WELLNESS Needs Assessment. The submission is now available in the Counselor Portal for your review.
      </p>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#edf5eb;border:1px solid #d8e8d5;border-radius:10px;"><tr>
       <td style="padding:20px;">
        <p style="margin:0 0 9px;font-size:16px;font-weight:bold;color:#3d7540;">&#9679; Pending Review</p>
        <p style="margin:0;font-size:14px;line-height:1.7;color:#48634a;">The assessment has been saved and is awaiting review by the Guidance Office.</p>
       </td>
      </tr></table>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin-top:24px;background-color:#f7f9f6;border:1px solid #e3eadd;border-radius:10px;"><tr>
       <td style="padding:20px;">
        <p style="margin:0 0 15px;font-size:16px;line-height:1.5;font-weight:bold;color:#304d32;">Assessment Details</p>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr><td style="padding:8px 0;color:#718071;font-size:13px;">Student Alias</td><td align="right" style="padding:8px 0;color:#304d32;font-size:14px;font-weight:bold;word-break:break-word;">{$safeAlias}</td></tr>
          <tr><td style="padding:8px 0;color:#718071;font-size:13px;">Assessment ID</td><td align="right" style="padding:8px 0;color:#304d32;font-size:14px;font-weight:bold;">#{$safeId}</td></tr>
          <tr><td style="padding:8px 0;color:#718071;font-size:13px;">Submitted On</td><td align="right" style="padding:8px 0;color:#304d32;font-size:13px;">{$safeDate}</td></tr>
        </table>
       </td>
      </tr></table>
      <p style="margin:22px 0 14px;font-size:14px;line-height:1.7;color:#555555;">
       Please sign in to the Counselor Portal to review the assessment and determine whether further guidance or follow-up is appropriate.
      </p>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 14px;"><tr>
       <td align="center" style="background-color:#416D48;border-radius:9px;">
        <a href="{$safeUrl}" style="display:inline-block;padding:13px 25px;font-family:Arial,sans-serif;font-size:14px;font-weight:bold;color:#ffffff;text-decoration:none;">View Assessment</a>
       </td>
      </tr></table>
      <p style="margin:0 0 22px;font-size:12px;line-height:1.6;color:#718071;text-align:center;">Secure counselor login is required to view this assessment.</p>
      <p style="margin:0 0 18px;font-size:14px;line-height:1.7;color:#555555;">Thank you for your continued commitment to student well-being.</p>
      <p style="margin:0;font-size:14px;line-height:1.7;color:#304d32;"><strong>Best regards,</strong><br><strong>PTC WELLNESS System</strong><br>Guidance Office</p>
    </td></tr>
    <!-- Same student-confirmation footer -->
    <tr><td align="center" style="background-color:#f7f9f6;border-top:1px solid #e5ebe3;padding:20px;">
      <p style="margin:0;font-size:12px;line-height:1.6;color:#777777;">This is an automated notification from PTC WELLNESS. No assessment answers or scores are included in this email.</p>
      <p style="margin:8px 0 0;font-size:12px;color:#5E8B59;font-weight:bold;">Pateros Technological College</p>
    </td></tr>
</table>
</td></tr></table>
</body>
</html>
HTML;
}

/**
 * Send once per newly committed assessment (call from the new-record branch).
 * All ACTIVE counselor accounts receive the notification; there is no assigned
 * counselor before a new assessment is reviewed.
 * Email failure is logged and cannot undo a committed assessment.
 */
function ptc_send_new_assessment_counselor_notifications(
    mysqli $conn,
    int $assessmentId,
    string $studentAlias
): void {
    if ($assessmentId < 1) {
        throw new InvalidArgumentException('Invalid assessment ID.');
    }

    $stmt = $conn->prepare('SELECT assessment_date FROM assessment_data WHERE assessment_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to load assessment submission timestamp.');
    }
    $stmt->bind_param('i', $assessmentId);
    $stmt->execute();
    $assessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$assessment) {
        throw new RuntimeException('New assessment not found after commit.');
    }

    // Database datetime is shown in the application's Asia/Manila timezone.
    $rawTime = trim((string)($assessment['assessment_date'] ?? ''));
    try {
        $dt = new DateTimeImmutable($rawTime !== '' ? $rawTime : 'now', new DateTimeZone('Asia/Manila'));
        $formattedTime = $dt->format('M d, Y · h:i A') . ' (PHT)';
    } catch (Throwable $e) {
        $formattedTime = 'Date recorded in the Counselor Portal';
    }

    $result = $conn->query(
        "SELECT user_id, fullname, email FROM user_data
         WHERE role_type = 'counselor' AND status = 'active'
         ORDER BY user_id ASC"
    );
    if (!$result) {
        throw new RuntimeException('Unable to load active counselor email recipients.');
    }

    $counselors = [];
    $seenEmails = [];
    while ($row = $result->fetch_assoc()) {
        $email = trim((string)($row['email'] ?? ''));
        $emailKey = strtolower($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seenEmails[$emailKey])) {
            continue;
        }
        $seenEmails[$emailKey] = true;
        $counselors[] = [
            'id' => (int)$row['user_id'],
            'name' => trim((string)($row['fullname'] ?? '')) ?: 'Counselor',
            'email' => $email
        ];
    }
    $result->free();
    if (!$counselors) {
        error_log('PTC WELLNESS: no active counselor recipients for new assessment ' . $assessmentId);
        return;
    }

    $url = ptc_counselor_assessment_base_url()
        . '/counselor/view_assessment.php?id=' . rawurlencode((string)$assessmentId);
    $logoPath = __DIR__ . '/../../assets/images/brainalter.png';
    require_once __DIR__ . '/../../config/email.php';

    $subject = 'PTC WELLNESS | New Assessment Submitted - #' . $assessmentId;
    foreach ($counselors as $counselor) {
        $plain = "Dear {$counselor['name']},\n\n"
            . "A student has submitted a new PTC WELLNESS Needs Assessment.\n\n"
            . "Student Alias: " . (trim($studentAlias) ?: 'Student') . "\n"
            . "Assessment ID: #{$assessmentId}\n"
            . "Submitted On: {$formattedTime}\n"
            . "Review Status: Pending Review\n\n"
            . "Review securely in the Counselor Portal: {$url}\n\n"
            . "Thank you for your continued commitment to student well-being.\n\n"
            . "Best regards,\nPTC WELLNESS System\nGuidance Office\n\n"
            . "This is an automated notification. Assessment answers and scores are not included.";
        $html = ptc_counselor_assessment_email_html(
            $counselor['name'], trim($studentAlias) ?: 'Student',
            $assessmentId, $formattedTime, $url
        );
        try {
            $status = sendSystemEmail(
                $counselor['email'], $counselor['name'], $subject,
                $plain, $html, $logoPath
            );
            if (empty($status['success'])) {
                error_log('PTC WELLNESS: counselor notification SMTP failed, assessment '
                    . $assessmentId . ', counselor user_id ' . $counselor['id']);
            }
        } catch (Throwable $e) {
            error_log('PTC WELLNESS: counselor notification error, assessment '
                . $assessmentId . ', counselor user_id ' . $counselor['id'] . ': ' . $e->getMessage());
        }
    }
}
