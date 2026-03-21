<?php

	require_once 'dblogin.php';
	require_once 'security.php';
	
	$db_server = mysql_connect($db_hostname, $db_username, $db_password);
	if (!$db_server) die("Unable to connect to DB: " . mysqlerror());
	mysql_select_db($db_database) or die("Unable to connect to DB: " . mysql_error());

	
	// Grab the user ID from the session token if it exists
	// Otherwise, send them back to the login screen
	$token = $_COOKIE['session'];
	$getUserId = mysql_query("SELECT userid FROM _SESSION WHERE token = '$token'");
	if (mysql_num_rows($getUserId)) {
		$userid = mysql_Result($getUserId, 0, 'userid');
	} else {
		header( "Location: index.php" );
	}
	
	// Get the Group ID and name
	//SESSION_START();
	if(isset($_SESSION['groupid']) && !empty($_SESSION['groupid'])) {
		$groupid = $_SESSION['groupid'];
	} else {
		$getGroup = mysql_query("SELECT Users.default_group, Groups.name, Users.timezone FROM Users INNER JOIN Groups ON (Users.default_group = Groups.id) WHERE Users.id = $userid;");
		$groupid = mysql_result($getGroup, 0, 'Users.default_group');
		$groupname = mysql_result($getGroup, 0, 'Groups.name');
		$_SESSION["groupid"] = $groupid;
		$_SESSION["groupname"] = $groupname;
	}
	$getSettings = mysql_query("SELECT * FROM Users INNER JOIN Groups ON (Users.default_group = Groups.id) WHERE Users.id = $userid;");
	$timezone = mysql_Result($getSettings, 0, 'Users.timezone');
	$nameFirst = mysql_Result($getSettings, 0, 'first_name');
	$nameLast = mysql_Result($getSettings, 0, 'last_name');
	$email = mysql_Result($getSettings, 0, 'email');
	$notifyResults = mysql_Result($getSettings, 0, 'notify_results');
	$notifyMessages = mysql_Result($getSettings, 0, 'notify_message');
	$superuser = mysql_Result($getSettings, 0, 'superuser');

	// Get any other user details
	$getSettings = mysql_query("SELECT timezone FROM Users WHERE Users.id = $userid;"); 
	$timezone = mysql_Result($getSettings, 0, 'timezone');
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