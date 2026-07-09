<?php
/**
 * quiz-generator.php
 *
 * Shared quiz-generation logic used by both generate-quiz.php (CLI cron, writes to DB)
 * and action-test-generate-quiz.php (ad hoc admin preview, does not write to DB).
 * The core generation logic has no DB dependency; fetchRecentQuestions() below is
 * a read-only helper both callers can use for cross-day duplicate avoidance.
 *
 * Generates one category at a time (small, fixed-count OpenAI calls) rather than
 * all 15 questions in a single call. A single call asking for 8 exact category
 * counts at once proved unreliable in practice — a real cron run exhausted 7
 * retries with the model unable to converge on the distribution (Sports kept
 * coming back empty, General Knowledge kept over-producing). Asking for e.g.
 * "exactly 2 Sports questions" in isolation is a far easier constraint for the
 * model to satisfy, and the app (not the model) now assigns each question's
 * category and decides which categories host the 2 true/false questions, which
 * also structurally rules out the model mislabeling a question's category.
 */

require_once __DIR__ . '/dblogin.php'; // defines OPENAI_API_KEY
require_once __DIR__ . '/config.php';

const CATEGORY_TARGETS = [
    'NZ Trivia'              => 2,
    'Australia Trivia'       => 2,
    'Sports'                 => 2,
    'NZ Current Events'      => 1,
    'Aussie Current Events'  => 1,
    'Geography'              => 2,
    'History'                => 2,
    'General Knowledge'      => 3,
];

const MAX_ATTEMPTS_PER_CATEGORY = 5;

const NZ_KEYWORDS = [
    'new zealand', 'nz', 'zealand', 'auckland', 'wellington', 'christchurch', 'dunedin',
    'hamilton', 'rotorua', 'tauranga', 'queenstown', 'napier', 'nelson', 'invercargill',
    'maori', 'māori', 'waitangi', 'kiwi', 'aotearoa', 'taupo', 'south island', 'north island',
    'wakari',
];

const AU_KEYWORDS = [
    'australia', 'aussie', 'sydney', 'melbourne', 'brisbane', 'perth', 'canberra', 'adelaide',
    'queensland', 'victoria', 'new south wales', 'nsw', 'tasmania', 'northern territory',
    'outback', 'great barrier reef', 'aboriginal',
];

/**
 * Regex => human-readable reason, for cliché questions the model reaches for
 * repeatedly despite the prompt's "avoid the single most famous fact" instruction.
 * Applies across all categories. Add a line here whenever a new recurring
 * cliché gets spotted — cheaper than trying to word-tune the prompt further.
 */
const BANNED_QUESTION_PATTERNS = [
    '/\bcapital( city)? of\b/i'            => 'capital-of-a-country cliché',
    '/\bopera house\b/i'                   => 'Sydney Opera House cliché',
    '/\bnational (symbol|animal|bird)\b/i' => 'national symbol/animal/bird cliché',
    '/\blongest river in the world\b/i'    => 'disputed fact (Nile vs Amazon) treated as settled',
];

const CURRENT_EVENTS_CATEGORIES = ['NZ Current Events', 'Aussie Current Events'];

/** @var array{categories_retried: int, fact_check_skips: int} */
$GLOBALS['quizGenStats'] = ['categories_retried' => 0, 'fact_check_skips' => 0];

function resetQuizGenStats(): void {
    $GLOBALS['quizGenStats'] = ['categories_retried' => 0, 'fact_check_skips' => 0];
}

function getQuizGenStats(): array {
    return $GLOBALS['quizGenStats'];
}

function bumpQuizGenStat(string $key): void {
    if (isset($GLOBALS['quizGenStats'][$key])) {
        $GLOBALS['quizGenStats'][$key]++;
    }
}

/** @var array<int, array{time: string, level: string, message: string}>|null */
$GLOBALS['quizGenLogCapture'] = null;

function startQuizGenLogCapture(): void {
    $GLOBALS['quizGenLogCapture'] = [];
}

function stopQuizGenLogCapture(): array {
    $lines = is_array($GLOBALS['quizGenLogCapture'] ?? null)
        ? $GLOBALS['quizGenLogCapture']
        : [];
    $GLOBALS['quizGenLogCapture'] = null;
    return $lines;
}

