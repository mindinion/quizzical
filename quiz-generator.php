<?php
/**
 * quiz-generator.php
 *
 * Shared quiz-generation logic used by both generate-quiz.php (CLI cron, writes to DB)
 * and action-test-generate-quiz.php (ad hoc admin preview, does not write to DB).
 * Fetches headlines, builds the prompt, calls OpenAI, and validates the result.
 * The core generation logic has no DB dependency; fetchRecentQuestions() below is
 * a read-only helper both callers can use for cross-day duplicate avoidance.
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

const MAX_ATTEMPTS = 4;

/**
 * Generates and validates a 15-question quiz. Returns the array of question objects.
 * Throws RuntimeException if OpenAI fails to produce a valid quiz within MAX_ATTEMPTS.
 *
 * @param string $quizType 'morning' or 'afternoon' — controls the headline recency window.
 * @param string $today Y-m-d date string used in the prompt.
 * @param string[] $avoidQuestions Question texts to avoid repeating (e.g. sibling quiz same day).
 */
function generateQuizQuestions(string $quizType, string $today, array $avoidQuestions = []): array {
    $headlinesByRegion = fetchHeadlines($quizType);
    $nzHeadlineBlock = implode("\n", array_map(fn($h) => '- ' . $h, $headlinesByRegion['NZ']));
    $auHeadlineBlock = implode("\n", array_map(fn($h) => '- ' . $h, $headlinesByRegion['AU']));

    $distributionLines = implode("\n", array_map(
        fn($cat, $n) => "- $cat: exactly $n question" . ($n === 1 ? '' : 's'),
        array_keys(CATEGORY_TARGETS),
        array_values(CATEGORY_TARGETS)
    ));

    $systemPrompt = <<<PROMPT
You are generating a daily quiz for a New Zealand audience. The quiz has 15 questions covering:
NZ Trivia, Australia Trivia, Sports, NZ Current Events, Aussie Current Events, Geography, History, General Knowledge.

Required category distribution (exact, not approximate — the quiz will be rejected and regenerated if these counts are wrong):
$distributionLines

Only "NZ Current Events" and "Aussie Current Events" questions may be based on the provided headlines — "NZ Current Events" must be based ONLY on the NZ headlines block, and "Aussie Current Events" must be based ONLY on the Australian headlines block. Double-check before finalising each Current Events question: if it's filed under "Aussie Current Events" it must not be about a New Zealand story (NZ places, NZ organisations, NZ people), and if it's filed under "NZ Current Events" it must not be about an Australian story. Do not reuse the same underlying story for both.

NZ Trivia, Australia Trivia, Sports, Geography, History, and General Knowledge questions MUST be based on established, verifiable facts that would be true regardless of today's news — do NOT let recent headlines leak into these categories, even indirectly (e.g. do not turn a news story about a school into an "NZ Trivia" question, or a news story about weather into a "General Knowledge" question).

Geography, History, and General Knowledge questions MUST be about the rest of the world, NOT New Zealand or Australia — NZ and Australia already have their own dedicated trivia and current-events categories, so Geography/History/General Knowledge exist to cover everything else (other countries, world history, science, arts, etc.). Do NOT ask about NZ/Australian mountains, lakes, treaties, wars, languages, or national symbols in these three categories — pick a different country or a global topic instead. Sports questions MAY reference NZ/Australian teams or leagues, since that's expected content for this audience.

Format rules:
- 13 questions must be multiple choice (format: "mc") with exactly 4 options, exactly 1 correct.
- 2 questions must be true/false (format: "tf") with exactly 2 options ("True" and "False"), exactly 1 correct. Every "tf" question MUST be phrased as a single declarative statement that is either true or false (ideally starting with "True or False:") — NEVER phrase a "tf" question as a "which/what/who/when/where" question, since that cannot be sensibly answered with just True or False.
- For 3–4 of the MC questions, include one answer option that sounds almost plausible but is subtly absurd — the kind that makes you wonder for a moment before realising it's wrong. Do NOT make it obviously silly or comedic. It should sound like a real answer.

Difficulty: The quiz should be genuinely challenging. A knowledgeable player should score around 10–11 out of 15. An average player should score 7–9. Getting 15/15 should be rare.

Do NOT ask questions whose answer is the single most famous fact about a topic — e.g. capital cities, a country's national animal/bird, the primary/official language of a country, "the" founding treaty of a nation, or the site of a famous natural landmark. These are trivially guessable. Instead ask about specific details, second-order facts, dates, numbers, or lesser-known angles on a topic that require genuine knowledge, not just cultural familiarity.

For "NZ Current Events" and "Aussie Current Events" questions, you MUST draw from the matching headlines block below. Phrase the question naturally — do not say "according to recent news" or "as reported today".

Return exactly 15 questions.
PROMPT;

    $avoidBlock = '';
    if ($avoidQuestions) {
        $avoidList = implode("\n", array_map(fn($q) => '- ' . $q, $avoidQuestions));
        $avoidBlock = "\n\nToday's other quiz already covered these topics/questions — do NOT repeat them or ask near-duplicates of them:\n$avoidList";
    }

    $userPrompt = "Today is $today.\n\nNZ headlines:\n$nzHeadlineBlock\n\nAustralian headlines:\n$auHeadlineBlock$avoidBlock\n\nGenerate the quiz now.";

    $schema = [
        'type' => 'json_schema',
        'json_schema' => [
            'name'   => 'quiz_response',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'questions' => [
                        'type'  => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'position' => ['type' => 'integer'],
                                'question' => ['type' => 'string'],
                                'category' => [
                                    'type' => 'string',
                                    'enum' => ['NZ Trivia','Australia Trivia','Sports','NZ Current Events','Aussie Current Events','Geography','History','General Knowledge'],
                                ],
                                'format'   => ['type' => 'string', 'enum' => ['mc','tf']],
                                'options'  => [
                                    'type'  => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'text'    => ['type' => 'string'],
                                            'correct' => ['type' => 'boolean'],
                                        ],
                                        'required'             => ['text','correct'],
                                        'additionalProperties' => false,
                                    ],
                                ],
                            ],
                            'required'             => ['position','question','category','format','options'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required'             => ['questions'],
                'additionalProperties' => false,
            ],
        ],
    ];

    $quizJson = null;
    $attemptUserPrompt = $userPrompt;

    for ($attempt = 1; $attempt <= MAX_ATTEMPTS; $attempt++) {
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
            logQuizGenError("Attempt $attempt: cURL error: $curlError");
            continue;
        }

        $decoded = json_decode($response, true);
        if (!isset($decoded['choices'][0]['message']['content'])) {
            logQuizGenError("Attempt $attempt: Unexpected OpenAI response: $response");
            continue;
        }

        $candidate = json_decode($decoded['choices'][0]['message']['content'], true);
        $validationErrors = validateQuiz($candidate);
        if ($validationErrors) {
            $errorList = implode("\n", array_map(fn($e) => '- ' . $e, $validationErrors));
            logQuizGenError("Attempt $attempt: " . count($validationErrors) . " violation(s):\n$errorList");
            $attemptUserPrompt = $userPrompt . "\n\nNOTE: Your previous attempt was rejected for the following reasons — fix ALL of them this time:\n$errorList";
            continue;
        }

        $quizJson = $candidate;
        break;
    }

    if ($quizJson === null) {
        logQuizGenError("All " . MAX_ATTEMPTS . " attempts failed validation — giving up.");
        throw new RuntimeException("Failed to generate a valid quiz after " . MAX_ATTEMPTS . " attempts — check logs/generate-quiz.log");
    }

    return $quizJson['questions'];
}

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
    '/\bcapital( city)? of\b/i'   => 'capital-of-a-country cliché',
    '/\bopera house\b/i'          => 'Sydney Opera House cliché',
];

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
 * Validates overall quiz structure, per-question answer/option counts, exact
 * category distribution, and content-level rules the model tends to ignore
 * (NZ/AU content leaking into global categories, current-events cross-country
 * mislabeling, true/false questions phrased as WH-questions, current-events
 * content dressed up as evergreen trivia).
 *
 * Collects ALL violations in one pass (rather than stopping at the first) so a
 * single retry can be told everything wrong at once — real quizzes have shown
 * up to 8 simultaneous violations, which would exhaust a small retry budget if
 * each attempt only learned about one problem at a time.
 *
 * @return string[] Empty array if valid, otherwise a list of violation descriptions.
 */
