<?php
/**
 * action-getquizzes.php
 *
 * Returns Quizzical AI quizzes from the last 7 days.
 * Each entry is marked 'done' if the logged-in user has already posted a result
 * for that quiz type on that date.
 *
 * Params: none
 */

	require_once 'require_auth.php';
	// $userid set by require_auth.php from validated session

	header('Cache-Control: no-store, no-cache, must-revalidate');
	header('Pragma: no-cache');

	$nztz    = new DateTimeZone('Pacific/Auckland');
	$aiCutoff = (new DateTime('now', $nztz))->modify('-7 days')->format('Y-m-d');

	$quizzes = [];
	$aiResult = $conn->query(
		"SELECT id, type, date FROM AIQuiz WHERE status = 'active' AND date >= '$aiCutoff' ORDER BY date DESC, type ASC"
	);

	if ($aiResult) {
		$aiDone = [];
		$doneQ = $conn->query(
			"SELECT REPLACE(type, 'Quizzical ', '') AS base_type, DATE_FORMAT(date, '%Y-%m-%d') AS date, score, max
			 FROM Results
			 WHERE user = $userid AND status = 'active' AND type IN ('Quizzical Morning', 'Quizzical Afternoon')
			 AND date >= DATE_SUB(CURDATE(), INTERVAL 8 DAY)"
		);
		if ($doneQ) {
			while ($row = $doneQ->fetch_assoc()) {
				$aiDone[$row['base_type'] . '|' . $row['date']] = ['score' => (int)$row['score'], 'max' => (int)$row['max']];
			}
		}

		while ($row = $aiResult->fetch_assoc()) {
			$dt = new DateTime($row['date'], $nztz);
			$quizDate = $dt->format('Y-m-d');
			$key = $row['type'] . '|' . $quizDate;

			$entry = [
				'url'     => null,
				'title'   => 'Quizzical ' . $row['type'] . ' Quiz — ' . $dt->format('j M'),
				'type'    => $row['type'],
				'date'    => $quizDate,
				'source'  => 'ai',
				'quiz_id' => (int)$row['id'],
				'done'    => isset($aiDone[$key]),
			];
			if (isset($aiDone[$key])) {
				$entry['score'] = $aiDone[$key]['score'];
				$entry['max']   = $aiDone[$key]['max'];
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
