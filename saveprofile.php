<?php
/**
 * saveprofile.php
 *
 * Older AJAX endpoint for saving user profile changes. Receives a JSON string via POST,
 * decodes it, verifies the current password, then updates the Users table.
 * Unlike action-saveprofile.php, this version does not support photo updates via the
 * $photo field — the photo upload code is commented out at the bottom.
 * The UPDATE query is split into two variants: one that includes password_hash (when a
 * new password is provided) and one that omits it (when no password change is requested).
 *
 * Note: This file is distinct from action-saveprofile.php, which conditionally appends
 * optional fields rather than branching on two full query strings.
 */

	error_reporting(-1);
	ini_set('display_errors', 'On');

	require_once 'security.php';
	require_once 'dblogin.php';

	// Receive and decode the profile data from the JSON POST field
	if (isset($_POST['json'])) $profileJson = $_POST['json'];
	$profile = json_decode ($profileJson  ) ;

	// Unpack JSON object fields into local variables
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

	// Verify current password before allowing any changes; respond HTTP 400 if wrong
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

	// Use two separate query strings to avoid setting an empty password_hash
	$passwordNewHash = md5($passwordNew);
	if ($passwordNew == "") {
		// No new password — omit password_hash from the UPDATE
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
		// New password provided — include the hashed value in the UPDATE
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
	// Photo upload code — commented out; photo updates are handled via action-uploadphoto.php
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