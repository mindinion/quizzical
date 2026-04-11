<?php
/**
 * action-emailcomment.php
 *
 * Sends email notifications when a new comment is posted on a QuizFeed item.
 * Recipients:
 *   1. The original poster — always notified (unless they are the commenter)
 *   2. Users who previously commented on the post — always notified
 *   3. Other group members — only if notify_message = 1
 * Each recipient receives at most one email regardless of which rules apply.
 */

	require_once 'require_auth.php';
	require_once 'config.php';
	// $userid set by require_auth.php from validated session

	$postId = isset($_GET['postId']) ? (int)$_GET['postId'] : 0;

	// Fetch post details — original poster, post content, linked result if any
	$q = "SELECT
			QuizFeed.id, QuizFeed.comment, QuizFeed.user_id AS poster_id,
			Users.first_name, Users.last_name, Users.email, Users.default_group,
			Results.score, Results.max, Results.type, Results.date
		FROM QuizFeed
		INNER JOIN Users ON QuizFeed.user_id = Users.id
		LEFT JOIN Results ON QuizFeed.result_id = Results.id
		WHERE QuizFeed.id = $postId";
	$result = $conn->query($q);
	$post = mysqli_fetch_assoc($result);
	if (!$post) exit;

	$posterId   = $post['poster_id'];
	$posterName = htmlspecialchars($post['first_name'] . ' ' . $post['last_name']);
	$posterEmail = $post['email'];
	$groupid    = $post['default_group'];

	// Fetch the new comment text
	$q = "SELECT comment FROM Comment WHERE quizfeed_id = $postId AND user_id = $userid ORDER BY id DESC LIMIT 1";
	$r = $conn->query($q);
	$newComment = ($row = mysqli_fetch_assoc($r)) ? $row['comment'] : '';

	// Fetch commenter name
	$q = "SELECT first_name, last_name FROM Users WHERE id = $userid LIMIT 1";
	$r = $conn->query($q);
	$commenterRow = mysqli_fetch_assoc($r);
	$commenterName = htmlspecialchars($commenterRow['first_name'] . ' ' . $commenterRow['last_name']);

	// Fetch attachments on the new comment
	$q = "SELECT original_name FROM Attachments WHERE comment_id = (SELECT id FROM Comment WHERE quizfeed_id = $postId AND user_id = $userid ORDER BY id DESC LIMIT 1)";
	$r = $conn->query($q);
	$attachNames = [];
	if ($r) while ($row = mysqli_fetch_assoc($r)) $attachNames[] = htmlspecialchars($row['original_name']);

	// Build the post context block (what the comment was on)
	if ($post['score'] !== null) {
		$postContext = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">Original post</p>
			<p style="margin:0 0 16px;font-size:13px;color:#666;background:#fff8f0;border-left:4px solid #e0c8a8;padding:10px 14px;border-radius:4px;">
			' . $posterName . ' scored ' . htmlspecialchars($post['score']) . '/' . htmlspecialchars($post['max']) . ' in the ' . htmlspecialchars($post['type']) . ' quiz on ' . date('j F Y', strtotime($post['date'])) .
			(($post['comment'] ?? '') !== '' ? '<br><em>' . htmlspecialchars($post['comment']) . '</em>' : '') . '
			</p>';
	} else {
		$postContext = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">Original post</p>
			<p style="margin:0 0 16px;font-size:13px;color:#666;background:#fff8f0;border-left:4px solid #e0c8a8;padding:10px 14px;border-radius:4px;">' . htmlspecialchars($post['comment'] ?? '') . '</p>';
	}

	// Build new comment block
	$attachHtml = '';
	if (!empty($attachNames)) {
		$attachHtml = '<p style="margin:4px 0 0;font-size:12px;color:#a07040;">Attachments: ' . implode(', ', $attachNames) . '</p>';
	}
	$commentBlock = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">New comment from ' . $commenterName . '</p>
		<p style="margin:0 0 ' . (empty($attachNames) ? '24' : '8') . 'px;font-size:13px;color:#333;background:#fff3e8;border-left:4px solid #e67300;padding:10px 14px;border-radius:4px;">' . htmlspecialchars($newComment) . '</p>
		' . $attachHtml . '
		<p style="margin:' . (empty($attachNames) ? '0' : '16px') . ' 0 0;">';

	$ctaBtn = '<a href="https://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a></p>';

	// Track who has been emailed to avoid duplicates
	$emailed = [];

	// 1. Notify the original poster (always, unless they're the commenter)
	if ($posterId != $userid) {
		$html = mailHtml('<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>' . $commenterName . '</strong> commented on your post.</p>' . $postContext . $commentBlock . $ctaBtn);
		sendMail($conn, $posterEmail, "New comment on your post", $html, false, true);
		$emailed[$posterId] = true;
	}

	// 2. Notify previous commenters (always) and other group members (notify_message only)
	// Single query: get all group members with a flag for whether they previously commented
	$q = "SELECT Users.id, Users.email, Users.notify_message,
			MAX(CASE WHEN Comment.user_id = Users.id THEN 1 ELSE 0 END) AS has_commented
		FROM Users
		LEFT JOIN Comment ON Comment.quizfeed_id = $postId AND Comment.user_id = Users.id AND Comment.status = 'active'
		WHERE Users.default_group = $groupid
		GROUP BY Users.id";
	$result = $conn->query($q);
	while ($row = mysqli_fetch_assoc($result)) {
		$id = $row['id'];
		if ($id == $userid) continue;             // skip the commenter
		if (isset($emailed[$id])) continue;        // skip already emailed
		if ($id == $posterId) continue;            // skip original poster (done above)

		$isPreviousCommenter = $row['has_commented'];
		$hasNotifyOn = $row['notify_message'];

		if ($isPreviousCommenter || $hasNotifyOn) {
			$intro = $isPreviousCommenter
				? '<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>' . $commenterName . '</strong> commented on a post you\'ve also commented on.</p>'
				: '<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>' . $commenterName . '</strong> commented on a post by ' . $posterName . '.</p>';
			$html = mailHtml($intro . $postContext . $commentBlock . $ctaBtn);
			sendMail($conn, $row['email'], "New comment on Quizzical", $html, false, true);
			$emailed[$id] = true;
		}
	}

	echo count($emailed);
?>
