<?php
/**
 * bank-difficulty-label.php
 *
 * Shared helpers for AI difficulty rating of bank questions.
 * Dry-run and future write jobs both use this — callers decide whether to persist.
 */

const BANK_DIFFICULTY_LABEL_MODEL = 'gpt-4o-mini';
const BANK_DIFFICULTY_PROMPT_VERSION = 'v1';

/**
 * System rubric for OpenTDB-style casual adult quiz difficulty.
 */
function bankDifficultyLabelSystemPrompt(): string {
    return <<<'PROMPT'
You rate multiple-choice trivia questions for a casual adult daily quiz (mixed global knowledge; New Zealand audience, but do not over-penalise US/UK-centric topics — rate absolute familiarity for a typical adult quiz player).

Assign exactly one of: easy, medium, hard.

- easy — most casual players would know, or confidently pick from the options (well-known facts, famous places/people/works, distinctive correct answer).
- medium — needs some general knowledge; not obscure, but not a gimme.
- hard — specialist knowledge, obscure detail, close distractors, or easy to miss even for keen players.

Base the rating on the question AND the options together (close wrong answers can make an otherwise easy fact hard).
Do not invent facts; only judge difficulty.
PROMPT;
}

/**
 * @param list<array{bank_id:int,question:string,options:list<string>,correct:string}> $items
 * @return array{ratings: array<int, array{difficulty:string,reason:string}>, usage: array{prompt_tokens:int,completion_tokens:int}, raw_error:?string}
 */
function bankDifficultyLabelBatch(array $items): array {
    $emptyUsage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
    if (!$items) {
        return ['ratings' => [], 'usage' => $emptyUsage, 'raw_error' => null];
    }
    if (!defined('OPENAI_API_KEY') || OPENAI_API_KEY === '') {
        return ['ratings' => [], 'usage' => $emptyUsage, 'raw_error' => 'OPENAI_API_KEY not configured'];
    }

    $lines = [];
    foreach ($items as $item) {
        $id = (int)$item['bank_id'];
        $opts = [];
        foreach ($item['options'] as $i => $text) {
            $opts[] = '(' . ($i + 1) . ') ' . $text;
        }
        $lines[] = "ID $id\nQ: {$item['question']}\nOptions: " . implode(' | ', $opts)
            . "\nCorrect: {$item['correct']}";
    }
    $userPrompt = "Rate each question. Return one rating per ID.\n\n" . implode("\n\n", $lines);

    $schema = [
        'type' => 'json_schema',
        'json_schema' => [
            'name'   => 'difficulty_ratings',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'ratings' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'bank_id'    => ['type' => 'integer'],
                                'difficulty' => ['type' => 'string', 'enum' => ['easy', 'medium', 'hard']],
                                'reason'     => ['type' => 'string'],
                            ],
                            'required'             => ['bank_id', 'difficulty', 'reason'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required'             => ['ratings'],
                'additionalProperties' => false,
            ],
        ],
    ];

    $payload = json_encode([
        'model'           => BANK_DIFFICULTY_LABEL_MODEL,
        'messages'        => [
            ['role' => 'system', 'content' => bankDifficultyLabelSystemPrompt()],
            ['role' => 'user',   'content' => $userPrompt],
        ],
        'response_format' => $schema,
        'temperature'     => 0,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ratings' => [], 'usage' => $emptyUsage, 'raw_error' => "cURL: $curlError"];
    }
    if (!$response) {
        return ['ratings' => [], 'usage' => $emptyUsage, 'raw_error' => 'Empty OpenAI response'];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ratings' => [], 'usage' => $emptyUsage, 'raw_error' => 'Invalid OpenAI JSON'];
    }

    $usage = [
        'prompt_tokens'     => (int)($decoded['usage']['prompt_tokens'] ?? 0),
        'completion_tokens' => (int)($decoded['usage']['completion_tokens'] ?? 0),
    ];

    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (!$content) {
        $err = $decoded['error']['message'] ?? 'Missing message content';
        return ['ratings' => [], 'usage' => $usage, 'raw_error' => $err];
    }

    $parsed = json_decode($content, true);
    if (!is_array($parsed) || !isset($parsed['ratings']) || !is_array($parsed['ratings'])) {
        return ['ratings' => [], 'usage' => $usage, 'raw_error' => 'Could not parse ratings payload'];
    }

    $byId = [];
    foreach ($parsed['ratings'] as $row) {
        if (!is_array($row) || !isset($row['bank_id'], $row['difficulty'])) {
            continue;
        }
        $id = (int)$row['bank_id'];
        $diff = strtolower(trim((string)$row['difficulty']));
        if (!in_array($diff, ['easy', 'medium', 'hard'], true)) {
            continue;
        }
        $byId[$id] = [
            'difficulty' => $diff,
            'reason'     => isset($row['reason']) ? trim((string)$row['reason']) : '',
        ];
    }

    return ['ratings' => $byId, 'usage' => $usage, 'raw_error' => null];
}

