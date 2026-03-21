<?php
	error_reporting(-1);
	ini_set('display_errors', 'On');
	
	//session_start();
	//$userid = $_SESSION['userid'];
	
	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';
	
	
		
	if (isset($_POST['newemail'])) $newEmail = sanitizeString($_POST['newemail']);
	else $newEmail = $email;
	if (isset($_POST['newfirstname'])) $newFirstName = sanitizeString($_POST['newfirstname']);
	else $newFirstName = $nameFirst;
	if (isset($_POST['newlastname'])) $newLastName = sanitizeString($_POST['newlastname']);
	else $newLastName = $nameLast;
	if (isset($_POST['passwordA'])) $newPa = ($_POST['passwordA']);
	else $newPa = "d";
	if (isset($_POST['passwordB'])) $newPb = ($_POST['passwordB']);
	else $newPb = "d";
	
	if (isset($_POST['defaultgroup'])) $newGroupcode = sanitizeString($_POST['defaultgroup']);
	if ($newGroupcode == "") $newGroupcode = "public";
	if (isset($_POST['numresults'])) $newNumResults = sanitizeString($_POST['numresults']);
	else $newNumResults = $numResults;
	if (isset($_POST['nummessages'])) $newNumMessages = sanitizeString($_POST['nummessages']);
	else $newNumMessages = $numMessages;
	if ((isset($_POST['notifyresults'])) and (isset($_POST['notifyresults'])) == "1") $newNotifyResults = 1; else $newNotifyResults = 0;
	if ((isset($_POST['notifymessages'])) and (isset($_POST['notifymessages'])) == "1") $newNotifyMessages = 1; else $newNotifyMessages = 0;
	if (isset($_POST['timezone'])) $newTimezone = sanitizeString($_POST['timezone']);
			
	// If either password field contains a value, either return to the profile screen if they don't match, or update the password if they do
	if ($newPa != $newPb) {
		//header( "Location: profile.php?mode=1" );
		//exit;
	}
	if ($newPa == "" or $newPb == "") {
		$newPassword = $password;
	} else {
		$newPassword = $newPa;
	}
	
	// Hash the new password
	$newpasswordhash = crypt($newPassword, CRYPT_BLOWFISH);
	
	/*
	// Take the group code and get the group ID from it if it exists, otherwise put them in the public group
	$idCodeQuery = mysqli_query($db_server, "SELECT id FROM Groups WHERE group_code = '$newGroupcode';");
	if (mysqli_num_rows($idCodeQuery) == 0) {
		$groupid = 2;
	} else {
		$groupid = mysql_result($idCodeQuery, 0, "id");
	}
	$result = mysqli_query($db_server, "UPDATE Users SET 
		first_name='$newFirstName', 
		last_name='$newLastName', 
		email='$newEmail', 
		password_hash='$newpasswordhash', 
		num_results=$newNumResults, 
		num_messages=$newNumMessages, 
		notify_results=$newNotifyResults, 
		notify_message=$newNotifyMessages, 
		Users.default_group=$groupid,
		timezone='$newTimezone'
		WHERE id = $userid;"
	);*/
	
	// Upload pic and update database with filename for pics
	if($_FILES['profilepic']['name']) {
		$newFileName = "zzuser" . $userid . "." . $ext = pathinfo($_FILES['profilepic']['name'], PATHINFO_EXTENSION);
		$newFileName = strtolower($newFileName);
		move_uploaded_file($_FILES["profilepic"]["tmp_name"], $newFileName);
		if($error) print $error;
		$result = mysqli_query($db_server, "UPDATE Users SET pic_filename = '$newFileName' WHERE id = $userid;");
	}
	 
	//header( "Location: main.php");		
	
?>