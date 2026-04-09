<?php
/**
 * action-saveprofile.php
 *
 * AJAX endpoint that saves changes to a user's profile (account details,
 * preferences, and photo). Password changes are handled separately by
 * action-savepassword.php.
 *
 * The UPDATE query conditionally updates pic_filename only if a photo
 * filename was provided, to avoid clearing a field that wasn't changed.
 */

	require_once 'require_auth.php';

	// Receive the profile data as a JSON-encoded POST body field
	if (isset($_POST['json'])) $profileJson = $_POST['json'];
	$profile = json_decode($profileJson);

	// Unpack the JSON object fields into local variables for clarity
	$email 			= $conn->real_escape_string($profile->email);
	$firstname 		= $conn->real_escape_string($profile->firstname);
	$lastname 		= $conn->real_escape_string($profile->lastname);
	$notifyEmail 	= (int)$profile->notifyEmail;
	$notifyMessage 	= (int)$profile->notifyMessage;
	$timezone 		= $conn->real_escape_string($profile->timezone);
	$groupid 		= (int)$profile->groupid;
	// $userid set by require_auth.php from validated session
	$photo			= $conn->real_escape_string($profile->photo);

	// Build the UPDATE query
	$q = 	"UPDATE Users SET first_name='$firstname',
			last_name='$lastname',
			email='$email',
			notify_results=$notifyEmail,
			notify_message=$notifyMessage,
			default_group=$groupid,";

	// Only update the photo filename if one was provided
	if ($photo != "") {
		$q.= "pic_filename='$photo',";
	}

	$q.= "timezone='$timezone' WHERE id = $userid;";

	$result = $conn->query($q);

?>
