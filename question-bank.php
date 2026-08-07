<?php
/**
 * question-bank.php
 *
 * Local verified trivia pool — seeded from OpenTDB and OpenTriviaQA.
 * Quizzes draw from here for Geography, History, and General Knowledge.
 */

const BANK_CATEGORY_TARGETS = [
    'Geography'         => 3,
    'History'           => 3,
    'General Knowledge' => 5,
];

const CE_CATEGORY_TARGETS = [
    'NZ Current Events'     => 2,
    'Aussie Current Events' => 2,
];

/** Target difficulty mix among the 11 bank slots (sum must match bank slot total). */
const BANK_DIFFICULTY_TARGETS = [
    'easy'   => 5,
    'medium' => 4,
    'hard'   => 2,
];

/** Days before a bank question can be reused. */
const BANK_REUSE_COOLDOWN_DAYS = 90;

/** OpenTDB category ID => Quizzical category */
const OPENTDB_CATEGORY_MAP = [
    22 => 'Geography',
    23 => 'History',
    9  => 'General Knowledge',
    17 => 'General Knowledge',
    18 => 'General Knowledge',
    19 => 'General Knowledge',
    20 => 'General Knowledge',
    27 => 'General Knowledge',
];

/** OpenTriviaQA filename (no ext) => Quizzical category */
const OPENTRIVIAQA_CATEGORY_MAP = [
    'geography'       => 'Geography',
    'history'         => 'History',
    'general'         => 'General Knowledge',
    'science'         => 'General Knowledge',
    'science-technology' => 'General Knowledge',
    'sports'          => 'General Knowledge',
    'art'             => 'General Knowledge',
    'literature'      => 'General Knowledge',
    'music'           => 'General Knowledge',
    'movies'          => 'General Knowledge',
    'television'      => 'General Knowledge',
    'food'            => 'General Knowledge',
    'people'          => 'General Knowledge',
    'words'           => 'General Knowledge',
    'world'           => 'General Knowledge',
];

const BANK_IMPORT_SKIP_PATTERNS = [
    '/\bcapital (city )?of\b/i',
    '/\bopera house\b/i',
];

/** Varied Pexels search terms per bank category — one static query always returned the same photo. */
const BANK_IMAGE_QUERY_POOLS = [
    'Geography' => [
        'mountain landscape', 'river valley aerial', 'coastal cliffs ocean', 'desert sand dunes',
        'tropical island beach', 'waterfall forest', 'rolling green hills', 'canyon rock formation',
        'lake mountain reflection', 'arctic snow landscape', 'volcanic terrain', 'savanna plains',
        'glacier ice field', 'coral reef underwater', 'world globe map',
    ],
    'History' => [
        'ancient stone ruins', 'medieval castle exterior', 'museum historical artifacts', 'archaeological excavation site',
        'classical marble columns', 'old world map parchment', 'historic monument plaza', 'vintage library books',
        'palace architecture', 'industrial revolution factory', 'war memorial statue', 'temple ancient architecture',
        'roman amphitheatre', 'historic ship sailing', 'cathedral stained glass',
    ],
    'General Knowledge' => [
        'library reading room', 'science laboratory equipment', 'art gallery paintings', 'orchestra musical instruments',
        'kitchen ingredients cooking', 'stadium crowd sports', 'starry night sky', 'classroom chalkboard',
        'farmers market produce', 'modern technology workspace', 'wildlife nature reserve', 'city skyline dusk',
        'microscope research', 'theatre stage lights', 'chess board strategy',
    ],
];

function bankImageQueryForQuestion(string $category, int $bankId): string {
    $pool = BANK_IMAGE_QUERY_POOLS[$category] ?? [
        'trivia quiz scene', 'learning education', 'books knowledge', 'curiosity discovery',
        'puzzle pieces table', 'question mark abstract', 'brain thinking concept', 'compass exploration',
    ];
    $idx = abs(crc32($bankId . '|' . $category)) % count($pool);
    return $pool[$idx];
}

function bankTableExists(mysqli $conn): bool {
    $r = $conn->query("SHOW TABLES LIKE 'QuizQuestionBank'");
    return $r && $r->num_rows > 0;
}

