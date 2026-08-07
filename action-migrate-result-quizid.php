<?php
/**
 * action-migrate-result-quizid.php
 *
 * Adds Results.ai_quiz_id and backfills it for existing Quizzical results.
 * Auth: QA_RUN_TOKEN (same as action-qa-generate-quiz.php). Idempotent.
 *
 * Historic results only record when they were posted, so a quiz played on a later day
 * than its own date was filed against the wrong quiz. The stored answers do identify the
 * quiz: a result is posted moments after the last answer of the quiz it belongs to, so
 * each result is matched to the most recently completed unlinked quiz of the same type.
 *
 * POST/GET: token, dry (1 to report matches without writing)
 */

require_once 'dblogin.php';
require_once 'config.php';
require_once 'qa-run-lib.php';
require_once __DIR__ . '/ai-quiz-stats.php';

header('Content-Type: application/json');

$token = $_REQUEST['token'] ?? null;
if (!qaTokenIsValid(is_string($token) ? $token : null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$dryRun = !empty($_REQUEST['dry']);
$log = [];

// 1. Schema. A dry run reports the matches it would make without touching the table,
//    treating every Quizzical result as unlinked since that is the state on first run.
$hasColumn = resultsHasAiQuizId($conn);
if (!$hasColumn && $dryRun) {
    $log[] = 'Results.ai_quiz_id missing — would be added.';
} elseif (!$hasColumn) {
    if (!$conn->query("ALTER TABLE `Results` ADD COLUMN `ai_quiz_id` int UNSIGNED DEFAULT NULL")) {
        echo json_encode(['error' => 'Failed to add column: ' . $conn->error]);
        exit;
    }
    $conn->query("ALTER TABLE `Results` ADD KEY `user_ai_quiz` (`user`, `ai_quiz_id`)");
    $hasColumn = true;
    $log[] = 'Added Results.ai_quiz_id.';
} else {
    $log[] = 'Results.ai_quiz_id already present.';
}

// 2. When each user finished each quiz. answered_at is in the server's timezone;
//    Results.date was written in the user's, so the two need aligning before comparison.
$serverTz = new DateTimeZone(date_default_timezone_get());
$finished = [];
// Only fully answered quizzes are candidates — a result cannot have come from a quiz
// the user never finished, since the score screen is only reached at the last question.
$q = $conn->query(
    "SELECT a.user_id, a.quiz_id, z.type, MAX(a.answered_at) AS finished_at, COUNT(*) AS answers,
            (SELECT COUNT(*) FROM AIQuestion q WHERE q.quiz_id = a.quiz_id) AS question_count
     FROM AIAnswer a
     INNER JOIN AIQuiz z ON z.id = a.quiz_id
     GROUP BY a.user_id, a.quiz_id, z.type
     HAVING answers >= question_count AND question_count > 0"
);
while ($q && $row = $q->fetch_assoc()) {
    $finished[(int)$row['user_id']][] = [
        'quiz_id'     => (int)$row['quiz_id'],
        'type'        => 'Quizzical ' . $row['type'],
        'finished_at' => $row['finished_at'],
        'answers'     => (int)$row['answers'],
    ];
}

// 3. Unlinked Quizzical results, oldest first
$results = [];
$unlinkedOnly = $hasColumn ? 'ai_quiz_id IS NULL AND' : '';
$q = $conn->query(
    "SELECT id, user, type, score, max, date FROM Results
     WHERE $unlinkedOnly status = 'active'
       AND type IN ('Quizzical Morning', 'Quizzical Afternoon')
     ORDER BY user, date ASC"
);
while ($q && $row = $q->fetch_assoc()) { $results[] = $row; }

$timezones = [];
$tzq = $conn->query("SELECT id, timezone FROM Users");
while ($tzq && $row = $tzq->fetch_assoc()) { $timezones[(int)$row['id']] = $row['timezone']; }

// Posting was the only way off the score screen, so historically a result follows its
// last answer within seconds. Allow a little slack for clock drift, and a wide but
// same-session window after finishing, so a result is never attached to a quiz the user
// happened to complete days earlier.
const CLOCK_SLACK_SECONDS = 600;
const MAX_POST_DELAY_SECONDS = 12 * 3600;

$claimed = [];
$matches = [];
$unmatched = [];

foreach ($results as $row) {
    $uid  = (int)$row['user'];
    $rid  = (int)$row['id'];
    $type = $row['type'];

    $postedAt = strtotime($row['date']);
    $userTz = $serverTz;
    if (!empty($timezones[$uid])) {
        try { $userTz = new DateTimeZone($timezones[$uid]); } catch (Exception $e) { $userTz = $serverTz; }
    }

    $best = null;
    $bestTime = null;
    foreach ($finished[$uid] ?? [] as $cand) {
        if ($cand['type'] !== $type) continue;
        // Everyone plays the same quizzes, so a claim only rules that quiz out for this user
        if (isset($claimed[$uid . '|' . $cand['quiz_id']])) continue;

        // answered_at read as server time, expressed in the user's timezone
        $dt = new DateTime($cand['finished_at'], $serverTz);
        $dt->setTimezone($userTz);
        $candTime = strtotime($dt->format('Y-m-d H:i:s'));

        if ($candTime > $postedAt + CLOCK_SLACK_SECONDS) continue;
        if ($candTime < $postedAt - MAX_POST_DELAY_SECONDS) continue;
        if ($bestTime === null || $candTime > $bestTime) {
            $bestTime = $candTime;
            $best = $cand;
        }
    }

    if ($best === null) {
        $unmatched[] = ['result_id' => $rid, 'user' => $uid, 'type' => $type, 'posted' => $row['date']];
        continue;
    }

    $claimed[$uid . '|' . $best['quiz_id']] = true;
    $matches[] = [
        'result_id'   => $rid,
        'user'        => $uid,
        'type'        => $type,
        'score'       => (int)$row['score'],
        'posted'      => $row['date'],
        'quiz_id'     => $best['quiz_id'],
        'finished_at' => $best['finished_at'],
        'answers'     => $best['answers'],
    ];
}

if (!$dryRun) {
    $stmt = $conn->prepare("UPDATE Results SET ai_quiz_id = ? WHERE id = ? AND ai_quiz_id IS NULL");
    $updated = 0;
    foreach ($matches as $m) {
        $stmt->bind_param('ii', $m['quiz_id'], $m['result_id']);
        $stmt->execute();
        $updated += $stmt->affected_rows > 0 ? 1 : 0;
    }
    $stmt->close();
    $log[] = "Linked $updated result(s) to their quiz.";
}

echo json_encode([
    'dry_run'         => $dryRun,
    'log'             => $log,
    'matched_count'   => count($matches),
    'unmatched_count' => count($unmatched),
    'matches'         => $matches,
    'unmatched'       => $unmatched,
], JSON_PRETTY_PRINT);
