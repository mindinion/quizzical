<?php
/**
 * action-resetpassword.php
 *
 * Validates a password reset token and, if valid and unexpired, updates the user's
 * password and creates a session so they are logged in immediately.
 * Echoes "OK" on success, "Expired" if the token has passed its expiry, or "Failed"
 * if the token does not exist.
 * The token is deleted after use so it cannot be reused.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	$token    = isset($_GET['token'])    ? $conn->real_escape_string($_GET['token']) : '';
	$password = isset($_GET['password']) ? $_GET['password'] : '';

	if (!$token || !$password) {
		echo "Failed";
		exit;
	}

	// Look up the token
	$result = $conn->query("SELECT email, expires FROM PasswordResets WHERE token = '$token' LIMIT 1");
	if (!$result || $result->num_rows === 0) {
		echo "Failed";
		exit;
	}

	$row     = $result->fetch_assoc();
	$email   = $row['email'];
	$expires = strtotime($row['expires']);

	// Check expiry
	if (time() > $expires) {
		$conn->query("DELETE FROM PasswordResets WHERE token = '$token'");
		echo "Expired";
		exit;
	}

	// Update the password
	$hash = password_hash($password, PASSWORD_BCRYPT);
	$safeEmail = $conn->real_escape_string($email);
	$conn->query("UPDATE Users SET password_hash = '$hash' WHERE email = '$safeEmail'");

	// Delete the used token
	$conn->query("DELETE FROM PasswordResets WHERE token = '$token'");

	// Look up the user id and create a session so they're logged in immediately
	$userResult = $conn->query("SELECT id FROM Users WHERE email = '$safeEmail' LIMIT 1");
	$userRow    = $userResult->fetch_assoc();
	$userid     = $userRow['id'];

	$sessionToken = bin2hex(openssl_random_pseudo_bytes(128));
	$location     = $conn->real_escape_string($_SERVER['REMOTE_ADDR']);
	$client       = $conn->real_escape_string($_SERVER['HTTP_USER_AGENT']);
	$conn->query("INSERT INTO _SESSION (userid, token, location, client) VALUES ('$userid', '$sessionToken', '$location', '$client')");
	$expiry = time() + (365 * 24 * 60 * 60);
	setcookie("session", $sessionToken, $expiry, "/", "", true, true);
	setcookie("userid",  $userid,       $expiry, "/", "", true, false);

	echo "OK";

?>