function bankSourceId(string $source, string $questionText): string {
    $norm = mb_strtolower(trim(preg_replace('/\s+/', ' ', $questionText)));
    return hash('sha256', $source . '|' . $norm);
}

function bankQuestionShouldSkip(string $text, ?array $options = null): bool {
    $text = bankNormalizeQuestionText($text);
    if ($text === '') {
        return true;
    }
    foreach (BANK_IMPORT_SKIP_PATTERNS as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    if (bankIsDubiousInventionCenturyQuestion($text)) {
        return true;
    }
    if (bankQuestionIsNonsenseText($text)) {
        return true;
    }
    if (bankQuestionReferencesMissingQuote($text)) {
        return true;
    }
    if ($options !== null && bankHasAllOfTheseAnswer($options)) {
        return true;
    }
    return false;
}

function bankHasAllOfTheseAnswer(array $options): bool {
    foreach ($options as $opt) {
        if (!is_array($opt)) {
            continue;
        }
        $text = $opt['text'] ?? $opt['option_text'] ?? '';
        $correct = !empty($opt['correct']) || !empty($opt['is_correct']);
        if ($correct && preg_match('/^all of (these|the above)\b/i', bankNormalizeOptionText((string)$text))) {
            return true;
        }
    }
    return false;
}

function bankIsDubiousInventionCenturyQuestion(string $text): bool {
    return (bool) preg_match(
        '/\b(invented|invention|were invented|was invented)\b/i',
        $text
    ) && (bool) preg_match('/\b\d+(st|nd|rd|th)\s+century\b/i', $text);
}

function bankDecodeHtml(string $text): string {
    // OpenTDB uses encode=url3986 (%20, %27, …); decode before HTML entities.
    $text = rawurldecode($text);
    return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function bankNormalizeQuestionText(string $text): string {
    return trim(preg_replace('/\s+/u', ' ', bankDecodeHtml($text)));
}

function bankNormalizeOptionText(string $text): string {
    return trim(preg_replace('/\s+/u', ' ', bankDecodeHtml($text)));
}

/** Corrupted payloads: random letter strings, leftover percent-encoding. */
function bankQuestionIsNonsenseText(string $text): bool {
    $trimmed = trim($text);
    if ($trimmed === '') {
        return true;
    }
    // e.g. "WETYIAFHKLXVNM" — single token of 8+ uppercase letters, no spaces.
    if (preg_match('/^[A-Z]{8,}$/', $trimmed)) {
        return true;
    }
    // Still contains percent-encoded bytes after decode (double-encoded or malformed).
    if (preg_match('/%[0-9A-Fa-f]{2}/', $trimmed)) {
        return true;
    }
    return false;
}

/** Quote-referencing questions that never include the quoted material. */
function bankQuestionReferencesMissingQuote(string $text): bool {
    if (preg_match('/["\'\x{201C}\x{201D}\x{2018}\x{2019}]/u', $text)) {
        return false;
    }
    if (preg_match('/\b(who said|who famously said|who once said)\b/i', $text)) {
        return true;
    }
    if (preg_match('/\b(complete|finish|fill in)\b.{0,24}\bquote\b/i', $text)) {
        return true;
    }
    if (preg_match('/\b(this|the|following)\s+quote\b/i', $text)
        && preg_match('/\b(who|identify|name|author|source)\b/i', $text)) {
        return true;
    }
    return false;
}

function bankOptionKey(string $text): string {
    return mb_strtolower(bankNormalizeOptionText($text));
}

/**
 * Build four MC options from an explicit answer list (OpenTriviaQA A–D lines).
 * The ^ line must match one of the choices.
 *
 * @param string[] $choiceTexts exactly four choice strings from A–D
 * @return list<array{text: string, correct: bool}>|null
 */
function bankBuildMcOptionsFromChoices(string $correctRaw, array $choiceTexts): ?array {
    $correct = bankNormalizeOptionText($correctRaw);
    if ($correct === '') {
        return null;
    }
    $correctKey = bankOptionKey($correct);

    $unique = [];
    $seen = [];
    foreach ($choiceTexts as $raw) {
        $text = bankNormalizeOptionText((string)$raw);
        if ($text === '') {
            continue;
        }
        $key = bankOptionKey($text);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $text;
    }

    if (count($unique) !== 4 || !isset($seen[$correctKey])) {
        return null;
    }

    shuffle($unique);
    $options = [];
    foreach ($unique as $text) {
        $options[] = [
            'text'    => $text,
            'correct' => bankOptionKey($text) === $correctKey,
        ];
    }

    return $options;
}

/**
 * Build four unique MC options. Filters duplicate distractors and drops the correct
 * answer if it also appears among wrong options (OpenTDB).
 *
 * @param string[] $distractorTexts
 * @return list<array{text: string, correct: bool}>|null
 */
function bankBuildMcOptions(string $correctRaw, array $distractorTexts): ?array {
    $correct = bankNormalizeOptionText($correctRaw);
    if ($correct === '') {
        return null;
    }
    $correctKey = bankOptionKey($correct);

    $distractors = [];
    $seen = [$correctKey => true];
    foreach ($distractorTexts as $raw) {
        $text = bankNormalizeOptionText((string)$raw);
        if ($text === '') {
            continue;
        }
        $key = bankOptionKey($text);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $distractors[] = $text;
    }

    if (count($distractors) < 3) {
        return null;
    }

    $all = array_merge([$correct], array_slice($distractors, 0, 3));
    shuffle($all);

    $options = [];
    foreach ($all as $text) {
        $options[] = [
            'text'    => $text,
            'correct' => bankOptionKey($text) === $correctKey,
        ];
    }

    return $options;
}

/**
 * Rebuild MC options loaded from DB, fixing duplicate texts from bad imports.
 *
 * @param list<array{option_text: string, is_correct: int|string}> $dbOptions
 * @return list<array{text: string, correct: bool}>|null
 */
function bankSanitizeStoredMcOptions(array $dbOptions): ?array {
    $correctRaw = null;
    foreach ($dbOptions as $o) {
        if ((bool)$o['is_correct']) {
            $correctRaw = $o['option_text'];
            break;
        }
    }
    if ($correctRaw === null) {
        return null;
    }

    $distractors = [];
    foreach ($dbOptions as $o) {
        if (bankOptionKey($o['option_text']) !== bankOptionKey($correctRaw)) {
            $distractors[] = $o['option_text'];
        }
    }

    return bankBuildMcOptions($correctRaw, $distractors);
}

/**
 * @return array{question: string, format: string, category: string, image_query: string, options: list<array{text: string, correct: bool}>, bank_id?: int, source?: string}|null
 */
function bankRowToQuizQuestion(array $row, array $options): ?array {
    $format = $row['format'] ?? 'mc';
    $opts = [];
    if ($format === 'mc') {
        $built = bankSanitizeStoredMcOptions($options);
        if ($built === null) {
            return null;
        }
        $opts = $built;
    } else {
        foreach ($options as $o) {
            $opts[] = [
                'text'    => bankNormalizeOptionText($o['option_text']),
                'correct' => (bool)$o['is_correct'],
            ];
        }
    }
    if ($format === 'mc' && count($opts) !== 4) {
        return null;
    }
    if ($format === 'tf' && count($opts) !== 2) {
        return null;
    }
    $questionText = bankNormalizeQuestionText($row['question_text']);
    if (bankQuestionShouldSkip($questionText, $opts)) {
        return null;
    }

    $cat = $row['category'];
    $bankId = (int)$row['id'];
    $imageQuery = bankImageQueryForQuestion($cat, $bankId);

    return [
        'question'    => $questionText,
        'format'      => $format,
        'category'    => $cat,
        'image_query' => $imageQuery,
        'options'     => $opts,
        'bank_id'     => $bankId,
        'source'      => $row['source'],
        'difficulty'  => $row['difficulty'] ?? null,
    ];
}

/**
 * Remaining difficulty quotas (easy/medium/hard counts still needed).
 *
 * @param array{easy?:int,medium?:int,hard?:int}|null $remaining
 * @return array{easy:int,medium:int,hard:int}
 */
function bankDifficultyRemainingInit(?array $remaining = null): array {
    $base = BANK_DIFFICULTY_TARGETS;
    if ($remaining === null) {
        return [
            'easy'   => (int)$base['easy'],
            'medium' => (int)$base['medium'],
            'hard'   => (int)$base['hard'],
        ];
    }
    return [
        'easy'   => max(0, (int)($remaining['easy'] ?? 0)),
        'medium' => max(0, (int)($remaining['medium'] ?? 0)),
        'hard'   => max(0, (int)($remaining['hard'] ?? 0)),
    ];
}

/**
 * Prefer the difficulty with the largest remaining quota; ties prefer easy → medium → hard.
 *
 * @param array{easy:int,medium:int,hard:int} $remaining
 */
function bankPickPreferredDifficulty(array $remaining): ?string {
    $best = null;
    $bestQty = -1;
    foreach (['easy', 'medium', 'hard'] as $diff) {
        $qty = (int)($remaining[$diff] ?? 0);
        if ($qty > $bestQty) {
            $bestQty = $qty;
            $best = $diff;
        }
    }
    return $bestQty > 0 ? $best : null;
}

/**
 * Fallback difficulty filters: preferred, then medium/easy/hard, then unlabeled, then any.
 *
 * @return list<array{diff:?string,any:bool}>
 */
function bankDifficultyFallbackSteps(?string $preferred): array {
    $steps = [];
    $seen = [];
    if ($preferred !== null) {
        $steps[] = ['diff' => $preferred, 'any' => false];
        $seen[$preferred] = true;
    }
    foreach (['medium', 'easy', 'hard'] as $diff) {
        if (!isset($seen[$diff])) {
            $steps[] = ['diff' => $diff, 'any' => false];
            $seen[$diff] = true;
        }
    }
    $steps[] = ['diff' => null, 'any' => false]; // unlabeled only
    $steps[] = ['diff' => null, 'any' => true];  // any remaining
    return $steps;
}

/**
 * @param int[] $excludeBankIds
 * @param array{easy:int,medium:int,hard:int}|null $difficultyRemaining mutated when questions are taken
 * @return list<array>
 */
function bankPickQuestionsFromCategory(
    mysqli $conn,
    string $category,
    int $need,
    array $excludeBankIds = [],
    ?array &$difficultyRemaining = null
): array {
    if ($need <= 0) {
        return [];
    }

    $remaining = bankDifficultyRemainingInit($difficultyRemaining);
    $cooldown = BANK_REUSE_COOLDOWN_DAYS;
    $selected = [];
    $bankIds = array_map('intval', $excludeBankIds);
    $triedIds = [];

    while (count($selected) < $need) {
        $preferred = bankPickPreferredDifficulty($remaining);
        $gotOne = false;

        foreach (bankDifficultyFallbackSteps($preferred) as $step) {
            $exclude = array_values(array_unique(array_merge($bankIds, $triedIds)));
            $rows = bankFetchCategoryCandidates(
                $conn,
                $category,
                $cooldown,
                40,
                $exclude,
                $step['diff'],
                $step['any']
            );
            if (!$rows) {
                continue;
            }

            foreach ($rows as $row) {
                $bid = (int)$row['id'];
                if (in_array($bid, $bankIds, true) || in_array($bid, $triedIds, true)) {
                    continue;
                }
                $triedIds[] = $bid;

                $optStmt = $conn->prepare(
                    'SELECT position, option_text, is_correct FROM QuizQuestionBankOption WHERE bank_id = ? ORDER BY position'
                );
                $optStmt->bind_param('i', $bid);
                $optStmt->execute();
                $opts = $optStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $optStmt->close();

                $q = bankRowToQuizQuestion($row, $opts);
                if ($q === null) {
                    continue;
                }

                $selected[] = $q;
                $bankIds[] = $bid;

                $actual = strtolower(trim((string)($q['difficulty'] ?? '')));
                if (isset($remaining[$actual]) && $remaining[$actual] > 0) {
                    $remaining[$actual]--;
                } elseif ($preferred !== null && ($remaining[$preferred] ?? 0) > 0) {
                    // Unlabeled / off-quota fill: consume the preferred quota.
                    $remaining[$preferred]--;
                }

                $gotOne = true;
                break 2;
            }
        }

        if (!$gotOne) {
            break;
        }
    }

    if ($difficultyRemaining !== null) {
        $difficultyRemaining = $remaining;
    }

    return $selected;
}

/**
 * @param int[] $excludeBankIds
 * @param array{easy:int,medium:int,hard:int}|null $difficultyRemaining
 * @return list<array>
 */
function selectGkFallbackQuestions(
    mysqli $conn,
    int $count,
    array $excludeBankIds = [],
    ?array &$difficultyRemaining = null
): array {
    return bankPickQuestionsFromCategory($conn, 'General Knowledge', $count, $excludeBankIds, $difficultyRemaining);
}

/**
 * @return list<array> Quiz question objects.
 */
function selectBankQuestionsForQuiz(mysqli $conn, ?array $targets = null): array {
    if (!bankTableExists($conn)) {
        throw new RuntimeException('QuizQuestionBank table missing — run question-bank-migration.sql and seed-question-bank.php');
    }

    $targets = $targets ?? BANK_CATEGORY_TARGETS;
    $selected = [];
    $bankIds = [];
    $difficultyRemaining = bankDifficultyRemainingInit(null);

    foreach ($targets as $category => $count) {
        $need = (int)$count;
        if ($need <= 0) {
            continue;
        }

        $picked = bankPickQuestionsFromCategory($conn, $category, $need, $bankIds, $difficultyRemaining);
        foreach ($picked as $q) {
            $selected[] = $q;
            $bankIds[] = (int)$q['bank_id'];
        }

        $shortfall = $need - count($picked);
        if ($shortfall > 0) {
            logQuizGenInfo("Bank short for '$category' by $shortfall — using General Knowledge fallback");
            if (function_exists('bumpQuizGenStat')) {
                bumpQuizGenStat('gk_fallbacks', $shortfall);
            }
            $fill = selectGkFallbackQuestions($conn, $shortfall, $bankIds, $difficultyRemaining);
            if (count($fill) < $shortfall) {
                throw new RuntimeException(
                    "Bank pool short for '$category' and insufficient GK fallback "
                    . '(need ' . $shortfall . ', got ' . count($fill) . ')'
                );
            }
            foreach ($fill as $q) {
                $selected[] = $q;
                $bankIds[] = (int)$q['bank_id'];
            }
        }
    }

    return $selected;
}

/**
 * @param int[] $excludeIds
 * @param string|null $difficulty 'easy'|'medium'|'hard', or null for unlabeled only; omit filter with $difficultyFilter=false
 * @return list<array>
 */
function bankFetchCategoryCandidates(
    mysqli $conn,
    string $category,
    int $cooldownDays,
    int $limit,
    array $excludeIds = [],
    ?string $difficulty = null,
    bool $anyDifficulty = false
): array {
    $limit = max(1, min(200, $limit));
    $sql = "SELECT id, source, category, question_text, format, difficulty
            FROM QuizQuestionBank
            WHERE category = ?
              AND (last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL ? DAY))";
    $types = 'si';
    $params = [$category, $cooldownDays];

    if (!$anyDifficulty) {
        if ($difficulty === null) {
            $sql .= " AND (difficulty IS NULL OR difficulty = '')";
        } else {
            $sql .= ' AND LOWER(difficulty) = ?';
            $types .= 's';
            $params[] = strtolower($difficulty);
        }
    }

    if ($excludeIds) {
        $excludeIds = array_map('intval', array_unique($excludeIds));
        $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
        $sql .= " AND id NOT IN ($placeholders)";
        $types .= str_repeat('i', count($excludeIds));
        $params = array_merge($params, $excludeIds);
    }

    $sql .= ' ORDER BY RAND() LIMIT ?';
    $types .= 'i';
    $params[] = $limit;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/** Rough count of rows that currently sanitize to four unique MC options. */
function bankCountValidAvailable(mysqli $conn, string $category): int {
    $cooldown = BANK_REUSE_COOLDOWN_DAYS;
    $stmt = $conn->prepare(
        "SELECT b.id
         FROM QuizQuestionBank b
         WHERE b.category = ?
           AND b.format = 'mc'
           AND (b.last_used_at IS NULL OR b.last_used_at < DATE_SUB(NOW(), INTERVAL ? DAY))
         LIMIT 500"
    );
    $stmt->bind_param('si', $category, $cooldown);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $valid = 0;
    foreach ($rows as $row) {
        $bid = (int)$row['id'];
        $optStmt = $conn->prepare(
            'SELECT option_text, is_correct FROM QuizQuestionBankOption WHERE bank_id = ? ORDER BY position'
        );
        $optStmt->bind_param('i', $bid);
        $optStmt->execute();
        $opts = $optStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $optStmt->close();
        if (bankSanitizeStoredMcOptions($opts) !== null) {
            $valid++;
        }
    }

    return $valid;
}

function bankCountAvailable(mysqli $conn, string $category): int {
    $cooldown = BANK_REUSE_COOLDOWN_DAYS;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM QuizQuestionBank
         WHERE category = ?
           AND (last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL ? DAY))"
    );
    $stmt->bind_param('si', $category, $cooldown);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['cnt'] ?? 0);
}

