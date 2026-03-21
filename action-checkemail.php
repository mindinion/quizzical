<?php
/**
 * action-checkemail.php
 *
 * Checks whether a given email address is already registered in the database.
 * Called via AJAX during account creation to give instant feedback before form submission.
 * Echoes "Exists" if the email is taken, or "Free" if it is available.
 */

	ini_set('error_reporting', E_STRICT);

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';

	if (isset($_GET['email'])) $email = sanitizeString($_GET['email']);

	$q = "SELECT id FROM Users WHERE email = '$email';";

	$user = $conn->query($q);

	// Fetch the user id if a matching email row exists
	while($row = mysqli_fetch_assoc($user)) {
		$id = $row['id'];
	}

	// Only set if the query returned at least one row
	if (isset($id) && $id != null) {
		echo "Exists";
	} else {
		echo "Free";
	}

?>