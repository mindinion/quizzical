<?php
/**
 * action-getstreaks.php
 *
 * Returns the current result streak for each user in a group.
 * Streak = consecutive days (backwards from today or yesterday) on which
 * the user posted at least one result of any quiz type.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['groupid'])) $groupid = sanitizeString($_GET['groupid']);

	// Fetch all distinct result dates per user, most recent first
	$q = "SELECT
		Users.id AS userid,
		DATE_FORMAT(Results.date, '%Y-%m-%d') AS result_date
	FROM Users
		INNER JOIN Memberships ON Memberships.user_id = Users.id
		INNER JOIN Results ON Results.user = Users.id AND Results.status = 'active'
	WHERE Memberships.group_id = $groupid
	GROUP BY Users.id, Results.date
	ORDER BY Users.id, Results.date DESC";

	$result = $conn->query($q);

	$userDates = [];
	if ($result && mysqli_num_rows($result) > 0) {
		while ($row = mysqli_fetch_assoc($result)) {
			$userDates[$row['userid']][] = $row['result_date'];
		}
	}

	$streaks = [];
	$today     = date('Y-m-d');
	$yesterday = date('Y-m-d', strtotime('-1 day'));

	foreach ($userDates as $userid => $dates) {
		$mostRecent = $dates[0];

		// Streak only counts if the user participated today or yesterday
		if ($mostRecent !== $today && $mostRecent !== $yesterday) {
			$streaks[] = ['userid' => (int)$userid, 'streak' => 0];
			continue;
		}

		$streak    = 1;
		$checkDate = $mostRecent;

		for ($i = 1; $i < count($dates); $i++) {
			$prevExpected = date('Y-m-d', strtotime($checkDate . ' -1 day'));
			if ($dates[$i] === $prevExpected) {
				$streak++;
				$checkDate = $prevExpected;
			} else {
				break;
			}
		}

		$streaks[] = ['userid' => (int)$userid, 'streak' => $streak];
	}

	echo json_encode($streaks);
?>
