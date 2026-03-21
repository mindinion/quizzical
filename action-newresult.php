<?php
/**
 * action-newresult.php
 *
 * Submits a new quiz result and creates the corresponding QuizFeed post.
 * The result date is resolved from a dateOption parameter:
 *   - "today"     -> current server date/time in the user's timezone
 *   - "yesterday" -> 24 hours before the current time
 *   - anything else -> the raw date string passed in the 'date' parameter
 *
 * Before inserting, checks for a duplicate submission (same user, type, and calendar day)
 * and silently returns if one is found, preventing double-posting.
 * On success, inserts a Results row and a linked QuizFeed row, then echoes the result id.
 */

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';

	// Get inputted details
	if (isset($_GET['type'])) $type = sanitizeString($_GET['type']);
	else $type = "";
	// Adding 0 casts the sanitized score string to a number, preventing injection via string tricks
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

	// Resolve the result timestamp based on the user's selected date option
	date_default_timezone_set($timezone);
	if ($dateOption == "today") {
		$dt = date("Y-m-d H:i:s");
	} else if ($dateOption == "yesterday") {
		// Subtract exactly 24 hours to get yesterday's timestamp
		$dt = date("Y-m-d H:i:s", strtotime(date("Y-m-d H:i:s")) - (60*60*24));
	} else {
		// Use the explicit date passed from the historical date picker
		$dt = $date;
	}

	// Duplicate check: compare day/month/year separately to handle time-component variation
	$q = "SELECT * FROM Results WHERE user='$userid' AND type='$type' AND DAY(date) = DAY('$dt') and MONTH(date) = MONTH('$dt') and YEAR(date) = YEAR('$dt') and Results.status = 'active';";
	$result = $conn->query($q);
	if (mysqli_num_rows($result) != 0) return;

	// Insert the result, then create a linked QuizFeed post using the same timestamp
	$q = "INSERT INTO Results (user, type, score, max, date) VALUES" . "('$userid','$type','$score','$max','$dt');";
	$result = $conn->query($q);
	$result_id = mysqli_insert_id($conn);
	$date = date("Y-m-d H:i:s");
	$q = "INSERT INTO QuizFeed (result_id, user_id, comment, timestamp) VALUES" . "('$result_id', '$userid', '$comment', '$dt');";
	$result = $conn->query($q);
	echo $result_id;


?>