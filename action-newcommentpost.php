<?php
	error_reporting(0);
	require_once 'dblogin.php';
	require_once 'security.php';
	
	// Get inputted details
	if (isset($_GET['comment'])) $comment = addslashes($_GET['comment']);
	else $comment = "";
	if (isset($_GET['userid'])) $userid = sanitizeString($_GET['userid']);
	else $userid = "";
	if (isset($_GET['timezone'])) $timezone = sanitizeString($_GET['timezone']);
	else $timezone = "NZ";
	
	// Find out the first name of the poster
	$q = "SELECT first_name, last_name FROM Users WHERE id = '$userid';";
	$result = $conn->query($q);		
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {
			$name = $row['first_name'] . " " . $row['last_name'];
		}
	}
	
	// Now go ahead and post item
	date_default_timezone_set($timezone);
	$dt = date("Y-m-d H:i:s");
	$q = "INSERT INTO QuizFeed (user_id, comment, timestamp) VALUES" . "('$userid', '$comment', '$dt');";
	$result = $conn->query($q);		
	$postId = mysqli_insert_id($conn);

	echo $postId;

?>