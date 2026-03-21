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
	$photo			= $profile->photo;
	
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
	
	$q = 	"UPDATE Users SET first_name='$firstname', 
			last_name='$lastname', 
			email='$email', 
			notify_results=$notifyEmail, 
			notify_message=$notifyMessage, 
			default_group=$groupid,";
			
	if ($passwordNew != "") {
		$q.= "password_hash='$passwordNewHash',";
	}
	
	if ($photo != "") {
		$q.= "pic_filename='$photo',";
	}
	
	$q.= "timezone='$timezone' WHERE id = $userid;";
	
	$result = $conn->query($q);
	echo $q;		

?>