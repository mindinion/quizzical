<?php
/**
 * action-getrankings.php
 *
 * Returns a JSON array of user rankings for a given group over a specified period.
 * Rankings are calculated as the average percentage score across Daily, Morning, and
 * Afternoon quiz types (case-insensitive). Only active results within the trailing
 * $period days are considered. Users are sorted by average descending.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['groupid'])) $groupid = sanitizeString($_GET['groupid']);
	// Period is the number of trailing days to include (e.g., 7 for weekly, 31 for monthly)
	if (isset($_GET['period'])) $period = sanitizeString($_GET['period']);

	$q = "SELECT
			user,
			First_Name AS 'first_name',
			Last_Name as 'last_name',
			pic_filename,
			ROUND(AVG(score / max) * 100, 0) AS average

		FROM Results
			INNER JOIN Users ON (Results.user = Users.id)
			INNER JOIN Memberships ON Memberships.user_id = Users.id

		WHERE
			Memberships.group_id = $groupid AND
			Results.status = 'active' AND (UPPER(type) = 'DAILY' or UPPER(type) = 'MORNING' OR UPPER(type) = 'AFTERNOON') AND
			Results.date BETWEEN DATE_SUB(CURDATE(), INTERVAL $period DAY) AND
			DATE_ADD(CURDATE(), INTERVAL 1 DAY)

		GROUP BY user ORDER BY average DESC;";

	$rankings = $conn->query($q);

	class Users {
		public $userid = "";
		public $first_name = "";
		public $last_name = "";
		public $pic_filename = "";
		public $average = "";
	}

	$count_users = 0;

	if (mysqli_num_rows($rankings) > 0) {
		while($row = mysqli_fetch_assoc($rankings)) {
			$userrankings[] = new Users;
			$userrankings[$count_users]->userid = $row['user'];
			$userrankings[$count_users]->first_name = $row['first_name'];
			$userrankings[$count_users]->last_name = $row['last_name'];
			$userrankings[$count_users]->pic_filename = $row['pic_filename'];
			$userrankings[$count_users]->average = $row['average'];
			$count_users++;
		}
	}

	echo JSON_ENCODE($userrankings);

	
?>

