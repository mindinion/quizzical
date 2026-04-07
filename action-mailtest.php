<?php
require_once 'dblogin.php';
require_once 'PHPMailer/Exception.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    $mail->SMTPDebug  = 2;
    $mail->Debugoutput = 'echo';
    $mail->isSMTP();
    $mail->Host       = 's04ne.syd7.hostingplatform.net.au';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'noreply@quizzical.co.nz';
    $mail->Password   = 'EAN_s.}covB4XW9x';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->isHTML(true);

    $mail->setFrom('noreply@quizzical.co.nz', 'Quizzical');
    $mail->addAddress('kris@teamdf.com');
    $mail->Subject = 'Quizzical verbose test';
    $mail->Body    = '<p>Verbose HTML test</p>';
    $mail->AltBody = 'Verbose HTML test';

    $mail->send();
    echo "\n\nSent OK";
} catch (Exception $e) {
    echo "\n\nFailed: " . $mail->ErrorInfo;
}
?>
