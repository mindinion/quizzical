<?php
/**
 * action-test-generate-quiz.php
 *
 * Ad hoc admin preview: generates a quiz via OpenAI using the same logic as the
 * cron job, but returns it as JSON without writing to AIQuiz/AIQuestion/AIOption.
 * Superuser-only — each call costs real OpenAI API usage.
 */

require_once 'getsettings.php'; // validates session, sets $userid and $superuser

header('Content-Type: application/json');

if (!$superuser) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/quiz-generator.php';

$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'morning';
if ($type !== 'morning' && $type !== 'afternoon') {
    $type = 'morning';
}

$nztz  = new DateTimeZone('Pacific/Auckland');
$today = (new DateTime('now', $nztz))->format('Y-m-d');

// Read-only — reuses the real quiz history so the preview reflects the same
// duplicate-avoidance behaviour the cron job gets, without writing anything.
$recentQuestions = fetchRecentQuestions($conn, $today, 3);

startQuizGenLogCapture();
try {
    $questions = generateQuizQuestions($type, $today, $recentQuestions);
    $questions = attachPreviewImages($questions);
    $log = stopQuizGenLogCapture();
    echo json_encode([
        'questions' => $questions,
        'log'       => $log,
        'stats'     => getQuizGenStats(),
    ]);
} catch (RuntimeException $e) {
    $log = stopQuizGenLogCapture();
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'log'   => $log,
        'stats' => getQuizGenStats(),
    ]);
}
