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
	require_once __DIR__ . '/quiz-feed-helper.php';
	// $userid set by require_auth.php from validated session

	$postId = isset($_GET['postId']) ? (int)$_GET['postId'] : 0;

	$hasQuizColumns = quizFeedHasDiscussionColumns($conn);
	$quizCols = $hasQuizColumns
		? "QuizFeed.ai_quiz_id, QuizFeed.quiz_type, QuizFeed.quiz_date"
		: "NULL AS ai_quiz_id, NULL AS quiz_type, NULL AS quiz_date";

	// Fetch post details — original poster, post content, linked result if any
	$q = "SELECT
			QuizFeed.id, QuizFeed.comment, QuizFeed.user_id AS poster_id, QuizFeed.result_id,
			$quizCols,
			Users.first_name, Users.last_name, Users.email, Users.default_group,
			Results.score, Results.max, Results.type, Results.date
		FROM QuizFeed
		INNER JOIN Users ON QuizFeed.user_id = Users.id
		LEFT JOIN Results ON QuizFeed.result_id = Results.id
		WHERE QuizFeed.id = $postId";
	$result = $conn->query($q);
	$post = mysqli_fetch_assoc($result);
	if (!$post) exit;

	// A quiz discussion shell has no author and no body — its QuizFeed.user_id is
	// only whoever happened to create it, so it must not be treated as a poster.
	$isQuizDiscussion = $post['result_id'] === null
		&& ($post['ai_quiz_id'] !== null
			|| ($post['quiz_type'] !== null && $post['quiz_type'] !== ''));
	$quizTitle = $isQuizDiscussion
		? feedQuizTitle($conn, $post['ai_quiz_id'] !== null ? (int)$post['ai_quiz_id'] : null, $post['quiz_type'], $post['quiz_date'])
		: '';

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
	if ($isQuizDiscussion) {
		$postContext = '<p style="margin:0 0 4px;font-size:11px;color:#a07040;text-transform:uppercase;letter-spacing:0.5px;">Quiz discussion</p>
			<p style="margin:0 0 16px;font-size:13px;color:#666;background:#fff8f0;border-left:4px solid #e0c8a8;padding:10px 14px;border-radius:4px;">'
			. htmlspecialchars($quizTitle) . '</p>';
	} elseif ($post['score'] !== null) {
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

	// 1. Notify the original poster (always, unless they're the commenter).
	// Skipped for quiz discussions, which have no author.
	if (!$isQuizDiscussion && $posterId != $userid) {
		$html = mailHtml('<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>' . $commenterName . '</strong> commented on your post.</p>' . $postContext . $commentBlock . $ctaBtn);
		sendMail($conn, $posterEmail, "New comment on your post", $html, false, true);
		$emailed[$posterId] = true;
	}

	// Matches the results belonging to the quiz under discussion, so the people who
	// played it can be notified the same way previous commenters are.
	$playedCondition = '0';
	if ($isQuizDiscussion) {
		$quizType = $post['quiz_type'];
		$quizDate = $post['quiz_date'];
		if ($post['ai_quiz_id'] !== null) {
			$zq = $conn->query("SELECT type, date FROM AIQuiz WHERE id = " . (int)$post['ai_quiz_id'] . " LIMIT 1");
			if ($zq && $zrow = $zq->fetch_assoc()) {
				$quizType = 'Quizzical ' . $zrow['type'];
				$quizDate = $quizDate ?: $zrow['date'];
			}
		}

		$match = [];
		if ($post['ai_quiz_id'] !== null && resultsHasAiQuizId($conn)) {
			$match[] = "Results.ai_quiz_id = " . (int)$post['ai_quiz_id'];
		}
		if ($quizType !== null && $quizType !== '' && $quizDate) {
			$match[] = "(Results.type = '" . $conn->real_escape_string($quizType) . "'"
				. " AND DATE(Results.date) = '" . $conn->real_escape_string($quizDate) . "')";
		}
		if ($match) {
			$playedCondition = '(' . implode(' OR ', $match) . ')';
		}
	}

	// 2. Notify previous commenters and (for quizzes) players, always; other group
	// members only if notify_message = 1
	$q = "SELECT Users.id, Users.email, Users.notify_message,
			MAX(CASE WHEN Comment.user_id = Users.id THEN 1 ELSE 0 END) AS has_commented,
			MAX(CASE WHEN Results.user = Users.id THEN 1 ELSE 0 END) AS has_played
		FROM Users
		LEFT JOIN Comment ON Comment.quizfeed_id = $postId AND Comment.user_id = Users.id AND Comment.status = 'active'
		LEFT JOIN Results ON Results.user = Users.id AND Results.status = 'active' AND $playedCondition
		WHERE Users.default_group = $groupid
		GROUP BY Users.id";
	$result = $conn->query($q);
	while ($row = mysqli_fetch_assoc($result)) {
		$id = $row['id'];
		if ($id == $userid) continue;             // skip the commenter
		if (isset($emailed[$id])) continue;        // skip already emailed
		if (!$isQuizDiscussion && $id == $posterId) continue;  // poster done above

		$isPreviousCommenter = $row['has_commented'];
		$hasPlayed = $row['has_played'];
		$hasNotifyOn = $row['notify_message'];

		if ($isQuizDiscussion) {
			if (!$isPreviousCommenter && !$hasPlayed && !$hasNotifyOn) continue;
			if ($isPreviousCommenter) {
				$intro = '<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>' . $commenterName . '</strong> added a comment to a quiz discussion you\'re part of.</p>';
			} elseif ($hasPlayed) {
				$intro = '<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>' . $commenterName . '</strong> commented on a quiz you played.</p>';
			} else {
				$intro = '<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>' . $commenterName . '</strong> commented on a quiz.</p>';
			}
			$subject = "New comment on a Quizzical quiz";
		} else {
			if (!$isPreviousCommenter && !$hasNotifyOn) continue;
			$intro = $isPreviousCommenter
				? '<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>' . $commenterName . '</strong> commented on a post you\'ve also commented on.</p>'
				: '<p style="margin:0 0 16px;font-size:15px;color:#333;"><strong>' . $commenterName . '</strong> commented on a post by ' . $posterName . '.</p>';
			$subject = "New comment on Quizzical";
		}

		$html = mailHtml($intro . $postContext . $commentBlock . $ctaBtn);
		sendMail($conn, $row['email'], $subject, $html, false, true);
		$emailed[$id] = true;
	}

	echo count($emailed);
?>
