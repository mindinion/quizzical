<?php
/**
 * config.php
 *
 * Application-wide configuration.
 * Set DEV_MODE to false at DNS cutover to enable email for all users.
 */

// When true, emails are only sent to superuser accounts
define('DEV_MODE', true);

define('MAIL_FROM', 'Quizzical <noreply@quizzical.co.nz>');

/**
 * Send an email. In DEV_MODE, only sends if the recipient is a superuser.
 * Always sends for account/password emails (system=true) regardless of DEV_MODE.
 */
function sendMail($conn, $to, $subject, $body, $system = false) {
    if (!DEV_MODE || $system) {
        mail($to, $subject, $body, "From: " . MAIL_FROM);
        return;
    }

    // In DEV_MODE, check if recipient is a superuser before sending
    $safeEmail = $conn->real_escape_string($to);
    $result = $conn->query("SELECT superuser FROM Users WHERE email = '$safeEmail' LIMIT 1");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['superuser']) {
            mail($to, $subject, $body, "From: " . MAIL_FROM);
        }
    }
}
?>
