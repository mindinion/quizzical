<?php
/**
 * action-getuserresults.php
 *
 * Returns individual results for a specific user in a group for a given period.
 * Used to power the drill-down detail panels in the rankings view.
 *
 * period: 'weekly' (last 7 days), 'monthly' (last 30 days), 'alltime'
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	$groupid    = isset($_GET['groupid'])    ? sanitizeString($_GET['groupid'])    : null;
	$userid     = isset($_GET['userid'])     ? (int)$_GET['userid']                : 0;
	$period     = isset($_GET['period'])     ? sanitizeString($_GET['period'])     : 'weekly';
	$typefilter = isset($_GET['typefilter']) ? sanitizeString($_GET['typefilter']) : 'all';

	$typeSQL = ($typefilter === 'main')
		? "AND (UPPER(Results.type) = 'MORNING' OR UPPER(Results.type) = 'AFTERNOON')"
		: "";

	if (!$groupid || !$userid) { echo '[]'; exit; }

	if ($period === 'monthly') {
		$dateFilter = 'AND Results.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
	} elseif ($period === 'yearly') {
		$dateFilter = 'AND Results.date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)';
	} elseif ($period === 'alltime') {
		$dateFilter = '';
	} else {
		$dateFilter = 'AND Results.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
	}

	$q = "SELECT
		DATE_FORMAT(Results.date, '%Y-%m-%d') AS date,
		Results.type,
		Results.score,
		Results.max,
		ROUND(Results.score / Results.max * 100, 0) AS pct
	FROM Results
		INNER JOIN Memberships ON Memberships.user_id = Results.user
	WHERE Results.user = $userid
		AND Memberships.group_id = $groupid
		AND Results.status = 'active'
		$dateFilter $typeSQL
	ORDER BY Results.date DESC, Results.type";

	$result = $conn->query($q);

	$rows = [];
	if ($result && mysqli_num_rows($result) > 0) {
		while ($row = mysqli_fetch_assoc($result)) {
			$rows[] = [
				'date'  => $row['date'],
				'type'  => $row['type'],
				'score' => (int)$row['score'],
				'max'   => (int)$row['max'],
				'pct'   => (int)$row['pct'],
			];
		}
	}

	echo json_encode($rows);
?>