function appendQuizGenLogCapture(string $level, string $msg): void {
    if (!is_array($GLOBALS['quizGenLogCapture'] ?? null)) {
        return;
    }
    $GLOBALS['quizGenLogCapture'][] = [
        'time'    => date('Y-m-d H:i:s'),
        'level'   => $level,
        'message' => $msg,
    ];
}

/**
 * Generates a full 15-question quiz, one category at a time, and returns the
 * combined array of question objects with positions 1..15 assigned. Throws
 * RuntimeException if any single category can't produce a valid result within
 * MAX_ATTEMPTS_PER_CATEGORY.
 *
 * @param string $quizType 'morning' or 'afternoon' — controls the headline recency window.
 * @param string $today Y-m-d date string used in the prompt.
 * @param string[] $avoidQuestions Question texts to avoid repeating (e.g. recent days' quizzes).
 */
function generateQuizQuestions(string $quizType, string $today, array $avoidQuestions = []): array {
    resetQuizGenStats();
    $headlinesByRegion = fetchHeadlines($quizType);
    $tfHosts = pickTfHostCategories();

    $allQuestions = [];
    foreach (CATEGORY_TARGETS as $category => $count) {
        $tfCount = in_array($category, $tfHosts, true) ? 1 : 0;
        $mcCount = $count - $tfCount;

        // Include topics already generated earlier in this same quiz, on top of
        // the cross-day avoid-list, so e.g. Geography and History don't both
        // reach for the same treaty.
        $avoidForThisCall = array_merge($avoidQuestions, array_column($allQuestions, 'question'));

        $categoryQuestions = generateCategoryQuestions(
            $category, $mcCount, $tfCount, $headlinesByRegion, $today, $avoidForThisCall
        );
        $allQuestions = array_merge($allQuestions, $categoryQuestions);
    }

    foreach ($allQuestions as $i => &$q) {
        $q['position'] = $i + 1;
    }
    unset($q);

    return $allQuestions;
}

/**
 * Picks 2 categories (from those with a count of 2+) to each host exactly one
 * of the quiz's 2 true/false questions. Randomised per generation for variety
 * rather than always landing the tf slot on the same categories.
 */
function pickTfHostCategories(): array {
    $eligible = array_keys(array_filter(CATEGORY_TARGETS, fn($count) => $count >= 2));
    shuffle($eligible);
    return array_slice($eligible, 0, 2);
}

/**
 * Generates and validates the questions for a single category via its own
 * small, fixed-count OpenAI call and retry loop. The category label itself is
 * assigned by the caller, not requested from the model, so mislabeling is
 * structurally impossible — only "wrong content for the requested category"
 * remains a risk, which validateOneQuestion() still checks for.
 */
