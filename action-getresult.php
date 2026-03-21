<?php
/**
 * action-getresult.php
 *
 * Determines the next quiz type a user should submit for today, based on what they
 * have already completed. The priority order is: Morning -> Afternoon -> Other.
 * Echoes one of: 'Morning', 'Afternoon', or 'Other'.
 * Used by the front end to pre-select the correct quiz type in the new result form.
 */

	error_reporting(E_ALL);
	ini_set('display_errors', '1');

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['userid'])) $userid = $_GET['userid'];

	// Fetch all result types submitted today to determine what's still outstanding
	$q = "SELECT type FROM Results WHERE user = $userid and DATE(date) = DATE(SYSDATE()) and status = 'active' ORDER BY date DESC;";
	$result = $conn->query($q);

	$i = 0;
	$daily = 0;
	$afternoon = 0;
	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$type = $row['type'];
			// Track which of the two main daily quiz slots have been completed
			if ($type == "Morning") $daily = 1;
			if ($type == "Afternoon") $afternoon = 1;
		}
	}

	// Return the first quiz type that hasn't been submitted yet today
	if ($daily == 0) echo 'Morning';
	else if ($afternoon == 0) echo 'Afternoon';
	else echo 'Other';


?>