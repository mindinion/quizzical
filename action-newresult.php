<?php
/**
 * action-newresult.php
 *
 * Submits a new quiz result and creates the corresponding QuizFeed post.
 * The result date is resolved from a dateOption parameter:
 *   - "today"     -> current server date/time in the user's timezone
 *   - "yesterday" -> 24 hours before the current time
 *   - anything else -> the raw date string passed in the 'date' parameter
 *
 * An AI quiz result also passes quiz_id, which is stored in Results.ai_quiz_id. That link
 * is what identifies which quiz was played; the date only records when it was played.
 * Duplicate submissions are rejected per quiz when a quiz_id is given, so catching up on
 * an earlier day's quiz no longer collides with today's. Older manual result types keep
 * the original one-per-type-per-calendar-day rule.
 * On success, inserts a Results row and a linked QuizFeed row, then echoes the result id.
 */

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';
	require_once __DIR__ . '/ai-quiz-stats.php';

	header('Content-Type: application/json');

	// Get inputted details
	$type       = isset($_GET['type'])       ? $conn->real_escape_string($_GET['type'])       : '';
	$score      = isset($_GET['score'])      ? (int)$_GET['score']                           : 0;
	$max        = isset($_GET['questions'])  ? (int)$_GET['questions']                       : 0;
	$dateOption = isset($_GET['dateOption']) ? $conn->real_escape_string($_GET['dateOption']) : '';
	$date       = isset($_GET['date'])       ? $conn->real_escape_string($_GET['date'])       : '';
	$comment    = isset($_GET['comment'])    ? $conn->real_escape_string($_GET['comment'])    : '';
	$aiQuizId   = isset($_GET['quiz_id'])    ? (int)$_GET['quiz_id']                          : 0;

	// Clients running a cached older script post without a quiz id. Recover it from the
	// user's answers so their result is still filed against the right quiz.
	if ($aiQuizId <= 0) {
		$aiQuizId = aiInferQuizIdForResult($conn, (int)$userid, $type);
	}

	$linkQuiz = $aiQuizId > 0 && resultsHasAiQuizId($conn);
	// $userid is set by getsettings.php from the validated session
	// $timezone is set by getsettings.php from the user's DB profile

	// Resolve the result timestamp based on the user's selected date option
	date_default_timezone_set($timezone);
	if ($dateOption == "today") {
		$dt = date("Y-m-d H:i:s");
	} else if ($dateOption == "yesterday") {
		// Subtract exactly 24 hours to get yesterday's timestamp
		$dt = date("Y-m-d H:i:s", strtotime(date("Y-m-d H:i:s")) - (60*60*24));
	} else {
		// Use the explicit date passed from the historical date picker
		$dt = $date;
	}

	// Duplicate check. An AI quiz is identified by its id, so the same quiz can only be
	// posted once but two quizzes of the same type can both be played on one day.
	if ($linkQuiz) {
		$q = "SELECT id FROM Results WHERE user='$userid' AND ai_quiz_id='$aiQuizId' AND status = 'active' LIMIT 1;";
	} else {
		// Compare day/month/year separately to handle time-component variation
		$q = "SELECT id FROM Results WHERE user='$userid' AND type='$type' AND DAY(date) = DAY('$dt') and MONTH(date) = MONTH('$dt') and YEAR(date) = YEAR('$dt') and Results.status = 'active';";
	}
	$result = $conn->query($q);
	if (mysqli_num_rows($result) != 0) {
		echo json_encode([
			'error'   => 'already_posted',
			'message' => $linkQuiz
				? 'You have already posted a result for this quiz.'
				: 'You have already posted a ' . $type . ' result for this day.',
		]);
		return;
	}

	// Insert the result, then create a linked QuizFeed post timestamped to now (not the quiz date)
	if ($linkQuiz) {
		$q = "INSERT INTO Results (user, type, score, max, date, ai_quiz_id) VALUES" . "('$userid','$type','$score','$max','$dt','$aiQuizId');";
	} else {
		$q = "INSERT INTO Results (user, type, score, max, date) VALUES" . "('$userid','$type','$score','$max','$dt');";
	}
	$result = $conn->query($q);
	$result_id = mysqli_insert_id($conn);
	$now = date("Y-m-d H:i:s");
	$q = "INSERT INTO QuizFeed (result_id, user_id, comment, timestamp) VALUES" . "('$result_id', '$userid', '$comment', '$now');";
	$result = $conn->query($q);
	$post_id = mysqli_insert_id($conn);
	echo json_encode(['result_id' => $result_id, 'post_id' => $post_id]);


?>