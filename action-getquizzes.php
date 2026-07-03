<?php
/**
 * action-getquizzes.php
 *
 * Fetches the Stuff.co.nz quiz RSS feed and returns quizzes from the last 7 days.
 * Each entry is marked 'done' if the logged-in user has already submitted a result
 * for that quiz type on that date.
 *
 * The raw RSS XML is cached to a file for 15 minutes to avoid hitting Stuff's server
 * on every page load. The cache is written atomically (temp file → rename) to prevent
 * corrupted reads if two requests write simultaneously.
 *
 * Params:
 *   typefilter - 'main' (Morning & Afternoon only) or 'all' (everything)
 */

	require_once 'require_auth.php';
	// $userid set by require_auth.php from validated session

	$typefilter = isset($_GET['typefilter']) ? sanitizeString($_GET['typefilter']) : 'main';

	// --- RSS cache ---
	$cacheFile = __DIR__ . '/uploads/rss_cache.xml';
	$cacheTtl  = 15 * 60; // 15 minutes in seconds

	$rss = null;
	if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
		$rss = file_get_contents($cacheFile);
	}

	if (!$rss) {
		$ch = curl_init('https://www.stuff.co.nz/rss?section=/quizzes');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 8);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		$fetched = curl_exec($ch);
		curl_close($ch);

		if ($fetched) {
			$rss = $fetched;
			// Atomic write: temp file then rename so readers never see a partial write
			$tmp = $cacheFile . '.tmp';
			if (file_put_contents($tmp, $rss) !== false) {
				rename($tmp, $cacheFile);
			}
		} elseif (file_exists($cacheFile)) {
			// Fetch failed — serve stale cache rather than returning nothing
			$rss = file_get_contents($cacheFile);
		}
	}

	if (!$rss) { echo json_encode([]); exit; }

	$xml = @simplexml_load_string($rss);
	if (!$xml) { echo json_encode([]); exit; }

	$nztz   = new DateTimeZone('Pacific/Auckland');
	$utctz  = new DateTimeZone('UTC');
	$cutoff = (new DateTime('now', $nztz))->modify('-7 days')->format('Y-m-d');

	$quizzes = [];
	foreach ($xml->entry as $entry) {
		$url       = (string)$entry->link['href'];
		$title     = (string)$entry->title;
		$published = (string)$entry->published;

		// Convert UTC published time to NZ date
		$dt = new DateTime($published, $utctz);
		$dt->setTimezone($nztz);
		$date = $dt->format('Y-m-d');

		if ($date < $cutoff) continue;

		// Determine quiz type from title prefix
		if (strpos($title, 'AM quiz') !== false) {
			$type = 'Morning';
		} elseif (strpos($title, 'PM quiz') !== false) {
			$type = 'Afternoon';
		} elseif (stripos($title, 'hard word') !== false) {
			$type = 'Hard Word';
		} else {
			$type = 'Other';
		}

		if ($typefilter === 'main' && $type !== 'Morning' && $type !== 'Afternoon') continue;

		$quizzes[] = [
			'url'   => $url,
			'title' => $title,
			'type'  => $type,
			'date'  => $date,
			'done'  => false,
		];
	}

	// Mark quizzes the user has already logged, including their score
	if (count($quizzes)) {
		$q = "SELECT type, DATE_FORMAT(date, '%Y-%m-%d') AS date, score, max
		      FROM Results
		      WHERE user = $userid AND status = 'active'
		      AND date >= DATE_SUB(CURDATE(), INTERVAL 8 DAY)";
		$result = $conn->query($q);
		$done = [];
		if ($result) {
			while ($row = mysqli_fetch_assoc($result)) {
				$done[$row['type'] . '|' . $row['date']] = [
					'score' => (int)$row['score'],
					'max'   => (int)$row['max'],
				];
			}
		}
		foreach ($quizzes as &$quiz) {
			$key = $quiz['type'] . '|' . $quiz['date'];
			if (isset($done[$key])) {
				$quiz['done']  = true;
				$quiz['score'] = $done[$key]['score'];
				$quiz['max']   = $done[$key]['max'];
			}
		}
	}

	// --- Append AI-generated quizzes ---
	$aiCutoff = (new DateTime('now', $nztz))->modify('-7 days')->format('Y-m-d');
	$aiResult = $conn->query(
		"SELECT id, type, date FROM AIQuiz WHERE status = 'active' AND date >= '$aiCutoff' ORDER BY date DESC, type ASC"
	);
	if ($aiResult) {
		// Collect AI quiz IDs the user has already completed (Results rows with AI types)
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

			if ($typefilter === 'main' && $row['type'] !== 'Morning' && $row['type'] !== 'Afternoon') continue;

			$key   = $row['type'] . '|' . $quizDate;
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

	// Sort all quizzes together by date desc, then Morning before Afternoon within same day
	usort($quizzes, function($a, $b) {
		if ($a['date'] !== $b['date']) return strcmp($b['date'], $a['date']);
		$order = ['Morning' => 0, 'Afternoon' => 1, 'Hard Word' => 2, 'Other' => 3];
		return ($order[$a['type']] ?? 3) - ($order[$b['type']] ?? 3);
	});

	echo json_encode($quizzes);
?>
