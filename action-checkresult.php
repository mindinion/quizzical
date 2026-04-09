<?php
/**
 * action-checkresult.php
 *
 * Looks up whether a specific user has already submitted a result for a given date and quiz type.
 * Returns the score if a result is found, or echoes "failed" if no matching active result exists.
 * Used to prevent duplicate submissions and to display a user's existing score.
 */

	require_once 'require_auth.php';
	// $userid set by require_auth.php from validated session

	if (isset($_GET['date'])) $date = sanitizeString($_GET['date']);
	if (isset($_GET['type'])) $type = sanitizeString($_GET['type']);
	$score = "";

	// Match on the formatted date string to avoid time-component mismatches
	$q = "SELECT score FROM Results WHERE DATE_FORMAT(date,'%Y-%m-%d') = '$date' AND type = '$type' AND user = $userid and status = 'active';";
	$result = $conn->query($q);

	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$score = $row['score'];
    	}
	} else {
		// Signal to the caller that no result was found
    	echo "failed";
    }
    echo $score;
?>