/** @param int[] $bankIds */
function markBankQuestionsUsed(mysqli $conn, array $bankIds): void {
    if (!$bankIds) {
        return;
    }
    $ids = array_map('intval', array_unique($bankIds));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $stmt = $conn->prepare("UPDATE QuizQuestionBank SET last_used_at = NOW() WHERE id IN ($placeholders)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $stmt->close();
}

function bankPoolHealth(mysqli $conn): array {
    $health = [];
    foreach (BANK_CATEGORY_TARGETS as $category => $dailyUse) {
        $perDay = $dailyUse * 2;
        $avail = bankCountAvailable($conn, $category);
        $runway = $perDay > 0 ? (int)floor($avail / $perDay) : 0;
        $health[$category] = ['available' => $avail, 'runway_days' => $runway];
    }
    return $health;
}

function bankInsertQuestion(mysqli $conn, array $q): bool {
    $source = $q['source'];
    $sourceId = $q['source_id'];
    $category = $q['category'];
    $text = bankNormalizeQuestionText($q['question_text']);
    $format = $q['format'];
    $difficulty = $q['difficulty'] ?? null;
    $attribution = $q['attribution'] ?? null;

    $stmt = $conn->prepare(
        'INSERT IGNORE INTO QuizQuestionBank (source, source_id, category, question_text, format, difficulty, attribution)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssssss', $source, $sourceId, $category, $text, $format, $difficulty, $attribution);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $bankId = $inserted ? (int)$conn->insert_id : 0;
    $stmt->close();

    if (!$inserted) {
        $find = $conn->prepare('SELECT id FROM QuizQuestionBank WHERE source = ? AND source_id = ? LIMIT 1');
        $find->bind_param('ss', $source, $sourceId);
        $find->execute();
        $row = $find->get_result()->fetch_assoc();
        $find->close();
        return false;
    }

    $optStmt = $conn->prepare(
        'INSERT INTO QuizQuestionBankOption (bank_id, position, option_text, is_correct) VALUES (?, ?, ?, ?)'
    );
    foreach ($q['options'] as $i => $opt) {
        $pos = $i + 1;
        $oText = $opt['text'];
        $correct = $opt['correct'] ? 1 : 0;
        $optStmt->bind_param('iisi', $bankId, $pos, $oText, $correct);
        $optStmt->execute();
    }
    $optStmt->close();
    return true;
}

/**
 * @return list<array> normalised question structs ready for bankInsertQuestion
 */
function bankNormaliseOpenTdbResult(array $item, string $category): ?array {
    $question = bankNormalizeQuestionText($item['question'] ?? '');
    if ($question === '' || bankQuestionShouldSkip($question)) {
        return null;
    }

    $type = $item['type'] ?? 'multiple';
    $format = ($type === 'boolean') ? 'tf' : 'mc';

    $correct = bankDecodeHtml(trim($item['correct_answer'] ?? ''));
    if ($correct === '') {
        return null;
    }

    $options = [];
    if ($format === 'mc') {
        $incorrect = $item['incorrect_answers'] ?? [];
        if (!is_array($incorrect)) {
            return null;
        }
        $built = bankBuildMcOptions($correct, $incorrect);
        if ($built === null) {
            return null;
        }
        $options = $built;
    } else {
        $options = [
            ['text' => 'True', 'correct' => strcasecmp($correct, 'True') === 0],
            ['text' => 'False', 'correct' => strcasecmp($correct, 'False') === 0],
        ];
    }

    if (bankQuestionShouldSkip($question, $options)) {
        return null;
    }

    return [
        'source'        => 'opentdb',
        'source_id'     => bankSourceId('opentdb', $question),
        'category'      => $category,
        'question_text' => $question,
        'format'        => $format,
        'difficulty'    => $item['difficulty'] ?? null,
        'attribution'   => 'Open Trivia DB (CC BY-SA 4.0)',
        'options'       => $options,
    ];
}

function bankFetchOpenTdbCategory(int $categoryId, int $amount = 50, ?string $sessionToken = null): array {
    $params = [
        'amount'   => min(50, max(1, $amount)),
        'category' => $categoryId,
        'encode'   => 'url3986',
    ];
    if ($sessionToken) {
        $params['token'] = $sessionToken;
    }
    $url = 'https://opentdb.com/api.php?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Quizzical/1.0',
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) {
        return [];
    }
    $decoded = json_decode($body, true);
    if (($decoded['response_code'] ?? 1) !== 0) {
        return [];
    }
    return $decoded['results'] ?? [];
}