function generateCategoryQuestions(
    string $category, int $mcCount, int $tfCount, array $headlinesByRegion, string $today, array $avoidQuestions
): array {
    $total = $mcCount + $tfCount;

    $headlineBlock = '';
    if ($category === 'NZ Current Events') {
        $headlineBlock = "\n\nNZ headlines:\n" . implode("\n", array_map(fn($h) => '- ' . $h, $headlinesByRegion['NZ']));
    } elseif ($category === 'Aussie Current Events') {
        $headlineBlock = "\n\nAustralian headlines:\n" . implode("\n", array_map(fn($h) => '- ' . $h, $headlinesByRegion['AU']));
    }

    $formatInstruction = $tfCount > 0
        ? "Produce exactly $total question(s): $mcCount in \"mc\" format (4 options, exactly 1 correct) and $tfCount in \"tf\" format (2 options, \"True\" and \"False\", exactly 1 correct — phrased as a single declarative true/false statement, ideally starting with \"True or False:\". NEVER phrase a tf question as a which/what/who/when/where question, since that can't be sensibly answered with just True or False)."
        : "Produce exactly $total question(s), all in \"mc\" format (4 options, exactly 1 correct).";

    $categoryGuidance = buildCategoryGuidance($category);

    $systemPrompt = <<<PROMPT
You are writing question(s) for the "$category" section of a daily quiz for a New Zealand audience.

$categoryGuidance

$formatInstruction

For any "mc" question, include one answer option that sounds almost plausible but is subtly absurd on reflection — not obviously silly, the kind that makes you second-guess yourself. Aim for roughly 1 in 3 of the mc questions to have this.

Difficulty: genuinely challenging — average players should get some wrong. Do NOT ask questions whose answer is the single most famous fact about a topic (e.g. capital cities, a country's national animal/bird, "the" founding treaty of a nation, the primary language of a country, a famous landmark's most basic fact). These are trivially guessable. Ask about specific details, dates, numbers, or lesser-known angles instead.

Never state the correct answer's exact wording anywhere in the question text itself.

For each question also output image_query: a 2–4 word visual search term for a stock photo related to the question topic. Must NOT name or depict the correct answer, must not repeat option text verbatim, and for tf questions must illustrate the subject — never "true" or "false".
PROMPT;

    $avoidBlock = '';
    if ($avoidQuestions) {
        $avoidList = implode("\n", array_map(fn($q) => '- ' . $q, $avoidQuestions));
        $avoidBlock = "\n\nDo NOT repeat or ask a near-duplicate of any of these already-used questions/topics:\n$avoidList";
    }

    $userPrompt = "Today is $today.$headlineBlock$avoidBlock\n\nGenerate the question(s) now.";

    $schema = [
        'type' => 'json_schema',
        'json_schema' => [
            'name'   => 'category_questions',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'questions' => [
                        'type'  => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'question'    => ['type' => 'string'],
                                'format'      => ['type' => 'string', 'enum' => ['mc', 'tf']],
                                'image_query' => ['type' => 'string'],
                                'options'     => [
                                    'type'  => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'text'    => ['type' => 'string'],
                                            'correct' => ['type' => 'boolean'],
                                        ],
                                        'required'             => ['text', 'correct'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                            'required'             => ['question', 'format', 'image_query', 'options'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required'             => ['questions'],
                'additionalProperties' => false,
            ],
        ],
    ];

    $result = null;
    $attemptUserPrompt = $userPrompt;

    for ($attempt = 1; $attempt <= MAX_ATTEMPTS_PER_CATEGORY; $attempt++) {
        $payload = json_encode([
            'model'           => 'gpt-4o-mini',
            'messages'        => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $attemptUserPrompt],
            ],
            'response_format' => $schema,
            'temperature'     => 0.9,
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . OPENAI_API_KEY,
            ],
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            logQuizGenError("[$category] Attempt $attempt: cURL error: $curlError");
            continue;
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['choices'][0]['message']['content'])) {
            logQuizGenError("[$category] Attempt $attempt: Unexpected OpenAI response: $response");
            continue;
        }

        $candidate = json_decode($decoded['choices'][0]['message']['content'], true);
        $errors = validateCategoryQuestions($candidate, $category, $mcCount, $tfCount);
        if (!$errors) {
            $errors = factCheckCategoryQuestions(
                $candidate['questions'] ?? [], $category, $headlinesByRegion
            );
        }
        if ($errors) {
            $errorList = implode("\n", array_map(fn($e) => '- ' . $e, $errors));
            logQuizGenError("[$category] Attempt $attempt: " . count($errors) . " violation(s):\n$errorList");
            $attemptUserPrompt = $userPrompt . "\n\nNOTE: Your previous attempt was rejected for the following reasons — fix ALL of them this time:\n$errorList";
            continue;
        }

        if ($attempt > 1) {
            bumpQuizGenStat('categories_retried');
        }

        $result = $candidate['questions'];
        break;
    }

    if ($result === null) {
        logQuizGenError("[$category] All " . MAX_ATTEMPTS_PER_CATEGORY . " attempts failed validation — giving up.");
        throw new RuntimeException("Failed to generate '$category' after " . MAX_ATTEMPTS_PER_CATEGORY . " attempts — check logs/generate-quiz.log");
    }

    foreach ($result as &$q) {
        $q['category'] = $category;
    }
    unset($q);

    return $result;
}

/**
 * Category-specific instructions: what the content should (and shouldn't) draw
 * from. Current Events categories are explicitly grounded in headlines; the
 * other 6 are explicitly told to ignore headlines/today's news entirely, and
 * their prompts never include a headline block in the first place — removing
 * the temptation structurally, not just via instruction.
 */
