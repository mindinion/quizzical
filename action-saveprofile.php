<?php
/**
 * action-saveprofile.php
 *
 * AJAX endpoint that saves changes to a user's profile. Receives profile data as a
 * JSON string in the POST body, decoded into an object. Before updating, verifies the
 * current password by comparing its MD5 hash against the stored hash — responds with
 * HTTP 400 if it doesn't match.
 *
 * The UPDATE query is built conditionally:
 *   - password_hash is only updated if a new password was provided
 *   - pic_filename is only updated if a photo filename was provided
 * This avoids accidentally clearing fields that weren't changed.
 *
 * Note: echo $q at the end outputs the raw SQL query to the response — this is a
 * debugging artifact and should be removed before production use.
 */

	error_reporting(-1);
	ini_set('display_errors', 'On');

	require_once 'security.php';
	require_once 'dblogin.php';

	// Receive the profile data as a JSON-encoded POST body field
	if (isset($_POST['json'])) $profileJson = $_POST['json'];
	$profile = json_decode ($profileJson  ) ;

	// Unpack the JSON object fields into local variables for clarity
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

	// Verify the current password before allowing any changes
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

	// Build the UPDATE query, conditionally appending optional fields
	$passwordNewHash = md5($passwordNew);

	$q = 	"UPDATE Users SET first_name='$firstname',
			last_name='$lastname',
			email='$email',
			notify_results=$notifyEmail,
			notify_message=$notifyMessage,
			default_group=$groupid,";

	// Only update the password if a new one was supplied
	if ($passwordNew != "") {
		$q.= "password_hash='$passwordNewHash',";
	}

	// Only update the photo filename if one was provided
	if ($photo != "") {
		$q.= "pic_filename='$photo',";
	}

	$q.= "timezone='$timezone' WHERE id = $userid;";

	$result = $conn->query($q);
	echo $q;  // Debug: echoes the executed SQL query — remove for production

?>