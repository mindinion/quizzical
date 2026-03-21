<?php
/**
 * action-deletepost.php
 *
 * Soft-deletes a QuizFeed post (and its linked Result, if any) by setting their status
 * to 'deleted'. Authorization is enforced by comparing the post's owner (user_id) against
 * the requesting userid — only the original poster can delete their own post.
 * Records are never physically removed from the database.
 */

	error_reporting(E_ALL);
	ini_set('display_errors', '1');

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['id'])) $postid = $_GET['id'];
	if (isset($_GET['userid'])) $userid = $_GET['userid'];

	// Fetch the owner and linked result id for authorization check
	$q = "SELECT result_id, user_id FROM QuizFeed WHERE QuizFeed.id = '$postid' ;";
	$result = $conn->query($q);

	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$postUser = $row['user_id'];
			$resultId = $row['result_id'];

			// Only allow deletion if the requester owns the post
			if ($postUser == $userid) {
				// Soft-delete the linked result first, then the feed post
				$q = "UPDATE Results SET status = 'deleted' WHERE id = '$resultId' ;";
				$result = $conn->query($q);
				$q = "UPDATE QuizFeed SET status = 'deleted' WHERE id = '$postid' ;";
				$result = $conn->query($q);
			}
    	}
    }


?>
