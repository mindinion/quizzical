<?php
/**
 * action-emailpost.php
 *
 * Sends email notifications to group members when a new QuizFeed post is created.
 * Only users in the same default group who have opted in to message notifications
 * (notify_message = 1) are emailed. The poster themselves is excluded.
 * Returns a count of emails sent.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	date_default_timezone_set($timezone);

	$postId = isset($_GET['postId']) ? (int)$_GET['postId'] : 0;

	if (!$postId) $response = "No input";

	// Resolve the poster's identity and their default group from the feed post
	$q = "SELECT default_group, Users.id as 'userid', first_name, last_name FROM QuizFeed INNER JOIN Users ON QuizFeed.user_id = Users.id WHERE QuizFeed.id = $postId ;";
	$result = $conn->query($q);
	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$firstName = $row['first_name'];
			$lastName = $row['last_name'];
			$type = $row['type'];
			$userid = $row['userid'];
			$groupid = $row['default_group'];
		}
	}

	// Email all group members who have enabled message notifications, except the poster
	$q = "SELECT id,email FROM Users WHERE notify_message = 1 and Users.default_group = $groupid;";
	$result = $conn->query($q);
	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$email = $row['email'];
			$id = $row['id'];
			if ($id != $userid) {
				mail($email,"New Quizzical Post", "There has been a new message posted on Quizzical, by " . $firstName . " " . $lastName . ".", "From: Quizzical");
				$response++;
			}
		}
	}

	echo $response;

?>