<?php
/**
 * action-getrankings.php
 *
 * Returns a JSON array of user rankings for a given group and time period.
 * Each entry includes: avg %, previous period avg % (for trend arrow), days active, period days.
 *
 * period:     'weekly' (last 7 days vs previous 7), 'monthly' (last 30 days vs previous 30), 'alltime'
 * typefilter: 'all' (every quiz type) or 'main' (Morning & Afternoon only)
 *
 * Alltime response also includes last_result_date for ghost detection in JS.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['groupid'])) $groupid = sanitizeString($_GET['groupid']);
	$period     = isset($_GET['period'])     ? sanitizeString($_GET['period'])     : 'weekly';
	$typefilter = isset($_GET['typefilter']) ? sanitizeString($_GET['typefilter']) : 'all';

	// SQL fragment added to JOIN / CASE WHEN when typefilter = 'main'
	$typeJoin = ($typefilter === 'main')
		? "AND (UPPER(Results.type) = 'MORNING' OR UPPER(Results.type) = 'AFTERNOON')"
		: "";
	$typeSub = ($typefilter === 'main')
		? "AND (UPPER(type) = 'MORNING' OR UPPER(type) = 'AFTERNOON')"
		: "";

	if ($period === 'monthly') {
		$days = 30;
		$prev_start = 60;
		$prev_end = 31;
	} elseif ($period === 'alltime') {
		$days = null;
	} elseif ($period === 'yearly') {
		$days = null;
	} else {
		$days = 7;
		$prev_start = 14;
		$prev_end = 8;
	}

	if ($period === 'yearly') {
		$q = "SELECT
			Users.id AS userid,
			Users.first_name,
			Users.last_name,
			Users.pic_filename,
			ROUND(AVG(score / max) * 100, 0) AS avg_pct,
			NULL AS prev_avg_pct,
			COUNT(DISTINCT Results.date) AS days_active,
			DATEDIFF(CURDATE(), MIN(Results.date)) + 1 AS period_days,
			NULL AS last_result_date
		FROM Users
			INNER JOIN Memberships ON Memberships.user_id = Users.id
			INNER JOIN Results ON Results.user = Users.id AND Results.status = 'active'
				AND Results.date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY) $typeJoin
		WHERE Memberships.group_id = $groupid
		GROUP BY Users.id
		ORDER BY avg_pct DESC";
	} elseif ($period === 'alltime') {
		$q = "SELECT
			Users.id AS userid,
			Users.first_name,
			Users.last_name,
			Users.pic_filename,
			ROUND(AVG(score / max) * 100, 0) AS avg_pct,
			NULL AS prev_avg_pct,
			COUNT(DISTINCT Results.date) AS days_active,
			DATEDIFF(CURDATE(), MIN(Results.date)) + 1 AS period_days,
			DATE_FORMAT(MAX(Results.date), '%Y-%m-%d') AS last_result_date
		FROM Users
			INNER JOIN Memberships ON Memberships.user_id = Users.id
			INNER JOIN Results ON Results.user = Users.id AND Results.status = 'active' $typeJoin
		WHERE Memberships.group_id = $groupid
		GROUP BY Users.id
		ORDER BY avg_pct DESC";
	} else {
		$q = "SELECT
			Users.id AS userid,
			Users.first_name,
			Users.last_name,
			Users.pic_filename,
			ROUND(AVG(CASE WHEN Results.date >= DATE_SUB(CURDATE(), INTERVAL $days DAY) $typeSub THEN score / max END) * 100, 0) AS avg_pct,
			ROUND(AVG(CASE WHEN Results.date BETWEEN DATE_SUB(CURDATE(), INTERVAL $prev_start DAY) AND DATE_SUB(CURDATE(), INTERVAL $prev_end DAY) $typeSub THEN score / max END) * 100, 0) AS prev_avg_pct,
			COUNT(DISTINCT CASE WHEN Results.date >= DATE_SUB(CURDATE(), INTERVAL $days DAY) $typeSub THEN Results.date END) AS days_active,
			$days AS period_days,
			NULL AS last_result_date
		FROM Users
			INNER JOIN Memberships ON Memberships.user_id = Users.id
			LEFT JOIN Results ON Results.user = Users.id
				AND Results.status = 'active'
				AND Results.date >= DATE_SUB(CURDATE(), INTERVAL $prev_start DAY)
				$typeJoin
		WHERE Memberships.group_id = $groupid
		GROUP BY Users.id
		HAVING avg_pct IS NOT NULL
		ORDER BY avg_pct DESC";
	}

	$result = $conn->query($q);

	$rankings = [];
	if ($result && mysqli_num_rows($result) > 0) {
		while ($row = mysqli_fetch_assoc($result)) {
			$rankings[] = [
				'userid'           => (int)$row['userid'],
				'first_name'       => $row['first_name'],
				'last_name'        => $row['last_name'],
				'pic_filename'     => $row['pic_filename'],
				'avg_pct'          => (int)$row['avg_pct'],
				'prev_avg_pct'     => $row['prev_avg_pct'] !== null ? (int)$row['prev_avg_pct'] : null,
				'days_active'      => (int)$row['days_active'],
				'period_days'      => (int)$row['period_days'],
				'last_result_date' => $row['last_result_date'],
			];
		}
	}

	echo json_encode($rankings);
?>
