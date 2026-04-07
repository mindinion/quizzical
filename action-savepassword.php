<?php
/**
 * action-savepassword.php
 *
 * AJAX endpoint for changing a user's password. Receives JSON with
 * passwordOld, passwordNew, and userid. Verifies the current password
 * against the stored hash (handles both legacy MD5 and bcrypt).
 * Saves the new password as bcrypt. Responds with HTTP 400 if the
 * old password is wrong, 200 on success.
 */

	require_once 'security.php';
	require_once 'dblogin.php';

	if (isset($_POST['json'])) $json = $_POST['json'];
	$data = json_decode($json);

	$passwordOld = $data->passwordOld;
	$passwordNew = $data->passwordNew;
	$userid      = (int)$data->userid;

	// Fetch stored hash
	$result     = $conn->query("SELECT password_hash FROM Users WHERE id = $userid LIMIT 1");
	$row        = $result ? $result->fetch_assoc() : null;
	$storedHash = $row ? $row['password_hash'] : '';

	// Verify old password — handle both legacy MD5 and bcrypt
	$isMd5    = (strlen($storedHash) === 32 && ctype_xdigit($storedHash));
	$verified = $isMd5 ? (md5($passwordOld) === $storedHash) : password_verify($passwordOld, $storedHash);

	if (!$verified) {
		header("HTTP/1.1 400 Incorrect password");
		exit;
	}

	// Save new password as bcrypt
	$newHash = password_hash($passwordNew, PASSWORD_BCRYPT);
	$conn->query("UPDATE Users SET password_hash = '" . $conn->real_escape_string($newHash) . "' WHERE id = $userid");
?>
