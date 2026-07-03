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

	// Resolve Riddle embed URLs (bypasses Stuff login wall)
	// Cache maps articleId -> Riddle embed URL (permanent — IDs never change)
	$riddleCacheFile = __DIR__ . '/uploads/riddle_cache.json';
	$riddleCache = [];
	if (file_exists($riddleCacheFile)) {
		$riddleCache = json_decode(file_get_contents($riddleCacheFile), true) ?: [];
	}
	$cacheUpdated = false;

	foreach ($quizzes as &$quiz) {
		if (!preg_match('/\/quizzes\/(\d+)\//', $quiz['url'], $m)) continue;
		$articleId = $m[1];

		if (array_key_exists($articleId, $riddleCache)) {
			if ($riddleCache[$articleId]) $quiz['url'] = $riddleCache[$articleId];
			continue;
		}

		// Fetch from Stuff's internal content API (no auth required)
		$ch = curl_init("https://www.stuff.co.nz/api/v1.0/stuff/story/$articleId");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
		$resp = curl_exec($ch);
		curl_close($ch);

		$riddleUrl = null;
		if ($resp) {
			$data = json_decode($resp, true);
			foreach (($data['content']['contentBody']['assets'] ?? []) as $asset) {
				if (($asset['type'] ?? '') === 'WIDGET') {
					if (preg_match('/data-rid-id="([^"]+)"/', $asset['item']['content'] ?? '', $rm)) {
						$riddleUrl = "https://www.riddle.com/embed/a/{$rm[1]}";
						break;
					}
				}
			}
		}

		$riddleCache[$articleId] = $riddleUrl;
		$cacheUpdated = true;
		if ($riddleUrl) $quiz['url'] = $riddleUrl;
	}
	unset($quiz);

	if ($cacheUpdated) {
		$tmp = $riddleCacheFile . '.tmp';
		if (file_put_contents($tmp, json_encode($riddleCache)) !== false) rename($tmp, $riddleCacheFile);
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

	echo json_encode($quizzes);
?>
