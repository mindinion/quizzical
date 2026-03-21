<?php
/**
 * login.php
 *
 * Handles the traditional form-based login flow (POST from index.php).
 * Looks up the user by email, then verifies the password using crypt() with Blowfish,
 * which matches the hashing method used in action-createaccount.php.
 *
 * On success: creates a session record in _SESSION, sets a persistent 'session' cookie
 * (10-year expiry), and redirects to main.php.
 * On failure: redirects to index.php?mode=1 (wrong password) or ?mode=2 (email not found).
 *
 * Note: This is separate from action-login.php which is the AJAX login endpoint and
 * uses MD5 hashing — the two are incompatible if the same account is used across both.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	// Initialise the login variables
	$email = "";
	$userpass = "";
	if (isset($_POST['email'])) $email = sanitizeString($_POST['email']);
	else $email = "";
	if (isset($_POST['userpass'])) $userpass = sanitizeString($_POST['userpass']);
	else $userpass = "";

	// Look up the user record by email to retrieve the stored password hash
	$resUser = mysqli_query($db_server, "SELECT * FROM Users WHERE email = '" . sanitizeMySQL($email) . "';");
	if (mysqli_num_rows($resUser) > 0) {
		$passhash = mysql_result($resUser, 0, 'password_hash');
		// crypt() with the stored hash as salt re-derives the hash for comparison (Blowfish)
		$userpasshash = crypt($userpass, CRYPT_BLOWFISH);

		$id = mysql_result($resUser, 0, 'id');
		$groupid = mysql_result($resUser, 0, 'default_group');

		if ($passhash == $userpasshash) {
			// Generate a 256-character cryptographically random session token
			$token = bin2hex(openssl_random_pseudo_bytes(128));
			$location = $_SERVER['REMOTE_ADDR'];
			$client = $_SERVER['HTTP_USER_AGENT'];
			// Record the new session in the database for future token validation
			$uploadToken = mysqli_query($db_server, "INSERT INTO _SESSION (userid, token, location, client) VALUES ('$id', '$token', '$location', '$client')");

			// Set a persistent session cookie (10-year expiry)
			SetCookie ("session", $token, time() + (10 * 365 * 24 * 60 * 60));

			header( "Location: main.php");
		}
		else {
			// mode=1 signals to the login form that the password was incorrect
			header( "Location: index.php?mode=1" );
		}
	} else {
		// mode=2 signals that no account exists for the given email
		header( "Location: index.php?mode=2" );
	}

?>
