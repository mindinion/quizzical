<?php
/**
 * action-logout.php
 *
 * Deletes the session record from the database, expires session cookies,
 * and redirects to the login page.
 * Clears cookies with both path="/" (new style) and no path (legacy style,
 * defaulted to the server subdirectory) to handle cookies set either way.
 */

	require_once 'dblogin.php';

	// Delete the session record from the database so the token can't be reused
	$token = isset($_COOKIE['session']) ? $conn->real_escape_string($_COOKIE['session']) : '';
	if ($token) {
		$conn->query("DELETE FROM _SESSION WHERE token = '$token'");
	}

	// Expire cookies — clear both "/" path (new) and default path (legacy)
	setcookie("session", "", time() - 3600, "/", "", true, true);
	setcookie("session", "", time() - 3600);
	setcookie("userid",  "", time() - 3600, "/", "", true, true);
	setcookie("userid",  "", time() - 3600);

	header("Location: index.html");
	exit;
?>
