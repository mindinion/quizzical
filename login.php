<?php
	require_once 'dblogin.php';
	require_once 'security.php';

	// Initialise the login variables
	$email = "";
	$userpass = "";
	if (isset($_POST['email'])) $email = sanitizeString($_POST['email']);
	else $email = "";
	if (isset($_POST['userpass'])) $userpass = sanitizeString($_POST['userpass']);
	else $userpass = "";

	// Query the database for the login details
	$resUser = mysqli_query($db_server, "SELECT * FROM Users WHERE email = '" . sanitizeMySQL($email) . "';");
	if (mysqli_num_rows($resUser) > 0) {
		$passhash = mysql_result($resUser, 0, 'password_hash');
		$userpasshash = crypt($userpass, CRYPT_BLOWFISH);

		$id = mysql_result($resUser, 0, 'id');
		$groupid = mysql_result($resUser, 0, 'default_group');

		// If the password is correct, then generate a new session token
		// Then add this token to the database as a session for the user
		// And create a cookie for this session and user
		if ($passhash == $userpasshash) {
			// Generate session token and save to database
			$token = bin2hex(openssl_random_pseudo_bytes(128));
			$location = $_SERVER['REMOTE_ADDR'];
			$client = $_SERVER['HTTP_USER_AGENT'];
			$uploadToken = mysqli_query($db_server, "INSERT INTO _SESSION (userid, token, location, client) VALUES ('$id', '$token', '$location', '$client')");

			// Create persistent cookies
			SetCookie ("session", $token, time() + (10 * 365 * 24 * 60 * 60));

			// Go to the main page
			header( "Location: main.php");
		}
		else {
			header( "Location: index.php?mode=1" );
		}
	} else {
		header( "Location: index.php?mode=2" );
	}

?>
