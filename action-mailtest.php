<?php
require_once 'dblogin.php';
require_once 'config.php';

$to = 'noreply@quizzical.co.nz';

// 1. New comment on your post
sendMail($conn, $to, "New comment on your post", mailHtml('
    <p style="margin:0 0 16px;font-size:15px;color:#333;">Someone commented on your post.</p>
    <a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>
'), true, true);

// 2. New comment on someone else's post you commented on
sendMail($conn, $to, "New comment on Quizzical", mailHtml('
    <p style="margin:0 0 16px;font-size:15px;color:#333;">Robyn Weyling commented on Kris Weyling\'s post.</p>
    <a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>
'), true, true);

// 3. Someone digs your result
sendMail($conn, $to, "Robyn Weyling digs your post", mailHtml('
    <p style="margin:0 0 8px;font-size:15px;color:#333;"><strong>Robyn Weyling</strong> digs your result:</p>
    <p style="margin:0 0 24px;font-size:14px;color:#666;background:#fff8f0;border-left:4px solid #e67300;padding:12px 16px;border-radius:4px;">15 out of 15 in the Morning quiz on Tuesday, 01 April 2026</p>
    <a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>
'), true, true);

// 4. Someone digs your comment
sendMail($conn, $to, "Robyn Weyling digs your comment", mailHtml('
    <p style="margin:0 0 8px;font-size:15px;color:#333;"><strong>Robyn Weyling</strong> digs your comment:</p>
    <p style="margin:0 0 24px;font-size:14px;color:#666;background:#fff8f0;border-left:4px solid #e67300;padding:12px 16px;border-radius:4px;">Haha well done everyone!</p>
    <a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>
'), true, true);

// 5. New post notification
sendMail($conn, $to, "New post on Quizzical", mailHtml('
    <p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>Robyn Weyling</strong> posted something new on Quizzical.</p>
    <a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>
'), true, true);

// 6. New result notification
sendMail($conn, $to, "New result on Quizzical", mailHtml('
    <p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>Robyn Weyling</strong> posted a new Morning quiz result on Quizzical.</p>
    <a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>
'), true, true);

// 7. Password reset
$resetUrl = "http://quizzical.co.nz/resetpassword.html?token=exampletoken123";
sendMail($conn, $to, "Reset your Quizzical password", mailHtml('
    <p style="margin:0 0 8px;font-size:15px;color:#333;">We received a request to reset your Quizzical password.</p>
    <p style="margin:0 0 24px;font-size:13px;color:#888;">This link expires in 1 hour. If you didn\'t request this, you can ignore this email.</p>
    <a href="' . $resetUrl . '" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">Reset my password</a>
'), true, true);

echo "All 7 emails sent.";
?>
