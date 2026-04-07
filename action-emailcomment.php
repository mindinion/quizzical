<?php
/**
 * action-emailcomment.php
 *
 * Sends email notifications when a new comment is posted on a QuizFeed item.
 * Two groups of recipients are notified:
 *   1. The original poster of the QuizFeed item (unless they are the one commenting).
 *   2. Any other users who have previously commented on the same post (excluding the
 *      commenter and the original poster, to avoid duplicates).
 * Called via AJAX immediately after a new comment is saved.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	date_default_timezone_set($timezone);
	
	$postId = isset($_GET['postId']) ? (int)$_GET['postId'] : 0;
	$userid = isset($_GET['userid']) ? (int)$_GET['userid'] : 0;	
	
	// Resolve the original poster's name and id from the QuizFeed post
	$q = "SELECT Users.id as userid, first_name, last_name FROM QuizFeed INNER JOIN Users ON QuizFeed.user_id = Users.id WHERE QuizFeed.id = $postId;";
	$result = $conn->query($q);
	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$firstName = $row['first_name'];
			$lastName = $row['last_name'];
			$posterId = $row['userid'];
		}
	}

	// Look up the poster's email address for the notification
	$q = "SELECT first_name, last_name, email FROM Users WHERE id = $posterId;";
	$result = $conn->query($q);
	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$name = $row['first_name'] . " " . $row['last_name'];
			$poster = $row['email'];
		}
	}

	// Notify the original poster, but skip if they are the one who just commented
	if ($posterId != $userid) {
		mail($poster,"New Comment", "There is a new comment on your post.", "From: Quizzical");
	}

	// Notify any other users who have previously commented on this post.
	// DISTINCT prevents sending multiple emails if a user commented more than once.
	$q = "SELECT DISTINCT(Comment.user_id), Users.email as useremail FROM QuizFeed INNER JOIN Comment ON QuizFeed.id = Comment.quizfeed_id INNER JOIN Users ON Comment.user_id = Users.id WHERE QuizFeed.id = $postId;";
	$result = $conn->query($q);
	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$email = $row['useremail'];
			$id = $row['user_id'];
			// Skip the commenter themselves and the original poster (already emailed above)
			if ($id != $userid && $id != $posterId) {
				mail($email,"New Comment", "There is a new comment on " . $firstName . " " . $lastName . "'s post, by " . $name . ".", "From: Quizzical");
				$response = $i;
			}
		}
	}

	echo $response ?? '';


?>