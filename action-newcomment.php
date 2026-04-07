<?php
/**
 * action-newcomment.php
 *
 * Inserts a new comment on a QuizFeed post. Receives the quizfeed post id, the commenter's
 * user id, and the comment text via GET parameters. addslashes() is used on the comment
 * text to escape quotes for the SQL string (rather than using a prepared statement).
 * Echoes back the quizFeedId so the caller can identify which post was commented on.
 */

	error_reporting(0);
	require_once 'dblogin.php';
	require_once 'security.php';

	date_default_timezone_set($timezone);

	// Get inputted details
	$quizFeedId = isset($_GET['quizFeedId']) ? (int)$_GET['quizFeedId'] : 0;
	$userid     = isset($_GET['userid'])     ? (int)$_GET['userid']     : 0;
	$comment    = isset($_GET['comment'])    ? $conn->real_escape_string($_GET['comment']) : '';

	$q = "INSERT INTO Comment (user_id, quizfeed_id, comment) VALUES" . "('$userid','$quizFeedId','$comment' );";
	$conn->query($q);

	// Return the new comment's id so the caller can link attachments to it
	echo $conn->insert_id;

?>