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
 *   --lookback=N   Days of recent questions for duplicate avoidance (default 5)
 *   --no-images    Skip Pexels preview fetch (faster; still validates question logic)
 *   --json         Print combined JSON to stdout when finished (logs go to stderr)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/dblogin.php';
require_once __DIR__ . '/qa-run-lib.php';

$type = 'morning';
$runs = 1;
$saveDir = null;
$lookback = 5;
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
  --lookback=N   Recent-question lookback days (default 5)
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

try {
    $summary = qaExecutePass($conn, $type, $runs, $saveDir, $lookback, $skipImages, true);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

foreach ($summary['runs'] as $result) {
    $target = $jsonStdout ? STDERR : STDOUT;
    if (!empty($result['saved_to'])) {
        fwrite($target, ($result['ok'] ? 'OK' : 'FAIL') . " — saved to {$result['saved_to']}\n");
    } elseif (!$jsonStdout) {
        $qCount = isset($result['questions']) ? count($result['questions']) : 0;
        fwrite(STDOUT, ($result['ok'] ? 'OK' : 'FAIL') . " — $qCount questions\n");
    }
}

if (!empty($summary['summary_file'])) {
    $target = $jsonStdout ? STDERR : STDOUT;
    fwrite($target, "Summary: {$summary['summary_file']}\n");
}

if ($jsonStdout) {
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

$conn->close();
exit($summary['meta']['failed'] > 0 ? 1 : 0);
