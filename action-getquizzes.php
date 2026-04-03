<?php
/**
 * action-getquizzes.php
 *
 * Fetches the Stuff.co.nz quiz RSS feed and returns quizzes from the last 7 days.
 * Each entry is marked 'done' if the logged-in user has already submitted a result
 * for that quiz type on that date.
 *
 * Params:
 *   userid     - the logged-in user's id
 *   typefilter - 'main' (Morning & Afternoon only) or 'all' (everything)
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	$userid     = isset($_GET['userid'])     ? (int)sanitizeString($_GET['userid'])     : 0;
	$typefilter = isset($_GET['typefilter']) ? sanitizeString($_GET['typefilter'])       : 'main';

	// Fetch RSS with a short timeout
	$ctx = stream_context_create(['http' => ['timeout' => 5]]);
	$rss = @file_get_contents('https://www.stuff.co.nz/rss?section=/quizzes', false, $ctx);
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

	// Mark quizzes the user has already logged
	if ($userid > 0 && count($quizzes)) {
		$q = "SELECT type, DATE_FORMAT(date, '%Y-%m-%d') AS date
		      FROM Results
		      WHERE user = $userid AND status = 'active'
		      AND date >= DATE_SUB(CURDATE(), INTERVAL 8 DAY)";
		$result = $conn->query($q);
		$done = [];
		if ($result) {
			while ($row = mysqli_fetch_assoc($result)) {
				$done[$row['type'] . '|' . $row['date']] = true;
			}
		}
		foreach ($quizzes as &$quiz) {
			$quiz['done'] = isset($done[$quiz['type'] . '|' . $quiz['date']]);
		}
	}

	echo json_encode($quizzes);
?>
