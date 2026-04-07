<?php
require_once 'dblogin.php';
require_once 'config.php';

$html = mailHtml('
    <p style="margin:0 0 16px;font-size:15px;color:#333;">This is a test email from Quizzical. If you\'re reading this, HTML email is working!</p>
    <a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View Quizzical</a>
');

sendMail($conn, 'widdakay@gmail.com', 'Quizzical test email', $html, true, true);
echo "Done";
?>
