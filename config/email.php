<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Builds the shared PTC WELLNESS email layout.
 *
 * $recipientName should be the student's alias, not their full name.
 * $senderName should be the logged-in counselor's display name.
 */
function buildPTCEmailTemplate(
    string $recipientName,
    string $message,
    string $logoCid = 'ptc_wellness_logo',
    string $senderName = 'PTC Wellness Guidance Office',
    string $senderRole = 'Guidance Counselor'
): string {

    $safeRecipient = htmlspecialchars(
        trim($recipientName) !== '' ? $recipientName : 'Student',
        ENT_QUOTES,
        'UTF-8'
    );

    $safeSender = htmlspecialchars(
        trim($senderName) !== '' ? $senderName : 'PTC Wellness Guidance Office',
        ENT_QUOTES,
        'UTF-8'
    );

    $safeRole = htmlspecialchars(
        trim($senderRole) !== '' ? $senderRole : 'Guidance Counselor',
        ENT_QUOTES,
        'UTF-8'
    );

    // Treat counselor-authored content as plain text and preserve paragraphs.
    $safeMessage = nl2br(
        htmlspecialchars(trim($message), ENT_QUOTES, 'UTF-8')
    );

    $safeLogoCid = htmlspecialchars($logoCid, ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>PTC Wellness</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f6f0;color:#354238;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background-color:#f3f6f0;padding:24px 12px;">
        <tr>
            <td align="center">

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background-color:#ffffff;border:1px solid #e3eadd;border-radius:14px;overflow:hidden;">

                    <!-- Branded header -->
                    <tr>
                        <td align="center" style="background-color:#527b55;padding:30px 22px 26px;">
                            <img src="cid:' . $safeLogoCid . '" alt="PTC Wellness logo" width="82" style="display:block;width:82px;max-width:100%;height:auto;margin:0 auto 13px;border:0;">

                            <h1 style="margin:0;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:23px;line-height:1.3;font-weight:700;letter-spacing:1px;">
                                PTC WELLNESS
                            </h1>

                            <p style="margin:7px 0 0;color:#f3f7f0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;">
                                GUIDANCE &amp; STUDENT WELL-BEING
                            </p>
                        </td>
                    </tr>

                    <!-- Email content -->
                    <tr>
                        <td style="padding:30px 28px 32px;font-family:Arial,Helvetica,sans-serif;">

                            <p style="margin:0 0 20px;color:#354238;font-size:15px;line-height:1.7;">
                                Dear <strong>' . $safeRecipient . '</strong>,
                            </p>

                            <div style="margin:0;color:#3f4941;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.85;overflow-wrap:break-word;word-wrap:break-word;">
                                ' . $safeMessage . '
                            </div>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin-top:28px;">
                                <tr>
                                    <td style="border-top:1px solid #e6ece2;padding-top:20px;">
                                        <p style="margin:0 0 8px;color:#3f4941;font-size:14px;line-height:1.6;">
                                            Sincerely,
                                        </p>
                                        <p style="margin:0;color:#294b34;font-size:15px;line-height:1.5;font-weight:700;">
                                            ' . $safeSender . '
                                        </p>
                                        <p style="margin:2px 0 0;color:#68766b;font-size:13px;line-height:1.5;">
                                            ' . $safeRole . '<br>
                                            PTC Wellness Guidance Office
                                        </p>
                                    </td>
                                </tr>
                            </table>

                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" style="background-color:#edf2e9;padding:18px 22px 20px;">
                            <p style="margin:0;color:#536454;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.6;font-weight:700;letter-spacing:.4px;">
                                PATEROS TECHNOLOGICAL COLLEGE
                            </p>
                            <p style="margin:3px 0 0;color:#718071;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.6;">
                                PTC WELLNESS
                            </p>
                            <p style="margin:10px 0 0;color:#7b857b;font-family:Arial,Helvetica,sans-serif;font-size:10px;line-height:1.6;">
                                Please avoid sending sensitive personal or assessment information by email.
                                You may contact the Guidance Office for private concerns.
                            </p>
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</body>
</html>';
}


/**
 * Sends system email notifications.
 *
 * Supports existing plain-text calls and branded HTML emails.
 * Existing calls with four arguments remain compatible.
 */
function sendSystemEmail(
    string $recipientEmail,
    string $recipientName,
    string $subject,
    string $message,
    ?string $htmlMessage = null,
    ?string $embeddedLogoPath = null
): array {

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;

        // Configure these locally. Never commit real credentials to GitHub.
        $mail->Username   = 'counselorptc@gmail.com';
        $mail->Password   = 'iwgv qubk zdev zdaa';

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(
            'YOUR_NEW_GMAIL@gmail.com',
            'PTC WELLNESS'
        );

        $mail->addAddress($recipientEmail, $recipientName);
        $mail->Subject = $subject;

        if ($htmlMessage !== null && trim($htmlMessage) !== '') {
            $mail->isHTML(true);

            if ($embeddedLogoPath !== null) {
                $resolvedLogoPath = realpath($embeddedLogoPath);

                if (
                    $resolvedLogoPath === false ||
                    !is_file($resolvedLogoPath) ||
                    !is_readable($resolvedLogoPath)
                ) {
                    throw new Exception(
                        'PTC WELLNESS logo file not found or unreadable.'
                    );
                }

                $mail->addEmbeddedImage(
                    $resolvedLogoPath,
                    'ptc_wellness_logo',
                    'brainalter.png',
                    'base64',
                    'image/png'
                );
            }

            $mail->Body = $htmlMessage;
            $mail->AltBody = $message;
        } else {
            $mail->isHTML(false);
            $mail->Body = $message;
        }

        $mail->send();

        return [
            'success' => true,
            'message' => 'Email sent successfully.'
        ];

    } catch (Exception $e) {
        error_log(
            'PTC WELLNESS email error: ' .
            $e->getMessage() .
            ' | ' .
            $mail->ErrorInfo
        );

        return [
            'success' => false,
            'message' => 'Unable to send email. Please check the email configuration.'
        ];
    }
}
