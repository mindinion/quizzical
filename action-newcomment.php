<?php
/**
 * action-newcomment.php
 *
 * Inserts a new comment on a QuizFeed post. Receives the quizfeed post id, the commenter's
 * user id, and the comment text via GET parameters. addslashes() is used on the comment
 * text to escape quotes for the SQL string (rather than using a prepared statement).
 * Echoes back the quizFeedId so the caller can identify which post was commented on.
 */

	require_once 'require_auth.php';

	// Get inputted details
	$quizFeedId = isset($_GET['quizFeedId']) ? (int)$_GET['quizFeedId'] : 0;
	// $userid set by require_auth.php from validated session
	$comment    = isset($_GET['comment'])    ? $conn->real_escape_string($_GET['comment']) : '';

	$q = "INSERT INTO Comment (user_id, quizfeed_id, comment) VALUES" . "('$userid','$quizFeedId','$comment' );";
	$conn->query($q);

	// Return the new comment's id so the caller can link attachments to it
	echo $conn->insert_id;

?>