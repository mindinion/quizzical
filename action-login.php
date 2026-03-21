<?php

	require_once 'dblogin.php';
	require_once 'security.php';
	
	if (isset($_GET['email'])) $email = sanitizeString($_GET['email']);
	if (isset($_GET['password'])) $password = sanitizeString($_GET['password']);
	$passhash = md5($password);


	$q = "SELECT id FROM Users WHERE email = '$email' and password_hash = '$passhash';";

	$user = $conn->query($q);

	while($row = mysqli_fetch_assoc($user)) {
		$userid = $row['id'];
	}
	
	if ($userid == "") {
		echo "Failed" ;
	} else {
			$token = bin2hex(openssl_random_pseudo_bytes(128));
			$location = $_SERVER['REMOTE_ADDR'];
			$client = $_SERVER['HTTP_USER_AGENT'];
			$q = "INSERT INTO _SESSION (userid, token, location, client) VALUES ('$userid', '$token', '$location', '$client')";
			$query = $conn->query($q);
			
			// Create persistent cookies
			SetCookie ("session", $token, time() + (10 * 365 * 24 * 60 * 60));
			SetCookie ("userid" , $userid, time() + (10 * 365 * 24 * 60 * 60));
	}
	
?>