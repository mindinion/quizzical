<?php
require_once 'dblogin.php';
require_once 'config.php';

$to      = 'widdakay@gmail.com';
$subject = 'Quizzical mail test';
$body    = 'If you received this, outbound email is working.';

$result = mail($to, $subject, $body, "From: " . MAIL_FROM);
echo $result ? "mail() returned true" : "mail() returned false";
?>
