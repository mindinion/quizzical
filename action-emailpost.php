<?php
/**
 * action-emailpost.php
 *
 * Sends email notifications to group members when a new plain-text QuizFeed post is created.
 * Only users in the same default group who have opted in to message notifications
 * (notify_message = 1) are emailed. The poster themselves is excluded.
 */

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'config.php';

	$postId = isset($_GET['postId']) ? (int)$_GET['postId'] : 0;
	if (!$postId) { echo "No input"; exit; }

	// Fetch post details and poster info
	$q = "SELECT QuizFeed.comment, Users.id AS userid, Users.first_name, Users.last_name, Users.default_group
		FROM QuizFeed
		INNER JOIN Users ON QuizFeed.user_id = Users.id
		WHERE QuizFeed.id = $postId LIMIT 1";
	$result = $conn->query($q);
	$post = mysqli_fetch_assoc($result);
	if (!$post) exit;

	$userid     = $post['userid'];
	$groupid    = $post['default_group'];
	$posterName = htmlspecialchars($post['first_name'] . ' ' . $post['last_name']);
	$comment    = $post['comment'] ?? '';

	// Fetch attachments on this post
	$q = "SELECT original_name FROM Attachments WHERE post_id = $postId";
	$r = $conn->query($q);
	$attachNames = [];
	if ($r) while ($row = mysqli_fetch_assoc($r)) $attachNames[] = htmlspecialchars($row['original_name']);

	$postBlock = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">' . $posterName . ' posted</p>
		<p style="margin:0 0 ' . (empty($attachNames) ? '24' : '8') . 'px;font-size:13px;color:#333;background:#fff8f0;border-left:4px solid #e67300;padding:10px 14px;border-radius:4px;">' . htmlspecialchars($comment) . '</p>';

	if (!empty($attachNames)) {
		$postBlock .= '<p style="margin:0 0 24px;font-size:12px;color:#a07040;">Attachments: ' . implode(', ', $attachNames) . '</p>';
	}

	$cta = '<a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>';

	$q = "SELECT id, email FROM Users WHERE notify_message = 1 AND default_group = $groupid";
	$result = $conn->query($q);
	$response = 0;
	while ($row = mysqli_fetch_assoc($result)) {
		if ($row['id'] == $userid) continue;
		$html = mailHtml($postBlock . $cta);
		sendMail($conn, $row['email'], $posterName . " posted on Quizzical", $html, false, true);
		$response++;
	}

	echo $response;
?>
