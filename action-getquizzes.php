<?php
/**
 * action-getquizzes.php
 *
 * Returns Quizzical AI quizzes from the last 7 days.
 * Each entry is marked 'done' if the logged-in user has already posted a result linked
 * to that quiz. Results posted before ai_quiz_id existed fall back to matching the quiz
 * type and the day the result was posted, which is only correct when the quiz was played
 * on its own date — run action-migrate-result-quizid.php to backfill the real links.
 *
 * Params: none
 */

	require_once 'require_auth.php';
	require_once __DIR__ . '/ai-quiz-stats.php';
	// $userid set by require_auth.php from validated session

	header('Cache-Control: no-store, no-cache, must-revalidate');
	header('Pragma: no-cache');

	$nztz    = new DateTimeZone('Pacific/Auckland');
	$aiCutoff = (new DateTime('now', $nztz))->modify('-7 days')->format('Y-m-d');

	$quizzes = [];
	$aiRows = [];
	$aiResult = $conn->query(
		"SELECT id, type, date FROM AIQuiz WHERE status = 'active' AND date >= '$aiCutoff' ORDER BY date DESC, type ASC"
	);
	if ($aiResult) {
		while ($row = $aiResult->fetch_assoc()) { $aiRows[] = $row; }
	}

	if ($aiRows) {
		$hasLink   = resultsHasAiQuizId($conn);
		$doneById  = [];
		$doneByKey = [];

		$quizIds = array_map(function($r) { return (int)$r['id']; }, $aiRows);
		$idList  = implode(',', $quizIds);
		// A linked result can be posted long after the quiz date, so it is matched on the
		// link alone; only unlinked legacy rows need the posting-date window.
		$recentlyPosted = "date >= DATE_SUB(CURDATE(), INTERVAL 8 DAY)";
		$linkSelect = $hasLink ? 'ai_quiz_id' : 'NULL AS ai_quiz_id';
		$scope = $hasLink
			? "(ai_quiz_id IN ($idList) OR (ai_quiz_id IS NULL AND $recentlyPosted))"
			: "($recentlyPosted)";
		$doneQ = $conn->query(
			"SELECT $linkSelect, REPLACE(type, 'Quizzical ', '') AS base_type, DATE_FORMAT(date, '%Y-%m-%d') AS date, score, max
			 FROM Results
			 WHERE user = $userid AND status = 'active' AND type IN ('Quizzical Morning', 'Quizzical Afternoon')
			 AND $scope"
		);
		if ($doneQ) {
			while ($row = $doneQ->fetch_assoc()) {
				$entry = ['score' => (int)$row['score'], 'max' => (int)$row['max']];
				if ($row['ai_quiz_id'] !== null) {
					$doneById[(int)$row['ai_quiz_id']] = $entry;
				} else {
					$doneByKey[$row['base_type'] . '|' . $row['date']] = $entry;
				}
			}
		}

		foreach ($aiRows as $row) {
			$dt = new DateTime($row['date'], $nztz);
			$quizDate = $dt->format('Y-m-d');
			$quizId = (int)$row['id'];
			$done = $doneById[$quizId] ?? $doneByKey[$row['type'] . '|' . $quizDate] ?? null;

			$entry = [
				'url'     => null,
				'title'   => $row['type'] . ' Quiz — ' . $dt->format('j M'),
				'type'    => $row['type'],
				'date'    => $quizDate,
				'source'  => 'ai',
				'quiz_id' => $quizId,
				'done'    => $done !== null,
			];
			if ($done !== null) {
				$entry['score'] = $done['score'];
				$entry['max']   = $done['max'];
			}
			$quizzes[] = $entry;
		}
	}

	usort($quizzes, function($a, $b) {
		if ($a['date'] !== $b['date']) return strcmp($b['date'], $a['date']);
		$order = ['Morning' => 0, 'Afternoon' => 1];
		return ($order[$a['type']] ?? 1) - ($order[$b['type']] ?? 1);
	});

	echo json_encode($quizzes);
?>
