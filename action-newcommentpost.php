<?php
/**
 * action-newcommentpost.php
 *
 * Creates a new plain-text (non-result) post in the QuizFeed. The post contains only a
 * comment — no associated quiz result. The timestamp is generated server-side using the
 * user's timezone so feed ordering reflects local time correctly.
 * Echoes back the new post id, which the caller uses to trigger email notifications.
 *
 * Note: the poster's name is fetched but not actually used in this file (leftover code).
 */

	error_reporting(0);
	require_once 'dblogin.php';
	require_once 'security.php';

	// Get inputted details
	$userid   = isset($_GET['userid'])   ? (int)$_GET['userid'] : 0;
	$comment  = isset($_GET['comment'])  ? $conn->real_escape_string($_GET['comment']) : '';
	$timezone = isset($_GET['timezone']) ? $conn->real_escape_string($_GET['timezone']) : 'NZ';

	// Fetch the poster's name (currently unused — likely intended for a future notification)
	$q = "SELECT first_name, last_name FROM Users WHERE id = '$userid';";
	$result = $conn->query($q);
	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$name = $row['first_name'] . " " . $row['last_name'];
		}
	}

	// Set timezone before generating the timestamp so it matches the user's local time
	date_default_timezone_set($timezone);
	$dt = date("Y-m-d H:i:s");
	$q = "INSERT INTO QuizFeed (user_id, comment, timestamp) VALUES" . "('$userid', '$comment', '$dt');";
	$result = $conn->query($q);
	// Retrieve the auto-incremented id of the newly created post
	$postId = mysqli_insert_id($conn);

	echo $postId;

?>