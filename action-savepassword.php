<?php
/**
 * action-savepassword.php
 *
 * AJAX endpoint for changing a user's password. Receives JSON with
 * passwordOld, passwordNew, and userid. Verifies the current password
 * by comparing its MD5 hash against the stored hash — responds with
 * HTTP 400 if it doesn't match, 200 on success.
 */

	require_once 'security.php';
	require_once 'dblogin.php';

	if (isset($_POST['json'])) $json = $_POST['json'];
	$data = json_decode($json);

	$passwordOld = $data->passwordOld;
	$passwordNew = $data->passwordNew;
	$userid      = (int)$data->userid;

	// Verify current password
	$passwordOldHash = md5($passwordOld);
	$q = "SELECT COUNT(*) as passed FROM Users WHERE id = $userid AND password_hash = '$passwordOldHash';";
	$result = $conn->query($q);
	$row = mysqli_fetch_assoc($result);
	if ($row['passed'] != 1) {
		header("HTTP/1.1 400 Incorrect password");
		exit;
	}

	// Update to new password
	$passwordNewHash = md5($passwordNew);
	$conn->query("UPDATE Users SET password_hash='$passwordNewHash' WHERE id = $userid;");

?>