function bankOpenTdbSessionToken(): ?string {
    $ch = curl_init('https://opentdb.com/api_token.php?command=request');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) {
        return null;
    }
    $decoded = json_decode($body, true);
    return $decoded['token'] ?? null;
}

function bankSeedOpenTdb(mysqli $conn, bool $full = false): array {
    $added = ['Geography' => 0, 'History' => 0, 'General Knowledge' => 0];
    $token = bankOpenTdbSessionToken();

    foreach (OPENTDB_CATEGORY_MAP as $catId => $quizzicalCat) {
        $attempts = $full ? 40 : 5;
        for ($i = 0; $i < $attempts; $i++) {
            $results = bankFetchOpenTdbCategory($catId, 50, $token);
            if (!$results) {
                break;
            }
            foreach ($results as $item) {
                $q = bankNormaliseOpenTdbResult($item, $quizzicalCat);
                if ($q && bankInsertQuestion($conn, $q)) {
                    $added[$quizzicalCat]++;
                }
            }
            usleep(350000);
        }
    }

    return $added;
}

/**
 * Parse OpenTriviaQA category file content.
 * @return list<array>
 */
function bankParseOpenTriviaQaFile(string $content, string $quizzicalCat): array {
    $questions = [];
    $blocks = preg_split('/\n\s*\n/', trim($content));
    foreach ($blocks as $block) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $block))));
        if (count($lines) < 3) {
            continue;
        }
        $questionLine = $lines[0];
        if (!str_starts_with($questionLine, '#Q ')) {
            continue;
        }
        $question = bankNormalizeQuestionText(substr($questionLine, 3));
        if ($question === '' || bankQuestionShouldSkip($question)) {
            continue;
        }

        $correct = null;
        $choices = [];
        foreach (array_slice($lines, 1) as $line) {
            if (str_starts_with($line, '^ ')) {
                $correct = trim(substr($line, 2));
            } elseif (preg_match('/^[A-D] (.+)$/', $line, $m)) {
                $choices[] = trim($m[1]);
            }
        }
        if ($correct === null || count($choices) < 4) {
            continue;
        }

        $built = bankBuildMcOptionsFromChoices($correct, array_slice($choices, 0, 4));
        if ($built === null) {
            continue;
        }
        if (bankQuestionShouldSkip($question, $built)) {
            continue;
        }

        $questions[] = [
            'source'        => 'opentriviaqa',
            'source_id'     => bankSourceId('opentriviaqa', $question),
            'category'      => $quizzicalCat,
            'question_text' => $question,
            'format'        => 'mc',
            'difficulty'    => null,
            'attribution'   => 'OpenTriviaQA (CC BY-SA 4.0)',
            'options'       => $built,
        ];
    }
    return $questions;
}

