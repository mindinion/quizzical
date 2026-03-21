<?php
	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';

	// Get inputted details
	if (isset($_GET['type'])) $type = sanitizeString($_GET['type']);
	else $type = "";
	if (isset($_GET['score'])) $score = sanitizeString($_GET['score']) + 0;
	else $score = "";
	if (isset($_GET['questions'])) $max = sanitizeString($_GET['questions']);
	else $max = "";
	if (isset($_GET['dateOption'])) $dateOption = sanitizeString($_GET['dateOption']);
	else $dateOption = "";
	if (isset($_GET['date'])) $date = sanitizeString($_GET['date']);
	else $date = "";
	if (isset($_GET['comment'])) $comment = sanitizeMySQL($_GET['comment']);
	else $comment = "";
	if (isset($_GET['userid'])) $userid = sanitizeString($_GET['userid']);
	else $userid = "";
	if (isset($_GET['timezone'])) $timezone = sanitizeString($_GET['timezone']);
	else $timezone = "NZ";

	// Grab the date based on what they entered
	date_default_timezone_set($timezone);
	if ($dateOption == "today") {
		$dt = date("Y-m-d H:i:s");
	} else if ($dateOption == "yesterday") {
		$dt = date("Y-m-d H:i:s", strtotime(date("Y-m-d H:i:s")) - (60*60*24));
	} else {
		$dt = $date;
	}

	// Make sure this result hasn't already been submitted
	$q = "SELECT * FROM Results WHERE user='$userid' AND type='$type' AND DAY(date) = DAY('$dt') and MONTH(date) = MONTH('$dt') and YEAR(date) = YEAR('$dt') and Results.status = 'active';";
	$result = mysqli_query($db_server, $q);
	if (mysqli_num_rows($result) != 0) return;

	// Add the result
	$q = "INSERT INTO Results (user, type, score, max, date) VALUES ('$userid','$type','$score','$max','$dt');";
	mysqli_query($db_server, $q);
	$result_id = mysqli_insert_id($db_server);
	$date = date("Y-m-d H:i:s");
	$q = "INSERT INTO QuizFeed (result_id, user_id, comment, timestamp) VALUES ('$result_id', '$userid', '$comment', '$dt');";
	mysqli_query($db_server, $q);
	echo $result_id;
?>
