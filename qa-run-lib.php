<?php
/**
 * qa-run-lib.php
 *
 * Shared QA pass logic for qa-generate-quiz.php (CLI) and action-qa-generate-quiz.php (HTTP).
 */

require_once __DIR__ . '/quiz-generator.php';

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
        $questions = generateQuizQuestionsWithFinalGate($type, $today, $recentQuestions);
        if (!$skipImages) {
            $questions = attachPreviewImages($questions);
        }
        $payload['questions'] = $questions;
        $payload['stats']     = getQuizGenStats();
        $payload['ok']          = count($questions) > 0;
        $payload['warnings']    = buildQuizGenWarnings(getQuizGenStats());
    } catch (Throwable $e) {
        logQuizGenError('QA run primary path failed: ' . $e->getMessage());
        try {
            $questions = generateQuizQuestions($type, $today, $recentQuestions);
            if (!$skipImages) {
                $questions = attachPreviewImages($questions);
            }
            $payload['questions'] = $questions;
            $payload['stats']     = getQuizGenStats();
            $payload['ok']          = count($questions) > 0;
            $payload['warnings']    = array_merge(
                ['Generation recovered via fallback: ' . $e->getMessage()],
                buildQuizGenWarnings(getQuizGenStats())
            );
        } catch (Throwable $inner) {
            $payload['error'] = 'Unexpected failure: ' . $inner->getMessage();
            $payload['stats'] = getQuizGenStats();
            $payload['ok']    = false;
        }
    }

    $payload['log'] = stopQuizGenLogCapture();
    return $payload;
}

/**
 * @return array{meta: array, runs: array, summary_file?: string}
 */
function qaExecutePass(
    mysqli $conn,
    string $type,
    int $runs = 1,
    ?string $saveDir = 'logs/qa',
    int $lookback = 5,
    bool $skipImages = false,
    bool $cliProgress = false
): array {
    @set_time_limit(0);

    $nztz  = new DateTimeZone('Pacific/Auckland');
    $today = (new DateTime('now', $nztz))->format('Y-m-d');
    $stamp = (new DateTime('now', $nztz))->format('Ymd-His');

    if ($saveDir !== null) {
        if (!is_dir($saveDir) && !mkdir($saveDir, 0755, true) && !is_dir($saveDir)) {
            throw new RuntimeException("Could not create save directory: $saveDir");
        }
        $saveDir = rtrim($saveDir, '/\\');
    }

    $allRuns = [];
    for ($i = 1; $i <= $runs; $i++) {
        if ($cliProgress && php_sapi_name() === 'cli') {
            fwrite(STDOUT, "=== QA run $i/$runs ($type, $today) ===\n");
        }
        $result = qaRunOnce($i, $type, $today, $lookback, $skipImages, $conn);
        if ($saveDir !== null) {
            $file = $saveDir . DIRECTORY_SEPARATOR . "{$stamp}-run{$i}.json";
            file_put_contents($file, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $result['saved_to'] = $file;
        }
        $allRuns[] = $result;
    }

    $summary = [
        'meta' => [
            'type'        => $type,
            'date'        => $today,
            'stamp'       => $stamp,
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
        $summary['summary_file'] = $summaryFile;
    }

    return $summary;
}

/**
 * Human-readable warnings when best-effort fallbacks were used (quiz still delivered).
 *
 * @param array<string, int> $stats
 * @return string[]
 */
function buildQuizGenWarnings(array $stats): array {
    $warnings = [];
    if (($stats['category_cap_fallbacks'] ?? 0) > 0) {
        $warnings[] = 'One or more categories accepted the last attempt after validation retries were exhausted.';
    }
    if (($stats['category_emergency_fallbacks'] ?? 0) > 0) {
        $warnings[] = 'One or more categories used emergency placeholder questions (API returned no usable output).';
    }
    if (($stats['final_gate_cap_fallbacks'] ?? 0) > 0) {
        $warnings[] = 'Final publish gate accepted the last quiz without a clean pass.';
    }
    return $warnings;
}

function qaTokenIsValid(?string $token): bool {
    return defined('QA_RUN_TOKEN')
        && QA_RUN_TOKEN !== ''
        && is_string($token)
        && hash_equals(QA_RUN_TOKEN, $token);
}