function buildCategoryGuidance(string $category): string {
    switch ($category) {
        case 'NZ Current Events':
            return 'These questions MUST be based only on the NZ headlines block below — genuinely current, real stories. Must NOT be about Australia. Phrase naturally — do not say "according to recent news" or "as reported today".';
        case 'Aussie Current Events':
            return 'These questions MUST be based only on the Australian headlines block below — genuinely current, real stories. Must NOT be about New Zealand. Phrase naturally — do not say "according to recent news" or "as reported today".';
        case 'NZ Trivia':
            return 'General New Zealand trivia — established, verifiable facts that would be true regardless of today\'s news. Do NOT base these on current headlines, even indirectly.';
        case 'Australia Trivia':
            return 'General Australia trivia — established, verifiable facts that would be true regardless of today\'s news. Do NOT base these on current headlines, even indirectly.';
        case 'Sports':
            return 'General sports trivia (may reference NZ/Australian teams or leagues, since that\'s expected content for this audience) — established, verifiable facts, NOT tied to today\'s news.';
        case 'Geography':
        case 'History':
        case 'General Knowledge':
            return "Must be about the rest of the world — NOT New Zealand or Australia, which already have their own dedicated trivia and current-events categories. Cover other countries, world history, science, arts, etc. Do NOT base these on today's news.";
        default:
            return '';
    }
}

/**
 * Validates a category's generated questions: exact count, exact mc/tf split,
 * and every per-question content rule (see validateOneQuestion()).
 *
 * @return string[] Empty array if valid, otherwise a list of violation descriptions.
 */
function validateCategoryQuestions(?array $candidate, string $category, int $mcCount, int $tfCount): array {
    $total = $mcCount + $tfCount;
    if (!isset($candidate['questions']) || count($candidate['questions']) !== $total) {
        return ["expected $total question(s) for '$category', got: " . json_encode($candidate)];
    }

    $errors = [];
    $actualMc = 0;
    $actualTf = 0;
    foreach ($candidate['questions'] as $i => $q) {
        $errors = array_merge($errors, validateOneQuestion($q, $category, $i + 1));
        if ($q['format'] === 'mc') $actualMc++;
        elseif ($q['format'] === 'tf') $actualTf++;
    }
    if ($actualMc !== $mcCount) {
        $errors[] = "'$category' has $actualMc mc question(s) (expected $mcCount)";
    }
    if ($actualTf !== $tfCount) {
        $errors[] = "'$category' has $actualTf tf question(s) (expected $tfCount)";
    }

    return $errors;
}

/**
 * Validates a single question: option/correct-answer counts, tf phrased as a
 * WH-question, banned clichés, answer-giveaway, current-events content leaking
 * into an evergreen category, and NZ/AU content bias or cross-country mixup.
 *
 * @return string[] Violation descriptions for this question (empty if clean).
 */
