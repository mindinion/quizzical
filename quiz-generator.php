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
                                'question' => ['type' => 'string'],
                                'format'   => ['type' => 'string', 'enum' => ['mc', 'tf']],
                                'options'  => [
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
                            'required'             => ['question', 'format', 'options'],
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
        if ($errors) {
            $errorList = implode("\n", array_map(fn($e) => '- ' . $e, $errors));
            logQuizGenError("[$category] Attempt $attempt: " . count($errors) . " violation(s):\n$errorList");
            $attemptUserPrompt = $userPrompt . "\n\nNOTE: Your previous attempt was rejected for the following reasons — fix ALL of them this time:\n$errorList";
            continue;
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

function logQuizGenError(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] ERROR: $msg\n";
    file_put_contents(__DIR__ . '/logs/generate-quiz.log', $line, FILE_APPEND | LOCK_EX);
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $line);
    }
}
