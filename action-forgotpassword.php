<?php
/**
 * action-forgotpassword.php
 *
 * Handles password reset requests. Looks up the provided email address and, if found,
 * generates a secure random token, stores it in PasswordResets with a 1-hour expiry,
 * and emails the user a link to reset their password.
 * Echoes "Success" on completion, or "Failed" if the email is not registered.
 */

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'config.php';

	if (isset($_GET['email'])) $email = $conn->real_escape_string($_GET['email']);

	$q = "SELECT id FROM Users WHERE email = '$email';";
	$user = $conn->query($q);

	while($row = mysqli_fetch_assoc($user)) {
		$userid = $row['id'];
	}

	if (($userid ?? "") == "") {
		echo "Failed";
	} else {
		// Generate a secure random token and store it with a 1-hour expiry
		$token = bin2hex(openssl_random_pseudo_bytes(32));
		$expires = date("Y-m-d H:i:s", time() + 3600);

		// Remove any existing reset tokens for this email before inserting a new one
		$conn->query("DELETE FROM PasswordResets WHERE email = '$email'");
		$conn->query("INSERT INTO PasswordResets (token, email, expires) VALUES ('$token', '$email', '$expires')");

		$resetUrl = "http://quizzical.co.nz/resetpassword.html?token=" . $token;
		$body = "Hi,\n\nClick the link below to reset your Quizzical password. This link expires in 1 hour.\n\n" . $resetUrl . "\n\nIf you didn't request this, you can ignore this email.";
		sendMail($conn, $email, "Reset your Quizzical password", $body, true);
		echo "Success";
	}

?>