function validateOneQuestion(array $q, string $category, int $qNum): array {
    $errors = [];
    $correctCount = 0;
    $correctText = '';
    foreach ($q['options'] as $opt) {
        if ($opt['correct']) {
            $correctCount++;
            $correctText = $opt['text'];
        }
    }
    if ($correctCount !== 1) {
        $errors[] = "question $qNum has $correctCount correct answers (expected 1)";
    }
    $expectedOptions = ($q['format'] === 'tf') ? 2 : 4;
    if (count($q['options']) !== $expectedOptions) {
        $errors[] = "question $qNum ({$q['format']}) has " . count($q['options']) . " options (expected $expectedOptions)";
    }

    if ($q['format'] === 'tf' && preg_match('/^\s*(which|who|what|when|where|why|how)\b/i', $q['question'])) {
        $errors[] = "question $qNum is format 'tf' but phrased as a WH-question (\"{$q['question']}\") — tf questions must be true/false statements";
    }

    foreach (BANNED_QUESTION_PATTERNS as $pattern => $reason) {
        if (preg_match($pattern, $q['question'])) {
            $errors[] = "question $qNum (\"{$q['question']}\") matches banned pattern: $reason — pick a different, less obvious question";
            break;
        }
    }

    // A well-formed MC question should never name its own answer in the prompt
    // (e.g. "...hosting the annual Sydney Festival?" with "Sydney" as the
    // correct option). Word-boundary match, case-insensitive; skip tf (its
    // "True"/"False" text isn't a meaningful giveaway) and very short answers
    // (avoid noise from incidental short-word overlap).
    if ($q['format'] === 'mc' && strlen($correctText) >= 3
        && preg_match('/\b' . preg_quote($correctText, '/') . '\b/i', $q['question'])) {
        $errors[] = "question $qNum gives away its own answer — correct answer \"$correctText\" appears verbatim in the question text (\"{$q['question']}\")";
    }

    $combined = $q['question'] . ' ' . $correctText;

    // Current events must stay out of the 6 "evergreen" categories — the model
    // keeps reaching for real news dressed as timeless trivia. Catches explicit
    // recency language and mentions of the current/previous year; a paraphrased
    // story with no such marker can still slip through — accepted residual risk.
    if (!in_array($category, CURRENT_EVENTS_CATEGORIES, true)) {
        if (preg_match('/\b(recent(ly)?|this year|last year|newly|latest|just (been|announced|appointed|released|launched))\b/i', $combined)) {
            $errors[] = "question $qNum (category '$category') uses recency language — this category must be evergreen, not current events";
        }
        $currentYear = (int) date('Y');
        foreach ([$currentYear, $currentYear - 1] as $yr) {
            if (preg_match('/\b' . $yr . '\b/', $combined)) {
                $errors[] = "question $qNum (category '$category') mentions $yr — looks like current-events content leaking into a non-news category";
                break;
            }
        }
    }

    if (in_array($category, ['Geography', 'History', 'General Knowledge'], true)) {
        $hit = textContainsKeyword($combined, NZ_KEYWORDS) ?? textContainsKeyword($combined, AU_KEYWORDS);
        if ($hit !== null) {
            $errors[] = "question $qNum (category '$category') is NZ/AU-specific (matched \"$hit\") — this category must be global content";
        }
    } elseif ($category === 'Aussie Current Events') {
        $hit = textContainsKeyword($combined, NZ_KEYWORDS);
        if ($hit !== null) {
            $errors[] = "question $qNum is meant to be about Australia but matched NZ term \"$hit\"";
        }
    } elseif ($category === 'NZ Current Events') {
        $hit = textContainsKeyword($combined, AU_KEYWORDS);
        if ($hit !== null) {
            $errors[] = "question $qNum is meant to be about New Zealand but matched Australian term \"$hit\"";
        }
    }

    $imageQuery = trim($q['image_query'] ?? '');
    if ($imageQuery === '') {
        $errors[] = "question $qNum is missing image_query";
    } else {
        if ($q['format'] === 'mc' && strlen($correctText) >= 3
            && preg_match('/\b' . preg_quote($correctText, '/') . '\b/i', $imageQuery)) {
            $errors[] = "question $qNum image_query gives away the correct answer — \"$correctText\" appears in image_query";
        }
        foreach ($q['options'] as $opt) {
            $optText = $opt['text'];
            if (strlen($optText) >= 3 && preg_match('/\b' . preg_quote($optText, '/') . '\b/i', $imageQuery)) {
                $errors[] = "question $qNum image_query repeats option text \"$optText\"";
                break;
            }
        }
        if (preg_match('/\b(true|false)\b/i', $imageQuery) && $q['format'] === 'tf') {
            $errors[] = "question $qNum image_query must not reference true/false for a tf question";
        }
    }

    return $errors;
}

/**
 * Case-insensitive whole-word/phrase search across a list of keywords.
 */
function textContainsKeyword(string $text, array $keywords): ?string {
    foreach ($keywords as $kw) {
        if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $text)) {
            return $kw;
        }
    }
    return null;
}

/**
 * Returns question texts from the last $lookbackDays of quizzes (inclusive of
 * $today), across both Morning and Afternoon, so generation can avoid repeating
 * a topic that's still fresh even after it's rotated out of the headline window
 * (e.g. a school competition result that keeps getting reused as "trivia" days
 * after the fact). Read-only — safe to call from the no-DB-write test endpoint too.
 */
