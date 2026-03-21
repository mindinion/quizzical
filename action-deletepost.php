<?php

	error_reporting(E_ALL);
	ini_set('display_errors', '1');
	
	require_once 'dblogin.php';
	require_once 'security.php';
	
	if (isset($_GET['id'])) $postid = $_GET['id'];
	if (isset($_GET['userid'])) $userid = $_GET['userid'];
	
	// Make sure we're allowed to delete this, and grab the result id
	$q = "SELECT result_id, user_id FROM QuizFeed WHERE QuizFeed.id = '$postid' ;";
	$result = $conn->query($q);


	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$postUser = $row['user_id'];
			$resultId = $row['result_id'];
			
			// Delete the result and post if we're allowed to
			if ($postUser == $userid) {
				$q = "UPDATE Results SET status = 'deleted' WHERE id = '$resultId' ;";
				$result = $conn->query($q);
				$q = "UPDATE QuizFeed SET status = 'deleted' WHERE id = '$postid' ;";
				$result = $conn->query($q);
			}
    	}
    }
	
	 
	
?>