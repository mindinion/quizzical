<?php
/**
 * action-forgotpassword.php
 *
 * Handles password reset requests. Looks up the provided email address and, if found,
 * generates a random 8-character alphanumeric password, stores its MD5 hash in the database,
 * and emails the plain-text password to the user.
 * Echoes "Success" on completion, or "Failed" if the email is not registered.
 *
 * Note: MD5 is used here for hashing — a stronger algorithm (e.g., bcrypt) is recommended
 * for production use.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['email'])) $email = sanitizeString($_GET['email']);

	$q = "SELECT id FROM Users WHERE email = '" . $email . "';";
	$user = $conn->query($q);

	while($row = mysqli_fetch_assoc($user)) {
		$userid = $row['id'];
	}

	if (($userid ?? "") == "") {
		echo "Failed";
	} else {
		// Generate a random 8-char password by shuffling the character pool and taking the first 8
		$newpassword = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') , 0 , 8 );
		$newpasswordhash = md5($newpassword);
		$q = "UPDATE Users SET Users.password_hash = '$newpasswordhash' WHERE Users.email = '$email';";
		$query = $conn->query($q);
		mail($email,"Forgotten Password", "Here is your new password: " . $newpassword, "From: Quizzical");
		echo "Success";
	}

?>