function validateQuiz(?array $quizJson): array {
    if (!isset($quizJson['questions']) || count($quizJson['questions']) !== 15) {
        return ["expected 15 questions, got: " . json_encode($quizJson)];
    }

    $errors = [];
    $categoryCounts = [];
    foreach ($quizJson['questions'] as $i => $q) {
        $qNum = $i + 1;
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

        $combined = $q['question'] . ' ' . $correctText;
        $category = $q['category'];

        // Current events must stay out of the 6 "evergreen" categories — the model
        // keeps reaching for real news dressed as timeless trivia (e.g. a school
        // competition result phrased as "NZ Trivia"). Catches explicit recency
        // language and mentions of the current/previous year; a paraphrased story
        // with no such marker (e.g. "ratings have fallen sharply") can still slip
        // through — same class of residual risk as the fabrication issue.
        if (!in_array($category, ['NZ Current Events', 'Aussie Current Events'], true)) {
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
                $errors[] = "question $qNum is labeled 'Aussie Current Events' but matched NZ term \"$hit\" — likely mislabeled, must be about Australia only";
            }
        } elseif ($category === 'NZ Current Events') {
            $hit = textContainsKeyword($combined, AU_KEYWORDS);
            if ($hit !== null) {
                $errors[] = "question $qNum is labeled 'NZ Current Events' but matched Australian term \"$hit\" — likely mislabeled, must be about New Zealand only";
            }
        }

        $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
    }

    foreach (CATEGORY_TARGETS as $cat => $expected) {
        $actual = $categoryCounts[$cat] ?? 0;
        if ($actual !== $expected) {
            $errors[] = "category '$cat' has $actual questions (expected exactly $expected) — full distribution: " . json_encode($categoryCounts);
        }
    }

    return $errors;
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