function fetchRecentQuestions(mysqli $conn, string $today, int $lookbackDays = 3): array {
    $stmt = $conn->prepare(
        "SELECT q.question_text FROM AIQuestion q
         JOIN AIQuiz qz ON qz.id = q.quiz_id
         WHERE qz.date BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND ?"
    );
    $stmt->bind_param('sis', $today, $lookbackDays, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $questions = array_map(fn($row) => $row['question_text'], $result->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    return $questions;
}

/**
 * Returns headlines keyed by region, e.g. ['NZ' => [...], 'AU' => [...]],
 * each capped independently so neither region can crowd out the other.
 */
function fetchHeadlines(string $quizType): array {
    $feedsByRegion = [
        'NZ' => [
            'https://www.rnz.co.nz/rss/national.rss',
            'https://news.google.com/rss?hl=en-NZ&gl=NZ&ceid=NZ:en',
        ],
        'AU' => [
            'https://www.abc.net.au/news/feed/51120/rss.xml',
            'https://news.google.com/rss?hl=en-AU&gl=AU&ceid=AU:en',
        ],
    ];

    // Afternoon gets a shorter window so it draws on fresher news than morning
    $hours  = ($quizType === 'afternoon') ? 12 : 36;
    $cutoff = time() - ($hours * 3600);
    $perRegionCap = 15;
    $headlinesByRegion = [];

    foreach ($feedsByRegion as $region => $feeds) {
        $regionHeadlines = [];
        foreach ($feeds as $url) {
            $xml = fetchRss($url);
            if (!$xml) continue;

            // Support both RSS <item> and Atom <entry>
            $items = $xml->channel->item ?? $xml->entry ?? [];
            foreach ($items as $item) {
                $pubDate = (string)($item->pubDate ?? $item->published ?? '');
                if ($pubDate && strtotime($pubDate) < $cutoff) continue;

                $title = trim(strip_tags((string)($item->title ?? '')));
                if ($title) $regionHeadlines[] = $title;
            }
        }
        $regionHeadlines = array_values(array_unique($regionHeadlines));
        $headlinesByRegion[$region] = array_slice($regionHeadlines, 0, $perRegionCap);
    }

    return $headlinesByRegion;
}

function fetchRss(string $url): ?SimpleXMLElement {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) return null;
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    return $xml ?: null;
}

// ── Fact-check (web search + snippet verifier) ─────────────────────────────

function buildFactCheckQuery(string $question): string {
    $q = preg_replace('/^\s*true or false\s*:\s*/i', '', $question);
    $q = preg_replace('/^\s*(which|who|what|when|where|why|how)\s+/i', '', $q);
    $q = preg_replace('/[^\w\s]/u', ' ', $q);
    $words = preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_slice($words, 0, 8));
}

function tavilySearch(string $query): ?array {
    if (!defined('TAVILY_API_KEY') || TAVILY_API_KEY === '') {
        return null;
    }

    $payload = json_encode([
        'api_key'      => TAVILY_API_KEY,
        'query'        => $query,
        'search_depth' => 'basic',
        'max_results'  => 5,
    ]);

    $ch = curl_init('https://api.tavily.com/search');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || !$response) {
        return null;
    }

    $decoded = json_decode($response, true);
    if (!isset($decoded['results']) || !is_array($decoded['results'])) {
        return null;
    }

    $snippets = [];
    foreach ($decoded['results'] as $row) {
        $content = trim($row['content'] ?? '');
        if ($content !== '') {
            $snippets[] = mb_substr($content, 0, 400);
        }
    }

    return $snippets ?: null;
}

