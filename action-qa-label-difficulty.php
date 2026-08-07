<?php
/**
 * action-qa-label-difficulty.php
 *
 * Dry-run AI difficulty labeling for bank questions.
 * Auth: QA_RUN_TOKEN. Reads bank rows and calls OpenAI; does NOT write difficulty.
 *
 * POST JSON:
 *   token     — must match QA_RUN_TOKEN
 *   limit     — 1–30 (default 20)
 *   category  — optional Geography|History|General Knowledge
 *   source    — opentriviaqa (default, unlabeled) | opentdb (labeled, for calibration)
 *   seed      — optional int for reproducible sample ordering
 */

require_once __DIR__ . '/dblogin.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/qa-run-lib.php';
require_once __DIR__ . '/question-bank.php';
require_once __DIR__ . '/bank-difficulty-label.php';

header('Content-Type: application/json');

@ignore_user_abort(true);
@set_time_limit(120);

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

$limit = isset($body['limit']) ? (int)$body['limit'] : 20;
$limit = max(1, min(30, $limit));

$source = isset($body['source']) ? strtolower(trim((string)$body['source'])) : 'opentriviaqa';
if (!in_array($source, ['opentriviaqa', 'opentdb'], true)) {
    $source = 'opentriviaqa';
}

$category = null;
if (isset($body['category']) && is_string($body['category']) && trim($body['category']) !== '') {
    $category = trim($body['category']);
}

$seed = null;
if (isset($body['seed']) && $body['seed'] !== '' && $body['seed'] !== null) {
    $seed = (int)$body['seed'];
}

try {
    $sample = bankDifficultySampleQuestions($conn, $source, $limit, $category, $seed);
    if (!$sample) {
        $conn->close();
        echo json_encode([
            'ok'             => true,
            'model'          => BANK_DIFFICULTY_LABEL_MODEL,
            'prompt_version' => BANK_DIFFICULTY_PROMPT_VERSION,
            'count'          => 0,
            'source'         => $source,
            'category'       => $category,
            'seed'           => $seed,
            'usage'          => ['prompt_tokens' => 0, 'completion_tokens' => 0],
            'items'          => [],
            'note'           => 'No matching bank questions found for this filter',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
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
    if ($labeled['raw_error']) {
        $conn->close();
        http_response_code(502);
        echo json_encode([
            'ok'             => false,
            'error'          => $labeled['raw_error'],
            'model'          => BANK_DIFFICULTY_LABEL_MODEL,
            'prompt_version' => BANK_DIFFICULTY_PROMPT_VERSION,
            'usage'          => $labeled['usage'],
            'sampled'        => count($sample),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $items = [];
    $agree = 0;
    $comparable = 0;
    foreach ($sample as $q) {
        $id = $q['bank_id'];
        $rating = $labeled['ratings'][$id] ?? null;
        $rated = $rating['difficulty'] ?? null;
        $reason = $rating['reason'] ?? null;
        $existing = $q['existing_difficulty'];

        if ($existing !== null && $rated !== null) {
            $comparable++;
            if (strcasecmp($existing, $rated) === 0) {
                $agree++;
            }
        }

        $items[] = [
            'bank_id'             => $id,
            'source'              => $q['source'],
            'category'            => $q['category'],
            'question'            => $q['question'],
            'options'             => $q['options'],
            'correct'             => $q['correct'],
            'existing_difficulty' => $existing,
            'rated_difficulty'    => $rated,
            'reason'              => $reason,
        ];
    }

    $out = [
        'ok'             => true,
        'model'          => BANK_DIFFICULTY_LABEL_MODEL,
        'prompt_version' => BANK_DIFFICULTY_PROMPT_VERSION,
        'count'          => count($items),
        'source'         => $source,
        'category'       => $category,
        'seed'           => $seed,
        'usage'          => $labeled['usage'],
        'items'          => $items,
    ];
    if ($comparable > 0) {
        $out['calibration'] = [
            'comparable' => $comparable,
            'agree'      => $agree,
            'agree_rate' => round($agree / $comparable, 3),
        ];
    }

    $conn->close();
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    $conn->close();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
