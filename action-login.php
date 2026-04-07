<?php
/**
 * action-login.php
 *
 * AJAX login endpoint used by the single-page front end. Validates credentials by
 * comparing the MD5 hash of the submitted password against the stored hash, then
 * creates a session record in the _SESSION table and sets persistent cookies
 * (10-year expiry) for the session token and userid.
 * Echoes "Failed" if credentials are incorrect; sets cookies and returns nothing on success.
 *
 * Note: MD5 password hashing is weak — the legacy login.php uses Blowfish (crypt).
 * This endpoint appears to be a separate, older API login path.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['email'])) $email = $conn->real_escape_string($_GET['email']);
	if (isset($_GET['password'])) $password = sanitizeString($_GET['password']);
	$passhash = md5($password);

	// Look up user by email and password hash in one query
	$q = "SELECT id FROM Users WHERE email = '$email' and password_hash = '$passhash';";

	$user = $conn->query($q);

	while($row = mysqli_fetch_assoc($user)) {
		$userid = $row['id'];
	}

	if ($userid == "") {
		echo "Failed" ;
	} else {
		// Generate a 256-character cryptographically random session token
		$token = bin2hex(openssl_random_pseudo_bytes(128));
		$location = $_SERVER['REMOTE_ADDR'];
		$client = $_SERVER['HTTP_USER_AGENT'];
		$q = "INSERT INTO _SESSION (userid, token, location, client) VALUES ('$userid', '$token', '$location', '$client')";
		$query = $conn->query($q);

		$expiry = time() + (365 * 24 * 60 * 60);
		setcookie("session", $token,  $expiry, "/", "", false, true);
		setcookie("userid",  $userid, $expiry, "/", "", false, true);
	}

?>