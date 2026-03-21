<?php
/**
 * action-createaccount.php
 *
 * Handles the legacy HTML form-based account creation flow (POST from create_account.php).
 * Checks that the email is not already in use, hashes the password with Blowfish,
 * inserts the new user with default settings, then adds them to the public group (id=2).
 * Redirects to index.php?mode=3 on success, or back to create_account.php?mode=1 if the
 * email already exists.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	// Get inputted details
	if (isset($_POST['firstname'])) $firstname = sanitizeString($_POST['firstname']);
	else $firstname = "";
	if (isset($_POST['lastname'])) $lastname = sanitizeString($_POST['lastname']);
	else $lastname = "";
	if (isset($_POST['email'])) $email = sanitizeString($_POST['email']);
	else $email = "";
	if (isset($_POST['password'])) $password = sanitizeString($_POST['password']);
	else $password = "";

	// If the email isn't currently being used, create the account and put them in the public group
	$resUser = mysqli_query($db_server, "SELECT * FROM Users WHERE email = '" . sanitizeMySQL($email) . "';");
	// Blowfish hash is more secure than md5; CRYPT_BLOWFISH is the cost-factor constant
	$passwordhash = crypt($password, CRYPT_BLOWFISH);
	if (mysqli_num_rows($resUser) == 0) {
		// Insert with sensible defaults: NZ timezone, group 2 (public), notifications off
		mysqli_query($db_server, "INSERT INTO Users (first_name, last_name, email, password_hash, num_results, num_messages, notify_results, notify_message, default_group, timezone)
		VALUES ('$firstname','$lastname','$email','$passwordhash', 11, 50, 0, 0, 2, 'NZ')");
		// Find the new user ID and add them to the public group
		$queryNewId = mysqli_query($db_server, "SELECT id FROM Users WHERE email = '$email';");
		$newId = mysql_result($queryNewId, 0, 'id');
		mysqli_query($db_server, "INSERT INTO Memberships (group_id, user_id) VALUES (2, $newId)");
		header( "Location: index.php?mode=3" );
	} else {
		header( "Location: create_account.php?mode=1" );
	}
?>
