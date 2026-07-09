<?php
/**
 * action-getpersonalbests.php
 *
 * Returns each user's best score per quiz type, including the date and raw score
 * for the actual result that achieved it. Used to power the Personal Bests section
 * and its drill-down detail panel.
 */

	require_once 'require_auth.php';
	require_once 'security.php';

	if (isset($_GET['groupid'])) $groupid = sanitizeString($_GET['groupid']);
	$period     = isset($_GET['period'])     ? sanitizeString($_GET['period'])     : 'alltime';
	$typefilter = isset($_GET['typefilter']) ? sanitizeString($_GET['typefilter']) : 'quizzical';

	if ($typefilter === 'stuff') {
		$typeSQL    = "AND r.type IN ('Morning', 'Afternoon')";
		$subTypeSQL = "AND type IN ('Morning', 'Afternoon')";
	} elseif ($typefilter === 'all') {
		$typeSQL    = "";
		$subTypeSQL = "";
	} else {
		$typeSQL    = "AND r.type IN ('Quizzical Morning', 'Quizzical Afternoon')";
		$subTypeSQL = "AND type IN ('Quizzical Morning', 'Quizzical Afternoon')";
	}

	if ($period === 'weekly') {
		$dateFilter = "AND r.date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
		$subDateFilter = "AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
	} elseif ($period === 'monthly') {
		$dateFilter = "AND r.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
		$subDateFilter = "AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
	} elseif ($period === 'yearly') {
		$dateFilter = "AND r.date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
		$subDateFilter = "AND date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
	} else {
		$dateFilter = "";
		$subDateFilter = "";
	}

	// Subquery finds the max % per user+type in the period, then we join back to get the
	// actual result row (date, score, max). GROUP BY handles ties by picking one.
	$q = "SELECT
		Users.id AS userid,
		Users.first_name,
		Users.last_name,
		r.type,
		r.score,
		r.max,
		DATE_FORMAT(r.date, '%Y-%m-%d') AS date,
		ROUND(r.score / r.max * 100, 0) AS best_pct
	FROM Results r
		INNER JOIN Users ON Users.id = r.user
		INNER JOIN Memberships ON Memberships.user_id = Users.id
		INNER JOIN (
			SELECT user, type, MAX(ROUND(score / max * 100, 0)) AS max_pct
			FROM Results
			WHERE status = 'active' $subDateFilter $subTypeSQL
			GROUP BY user, type
		) AS bests ON bests.user = r.user
			AND bests.type = r.type
			AND ROUND(r.score / r.max * 100, 0) = bests.max_pct
	WHERE r.status = 'active'
		AND Memberships.group_id = $groupid
		$dateFilter $typeSQL
	GROUP BY r.user, r.type
	ORDER BY Users.id, best_pct DESC";

	$result = $conn->query($q);

	$pbs = [];
	if ($result && mysqli_num_rows($result) > 0) {
		while ($row = mysqli_fetch_assoc($result)) {
			$pbs[] = [
				'userid'     => (int)$row['userid'],
				'first_name' => $row['first_name'],
				'last_name'  => $row['last_name'],
				'type'       => $row['type'],
				'score'      => (int)$row['score'],
				'max'        => (int)$row['max'],
				'date'       => $row['date'],
				'best_pct'   => (int)$row['best_pct'],
			];
		}
	}

	echo json_encode($pbs);
?>
