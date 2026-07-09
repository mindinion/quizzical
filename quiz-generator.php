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

const MAX_ATTEMPTS_PER_CATEGORY = 10;

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
    // "capital city of France" — but not nicknames like "Adventure Capital of the World"
    '/\bcapital city of\b/i'                          => 'capital-of-a-country cliché',
    '/\bcapital of (?!the world\b)/i'                  => 'capital-of-a-country cliché',
    '/\bopera house\b/i'                               => 'Sydney Opera House cliché',
    '/\bnational (symbol|animal|bird)\b/i'             => 'national symbol/animal/bird cliché',
    '/\blongest river in the world\b/i'                 => 'disputed fact (Nile vs Amazon) treated as settled',
];

/**
 * Superlative / record / obscure-stat patterns — high rate of wrong marked answers in testing.
 * Prefer specific-year or plain factual questions instead.
 */
const SUPERLATIVE_QUESTION_PATTERNS = [
    '/\bfirst\b.{0,45}\b(to win|to ever|Olympic gold|All Blacks|Test match|participate|qualif(y|ied|ying|ication)|qualified for)\b/i'
        => 'first-ever superlative — ask "In which year did…?" instead',
    '/\bfirst\b.{0,35}\b(World Cup|FIFA|Olympics|Olympic Games)\b/i'
        => 'first-at-tournament superlative — ask about a specific year or edition instead',
    '/\bfirst\b.{0,25}\b(to enact|to grant|to allow|to introduce|to pass)\b/i'
        => 'first-to-do-something superlative — embeds disputed "first" claims',
    '/\b(longest consecutive|most premiership)\b/i'
        => 'sports record superlative — ask about a specific season or year instead',
    '/\b(highest|greatest|lowest) overall winning\b/i'
        => 'winning-percentage record claim — ask about a specific season instead',
    '/\brecord (for|holder)\b/i'
        => 'record-holder superlative',
    '/\bper capita\b/i'
        => 'per-capita statistic — often fabricated or unverifiable',
    '/\bwinning percentage in\b.{0,30}\bhistory\b/i'
        => 'all-time winning-percentage record',
    '/\b(largest number|most per capita)\b/i'
        => 'largest/most superlative with obscure counting metric',
];

/** Country/place answer => adjective/demonym forms that give the answer away in question text or image_query. */
const ANSWER_GIVEAWAY_FORMS = [
    'france'          => ['french', 'francais', 'français', 'parisian'],
    'italy'           => ['italian'],
    'spain'           => ['spanish'],
    'germany'         => ['german'],
    'australia'       => ['australian', 'aussie'],
    'england'         => ['english'],
    'united kingdom'  => ['british', 'english'],
    'china'           => ['chinese'],
    'japan'           => ['japanese'],
    'russia'          => ['russian'],
    'brazil'          => ['brazilian'],
    'mexico'          => ['mexican'],
    'canada'          => ['canadian'],
    'india'           => ['indian'],
    'greece'          => ['greek'],
    'turkey'          => ['turkish'],
    'poland'          => ['polish'],
    'sweden'          => ['swedish'],
    'norway'          => ['norwegian'],
    'denmark'         => ['danish'],
    'finland'         => ['finnish'],
    'netherlands'     => ['dutch', 'holland'],
    'holland'         => ['dutch'],
    'switzerland'     => ['swiss'],
    'portugal'        => ['portuguese'],
    'argentina'       => ['argentine', 'argentinian'],
    'egypt'           => ['egyptian'],
    'israel'          => ['israeli'],
    'korea'           => ['korean'],
    'south korea'     => ['korean'],
    'north korea'     => ['korean'],
    'vietnam'         => ['vietnamese'],
    'thailand'        => ['thai'],
    'ireland'         => ['irish'],
    'scotland'        => ['scottish'],
    'wales'           => ['welsh'],
    'america'         => ['american'],
    'united states'   => ['american'],
    'austria'         => ['austrian'],
    'belgium'         => ['belgian'],
    'monaco'          => ['monegasque'],
    'st. petersburg'  => ['petersburg', 'leningrad'],
    'saint petersburg'=> ['petersburg', 'leningrad'],
];

const CURRENT_EVENTS_CATEGORIES = ['NZ Current Events', 'Aussie Current Events'];

/** @var array{categories_retried: int, fact_check_skips: int, preview_images_fetched: int} */
$GLOBALS['quizGenStats'] = [
    'categories_retried'      => 0,
    'fact_check_skips'        => 0,
    'preview_images_fetched'  => 0,
];

