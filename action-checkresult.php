<?php
/**
 * action-checkresult.php
 *
 * Looks up whether the logged-in user has already submitted a result for a given quiz.
 * Returns the score if a result is found, or echoes "failed" if no matching active result exists.
 * Used by the feed bubbles to offer "review" or "play" against someone else's post.
 *
 * Prefers quiz_id, which identifies the quiz exactly. Falls back to type + date for feed
 * posts made before results were linked to their quiz.
 */

	require_once 'require_auth.php';
	require_once __DIR__ . '/ai-quiz-stats.php';
	// $userid set by require_auth.php from validated session

	if (isset($_GET['date'])) $date = sanitizeString($_GET['date']);
	if (isset($_GET['type'])) $type = sanitizeString($_GET['type']);
	$quizId = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
	$score = "";

	if ($quizId > 0 && resultsHasAiQuizId($conn)) {
		$q = "SELECT score FROM Results WHERE ai_quiz_id = $quizId AND user = $userid and status = 'active';";
	} else {
		// Match on the formatted date string to avoid time-component mismatches
		$q = "SELECT score FROM Results WHERE DATE_FORMAT(date,'%Y-%m-%d') = '$date' AND type = '$type' AND user = $userid and status = 'active';";
	}
	$result = $conn->query($q);

	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$score = $row['score'];
    	}
	} else {
		// Signal to the caller that no result was found
    	echo "failed";
    }
    echo $score;
?>