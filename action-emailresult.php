<?php
/**
 * action-emailresult.php
 *
 * Sends email notifications to group members when a new quiz result is posted.
 * Only users who share the poster's default group and have opted in to result
 * notifications (notify_results = 1) are contacted. The result submitter is excluded.
 * Returns a count of emails sent, or "No input" if no resultId was provided.
 */

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'config.php';

	date_default_timezone_set($timezone);

	$resultId = isset($_GET['resultId']) ? (int)$_GET['resultId'] : 0;

	if (!$resultId) $response = "No input";

	// Fetch the submitter's details and their group from the Results table
	$q = "SELECT default_group, Users.id as 'userid', first_name, last_name, type FROM Results INNER JOIN Users ON Results.user = Users.id WHERE Results.id = $resultId;";
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

	// Email subscribed group members, skipping the person who submitted the result
	$q = "SELECT id,email FROM Users WHERE notify_results = 1 and Users.default_group = $groupid;";
	$result = $conn->query($q);
	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$email = $row['email'];
			$id = $row['id'];
			if ($id != $userid) {
				sendMail($conn, $email, "New Result Posted", "There has been a new " . $type . " quiz result posted on Quizzical, by " . $firstName . " " . $lastName . ".");
				$response++;
			}
		}
	}

	echo $response ?? '';

?>