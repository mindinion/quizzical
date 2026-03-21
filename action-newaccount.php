<?php

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';
	
	if (isset($_GET['firstname'])) $firstname = sanitizeString($_GET['firstname']);
	if (isset($_GET['lastname'])) $lastname = sanitizeString($_GET['lastname']);	
	if (isset($_GET['email'])) $email = sanitizeString($_GET['email']);	
	if (isset($_GET['password'])) $password = sanitizeString($_GET['password']);	
	$passwordhash = md5($password);
	
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