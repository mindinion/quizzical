<?php

	error_reporting(E_ALL);
	ini_set('display_errors', '1');
	
	require_once 'dblogin.php';
	require_once 'security.php';
	
	$postid = "";
	$commentid = "";
	
	if (isset($_GET['postid'])) $postid = $_GET['postid'];
	if (isset($_GET['commentid'])) $commentid = $_GET['commentid'];
	if (isset($_GET['userid'])) $userid = $_GET['userid'];
	
	if ($postid == "")    $q = "INSERT INTO Digs (userid, commentid) values ($userid, $commentid);";
	if ($commentid == "") $q = "INSERT INTO Digs (userid, postid) values ($userid, $postid);";

	$conn->query($q);	
	$last_id = mysqli_insert_id($conn);

	echo $last_id;
	 	
?>