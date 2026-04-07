<?php
/**
 * config.php
 *
 * Application-wide configuration.
 * Set DEV_MODE to false at DNS cutover to enable email for all users.
 */

// When true, emails are only sent to superuser accounts
define('DEV_MODE', true);

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
