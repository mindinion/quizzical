<?php
	error_reporting(-1);
	ini_set('display_errors', 'On');
	
	require_once 'security.php';
	require_once 'dblogin.php';

	// Retrieve the json data
	if (isset($_POST['json'])) $profileJson = $_POST['json'];
	$profile = json_decode ($profileJson  ) ;
	
	// Make sure the current password is correct
	$email 			= $profile->email;
	$firstname 		= $profile->firstname;
	$lastname 		= $profile->lastname;
	$passwordOld 	= $profile->password;
	$passwordNew 	= $profile->passwordNew;
	$notifyEmail 	= $profile->notifyEmail;
	$notifyMessage 	= $profile->notifyMessage;
	$timezone 		= $profile->timezone;
	$groupid 		= $profile->groupid;
	$userid 		= $profile->userid;
	
	// First make sure the current password is correct
	$passwordOldHash = md5($passwordOld);
	$q = "SELECT COUNT(*) as passed FROM Users WHERE id = $userid and password_hash = '$passwordOldHash';";
	$result = $conn->query($q);		
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {	
			if ($row['passed'] != 1 ) {
				header("HTTP/1.1 400 Incorrect password");
				exit;
			}
		 	else header("HTTP/1.1 200 OK");
		}
	}
	
	// Generate the query to update the users data
	$passwordNewHash = md5($passwordNew);
	if ($passwordNew == "") {
		$q = "UPDATE Users SET 
			first_name='$firstname', 
			last_name='$lastname', 
			email='$email', 
			notify_results=$notifyEmail, 
			notify_message=$notifyMessage, 
			default_group=$groupid,
			timezone='$timezone'
			WHERE id = $userid;";
	} else {
		$q = "UPDATE Users SET 
			first_name='$firstname', 
			last_name='$lastname', 
			email='$email', 
			password_hash='$passwordNewHash', 
			notify_results=$notifyEmail, 
			notify_message=$notifyMessage, 
			default_group=$groupid,
			timezone='$timezone'
			WHERE id = $userid;";
	}
	$result = $conn->query($q);		

	/*
	
	// Upload pic and update database with filename for pics
	if($_FILES['profilepic']['name']) {
		$newFileName = "zzuser" . $userid . "." . $ext = pathinfo($_FILES['profilepic']['name'], PATHINFO_EXTENSION);
		$newFileName = strtolower($newFileName);
		move_uploaded_file($_FILES["profilepic"]["tmp_name"], $newFileName);
		if($error) print $error;
		$result = mysql_query("UPDATE Users SET pic_filename = '$newFileName' WHERE id = $userid;");
	}
	 
	//header( "Location: main.php");		
	*/
?>