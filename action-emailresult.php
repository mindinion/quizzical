<?php
/**
 * action-emailresult.php
 *
 * Sends email notifications to group members when a new quiz result is posted.
 * Only users who share the poster's default group and have opted in to result
 * notifications (notify_results = 1) are contacted. The result submitter is excluded.
 */

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'config.php';

	$resultId = isset($_GET['resultId']) ? (int)$_GET['resultId'] : 0;
	if (!$resultId) { echo "No input"; exit; }

	// Fetch result details and poster info
	$q = "SELECT Results.score, Results.max, Results.type, Results.date,
			QuizFeed.comment AS post_comment,
			Users.id AS userid, Users.first_name, Users.last_name, Users.default_group
		FROM Results
		INNER JOIN Users ON Results.user = Users.id
		LEFT JOIN QuizFeed ON QuizFeed.result_id = Results.id
		WHERE Results.id = $resultId LIMIT 1";
	$result = $conn->query($q);
	$row = mysqli_fetch_assoc($result);
	if (!$row) exit;

	$userid    = $row['userid'];
	$groupid   = $row['default_group'];
	$posterName = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
	$score     = htmlspecialchars($row['score']);
	$max       = htmlspecialchars($row['max']);
	$type      = htmlspecialchars($row['type']);
	$date      = date('j F Y', strtotime($row['date']));
	$comment   = $row['post_comment'] ?? '';

	$resultBlock = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">Result</p>
		<p style="margin:0 0 24px;font-size:13px;color:#333;background:#fff8f0;border-left:4px solid #e67300;padding:10px 14px;border-radius:4px;">
		<strong>' . $posterName . '</strong> scored <strong>' . $score . '/' . $max . '</strong> in the ' . $type . ' quiz on ' . $date .
		($comment !== '' ? '<br><em style="color:#666;">' . htmlspecialchars($comment) . '</em>' : '') .
		'</p>';

	$cta = '<a href="https://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>';

	$q = "SELECT id, email FROM Users WHERE notify_results = 1 AND default_group = $groupid";
	$result = $conn->query($q);
	$response = 0;
	while ($row = mysqli_fetch_assoc($result)) {
		if ($row['id'] == $userid) continue;
		$html = mailHtml($resultBlock . $cta);
		sendMail($conn, $row['email'], $posterName . " posted a new " . $type . " result", $html, false, true);
		$response++;
	}

	echo $response;
?>
