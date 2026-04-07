<?php
/**
 * action-login.php
 *
 * AJAX login endpoint. Verifies credentials and creates a session.
 * Supports lazy MD5→bcrypt migration: if the stored hash is legacy MD5,
 * it is verified and then immediately upgraded to bcrypt on successful login.
 * Echoes "Failed" on bad credentials; sets cookies and echoes nothing on success.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	$email    = isset($_GET['email'])    ? $conn->real_escape_string($_GET['email']) : '';
	$password = isset($_GET['password']) ? $_GET['password'] : '';

	// Fetch stored hash by email
	$result = $conn->query("SELECT id, password_hash FROM Users WHERE email = '$email' LIMIT 1");
	$row    = $result ? $result->fetch_assoc() : null;

	if (!$row) {
		echo "Failed";
		exit;
	}

	$userid     = $row['id'];
	$storedHash = $row['password_hash'];

	// Detect legacy MD5 hash (32 lowercase hex chars) vs bcrypt
	$isMd5    = (strlen($storedHash) === 32 && ctype_xdigit($storedHash));
	// Legacy MD5 hashes were stored as md5(sanitizeString($password)) — match that exactly
	$verified = $isMd5 ? (md5(sanitizeString($password)) === $storedHash) : password_verify($password, $storedHash);

	if (!$verified) {
		echo "Failed";
		exit;
	}

	// Upgrade MD5 to bcrypt on successful login
	if ($isMd5) {
		$newHash = password_hash($password, PASSWORD_BCRYPT);
		$conn->query("UPDATE Users SET password_hash = '" . $conn->real_escape_string($newHash) . "' WHERE id = $userid");
	}

	// Generate a 256-character cryptographically random session token
	$token    = bin2hex(openssl_random_pseudo_bytes(128));
	$location = $conn->real_escape_string($_SERVER['REMOTE_ADDR']);
	$client   = $conn->real_escape_string($_SERVER['HTTP_USER_AGENT']);
	$conn->query("INSERT INTO _SESSION (userid, token, location, client) VALUES ('$userid', '$token', '$location', '$client')");

	$expiry = time() + (365 * 24 * 60 * 60);
	setcookie("session", $token,  $expiry, "/", "", false, true);
	setcookie("userid",  $userid, $expiry, "/", "", false, true);
?>
