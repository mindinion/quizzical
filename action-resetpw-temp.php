<?php
require_once 'dblogin.php';
$email = 'widdakay@gmail.com';
$newpassword = 'Quizzical1';
$hash = md5($newpassword);
$conn->query("UPDATE Users SET password_hash = '$hash' WHERE email = '" . $conn->real_escape_string($email) . "'");
echo "Done — rows affected: " . $conn->affected_rows;
?>
