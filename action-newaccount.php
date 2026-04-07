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
	if (isset($_GET['password'])) $password = sanitizeString($_GET['password']);
	$passwordhash = md5($password);

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
		// Create the group membership record for the new user
		$q = "INSERT INTO Memberships (group_id, user_id) VALUES ($groupid, $last_id)";
		$conn->query($q);
		$last_id = mysqli_insert_id($conn);
		if ($last_id != null) {
			echo $last_id;
		} else {
			echo "Failed";
		}
	} else {
		echo "Failed";
	}

?>