function bankSeedOpenTriviaQa(mysqli $conn): array {
    $added = ['Geography' => 0, 'History' => 0, 'General Knowledge' => 0];
    $baseUrl = 'https://raw.githubusercontent.com/uberspot/OpenTriviaQA/master/categories/';

    foreach (OPENTRIVIAQA_CATEGORY_MAP as $file => $quizzicalCat) {
        $url = $baseUrl . rawurlencode($file);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $content = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$content) {
            continue;
        }

        foreach (bankParseOpenTriviaQaFile($content, $quizzicalCat) as $q) {
            if (bankInsertQuestion($conn, $q)) {
                $added[$quizzicalCat]++;
            }
        }
        usleep(200000);
    }

    return $added;
}

/** Delete and re-import OpenTriviaQA rows with the current parser (fixes legacy duplicate options). */
function bankRepairOpenTriviaQa(mysqli $conn): array {
    $conn->query(
        'DELETE o FROM QuizQuestionBankOption o
         INNER JOIN QuizQuestionBank b ON o.bank_id = b.id
         WHERE b.source = \'opentriviaqa\''
    );
    $conn->query("DELETE FROM QuizQuestionBank WHERE source = 'opentriviaqa'");
    $deleted = $conn->affected_rows;

    return [
        'deleted'    => $deleted,
        'reimported' => bankSeedOpenTriviaQa($conn),
    ];
}

