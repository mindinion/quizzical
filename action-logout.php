<?php
/**
 * action-logout.php
 *
 * Logs the user out by expiring the session cookie (setting expiry to -1 makes it
 * expire immediately) and redirecting to the index page.
 * The session record in the _SESSION database table is not explicitly removed here.
 */

	require_once 'dblogin.php';

	// Delete the session record from the database so the token can't be reused
	$token = isset($_COOKIE['session']) ? $conn->real_escape_string($_COOKIE['session']) : '';
	if ($token) {
		$conn->query("DELETE FROM _SESSION WHERE token = '$token'");
	}

	// Expire both cookies immediately
	setcookie("session", "", time() - 3600, "/", "", false, true);
	setcookie("userid",  "", time() - 3600, "/", "", false, true);
	header("Location: index.html");
?>