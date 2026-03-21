<?php
/**
 * action-getsettings.php
 *
 * Returns a JSON object containing the profile and preference settings for a given user.
 * Joins Users with Groups to include the user's current group name.
 * The old_timezone field is set to "Australia/Melbourne" as a hardcoded fallback —
 * it reflects the server's previous default timezone rather than a dynamic value.
 * Called by the front-end profile/settings panel to pre-populate form fields.
 */

	//ini_set('error_reporting', E_STRICT);

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';

	if (isset($_GET['userid'])) $userid = sanitizeString($_GET['userid']);
	$q = "
		SELECT 
			Users.id as user_id,
			Users.default_group AS group_id, 
			`Groups`.name as group_name, 
			Users.timezone as timezone,
			first_name,
			last_name,
			email,
			notify_results,
			notify_message,
			superuser
		FROM Users 
		INNER JOIN `Groups` ON (Users.default_group = `Groups`.id) 
		WHERE Users.id = $userid;";

		

	
	$user = $conn->query($q);		
	
	class User {
		public $group_id = "";
		public $group_name = "";
		public $timezone = "";
		public $old_timezone = "";
		public $first_name = "";
		public $last_name = "";
		public $email = "";	
		public $notify_results = "";
		public $notify_messages = "";
		public $superuser = "";
	}

	while($row = mysqli_fetch_assoc($user)) {
		$newUser = new User();
		$newUser->user_id = $row['user_id'];
		$newUser->group_id = $row['group_id'];
		$newUser->group_name = $row['group_name'];
		$newUser->timezone = $row['timezone'];
		$newUser->old_timezone = "Australia/Melbourne";
		$newUser->first_name = $row['first_name'];
		$newUser->last_name = $row['last_name'];
		$newUser->email = $row['email'];
		$newUser->notify_results = $row['notify_results'];
		$newUser->notify_message = $row['notify_message'];
		$newUser->superuser = $row['superuser'];
	}
	
	echo JSON_ENCODE($newUser);
?>
