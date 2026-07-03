<?php
/**
 * config.php
 *
 * Application-wide configuration.
 * Set DEV_MODE to false at DNS cutover to enable email for all users.
 */

// When true, emails are only sent to superuser accounts
define('DEV_MODE', false);

// OPENAI_API_KEY is defined in dblogin.php (gitignored) alongside DB credentials.

define('MAIL_FROM',      'noreply@quizzical.co.nz');
define('MAIL_FROM_NAME', 'Quizzical');
define('SMTP_HOST',      's04ne.syd7.hostingplatform.net.au');
define('SMTP_USER',      'noreply@quizzical.co.nz');
define('SMTP_PASS',      'EAN_s.}covB4XW9x');
define('SMTP_PORT',      465);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

/**
 * Wraps content in the standard Quizzical HTML email template.
 * $content should be HTML snippets — headings, paragraphs, buttons etc.
 */
function mailHtml($content) {
    return '<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5e6d0;font-family:verdana,arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#fee9cd;padding:40px 0;">
    <tr><td align="center">
      <table width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">

        <!-- Header -->
        <tr><td style="background:#fee9cd;padding:28px 32px 20px;border-radius:12px 12px 0 0;text-align:center;">
          <span style="font-family:verdana,arial,sans-serif;font-size:28px;font-weight:bold;color:#cc5500;letter-spacing:-0.5px;">Qu</span><span style="font-family:verdana,arial,sans-serif;font-size:28px;font-weight:bold;color:#e67300;">izzical</span>
        </td></tr>

        <!-- Body -->
        <tr><td style="background:#ffffff;padding:32px 32px 28px;border-radius:0 0 12px 12px;">
          ' . $content . '
        </td></tr>

        <!-- Footer -->
        <tr><td style="padding:20px 0;text-align:center;">
          <span style="font-family:verdana,arial,sans-serif;font-size:11px;color:#b08060;">You\'re receiving this because you\'re a Quizzical member.</span>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>';
}

/**
 * Send an email via SMTP. In DEV_MODE, only sends if the recipient is a superuser.
 * Pass $system = true for account/password emails to always send regardless of DEV_MODE.
 * Pass $html to send an HTML email body (plain text falls back to strip_tags version).
 */
function sendMail($conn, $to, $subject, $body, $system = false, $html = false) {
    if (DEV_MODE && !$system) {
        $safeEmail = $conn->real_escape_string($to);
        $result = $conn->query("SELECT superuser FROM Users WHERE email = '$safeEmail' LIMIT 1");
        if (!$result || $result->num_rows === 0) return;
        $row = $result->fetch_assoc();
        if (!$row['superuser']) return;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = SMTP_PORT;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($to);
        $mail->Subject = $subject;

        if ($html) {
            $mail->isHTML(true);
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body);
        } else {
            $mail->Body = $body;
        }

        $mail->send();
    } catch (Exception $e) {
        error_log('Mailer error for ' . $to . ': ' . $mail->ErrorInfo);
    }
}
?>