/** Delete invalid bank rows and fix URL-encoded question text in survivors. */
function bankRepairQuestionQuality(mysqli $conn): array {
    $deleted = 0;
    $updated = 0;
    $r = $conn->query('SELECT id, question_text, format, category FROM QuizQuestionBank ORDER BY id');
    if (!$r) {
        return ['deleted' => 0, 'updated' => 0, 'error' => $conn->error];
    }

    while ($row = $r->fetch_assoc()) {
        $id = (int)$row['id'];
        $normalized = bankNormalizeQuestionText($row['question_text']);

        $optStmt = $conn->prepare(
            'SELECT option_text, is_correct FROM QuizQuestionBankOption WHERE bank_id = ? ORDER BY position'
        );
        $optStmt->bind_param('i', $id);
        $optStmt->execute();
        $dbOpts = $optStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $optStmt->close();

        $quizRow = [
            'question_text' => $normalized,
            'format'        => $row['format'],
            'id'            => $id,
            'category'      => $row['category'],
            'source'        => '',
        ];
        if (bankRowToQuizQuestion($quizRow, $dbOpts) === null) {
            $delOpts = $conn->prepare('DELETE FROM QuizQuestionBankOption WHERE bank_id = ?');
            $delOpts->bind_param('i', $id);
            $delOpts->execute();
            $delOpts->close();
            $delQ = $conn->prepare('DELETE FROM QuizQuestionBank WHERE id = ?');
            $delQ->bind_param('i', $id);
            $delQ->execute();
            $delQ->close();
            $deleted++;
            continue;
        }

        if ($normalized !== $row['question_text']) {
            $upd = $conn->prepare('UPDATE QuizQuestionBank SET question_text = ? WHERE id = ?');
            $upd->bind_param('si', $normalized, $id);
            $upd->execute();
            $upd->close();
            $updated++;
        }
    }

    return ['deleted' => $deleted, 'updated' => $updated];
}

function bankTotalCounts(mysqli $conn): array {
    $out = [];
    $r = $conn->query(
        "SELECT category, COUNT(*) AS total,
                SUM(CASE WHEN last_used_at IS NULL OR last_used_at < DATE_SUB(NOW(), INTERVAL "
        . (int)BANK_REUSE_COOLDOWN_DAYS . " DAY) THEN 1 ELSE 0 END) AS available
         FROM QuizQuestionBank GROUP BY category"
    );
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $out[$row['category']] = [
                'total'     => (int)$row['total'],
                'available' => (int)$row['available'],
            ];
        }
    }
    return $out;
}
