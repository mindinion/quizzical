<?php
/**
 * action-deletepost.php
 *
 * Soft-deletes a QuizFeed post (and its linked Result, if any) by setting their status
 * to 'deleted'. Authorization is enforced by comparing the post's owner (user_id) against
 * the requesting userid — only the original poster can delete their own post.
 * Records are never physically removed from the database.
 */

	require_once 'require_auth.php';

	$postid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
	// $userid set by require_auth.php from validated session

	// Fetch the owner and linked result id for authorization check
	$q = "SELECT result_id, user_id FROM QuizFeed WHERE QuizFeed.id = $postid ;";
	$result = $conn->query($q);

	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$postUser = $row['user_id'];
			$resultId = $row['result_id'];

			// Only allow deletion if the requester owns the post
			if ($postUser == $userid) {
				// Soft-delete the linked result first (only if this post has one)
				if ($resultId) {
					$conn->query("UPDATE Results SET status = 'deleted' WHERE id = $resultId");
				}
				$conn->query("UPDATE QuizFeed SET status = 'deleted' WHERE id = $postid");
			}
    	}
    }


?>
