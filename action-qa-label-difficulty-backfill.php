<?php
/**
 * action-qa-label-difficulty-backfill.php
 *
 * Write backfill: AI-label unlabeled QuizQuestionBank rows and UPDATE difficulty.
 * Auth: QA_RUN_TOKEN. Processes one or more batches per request.
 *
 * POST JSON:
 *   token     — QA_RUN_TOKEN
 *   limit     — questions per API batch (default 25, max 40)
 *   batches   — how many batches this request (default 5, max 15)
 *   category  — optional Geography|History|General Knowledge
 */

require_once __DIR__ . '/dblogin.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/qa-run-lib.php';
require_once __DIR__ . '/question-bank.php';
require_once __DIR__ . '/bank-difficulty-label.php';

header('Content-Type: application/json');

@ignore_user_abort(true);
@set_time_limit(600);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}

$token = $body['token'] ?? ($_SERVER['HTTP_X_QA_TOKEN'] ?? '');
if (!qaTokenIsValid(is_string($token) ? $token : null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden — set QA_RUN_TOKEN in dblogin.php']);
    exit;
}

if (!bankTableExists($conn)) {
    http_response_code(500);
    echo json_encode(['error' => 'QuizQuestionBank table missing']);
    exit;
}

$limit = isset($body['limit']) ? (int)$body['limit'] : 25;
$limit = max(1, min(40, $limit));

$batches = isset($body['batches']) ? (int)$body['batches'] : 5;
$batches = max(1, min(15, $batches));

$category = null;
if (isset($body['category']) && is_string($body['category']) && trim($body['category']) !== '') {
    $cat = trim($body['category']);
    if (in_array($cat, ['Geography', 'History', 'General Knowledge'], true)) {
        $category = $cat;
    }
}

$updatedTotal = 0;
$promptTokens = 0;
$completionTokens = 0;
$sampleIds = [];
$batchLogs = [];
$error = null;

try {
    for ($b = 1; $b <= $batches; $b++) {
        $sample = bankDifficultyFetchUnlabeledBatch($conn, $limit, $category);
        if (!$sample) {
            $batchLogs[] = ['batch' => $b, 'fetched' => 0, 'updated' => 0, 'note' => 'no unlabeled rows'];
            break;
        }

        $apiItems = [];
        foreach ($sample as $q) {
            $apiItems[] = [
                'bank_id'  => $q['bank_id'],
                'question' => $q['question'],
                'options'  => $q['options'],
                'correct'  => $q['correct'],
            ];
        }

        $labeled = bankDifficultyLabelBatch($apiItems);
        $promptTokens += (int)$labeled['usage']['prompt_tokens'];
        $completionTokens += (int)$labeled['usage']['completion_tokens'];

        if ($labeled['raw_error']) {
            $error = $labeled['raw_error'];
            $batchLogs[] = ['batch' => $b, 'fetched' => count($sample), 'updated' => 0, 'error' => $error];
            break;
        }

        $updated = bankDifficultyPersistRatings($conn, $labeled['ratings']);
        $updatedTotal += $updated;
        foreach (array_keys($labeled['ratings']) as $id) {
            if (count($sampleIds) < 10) {
                $sampleIds[] = (int)$id;
            }
        }
        $batchLogs[] = [
            'batch'   => $b,
            'fetched' => count($sample),
            'rated'   => count($labeled['ratings']),
            'updated' => $updated,
        ];
    }

    $remaining = bankDifficultyCountUnlabeled($conn, $category);
    $conn->close();

    $out = [
        'ok'                 => $error === null,
        'model'              => BANK_DIFFICULTY_LABEL_MODEL,
        'prompt_version'     => BANK_DIFFICULTY_PROMPT_VERSION,
        'updated'            => $updatedTotal,
        'remaining_unlabeled'=> $remaining,
        'category'           => $category,
        'limit'              => $limit,
        'batches_requested'  => $batches,
        'usage'              => [
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
        ],
        'sample_ids'         => $sampleIds,
        'batch_logs'         => $batchLogs,
    ];
    if ($error !== null) {
        $out['error'] = $error;
        http_response_code(502);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    $conn->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
