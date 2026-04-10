<?php
/**
 * action-newaccount.php
 *
 * AJAX endpoint for creating a new user account from the single-page front end.
 * Assigns the user to a group based on their email domain:
 *   - @teamdf.com addresses are placed in group 22 (a private team group)
 *   - All other addresses are placed in group 2 (the public group)
 * After inserting the user, also inserts a Memberships record to link them to their group.
 * Echoes the new Membership id on success, or "Failed" if either insert did not produce an id.
 *
 * Note: passwords are hashed with MD5 here, which is weaker than the Blowfish approach
 * used in action-createaccount.php.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['firstname'])) $firstname = $conn->real_escape_string($_GET['firstname']);
	if (isset($_GET['lastname'])) $lastname = $conn->real_escape_string($_GET['lastname']);
	if (isset($_GET['email'])) $email = $conn->real_escape_string($_GET['email']);
	if (isset($_GET['password'])) $password = $_GET['password'];
	$passwordhash = password_hash($password, PASSWORD_BCRYPT);

	// Route @teamdf.com users to their private group; everyone else goes to the public group
	if (strpos($email, '@teamdf.com') !== false) {
    	$groupid = 22 ;
    } else {
    	$groupid = 2 ;
    }

	$q = "INSERT INTO Users
		SET
		first_name = '$firstname',
		last_name = '$lastname',
		email = '$email',
		password_hash = '$passwordhash',
		default_group = $groupid";

	$conn->query($q);
	$last_id = mysqli_insert_id($conn);

	if ($last_id != null) {
		$userid = $last_id;
		// Create the group membership record for the new user
		$q = "INSERT INTO Memberships (group_id, user_id) VALUES ($groupid, $userid)";
		$conn->query($q);
		if (mysqli_insert_id($conn) != null) {
			// Create a session so the user is logged in immediately
			$token = bin2hex(openssl_random_pseudo_bytes(128));
			$location = $conn->real_escape_string($_SERVER['REMOTE_ADDR']);
			$client = $conn->real_escape_string($_SERVER['HTTP_USER_AGENT']);
			$conn->query("INSERT INTO _SESSION (userid, token, location, client) VALUES ('$userid', '$token', '$location', '$client')");
			$expiry = time() + (365 * 24 * 60 * 60);
			setcookie("session", $token,  $expiry, "/", "", true, true);
			setcookie("userid",  $userid, $expiry, "/", "", true, false);
			echo "OK";
		} else {
			echo "Failed";
		}
	} else {
		echo "Failed";
	}

?>
