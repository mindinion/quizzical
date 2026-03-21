<?php
	error_reporting(0);
	require_once 'dblogin.php';
	require_once 'security.php';
	
	date_default_timezone_set($timezone);
	
	// Get inputted details
	if (isset($_GET['quizFeedId'])) $quizFeedId = sanitizeString($_GET['quizFeedId']);
	else $quizFeedId = "";
	if (isset($_GET['comment'])) $comment = addslashes($_GET['comment']);
	else $comment = "";
	if (isset($_GET['userid'])) $userid = sanitizeString($_GET['userid']);
	else $userid = "";
	
	
	
	$q = "INSERT INTO Comment (user_id, quizfeed_id, comment) VALUES" . "('$userid','$quizFeedId','$comment' );";
	$conn->query($q);	
	
	echo $quizFeedId;

?>