/**
 * Sample bank questions for dry-run labeling.
 *
 * - opentriviaqa: unlabeled only (difficulty IS NULL)
 * - opentdb: prefer labeled rows so we can compare model vs stored difficulty
 *
 * @return list<array{bank_id:int,source:string,category:string,question:string,options:list<string>,correct:string,existing_difficulty:?string}>
 */
function bankDifficultySampleQuestions(
    mysqli $conn,
    string $source,
    int $limit,
    ?string $category = null,
    ?int $seed = null
): array {
    $limit = max(1, min(30, $limit));
    $source = strtolower(trim($source));
    if (!in_array($source, ['opentriviaqa', 'opentdb'], true)) {
        $source = 'opentriviaqa';
    }

    $allowedCats = ['Geography', 'History', 'General Knowledge'];
    if ($category !== null && $category !== '' && !in_array($category, $allowedCats, true)) {
        $category = null;
    }

    $idSql = 'SELECT b.id FROM QuizQuestionBank b WHERE b.source = ? AND b.format = \'mc\'';
    $types = 's';
    $params = [$source];

    if ($source === 'opentriviaqa') {
        $idSql .= ' AND (b.difficulty IS NULL OR b.difficulty = \'\')';
    } else {
        $idSql .= ' AND b.difficulty IS NOT NULL AND b.difficulty <> \'\'';
    }

    if ($category) {
        $idSql .= ' AND b.category = ?';
        $types .= 's';
        $params[] = $category;
    }

    $poolLimit = max($limit * 4, 40);
    if ($seed !== null) {
        $idSql .= ' ORDER BY CRC32(CONCAT(b.id, ?)) ASC, b.id ASC LIMIT ?';
        $types .= 'si';
        $params[] = (string)$seed;
        $params[] = $poolLimit;
    } else {
        $idSql .= ' ORDER BY RAND() LIMIT ?';
        $types .= 'i';
        $params[] = $poolLimit;
    }

    $stmt = $conn->prepare($idSql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $idRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $ids = array_map(static fn(array $r): int => (int)$r['id'], $idRows);
    $ids = array_slice($ids, 0, $limit);
    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT b.id, b.source, b.category, b.question_text, b.difficulty,
                   o.position, o.option_text, o.is_correct
            FROM QuizQuestionBank b
            INNER JOIN QuizQuestionBankOption o ON o.bank_id = b.id
            WHERE b.id IN ($placeholders)
            ORDER BY b.id ASC, o.position ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $idTypes = str_repeat('i', count($ids));
    $stmt->bind_param($idTypes, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();

    $grouped = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['id'];
        if (!isset($grouped[$id])) {
            $grouped[$id] = [
                'bank_id'             => $id,
                'source'              => (string)$row['source'],
                'category'            => (string)$row['category'],
                'question'            => (string)$row['question_text'],
                'options'             => [],
                'correct'             => '',
                'existing_difficulty' => $row['difficulty'] !== null && $row['difficulty'] !== ''
                    ? (string)$row['difficulty']
                    : null,
            ];
        }
        $text = (string)$row['option_text'];
        $grouped[$id]['options'][] = $text;
        if ((int)$row['is_correct'] === 1) {
            $grouped[$id]['correct'] = $text;
        }
    }
    $stmt->close();

    // Preserve sample order from the ID query.
    $list = [];
    foreach ($ids as $id) {
        if (!isset($grouped[$id])) {
            continue;
        }
        $q = $grouped[$id];
        if (count($q['options']) < 2 || $q['correct'] === '') {
            continue;
        }
        $list[] = $q;
    }

    return $list;
}
