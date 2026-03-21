<?php

	error_reporting(-1);
	ini_set('display_errors', 'On');

	if (isset($_GET['from'])) $from = $_GET['from'];
	if (isset($_GET['to'])) $to = $_GET['to'];
	if (isset($_GET['userid'])) $userid = $_GET['userid'];


	rename($from,$to);
	
	// Change the name of the users profile photo
	require_once 'security.php';
	require_once 'dblogin.php';
	$q = "UPDATE Users SET pic_filename='$to' WHERE id = $userid";
	$result = mysqli_query($db_server, $q);   
	var_dump($result);
	
?>