function verifyAnswerWithSnippets(
    string $question, string $markedAnswer, array $allOptions, string $format, array $snippets
): array {
    $snippetBlock = implode("\n\n", array_map(
        fn($s, $i) => '[' . ($i + 1) . '] ' . $s,
        $snippets,
        array_keys($snippets)
    ));

    $optionsBlock = implode(', ', array_map(
        fn($o) => '"' . $o['text'] . '"' . ($o['correct'] ? ' (marked correct)' : ''),
        $allOptions
    ));

    $systemPrompt = <<<'PROMPT'
You verify quiz answers using ONLY the provided search snippets — not your own memory.

Rules:
- Default to valid: true. Only reject when snippets contain explicit evidence the marked answer is wrong.
- Reject if snippets clearly contradict the marked answer (state a different fact, number, date, or name).
- If snippets are silent, off-topic, or too vague to verify — respond valid: true. Absence of evidence is NOT a rejection.
- Do NOT reject because snippets fail to mention the answer, the topic, or Jane Campion / a specific obscure detail.
- Do NOT reject because another option seems plausible, the question is oversimplified, or historians might debate nuance.
- For tf questions: reject only if snippets show the statement is the opposite truth to the marked True/False option.
- For mc questions: reject only if snippets clearly support a different listed option over the marked one — not merely because the marked option is unmentioned.

Respond with strict JSON: {"valid": true/false, "issue": "short reason if invalid, else empty string"}
PROMPT;

    $userPrompt = "Question: $question\nFormat: $format\nOptions: $optionsBlock\nMarked correct: \"$markedAnswer\"\n\nSearch snippets:\n$snippetBlock";

    $result = callOpenAIJsonVerifier($systemPrompt, $userPrompt);
    if ($result === null) {
        return ['valid' => true, 'issue' => ''];
    }

    return [
        'valid' => (bool)($result['valid'] ?? true),
        'issue' => trim($result['issue'] ?? ''),
    ];
}

function callOpenAIJsonVerifier(string $systemPrompt, string $userPrompt): ?array {
    $schema = [
        'type' => 'json_schema',
        'json_schema' => [
            'name'   => 'fact_check',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'valid' => ['type' => 'boolean'],
                    'issue' => ['type' => 'string'],
                ],
                'required'             => ['valid', 'issue'],
                'additionalProperties' => false,
            ],
        ],
    ];

    $payload = json_encode([
        'model'           => 'gpt-4o-mini',
        'messages'        => [
            ['role' => 'system', 'content' => $systemPrompt],
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
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY,
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return null;
    }

    $decoded = json_decode($response, true);
    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (!$content) {
        return null;
    }

    return json_decode($content, true);
}

/**
 * @return string[] Violation descriptions (empty if all questions pass).
 */
function factCheckCategoryQuestions(array $questions, string $category, array $headlinesByRegion): array {
    if (!defined('TAVILY_API_KEY') || TAVILY_API_KEY === '') {
        if (in_array($category, CURRENT_EVENTS_CATEGORIES, true)) {
            // Current events can still verify against headlines without Tavily
        } else {
            return [];
        }
    }

    $errors = [];
    foreach ($questions as $i => $q) {
        $qNum = $i + 1;
        $markedAnswer = '';
        foreach ($q['options'] as $opt) {
            if ($opt['correct']) {
                $markedAnswer = $opt['text'];
                break;
            }
        }

        if ($category === 'NZ Current Events') {
            $snippets = array_map(fn($h) => 'Headline: ' . $h, $headlinesByRegion['NZ'] ?? []);
        } elseif ($category === 'Aussie Current Events') {
            $snippets = array_map(fn($h) => 'Headline: ' . $h, $headlinesByRegion['AU'] ?? []);
        } else {
            $query = buildFactCheckQuery($q['question']);
            $snippets = tavilySearch($query);
            if ($snippets === null) {
                bumpQuizGenStat('fact_check_skips');
                logQuizGenError("[$category] [fact-check] Tavily unavailable — skipped fact-check for question $qNum");
                continue;
            }
        }

        if (!$snippets) {
            bumpQuizGenStat('fact_check_skips');
            logQuizGenError("[$category] [fact-check] No snippets — skipped fact-check for question $qNum");
            continue;
        }

        $verdict = verifyAnswerWithSnippets(
            $q['question'], $markedAnswer, $q['options'], $q['format'], $snippets
        );

        if (!$verdict['valid']) {
            $reason = $verdict['issue'] !== '' ? $verdict['issue'] : 'marked answer not supported by sources';
            $errors[] = "question $qNum [fact-check]: marked answer \"$markedAnswer\" rejected — $reason";
        }
    }

    return $errors;
}

// ── Question images (Pexels keyword search) ────────────────────────────────

function fetchQuestionImage(string $imageQuery, string $destDir, string $filenameBase): ?array {
    if (!defined('PEXELS_API_KEY') || PEXELS_API_KEY === '') {
        return null;
    }

    $url = 'https://api.pexels.com/v1/search?' . http_build_query([
        'query'       => $imageQuery,
        'orientation' => 'landscape',
        'per_page'    => 1,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . PEXELS_API_KEY],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return null;
    }

    $decoded = json_decode($response, true);
    $photo = $decoded['photos'][0] ?? null;
    if (!$photo) {
        return null;
    }

    $imageUrl = $photo['src']['large'] ?? $photo['src']['medium'] ?? null;
    if (!$imageUrl) {
        return null;
    }

    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        return null;
    }

    $destPath = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $filenameBase . '.jpg';
    if (!downloadAndResizeImage($imageUrl, $destPath)) {
        return null;
    }

    $photographer = $photo['photographer'] ?? 'Unknown';
    return [
        'path'         => $destPath,
        'attribution'  => "Photo by $photographer on Pexels",
    ];
}

