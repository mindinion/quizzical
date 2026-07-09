<?php
/**
 * qa-generate-quiz.php
 *
 * CLI-only QA harness for tuning quiz-generator.php. Same pipeline as
 * action-test-generate-quiz.php and generate-quiz.php, but does not write to
 * AIQuiz / AIQuestion / AIOption. Intended for optimization passes on the
 * server (where dblogin.php has DB + API keys).
 *
 * Usage:
 *   php qa-generate-quiz.php morning
 *   php qa-generate-quiz.php morning --runs=3
 *   php qa-generate-quiz.php afternoon --runs=3 --save=logs/qa
 *
 * Options:
 *   --runs=N       Generate N quizzes in sequence (default 1)
 *   --save=DIR     Write each run as JSON under DIR (recommended for agent review)
 *   --lookback=N   Days of recent questions for duplicate avoidance (default 3)
 *   --no-images    Skip Pexels preview fetch (faster; still validates question logic)
 *   --json         Print combined JSON to stdout when finished (logs go to stderr)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/dblogin.php';
require_once __DIR__ . '/quiz-generator.php';

// --- Parse args ---
$type = 'morning';
$runs = 1;
$saveDir = null;
$lookback = 3;
$skipImages = false;
$jsonStdout = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === 'morning' || $arg === 'afternoon') {
        $type = $arg;
    } elseif (preg_match('/^--runs=(\d+)$/', $arg, $m)) {
        $runs = max(1, (int)$m[1]);
    } elseif (preg_match('/^--save=(.+)$/', $arg, $m)) {
        $saveDir = $m[1];
    } elseif (preg_match('/^--lookback=(\d+)$/', $arg, $m)) {
        $lookback = max(0, (int)$m[1]);
    } elseif ($arg === '--no-images') {
        $skipImages = true;
    } elseif ($arg === '--json') {
        $jsonStdout = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, <<<HELP
Usage: php qa-generate-quiz.php morning|afternoon [options]

Options:
  --runs=N       Generate N quizzes in sequence (default 1)
  --save=DIR     Write each run as JSON under DIR
  --lookback=N   Recent-question lookback days (default 3)
  --no-images    Skip Pexels preview images
  --json         Print combined JSON to stdout at end

Examples:
  php qa-generate-quiz.php morning --runs=3 --save=logs/qa
  php qa-generate-quiz.php afternoon --json 2>progress.log

HELP);
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: $arg (try --help)\n");
        exit(1);
    }
}

if (!in_array($type, ['morning', 'afternoon'], true)) {
    fwrite(STDERR, "Usage: php qa-generate-quiz.php morning|afternoon [--runs=N] [--save=DIR]\n");
    exit(1);
}

@set_time_limit(0);

$nztz  = new DateTimeZone('Pacific/Auckland');
$today = (new DateTime('now', $nztz))->format('Y-m-d');
$stamp = (new DateTime('now', $nztz))->format('Ymd-His');

if ($saveDir !== null) {
    if (!is_dir($saveDir) && !mkdir($saveDir, 0755, true) && !is_dir($saveDir)) {
        fwrite(STDERR, "Could not create save directory: $saveDir\n");
        exit(1);
    }
    $saveDir = rtrim($saveDir, '/\\');
}

/**
 * @return array{ok: bool, run: int, type: string, date: string, questions?: array, log: array, stats: array, error?: string, saved_to?: string}
 */
function qaRunOnce(int $runNum, string $type, string $today, int $lookback, bool $skipImages, mysqli $conn): array {
    resetQuizGenStats();
    startQuizGenLogCapture();

    $recentQuestions = $lookback > 0
        ? fetchRecentQuestions($conn, $today, $lookback)
        : [];

    $payload = [
        'ok'   => false,
        'run'  => $runNum,
        'type' => $type,
        'date' => $today,
        'log'  => [],
        'stats'=> getQuizGenStats(),
    ];

    try {
        $questions = generateQuizQuestions($type, $today, $recentQuestions);
        if (!$skipImages) {
            $questions = attachPreviewImages($questions);
        }
        $payload['ok']        = true;
        $payload['questions'] = $questions;
        $payload['stats']     = getQuizGenStats();
    } catch (RuntimeException $e) {
        $payload['error'] = $e->getMessage();
        $payload['stats'] = getQuizGenStats();
    } catch (Throwable $e) {
        $payload['error'] = 'Unexpected failure: ' . $e->getMessage();
        $payload['stats'] = getQuizGenStats();
    }

    $payload['log'] = stopQuizGenLogCapture();
    return $payload;
}

$allRuns = [];

for ($i = 1; $i <= $runs; $i++) {
    if ($jsonStdout) {
        fwrite(STDERR, "=== QA run $i/$runs ($type, $today) ===\n");
    } else {
        fwrite(STDOUT, "=== QA run $i/$runs ($type, $today) ===\n");
    }

    $result = qaRunOnce($i, $type, $today, $lookback, $skipImages, $conn);
    $allRuns[] = $result;

    if ($saveDir !== null) {
        $file = $saveDir . DIRECTORY_SEPARATOR . "{$stamp}-run{$i}.json";
        file_put_contents($file, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $result['saved_to'] = $file;
        $allRuns[$i - 1] = $result;
        $target = $jsonStdout ? STDERR : STDOUT;
        fwrite($target, ($result['ok'] ? 'OK' : 'FAIL') . " — saved to $file\n");
    } elseif (!$jsonStdout) {
        $qCount = isset($result['questions']) ? count($result['questions']) : 0;
        fwrite(STDOUT, ($result['ok'] ? 'OK' : 'FAIL') . " — $qCount questions");
        if (!$result['ok']) {
            fwrite(STDOUT, ' — ' . ($result['error'] ?? 'unknown error'));
        }
        fwrite(STDOUT, "\n");
    }
}

$summary = [
    'meta' => [
        'type'        => $type,
        'date'        => $today,
        'runs'        => $runs,
        'lookback'    => $lookback,
        'skip_images' => $skipImages,
        'completed'   => count(array_filter($allRuns, fn($r) => $r['ok'])),
        'failed'      => count(array_filter($allRuns, fn($r) => !$r['ok'])),
    ],
    'runs' => $allRuns,
];

if ($saveDir !== null) {
    $summaryFile = $saveDir . DIRECTORY_SEPARATOR . "{$stamp}-summary.json";
    file_put_contents($summaryFile, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $target = $jsonStdout ? STDERR : STDOUT;
    fwrite($target, "Summary: $summaryFile\n");
}

if ($jsonStdout) {
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

$conn->close();
exit($summary['meta']['failed'] > 0 ? 1 : 0);
