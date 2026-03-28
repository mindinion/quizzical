<?php
/**
 * action-getpersonalbests.php
 *
 * Returns each user's best score (as %) per quiz type, for all users in a group.
 * Used to power the Personal Bests section in the rankings panel.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['groupid'])) $groupid = sanitizeString($_GET['groupid']);

	$q = "SELECT
		Users.id AS userid,
		Users.first_name,
		Users.last_name,
		Results.type,
		MAX(ROUND(score / max * 100, 0)) AS best_pct
	FROM Users
		INNER JOIN Memberships ON Memberships.user_id = Users.id
		INNER JOIN Results ON Results.user = Users.id AND Results.status = 'active'
	WHERE Memberships.group_id = $groupid
	GROUP BY Users.id, Results.type
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
				'best_pct'   => (int)$row['best_pct'],
			];
		}
	}

	echo json_encode($pbs);
?>