function resetQuizGenStats(): void {
    $GLOBALS['quizGenStats'] = [
        'categories_retried'      => 0,
        'fact_check_skips'        => 0,
        'preview_images_fetched'  => 0,
    ];
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

function enableQuizGenLogStream(callable $emitter): void {
    startQuizGenLogCapture();
    $GLOBALS['quizGenLogStreamEmitter'] = $emitter;
}

function disableQuizGenLogStream(): void {
    $GLOBALS['quizGenLogStreamEmitter'] = null;
}

function stopQuizGenLogCapture(): array {
    $lines = is_array($GLOBALS['quizGenLogCapture'] ?? null)
        ? $GLOBALS['quizGenLogCapture']
        : [];
    $GLOBALS['quizGenLogCapture'] = null;
    disableQuizGenLogStream();
    return $lines;
}

function appendQuizGenLogCapture(string $level, string $msg): void {
    $entry = [
        'time'    => date('Y-m-d H:i:s'),
        'level'   => $level,
        'message' => $msg,
    ];
    if (is_array($GLOBALS['quizGenLogCapture'] ?? null)) {
        $GLOBALS['quizGenLogCapture'][] = $entry;
    }
    $emitter = $GLOBALS['quizGenLogStreamEmitter'] ?? null;
    if (is_callable($emitter)) {
        $emitter(['type' => 'log', 'entry' => $entry]);
    }
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
    logQuizGenInfo('Fetching headlines…');
    $headlinesByRegion = fetchHeadlines($quizType);
    $tfHosts = pickTfHostCategories();

    $allQuestions = [];
    foreach (CATEGORY_TARGETS as $category => $count) {
        $tfCount = in_array($category, $tfHosts, true) ? 1 : 0;
        $mcCount = $count - $tfCount;

        logQuizGenInfo("Generating: $category ($count question(s))");

        // Include topics already generated earlier in this same quiz, on top of
        // the cross-day avoid-list, so e.g. Geography and History don't both
        // reach for the same treaty.
        $avoidForThisCall = array_merge($avoidQuestions, array_column($allQuestions, 'question'));

        $categoryQuestions = generateCategoryQuestions(
            $category, $mcCount, $tfCount, $headlinesByRegion, $today, $avoidForThisCall
        );
        $allQuestions = array_merge($allQuestions, $categoryQuestions);
        logQuizGenInfo("Accepted: $category");
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

    $formatInstruction = $tfCount > 0
        ? "Produce exactly $total question(s): $mcCount in \"mc\" format (4 options, exactly 1 correct) and $tfCount in \"tf\" format (2 options, \"True\" and \"False\", exactly 1 correct — phrased as a single declarative true/false statement, ideally starting with \"True or False:\". NEVER phrase a tf question as a which/what/who/when/where question, since that can't be sensibly answered with just True or False)."
        : "Produce exactly $total question(s), all in \"mc\" format (4 options, exactly 1 correct).";

    $categoryGuidance = buildCategoryGuidance($category);
    $validatorRules = buildValidatorRulesBlock($category);

    $systemPrompt = <<<PROMPT
You are writing question(s) for the "$category" section of a daily quiz for a New Zealand audience.

$categoryGuidance

$validatorRules

$formatInstruction

For any "mc" question, include one answer option that sounds almost plausible but is subtly absurd on reflection — not obviously silly, the kind that makes you second-guess yourself. Aim for roughly 1 in 3 of the mc questions to have this.

Difficulty: genuinely challenging — average players should get some wrong. Do NOT ask questions whose answer is the single most famous fact about a topic (e.g. capital cities, a country's national animal/bird, "the" founding treaty of a nation, the primary language of a country, a famous landmark's most basic fact). These are trivially guessable. Ask about specific details, dates, numbers, or lesser-known angles instead — but only facts you are confident are real and verifiable from standard reference sources, not obscure one-off records you are unsure about.

Never state the correct answer's exact wording anywhere in the question text itself. Also never use the adjective/demonym form when the answer is the country name (e.g. do NOT say "French" in the question if the correct answer is "France").

For each question also output image_query: a 2–4 word visual search term for a stock photo related to the question topic. Must NOT name or depict the correct answer (including demonyms like "French" for France), must not repeat option text verbatim, and for tf questions must illustrate the subject — never "true" or "false".
PROMPT;

    $excludedHeadlines = [];
    $failedQuestions   = [];
    $retryNote         = '';

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

    for ($attempt = 1; $attempt <= MAX_ATTEMPTS_PER_CATEGORY; $attempt++) {
        $headlineBlock = buildCategoryHeadlineBlock($category, $headlinesByRegion, $excludedHeadlines);
        $avoidBlock = buildQuestionAvoidBlock($avoidQuestions, $failedQuestions);
        $attemptUserPrompt = "Today is $today.$headlineBlock$avoidBlock\n\nGenerate the question(s) now.";
        if ($retryNote !== '') {
            $attemptUserPrompt .= "\n\nNOTE: $retryNote";
        }

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

            foreach ($candidate['questions'] ?? [] as $q) {
                $qText = trim($q['question'] ?? '');
                if ($qText === '') {
                    continue;
                }
                $failedQuestions[] = $qText;
                if (in_array($category, CURRENT_EVENTS_CATEGORIES, true)) {
                    $region = $category === 'NZ Current Events' ? 'NZ' : 'AU';
                    foreach ($headlinesByRegion[$region] ?? [] as $headline) {
                        if (questionMatchesHeadline($qText, $headline)) {
                            $excludedHeadlines[] = $headline;
                        }
                    }
                }
            }
            $failedQuestions = array_values(array_unique($failedQuestions));
            $excludedHeadlines = array_values(array_unique($excludedHeadlines));

            $retryNote = "Your previous attempt was rejected for the following reasons — fix ALL of them this time:\n$errorList";
            $retryNote .= buildRetryHintForErrors($errorList, $category);
            if (in_array($category, CURRENT_EVENTS_CATEGORIES, true)) {
                $retryNote .= "\n\nUse a COMPLETELY DIFFERENT news story from the headlines above. "
                    . "Do NOT retry the same story or a reworded version of your rejected question.";
                if ($excludedHeadlines) {
                    $retryNote .= "\n\nAlready-tried stories (do NOT use again):\n"
                        . implode("\n", array_map(fn($h) => '- ' . $h, $excludedHeadlines));
                }
            }
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

function buildCategoryHeadlineBlock(string $category, array $headlinesByRegion, array $excludedHeadlines = []): string {
    if ($category === 'NZ Current Events') {
        $label = 'NZ headlines';
        $headlines = $headlinesByRegion['NZ'] ?? [];
    } elseif ($category === 'Aussie Current Events') {
        $label = 'Australian headlines';
        $headlines = $headlinesByRegion['AU'] ?? [];
    } else {
        return '';
    }

    if ($excludedHeadlines) {
        $excludeSet = array_flip($excludedHeadlines);
        $headlines = array_values(array_filter(
            $headlines,
            fn($h) => !isset($excludeSet[$h])
        ));
    }

    if (!$headlines) {
        return "\n\n($label: all provided stories were already tried — pick any fresh angle from today's news.)";
    }

    return "\n\n$label:\n" . implode("\n", array_map(fn($h) => '- ' . $h, $headlines));
}

function buildQuestionAvoidBlock(array $avoidQuestions, array $failedQuestions = []): string {
    $combined = array_values(array_unique(array_filter(array_merge($avoidQuestions, $failedQuestions))));
    if (!$combined) {
        return '';
    }

    $avoidList = implode("\n", array_map(fn($q) => '- ' . $q, $combined));
    return "\n\nDo NOT repeat or ask a near-duplicate of any of these already-used questions/topics:\n$avoidList";
}

/**
 * Heuristic: does this question appear to be based on this headline?
 */
function questionMatchesHeadline(string $question, string $headline): bool {
    $q = mb_strtolower(trim($question));
    $h = mb_strtolower(trim($headline));
    if ($q === '' || $h === '') {
        return false;
    }

    if (str_contains($q, mb_substr($h, 0, 40)) || str_contains($h, mb_substr($q, 0, 40))) {
        return true;
    }

    $stopWords = ['with', 'from', 'that', 'this', 'were', 'been', 'have', 'after', 'into', 'over', 'amid'];
    $words = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $h));
    $significant = array_values(array_filter(
        $words,
        fn($w) => mb_strlen($w) >= 4 && !in_array($w, $stopWords, true)
    ));

    if (count($significant) < 2) {
        return false;
    }

    $hits = 0;
    foreach ($significant as $word) {
        if (str_contains($q, $word)) {
            $hits++;
        }
    }

    return $hits >= 2;
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
            return 'These questions MUST be based only on the NZ headlines block below — genuinely current, real stories. Must NOT be about Australia. Phrase naturally — do not say "according to recent news" or "as reported today". The marked correct answer must stay close to what the headlines actually say — do not add speculative detail (e.g. "under suspicious circumstances") that headlines do not support.';
        case 'Aussie Current Events':
            return 'These questions MUST be based only on the Australian headlines block below — genuinely current, real stories. Must NOT be about New Zealand. Phrase naturally — do not say "according to recent news" or "as reported today". The marked correct answer must stay close to what the headlines actually say — do not add speculative detail that headlines do not support.';
        case 'NZ Trivia':
            return 'General New Zealand trivia — established, verifiable facts that would be true regardless of today\'s news. Do NOT base these on current headlines, even indirectly. Use widely documented facts only — NOT obscure micro-records (e.g. "first descent" of a specific river, one-off local sporting feats). For year questions, put the year in the answer options, not in the question stem.';
        case 'Australia Trivia':
            return 'General Australia trivia — established, verifiable facts that would be true regardless of today\'s news. Do NOT base these on current headlines, even indirectly. Avoid the Sydney Opera House, Australia Day, and "national animal/bird" angles. Do NOT name a city or holiday in the question if it is the correct answer (e.g. do not mention "Sydney Festival" if Sydney is an option). For year questions, put the year in the answer options, not in the question stem.';
        case 'Sports':
            return <<<'GUIDE'
General sports trivia (NZ/Australian teams and leagues are fine) — established, verifiable facts, NOT tied to today's news.

Sports has strict automatic rejection rules — follow exactly:
- The word "first" must NEVER appear in any question (not "first win", "first time", "first World Cup", "first ever", "first to qualify"). Rephrase as a plain event/year question with no "first".
  GOOD: "In which year was the Rugby World Cup held in South Africa?"
  GOOD: "Which AFL club is known for black and white vertical stripes?"
  GOOD: "In which year did Cathy Freeman win Olympic gold in the 400 metres?"
  BAD:  "In which year did the All Blacks first win the Rugby World Cup?"
  BAD:  "When did the Silver Ferns win their first World Cup title?"
- For year MC questions: describe the event in the question; put years ONLY in the four options. Do not embed a year in the question stem.
- No all-time records (most premierships, longest streak, winning percentage, per capita).
- Prefer: a specific tournament edition/host city, team colours/nicknames, a named athlete at a named Games, stadium or rule facts with the year in the options.
GUIDE;
        case 'Geography':
            return 'Must be about the rest of the world — NOT New Zealand or Australia. No Great Barrier Reef, Sydney, or other NZ/AU references. No "longest river in the world" (Nile vs Amazon) questions. No Sydney Opera House. For year questions, put the year in the answer options, not in the question stem.';
        case 'History':
            return 'Must be about the rest of the world — NOT New Zealand or Australia. Use standard names for treaties and events (e.g. "Treaty of Paris", "Congress of Vienna" — never invent names like "Treaty of Vienna"). No "first country to…" superlatives. TF statements must be plain factual claims, not joke premises. For year questions, put the year in the answer options, not in the question stem.';
        case 'General Knowledge':
            return 'Must be about the rest of the world — NOT New Zealand or Australia. No Great Barrier Reef or other NZ/AU references. Use real, standard names for treaties, laws, and historical figures — do not invent obscure attributions. Distinguish declaration vs recognition vs annexation for independence questions. For year questions, put the year in the answer options, not in the question stem.';
        default:
            return '';
    }
}

/**
 * Shared automatic-rejection rules mirrored from the PHP validators — the model
 * sees these on every category so it can steer away from failures upfront.
 */
function buildValidatorRulesBlock(string $category): string {
    $rules = <<<'RULES'
Automatic rejection rules (violating ANY of these fails the whole batch):
- Superlatives: never use "first", "most", "longest", "record holder", "per capita", or "winning percentage in history". Especially in Sports — the word "first" alone causes rejection.
- Answer giveaway: the correct option's exact text (or its demonym, e.g. French→France) must not appear in the question text or image_query.
- image_query: 2–4 words, no option text, no correct answer hint.
- tf format: a declarative "True or False: …" statement — never a which/what/who/when question.
- Geography / History / General Knowledge: zero NZ or Australia content (no cities, landmarks, or teams from NZ/AU).
- Clichés: no "capital of [country]", no "national animal/bird/symbol", no "longest river in the world", no Sydney Opera House.
- Evergreen categories: no "recently", "this year", "last year", or the current/previous calendar year in the question.
- Wrong dates in the question stem are rejected by fact-check — if asking "in which year", keep the year out of the question text.
RULES;

    if ($category === 'Sports') {
        $rules .= "\n- Sports reminder: if you are about to write \"first\", stop and rephrase without it.";
    }

    return $rules;
}

/**
 * Targeted rewrite hints appended to retry notes based on which validators fired.
 */
function buildRetryHintForErrors(string $errorList, string $category): string {
    $hints = [];

    if (preg_match('/superlative pattern/i', $errorList)) {
        if ($category === 'Sports') {
            $hints[] = 'SPORTS REPHRASE REQUIRED: Remove the word "first" from every question. '
                . 'Ask "In which year was [event] held in [place]?" or "Which team is known for [colours/nickname]?" '
                . 'Example: instead of "first Rugby World Cup win", ask "In which year was the Rugby World Cup held in South Africa?"';
        } else {
            $hints[] = 'Remove all "first/most/longest/record" framing. Ask a plain "In which year did…?" question with the year only in the options.';
        }
    }

    if (preg_match('/gives away|image_query/i', $errorList)) {
        if (preg_match('/correct answer "(True|False)"/i', $errorList)) {
            $hints[] = 'FORMAT FIX: Do not use "True or False:" phrasing in mc questions. '
                . 'Either set format to "tf" (2 options: True/False) or write a plain MC question with four content options and no True/False prefix.';
        } else {
            $hints[] = 'GIVEAWAY FIX: The question or image_query names the correct answer. '
                . 'Rephrase the question without the answer word; use a generic image_query (e.g. "rugby match crowd" not "Sydney Festival").';
        }
    }

    if (preg_match('/NZ\/AU-specific|meant to be about/i', $errorList)) {
        $hints[] = 'CATEGORY FIX: This category must not mention New Zealand or Australia at all — pick a different country/topic.';
    }

    if (preg_match('/banned pattern|cliché/i', $errorList)) {
        $hints[] = 'CLICHÉ FIX: Pick a less famous angle on the topic — not capital cities, national symbols, Opera House, or longest-river debates.';
    }

    if (preg_match('/\[fact-check premise\]/i', $errorList)) {
        $hints[] = 'PREMISE FIX: A date or fact stated in the question text is wrong. '
            . 'Either correct it or remove the date from the question stem and put years only in the MC options.';
    }

    if (preg_match('/\[fact-check distractor\]/i', $errorList)) {
        $hints[] = 'ANSWER FIX: The marked correct year/fact is wrong — a different option is actually correct. '
            . 'Verify all four options and mark the one that matches established fact.';
    }

    if (preg_match('/\[fact-check\]:/i', $errorList) && !preg_match('/\[fact-check distractor\]/i', $errorList)) {
        $hints[] = 'FACT-CHECK FIX: The marked answer is not supported by sources. Change the marked answer or rewrite the question entirely.';
    }

    if (!$hints) {
        return '';
    }

    return "\n\n" . implode("\n", $hints);
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

    foreach (SUPERLATIVE_QUESTION_PATTERNS as $pattern => $reason) {
        if (preg_match($pattern, $q['question'])) {
            $errors[] = "question $qNum (\"{$q['question']}\") matches superlative pattern: $reason";
            break;
        }
    }

    if ($q['format'] === 'mc' && preg_match('/^\s*true or false\s*:/i', $q['question'])) {
        $errors[] = "question $qNum is format 'mc' but phrased as true/false — use format 'tf' for True/False statements, or rewrite as a which/what/when MC question with four content options";
    }

    // A well-formed MC question should never name its own answer in the prompt
    // (e.g. "...hosting the annual Sydney Festival?" with "Sydney" as the
    // correct option). Also catch demonyms/stems (e.g. "French" → France).
    if ($q['format'] === 'mc' && answerGiveawayInText($correctText, $q['question'])) {
        $errors[] = "question $qNum gives away its own answer — correct answer \"$correctText\" is hinted in the question text (\"{$q['question']}\")";
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
        if ($q['format'] === 'mc' && answerGiveawayInText($correctText, $imageQuery)) {
            $errors[] = "question $qNum image_query gives away the correct answer — \"$correctText\" is hinted in image_query \"$imageQuery\"";
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
 * Detects whether haystack text hints at the correct MC answer — verbatim match,
 * demonym forms (French → France), or close orthographic stems.
 */
function answerGiveawayInText(string $correctText, string $haystack): bool {
    if ($correctText === '' || $haystack === '' || strlen($correctText) < 3) {
        return false;
    }

    // True/False are tf meta-options — the "True or False:" stem prefix is not a content giveaway.
    if (strcasecmp(trim($correctText), 'true') === 0 || strcasecmp(trim($correctText), 'false') === 0) {
        $haystack = preg_replace('/^\s*true or false\s*:\s*/i', '', $haystack);
    }

    $answer = mb_strtolower(trim($correctText));
    $text = mb_strtolower($haystack);

    if (preg_match('/\b' . preg_quote($answer, '/') . '\b/ui', $text)) {
        return true;
    }

    foreach (ANSWER_GIVEAWAY_FORMS[$answer] ?? [] as $form) {
        if (preg_match('/\b' . preg_quote($form, '/') . '\b/ui', $text)) {
            return true;
        }
    }

    $answerNorm = preg_replace('/[^\p{L}]/u', '', $answer);
    if (mb_strlen($answerNorm) < 4) {
        return false;
    }

    preg_match_all('/\p{L}{4,}/u', $text, $matches);
    foreach ($matches[0] as $word) {
        if (abs(mb_strlen($word) - mb_strlen($answerNorm)) > 2) {
            continue;
        }
        similar_text($answerNorm, $word, $pct);
        if ($pct >= 76) {
            return true;
        }
    }

    return false;
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
- If snippets are silent, off-topic, or too vague to verify — you MUST respond valid: true. Absence of evidence is NOT a rejection.
- NEVER reject because snippets "do not mention", "do not provide", "fail to support", "are silent on", or "insufficient" — those always mean valid: true.
- Do NOT reject because another option seems plausible, the question is oversimplified, or historians might debate nuance.
- Do NOT reject when the marked answer is a shorter summary of what snippets state (e.g. "16 officers" vs "16 driver testing officers suspended"). Compatible summaries are valid, not contradictions.
- For tf questions: reject only if snippets show the statement is the opposite truth to the marked True/False option.
- For mc questions: reject only if snippets clearly support a different listed option over the marked one — not merely because the marked option is unmentioned.

If valid is false, your issue must quote or paraphrase a specific contradictory fact from the snippets — not the absence of mention.

Respond with strict JSON: {"valid": true/false, "issue": "short reason if invalid, else empty string"}
PROMPT;

    $userPrompt = "Question: $question\nFormat: $format\nOptions: $optionsBlock\nMarked correct: \"$markedAnswer\"\n\nSearch snippets:\n$snippetBlock";

    $result = callOpenAIJsonVerifier($systemPrompt, $userPrompt);
    if ($result === null) {
        return ['valid' => true, 'issue' => ''];
    }

    $valid = (bool)($result['valid'] ?? true);
    $issue = trim($result['issue'] ?? '');

    if (!$valid && $issue !== '' && factCheckIssueIsSilenceOnly($issue)) {
        return ['valid' => true, 'issue' => ''];
    }

    if (!$valid && $issue !== '' && factCheckIssueIsSpecificityMismatch($issue, $markedAnswer, $snippets)) {
        return ['valid' => true, 'issue' => ''];
    }

    return [
        'valid' => $valid,
        'issue' => $issue,
    ];
}

/**
 * Rejects verifier outputs that cite missing snippet support without an actual contradiction.
 */
function factCheckIssueIsSilenceOnly(string $issue): bool {
    if (preg_match('/\b(contradict|incorrect|wrong|instead|rather than|actually|different (date|year|number|answer))\b/i', $issue)) {
        return false;
    }
    return (bool) preg_match(
        '/\b(silent|absence of|do not mention|does not mention|not mention|do not provide|does not provide|not provide|insufficient|fail to support|not supported|no information|without evidence|unverified)\b/i',
        $issue
    );
}

/**
 * Rejects verifier outputs that treat a compatible summary as a contradiction.
 */
function factCheckIssueIsSpecificityMismatch(string $issue, string $markedAnswer, array $snippets): bool {
    if (!preg_match('/\bcontradict/i', $issue)) {
        return false;
    }

    $snippetText = mb_strtolower(implode(' ', $snippets));
    $answer = mb_strtolower(trim($markedAnswer));
    if ($answer === '') {
        return false;
    }

    if (preg_match_all('/\d+/', $answer, $nums) && !empty($nums[0])) {
        foreach ($nums[0] as $num) {
            if (!str_contains($snippetText, $num)) {
                return false;
            }
        }
    }

    $words = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $answer));
    $significant = array_values(array_filter($words, fn($w) => mb_strlen($w) >= 4));
    if (!$significant) {
        return false;
    }

    $matched = 0;
    foreach ($significant as $word) {
        if (str_contains($snippetText, $word)) {
            $matched++;
        }
    }

    return $matched >= max(1, (int)ceil(count($significant) * 0.5));
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
 * True when the question embeds a date, stat, or factual claim worth verifying before answer check.
 * TF questions always need premise check (the entire statement is a factual claim).
 */
function questionNeedsPremiseCheck(string $question, string $format): bool {
    if ($format === 'tf') {
        return true;
    }
    if (preg_match('/\b\d+\s*(BC|BCE|AD|CE)\b/i', $question)) {
        return true;
    }
    if (preg_match('/\b(18|19|20)\d{2}\b/', $question)) {
        return true;
    }
    if (preg_match('/\b\d{1,3}[,.]?\d*\s*(km|kilometers|kilometres|metres|meters|percent|%)\b/i', $question)) {
        return true;
    }
    if (preg_match('/\b(approximately|roughly|about)\s+\d/i', $question)) {
        return true;
    }
    if (preg_match('/\b(flag|national flag|officially adopted|adopted as)\b/i', $question)) {
        return true;
    }
    return false;
}

function verifyQuestionPremiseWithSnippets(string $question, array $snippets): array {
    $snippetBlock = implode("\n\n", array_map(
        fn($s, $i) => '[' . ($i + 1) . '] ' . $s,
        $snippets,
        array_keys($snippets)
    ));

    $systemPrompt = <<<'PROMPT'
You verify whether factual claims stated IN the quiz question text are accurate, using ONLY the provided search snippets — not your own memory.

Scope:
- Check dates, numbers, statistics, and named historical facts embedded in the question stem.
- For true/false questions, verify the factual claims in the statement (not whether True or False is the right answer — that is checked separately).
- Ignore the answer options entirely — do not judge which answer is correct.
- Do NOT check whether the question is tricky, oversimplified, or debatable among experts.

Rules:
- Default to valid: true. Only reject when snippets explicitly contradict a date, number, or fact stated in the question.
- Example reject: question says "in 1902" but snippets say the event was 1894.
- Example reject: question says "built starting in 700 BC" but snippets say construction began circa 221 BC.
- Example reject (tf): statement says "dedicated to the goddess Isis" but snippets say it honours Ptolemy V.
- Example reject: question says "27,000 kilometers" but snippets cite ~15,000 km.
- If snippets are silent or vague — you MUST respond valid: true. Absence of evidence is NOT a rejection.
- NEVER reject because snippets "do not mention" or "fail to support" — those mean valid: true.

If valid is false, your issue must cite the specific contradictory fact from the snippets.

Respond with strict JSON: {"valid": true/false, "issue": "short reason if invalid, else empty string"}
PROMPT;

    $userPrompt = "Question text to verify:\n$question\n\nSearch snippets:\n$snippetBlock";

    $result = callOpenAIJsonVerifier($systemPrompt, $userPrompt);
    if ($result === null) {
        return ['valid' => true, 'issue' => ''];
    }

    $valid = (bool)($result['valid'] ?? true);
    $issue = trim($result['issue'] ?? '');

    if (!$valid && $issue !== '' && factCheckIssueIsSilenceOnly($issue)) {
        return ['valid' => true, 'issue' => ''];
    }

    return [
        'valid' => $valid,
        'issue' => $issue,
    ];
}

/**
 * MC only — reject when snippets clearly support a wrong option over the marked answer.
 */
function verifyNoDistractorIsCorrectWithSnippets(
    string $question, string $markedAnswer, array $allOptions, array $snippets
): array {
    $wrongOptions = array_values(array_filter(
        $allOptions,
        fn($o) => empty($o['correct'])
    ));
    if (!$wrongOptions) {
        return ['valid' => true, 'issue' => ''];
    }

    $snippetBlock = implode("\n\n", array_map(
        fn($s, $i) => '[' . ($i + 1) . '] ' . $s,
        $snippets,
        array_keys($snippets)
    ));

    $optionsBlock = implode(', ', array_map(
        fn($o) => '"' . $o['text'] . '"' . ($o['correct'] ? ' (marked correct)' : ' (wrong option)'),
        $allOptions
    ));

    $systemPrompt = <<<'PROMPT'
You verify multiple-choice quiz answers using ONLY the provided search snippets — not your own memory.

Task: Determine whether any WRONG option is clearly the correct answer according to the snippets, instead of the marked correct option.

Rules:
- Default to valid: true. Only reject when snippets EXPLICITLY support a wrong option AND contradict the marked answer.
- Wrong options are SUPPOSED to be incorrect. Do NOT reject merely because a distractor is obviously wrong, implausible, or contradicted by snippets — that is normal.
- Reject ONLY when snippets state a date, number, name, or fact that matches a wrong option and clearly shows the MARKED answer is wrong.
- Example reject: marked "1991" but snippets say "1987"; wrong option "1987" is in the list.
- Example reject: marked "1959" but snippets say the flag was adopted in "1902"; wrong option "1902" is in the list.
- Example reject: marked "2003" but snippets say first qualification was "1999"; wrong option "1999" is in the list.
- Do NOT reject merely because the marked answer lacks snippet mention.
- Do NOT reject because both options seem plausible without explicit snippet text favoring the wrong one.
- If valid is false, your issue must name the wrong option and cite the contradictory snippet fact.

Respond with strict JSON: {"valid": true/false, "issue": "short reason if invalid, else empty string"}
PROMPT;

    $userPrompt = "Question: $question\nOptions: $optionsBlock\nMarked correct: \"$markedAnswer\"\n\nSearch snippets:\n$snippetBlock";

    $result = callOpenAIJsonVerifier($systemPrompt, $userPrompt);
    if ($result === null) {
        return ['valid' => true, 'issue' => ''];
    }

    $valid = (bool)($result['valid'] ?? true);
    $issue = trim($result['issue'] ?? '');

    if (!$valid && $issue !== '' && factCheckIssueIsSilenceOnly($issue)) {
        return ['valid' => true, 'issue' => ''];
    }

    return [
        'valid' => $valid,
        'issue' => $issue,
    ];
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

        if (questionNeedsPremiseCheck($q['question'], $q['format'])) {
            logQuizGenInfo("[$category] question $qNum [fact-check premise]: checking question text…");
            $premiseVerdict = verifyQuestionPremiseWithSnippets($q['question'], $snippets);
            if (!$premiseVerdict['valid']) {
                $reason = $premiseVerdict['issue'] !== ''
                    ? $premiseVerdict['issue']
                    : 'question contains an inaccurate date or statistic';
                $errors[] = "question $qNum [fact-check premise]: question text rejected — $reason";
                continue;
            }
            logQuizGenInfo("[$category] question $qNum [fact-check premise]: passed");
        }

        $verdict = verifyAnswerWithSnippets(
            $q['question'], $markedAnswer, $q['options'], $q['format'], $snippets
        );

        if (!$verdict['valid']) {
            $reason = $verdict['issue'] !== '' ? $verdict['issue'] : 'marked answer not supported by sources';
            $errors[] = "question $qNum [fact-check]: marked answer \"$markedAnswer\" rejected — $reason";
            continue;
        }

        if ($q['format'] === 'mc') {
            $distractorVerdict = verifyNoDistractorIsCorrectWithSnippets(
                $q['question'], $markedAnswer, $q['options'], $snippets
            );
            if (!$distractorVerdict['valid']) {
                $reason = $distractorVerdict['issue'] !== ''
                    ? $distractorVerdict['issue']
                    : 'a wrong option is better supported by sources than the marked answer';
                $errors[] = "question $qNum [fact-check distractor]: $reason";
            }
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
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Authorization: ' . PEXELS_API_KEY],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if (!$response) {
        $detail = $curlErr !== '' ? $curlErr : 'no response';
        logQuizGenError("[images] Pexels request failed for \"$imageQuery\" ($detail)");
        return null;
    }

    $decoded = json_decode($response, true);
    if ($httpCode >= 400) {
        $err = $decoded['error'] ?? "HTTP $httpCode";
        logQuizGenError("[images] Pexels API error for \"$imageQuery\": $err");
        return null;
    }

    $photo = $decoded['photos'][0] ?? null;
    if (!$photo) {
        logQuizGenError("[images] Pexels returned no photos for \"$imageQuery\"");
        return null;
    }

    $imageUrl = $photo['src']['large'] ?? $photo['src']['medium'] ?? null;
    if (!$imageUrl) {
        logQuizGenError("[images] Pexels photo missing src URL for \"$imageQuery\"");
        return null;
    }

    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        logQuizGenError("[images] Could not create directory: $destDir");
        return null;
    }

    $destPath = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . $filenameBase . '.jpg';
    if (!downloadAndResizeImage($imageUrl, $destPath)) {
        logQuizGenError("[images] Download/resize failed for \"$imageQuery\"");
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
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if (!$body) {
        if ($curlErr !== '') {
            logQuizGenError("[images] Image download failed: $curlErr");
        }
        return false;
    }

    $src = @imagecreatefromstring($body);
    if (!$src) {
        // GD unavailable or unsupported format — save original bytes if valid image
        if (@getimagesizefromstring($body)) {
            return file_put_contents($destPath, $body) !== false;
        }
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
    if (!defined('PEXELS_API_KEY') || PEXELS_API_KEY === '') {
        logQuizGenError('[images] Preview images skipped — set PEXELS_API_KEY in dblogin.php');
        return $questions;
    }

    if (!function_exists('curl_init')) {
        logQuizGenError('[images] Preview images skipped — PHP curl extension is not available');
        return $questions;
    }

    $runId = uniqid('', true);
    $previewDir = quizImagesRoot() . DIRECTORY_SEPARATOR . 'preview' . DIRECTORY_SEPARATOR . $runId;
    $fetched = 0;
    $failed = 0;

    foreach ($questions as &$q) {
        $query = trim($q['image_query'] ?? '');
        $pos = $q['position'] ?? '?';
        if ($query === '') {
            $failed++;
            logQuizGenError("[images] Question $pos missing image_query");
            continue;
        }
        logQuizGenInfo("[images] Q$pos: fetching \"$query\"…");
        $result = fetchQuestionImage($query, $previewDir, (string)$pos);
        if ($result) {
            $q['image_url'] = relativeQuizImagePath($result['path']);
            $q['image_attribution'] = $result['attribution'];
            $fetched++;
            logQuizGenInfo("[images] Q$pos: saved");
        } else {
            $failed++;
        }
    }
    unset($q);

    $GLOBALS['quizGenStats']['preview_images_fetched'] = $fetched;
    logQuizGenInfo("[images] Preview images: $fetched/15 saved" . ($failed ? ", $failed failed" : ''));

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
