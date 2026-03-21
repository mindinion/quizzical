<?php

	require_once 'dblogin.php';
	require_once 'security.php';

	// Grab the user ID from the session token if it exists
	// Otherwise, send them back to the login screen
	$token = $_COOKIE['session'];
	$getUserId = mysqli_query($db_server, "SELECT userid FROM _SESSION WHERE token = '$token'");
	if (mysqli_num_rows($getUserId)) {
		$userid = mysql_result($getUserId, 0, 'userid');
	} else {
		header( "Location: index.php" );
	}

	// Get the Group ID and name
	if(isset($_SESSION['groupid']) && !empty($_SESSION['groupid'])) {
		$groupid = $_SESSION['groupid'];
	} else {
		$getGroup = mysqli_query($db_server, "SELECT Users.default_group, Groups.name, Users.timezone FROM Users INNER JOIN Groups ON (Users.default_group = Groups.id) WHERE Users.id = $userid;");
		$groupid = mysql_result($getGroup, 0, 'default_group');
		$groupname = mysql_result($getGroup, 0, 'name');
		$_SESSION["groupid"] = $groupid;
		$_SESSION["groupname"] = $groupname;
	}
	$getSettings = mysqli_query($db_server, "SELECT * FROM Users INNER JOIN Groups ON (Users.default_group = Groups.id) WHERE Users.id = $userid;");
	$timezone = mysql_result($getSettings, 0, 'timezone');
	$nameFirst = mysql_result($getSettings, 0, 'first_name');
	$nameLast = mysql_result($getSettings, 0, 'last_name');
	$email = mysql_result($getSettings, 0, 'email');
	$notifyResults = mysql_result($getSettings, 0, 'notify_results');
	$notifyMessages = mysql_result($getSettings, 0, 'notify_message');
	$superuser = mysql_result($getSettings, 0, 'superuser');

	// Get any other user details
	$getSettings = mysqli_query($db_server, "SELECT timezone FROM Users WHERE Users.id = $userid;");
	$timezone = mysql_result($getSettings, 0, 'timezone');
	$oldTimezone = date_default_timezone_get();
	$oldTS = date("Y-m-d H:i:s");
	date_default_timezone_set($timezone);
	$newTS = date("Y-m-d H:i:s");
	$tzDiff = StrToTime($oldTS) - StrToTime($newTS);

	//Find out what the site is being accessed on
	$iPod    = stripos($_SERVER['HTTP_USER_AGENT'],"iPod");
	$iPhone  = stripos($_SERVER['HTTP_USER_AGENT'],"iPhone");
	$iPad    = stripos($_SERVER['HTTP_USER_AGENT'],"iPad");
	$Android = stripos($_SERVER['HTTP_USER_AGENT'],"Android");
	$webOS   = stripos($_SERVER['HTTP_USER_AGENT'],"webOS");
	if ( $iPod || $iPhone || $Android ) {
		$portable = 1;
	} else {
		$portable = 0;
	}

?>
