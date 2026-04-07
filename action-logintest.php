<?php
require_once 'dblogin.php';
require_once 'security.php';

$email    = isset($_GET['email'])    ? $conn->real_escape_string($_GET['email']) : '';
$password = isset($_GET['password']) ? $_GET['password'] : '';

$result = $conn->query("SELECT id, password_hash FROM Users WHERE email = '$email' LIMIT 1");
$row    = $result ? $result->fetch_assoc() : null;

if (!$row) { echo "NO USER FOUND for email: $email"; exit; }

$storedHash = $row['password_hash'];
$isMd5      = (strlen($storedHash) === 32 && ctype_xdigit($storedHash));
$sanitized  = sanitizeString($password);

echo "Hash type: " . ($isMd5 ? 'MD5' : 'bcrypt') . "\n";
echo "Hash length: " . strlen($storedHash) . "\n";
echo "Stored hash: $storedHash\n";
echo "Raw password md5: " . md5($password) . "\n";
echo "Sanitized password md5: " . md5($sanitized) . "\n";
echo "Sanitized == raw: " . ($sanitized === $password ? 'YES' : 'NO') . "\n";
if (!$isMd5) echo "password_verify result: " . (password_verify($password, $storedHash) ? 'TRUE' : 'FALSE') . "\n";
?>
