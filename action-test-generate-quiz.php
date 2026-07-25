<?php
/**
 * action-test-generate-quiz.php
 *
 * Ad hoc admin preview: generates a quiz via OpenAI using the same logic as the
 * cron job, but returns it as JSON without writing to AIQuiz/AIQuestion/AIOption.
 * Superuser-only — each call costs real OpenAI API usage.
 *
 * ?stream=1 — Server-Sent Events: log lines appear live during generation.
 */

require_once 'getsettings.php'; // validates session, sets $userid and $superuser

if (!$superuser) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

require_once __DIR__ . '/quiz-generator.php';

$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'morning';
if ($type !== 'morning' && $type !== 'afternoon') {
    $type = 'morning';
}

$stream = isset($_GET['stream']) && $_GET['stream'] === '1';
$skipImages = isset($_GET['no_images']) && $_GET['no_images'] === '1';

$nztz  = new DateTimeZone('Pacific/Auckland');
$today = (new DateTime('now', $nztz))->format('Y-m-d');

$recentQuestions = fetchRecentQuestions($conn, $today, 5);
$recentTopicLabels = fetchRecentTopicLabels($conn, $today);

function emitTestQuizSse(array $payload): void {
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

if ($stream) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    @set_time_limit(300);

    enableQuizGenLogStream(function (array $payload): void {
        emitTestQuizSse($payload);
    });
    emitTestQuizSse(['type' => 'status', 'message' => 'Starting quiz generation…']);

    try {
        $questions = generateQuizQuestions($type, $today, $recentQuestions, $recentTopicLabels, $conn);
        if (!$skipImages) {
            emitTestQuizSse(['type' => 'status', 'message' => 'Fetching preview images…']);
            $questions = attachPreviewImages($questions);
        }
        $log = stopQuizGenLogCapture();
        emitTestQuizSse([
            'type'      => 'done',
            'questions' => $questions,
            'log'       => $log,
            'stats'     => getQuizGenStats(),
        ]);
    } catch (RuntimeException $e) {
        $log = stopQuizGenLogCapture();
        emitTestQuizSse([
            'type'  => 'error',
            'error' => $e->getMessage(),
            'log'   => $log,
            'stats' => getQuizGenStats(),
        ]);
    } catch (Throwable $e) {
        $log = stopQuizGenLogCapture();
        emitTestQuizSse([
            'type'  => 'error',
            'error' => 'Image fetch failed: ' . $e->getMessage(),
            'log'   => $log,
            'stats' => getQuizGenStats(),
        ]);
    }
    exit;
}

header('Content-Type: application/json');

startQuizGenLogCapture();
try {
    $questions = generateQuizQuestions($type, $today, $recentQuestions, $recentTopicLabels, $conn);
    if (!$skipImages) {
        $questions = attachPreviewImages($questions);
    }
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