function downloadAndResizeImage(string $url, string $destPath, int $maxW = 800, int $maxH = 450): bool {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    if (!$body) {
        return false;
    }

    $src = @imagecreatefromstring($body);
    if (!$src) {
        return false;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $scale = min($maxW / $srcW, $maxH / $srcH, 1.0);
    $newW = max(1, (int)round($srcW * $scale));
    $newH = max(1, (int)round($srcH * $scale));

    $out = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($out, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
    imagedestroy($src);

    $ok = imagejpeg($out, $destPath, 85);
    imagedestroy($out);

    return $ok;
}

function quizImagesRoot(): string {
    return __DIR__ . '/uploads/quiz-images';
}

function relativeQuizImagePath(string $absolutePath): string {
    $root = str_replace('\\', '/', __DIR__);
    $abs  = str_replace('\\', '/', $absolutePath);
    return ltrim(str_replace($root . '/', '', $abs), '/');
}

function deleteDirectory(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

function cleanupQuizImages(mysqli $conn): void {
    $imagesRoot = quizImagesRoot();
    $liveCleaned = 0;
    $previewCleaned = 0;

    $result = $conn->query(
        "SELECT id FROM AIQuiz WHERE date < DATE_SUB(CURDATE(), INTERVAL 14 DAY)"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $quizId = (int)$row['id'];
            $dir = $imagesRoot . DIRECTORY_SEPARATOR . $quizId;
            if (is_dir($dir)) {
                deleteDirectory($dir);
                $liveCleaned++;
            }
            $stmt = $conn->prepare(
                'UPDATE AIQuestion SET image_path = NULL, image_attribution = NULL WHERE quiz_id = ?'
            );
            $stmt->bind_param('i', $quizId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $previewRoot = $imagesRoot . DIRECTORY_SEPARATOR . 'preview';
    if (is_dir($previewRoot)) {
        foreach (glob($previewRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - 86400) {
                deleteDirectory($dir);
                $previewCleaned++;
            }
        }
    }

    logQuizGenInfo("Cleaned $liveCleaned quiz image folders, $previewCleaned preview folders");
}

function attachPreviewImages(array $questions): array {
    $runId = uniqid('', true);
    $previewDir = quizImagesRoot() . DIRECTORY_SEPARATOR . 'preview' . DIRECTORY_SEPARATOR . $runId;

    foreach ($questions as &$q) {
        $query = trim($q['image_query'] ?? '');
        if ($query === '') {
            continue;
        }
        $result = fetchQuestionImage($query, $previewDir, (string)($q['position'] ?? '0'));
        if ($result) {
            $q['image_url'] = relativeQuizImagePath($result['path']);
            $q['image_attribution'] = $result['attribution'];
        }
    }
    unset($q);

    return $questions;
}

function logQuizGenInfo(string $msg): void {
    if (defined('QUIZ_GEN_VERBOSE_LOG') && !QUIZ_GEN_VERBOSE_LOG) {
        return;
    }
    appendQuizGenLogCapture('INFO', $msg);
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] INFO: $msg\n";
    file_put_contents(__DIR__ . '/logs/generate-quiz.log', $line, FILE_APPEND | LOCK_EX);
    if (php_sapi_name() === 'cli') {
        fwrite(STDOUT, $line);
    }
}

function logQuizGenError(string $msg): void {
    appendQuizGenLogCapture('ERROR', $msg);
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] ERROR: $msg\n";
    file_put_contents(__DIR__ . '/logs/generate-quiz.log', $line, FILE_APPEND | LOCK_EX);
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $line);
    }
}
