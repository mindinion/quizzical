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
				$html = mailHtml('
				<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>' . htmlspecialchars($firstName . ' ' . $lastName) . '</strong> posted a new ' . htmlspecialchars($type) . ' quiz result on Quizzical.</p>
				<a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>
			');
			sendMail($conn, $email, "New result on Quizzical", $html, false, true);
				$response++;
			}
		}
	}

	echo $response ?? '';

?>