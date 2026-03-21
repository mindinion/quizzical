<?php
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
		// Create new random password and set it
		$newpassword = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789') , 0 , 8 );
		$newpasswordhash = md5($newpassword);
		$q = "UPDATE Users SET Users.password_hash = '$newpasswordhash' WHERE Users.email = '$email';";
		$query = $conn->query($q);
		mail($email,"Forgotten Password", "Here is your new password: " . $newpassword, "From: Quizzical");
		echo "Success";
	}

		
?>