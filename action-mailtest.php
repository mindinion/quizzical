<?php
require_once 'dblogin.php';
require_once 'config.php';

$to      = 'widdakay@gmail.com';
$subject = 'Quizzical SMTP test';
$body    = 'If you received this, SMTP email is working correctly.';

sendMail($conn, $to, $subject, $body, true);
echo "Done";
?>
