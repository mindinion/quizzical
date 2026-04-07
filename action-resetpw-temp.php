<?php
require_once 'dblogin.php';
$email    = isset($_GET['email'])    ? $conn->real_escape_string($_GET['email']) : '';
$password = isset($_GET['password']) ? $_GET['password'] : '';
if (!$email || !$password) { echo "Provide email and password params"; exit; }
$hash = password_hash($password, PASSWORD_BCRYPT);
$result = $conn->query("UPDATE Users SET password_hash = '" . $conn->real_escape_string($hash) . "' WHERE email = '$email'");
echo $result && $conn->affected_rows === 1 ? "OK — password updated" : "Error or email not found";
?>
