<?php
require_once 'dblogin.php';
require_once 'config.php';

$to = 'noreply@quizzical.co.nz';

$cta = '<a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>';

// Shared blocks used across samples
$resultBlock = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">Result</p>
    <p style="margin:0 0 24px;font-size:13px;color:#333;background:#fff8f0;border-left:4px solid #e67300;padding:10px 14px;border-radius:4px;">
    <strong>Robyn Weyling</strong> scored <strong>15/15</strong> in the Morning quiz on 1 April 2026<br>
    <em style="color:#666;">Smashed it today!</em>
    </p>';

$postBlock = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">Robyn Weyling posted</p>
    <p style="margin:0 0 24px;font-size:13px;color:#333;background:#fff8f0;border-left:4px solid #e67300;padding:10px 14px;border-radius:4px;">Anyone else find today\'s music round impossible? 🎵</p>';

$postBlockWithAttachments = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">Robyn Weyling posted</p>
    <p style="margin:0 0 8px;font-size:13px;color:#333;background:#fff8f0;border-left:4px solid #e67300;padding:10px 14px;border-radius:4px;">Check out my score sheet!</p>
    <p style="margin:0 0 24px;font-size:12px;color:#a07040;">Attachments: scoresheet.jpg, results.pdf</p>';

$postContext = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">Original post</p>
    <p style="margin:0 0 16px;font-size:13px;color:#666;background:#fff8f0;border-left:4px solid #e0c8a8;padding:10px 14px;border-radius:4px;">Anyone else find today\'s music round impossible? 🎵</p>';

$resultContext = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">Original post</p>
    <p style="margin:0 0 16px;font-size:13px;color:#666;background:#fff8f0;border-left:4px solid #e0c8a8;padding:10px 14px;border-radius:4px;">
    Robyn Weyling scored 15/15 in the Morning quiz on 1 April 2026<br><em>Smashed it today!</em>
    </p>';

$commentBlock = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">New comment from Kris Weyling</p>
    <p style="margin:0 0 24px;font-size:13px;color:#333;background:#fff3e8;border-left:4px solid #e67300;padding:10px 14px;border-radius:4px;">Haha same, those song clips were brutal 😂</p>
    <p style="margin:0;">';

$commentBlockWithAttachments = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">New comment from Kris Weyling</p>
    <p style="margin:0 0 8px;font-size:13px;color:#333;background:#fff3e8;border-left:4px solid #e67300;padding:10px 14px;border-radius:4px;">Here\'s my answer sheet</p>
    <p style="margin:0 0 16px;font-size:12px;color:#a07040;">Attachments: answers.jpg</p>
    <p style="margin:16px 0 0;">';

$ctaClose = $cta . '</p>';

// 1. New result notification (notify_results=1 group members)
sendMail($conn, $to, "Robyn Weyling posted a new Morning quiz result", mailHtml($resultBlock . $cta), true, true);

// 2. New post notification (notify_message=1 group members)
sendMail($conn, $to, "Robyn Weyling posted on Quizzical", mailHtml($postBlock . $cta), true, true);

// 3. New post with attachments
sendMail($conn, $to, "Robyn Weyling posted on Quizzical", mailHtml($postBlockWithAttachments . $cta), true, true);

// 4. New comment on your post (original poster always notified)
sendMail($conn, $to, "New comment on your post", mailHtml(
    '<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>Kris Weyling</strong> commented on your post.</p>' .
    $postContext . $commentBlock . $ctaClose
), true, true);

// 5. New comment on a result post you made
sendMail($conn, $to, "New comment on your post", mailHtml(
    '<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>Kris Weyling</strong> commented on your post.</p>' .
    $resultContext . $commentBlock . $ctaClose
), true, true);

// 6. New comment on a post you also commented on (previous commenter always notified)
sendMail($conn, $to, "New comment on Quizzical", mailHtml(
    '<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>Kris Weyling</strong> commented on a post you\'ve also commented on.</p>' .
    $postContext . $commentBlock . $ctaClose
), true, true);

// 7. New comment with attachment
sendMail($conn, $to, "New comment on your post", mailHtml(
    '<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>Kris Weyling</strong> commented on your post.</p>' .
    $postContext . $commentBlockWithAttachments . $ctaClose
), true, true);

// 8. Someone digs your result
sendMail($conn, $to, "Robyn Weyling digs your post", mailHtml(
    '<p style="margin:0 0 8px;font-size:15px;color:#333;"><strong>Robyn Weyling</strong> digs your result:</p>
    <p style="margin:0 0 24px;font-size:14px;color:#666;background:#fff8f0;border-left:4px solid #e67300;padding:12px 16px;border-radius:4px;">15 out of 15 in the Morning quiz on Tuesday, 01 April 2026</p>' .
    $cta
), true, true);

// 9. Someone digs your comment
sendMail($conn, $to, "Robyn Weyling digs your comment", mailHtml(
    '<p style="margin:0 0 8px;font-size:15px;color:#333;"><strong>Robyn Weyling</strong> digs your comment:</p>
    <p style="margin:0 0 24px;font-size:14px;color:#666;background:#fff8f0;border-left:4px solid #e67300;padding:12px 16px;border-radius:4px;">Haha same, those song clips were brutal 😂</p>' .
    $cta
), true, true);

// 10. Password reset
$resetUrl = "http://quizzical.co.nz/resetpassword.html?token=exampletoken123";
sendMail($conn, $to, "Reset your Quizzical password", mailHtml('
    <p style="margin:0 0 8px;font-size:15px;color:#333;">We received a request to reset your Quizzical password.</p>
    <p style="margin:0 0 24px;font-size:13px;color:#888;">This link expires in 1 hour. If you didn\'t request this, you can ignore this email.</p>
    <a href="' . $resetUrl . '" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">Reset my password</a>
'), true, true);

echo "All 10 emails sent.";
?>
