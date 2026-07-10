<?php
/**
 * quiz-generator.php
 *
 * Shared quiz-generation logic used by generate-quiz.php (CLI cron, writes to DB),
 * action-test-generate-quiz.php (superuser web preview), and qa-generate-quiz.php
 * (CLI QA harness for optimization passes — does not write to DB).
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

/** Model for category question generation. gpt-4o is more accurate; gpt-4o-mini is faster/cheaper. */
const QUIZ_GENERATION_MODEL = 'gpt-4o-mini';

/** Model for fact-check JSON verifiers (premise, answer, distractor). */
const QUIZ_FACT_CHECK_MODEL = 'gpt-4o-mini';

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
const MAX_ATTEMPTS_SPORTS = 15;

const NZ_KEYWORDS = [
    'new zealand', 'nz', 'zealand', 'auckland', 'wellington', 'christchurch', 'dunedin',
    'hamilton', 'rotorua', 'tauranga', 'queenstown', 'napier', 'nelson', 'invercargill',
    'maori', 'māori', 'waitangi', 'kiwi', 'aotearoa', 'taupo', 'south island', 'north island',
    'wakari', 'kakapo', 'tuatara', 'all blacks', 'silver ferns',
];

const AU_KEYWORDS = [
    'australia', 'aussie', 'sydney', 'melbourne', 'brisbane', 'perth', 'canberra', 'adelaide',
    'queensland', 'victoria', 'new south wales', 'nsw', 'tasmania', 'northern territory',
    'outback', 'great barrier reef', 'aboriginal',
    'kangaroo', 'koala', 'platypus', 'wombat', 'echidna', 'wallaby',
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
    '/\bextends across both\b.{0,25}\b(Europe and Asia|Asia and Europe)\b/i'
        => 'geography extent error — Himalayas are Asia-only; Urals/Caucasus divide Europe and Asia',
    '/\b(both Europe and Asia|Europe and Asia)\b.{0,40}\b(mountain|range|river)\b/i'
        => 'geography extent error — verify which continent(s) a feature actually spans',
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
    '/\bfirst\b.{0,25}\b(to enact|to grant|to allow|to introduce|to pass|to adopt)\b/i'
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
    '/\bfor the first time\b/i'
        => 'first-time phrasing — ask "In which year did…?" with year in options only',
    '/\btheir first\b/i'
        => 'their-first superlative — ask about a specific year instead',
    '/\b(?:second|third|fourth|fifth|\d+(?:st|nd|rd|th)) time\b.{0,40}\b(?:held|hosted?|host|took place in)\b/i'
        => 'Nth-time plus host-country claim — ask about the year or host separately, not both',
    '/\bfirst\b.{0,30}\b(successful )?(ascent|descent|summit)\b/i'
        => 'first-ascent superlative — ask a plain year question with the year in the options only',
    '/\bworld\'s largest\b/i'
        => 'world\'s-largest superlative — ask a plain factual question without record claims',
    '/\blargest\b.{0,35}\b(in the world|on earth|on Earth|living structure)\b/i'
        => 'largest-on-earth superlative — ask a plain factual question without record claims',
    '/\blargest coral reef\b/i'
        => 'largest coral reef superlative — ask about a specific place or feature without record claims',
    '/\blast (country|countries|nation|nations|to)\b.{0,40}\b(Europe|Asia|Africa|Americas|the world)\b/i'
        => 'last-in-region superlative — regional rankings are disputed (e.g. Belarus still has the death penalty in Europe)',
    '/\bonly country (in|to)\b.{0,40}\b(Europe|Asia|Africa|the world)\b/i'
        => 'only-country-in-region superlative — ask a plain verifiable fact instead',
];

/** Topic labels already used elsewhere in the same quiz — reject repeats like Versailles in History and GK. */
const DUPLICATE_QUIZ_TOPIC_PATTERNS = [
    '/\btreaty of versailles\b/i'  => 'Treaty of Versailles',
    '/\bmagna carta\b/i'            => 'Magna Carta',
    '/\btreaty of waitangi\b/i'     => 'Treaty of Waitangi',
    '/\bberlin wall\b/i'            => 'Berlin Wall',
    '/\bgreat barrier reef\b/i'     => 'Great Barrier Reef',
    '/\bharry potter\b/i'           => 'Harry Potter',
    '/\bhogwarts\b/i'               => 'Harry Potter',
    '/\bfinding nemo\b/i'           => 'Finding Nemo',
    '/\bfinding dory\b/i'           => 'Finding Dory',
];

/** Lazy model defaults — rejected in General Knowledge pop culture slots. */
const GK_OVERUSED_POP_CULTURE_PATTERNS = [
    '/\bharry potter\b/i'   => 'Harry Potter',
    '/\bhogwarts\b/i'       => 'Harry Potter',
    '/\bfinding nemo\b/i'   => 'Finding Nemo',
    '/\bfinding dory\b/i'   => 'Finding Dory',
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

/**
 * Soft cap on MC questions whose answers are calendar years (e.g. "1987").
 * Categories generate independently, so the running count is passed forward
 * like usedTopicLabels — prompt guidance alone cannot see later categories.
 */
const MAX_YEAR_ANSWER_QUESTIONS = 4;

/** @var array{categories_retried: int, fact_check_skips: int, preview_images_fetched: int, category_cap_fallbacks: int, category_emergency_fallbacks: int, answer_corrections: int} */
$GLOBALS['quizGenStats'] = [
    'categories_retried'         => 0,
    'fact_check_skips'           => 0,
    'preview_images_fetched'     => 0,
    'category_cap_fallbacks'     => 0,
    'category_emergency_fallbacks' => 0,
    'answer_corrections'         => 0,
];

function resetQuizGenStats(): void {
    $GLOBALS['quizGenStats'] = [
        'categories_retried'         => 0,
        'fact_check_skips'           => 0,
        'preview_images_fetched'     => 0,
        'category_cap_fallbacks'     => 0,
        'category_emergency_fallbacks' => 0,
        'answer_corrections'         => 0,
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
 * combined array of question objects with positions 1..15 assigned. Questions
 * are shuffled before numbering so presentation order is not fixed by category.
 * When a category exhausts its retry cap, the last generated batch is accepted (best effort).
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
    $usedTopicLabels = [];
    $yearAnswerCount = 0;
    $quizTfFirstCorrectIsTrue = null;
    foreach (CATEGORY_TARGETS as $category => $count) {
        $tfCount = in_array($category, $tfHosts, true) ? 1 : 0;
        $mcCount = $count - $tfCount;

        logQuizGenInfo("Generating: $category ($count question(s))");

        // Include topics already generated earlier in this same quiz, on top of
        // the cross-day avoid-list, so e.g. Geography and History don't both
        // reach for the same treaty.
        $avoidForThisCall = array_merge($avoidQuestions, array_column($allQuestions, 'question'));

        $tfCorrectPreference = null;
        if ($tfCount === 1) {
            $tfCorrectPreference = $quizTfFirstCorrectIsTrue === null
                ? (random_int(0, 1) === 0 ? 'true' : 'false')
                : ($quizTfFirstCorrectIsTrue ? 'false' : 'true');
        }

        $categoryQuestions = generateCategoryQuestions(
            $category, $mcCount, $tfCount, $headlinesByRegion, $today, $avoidForThisCall,
            $usedTopicLabels, $yearAnswerCount, $tfCorrectPreference
        );
        foreach ($categoryQuestions as $q) {
            foreach (duplicateTopicsInQuestion($q) as $topic) {
                $usedTopicLabels[] = $topic;
            }
            if (questionIsYearAnswerQuestion($q)) {
                $yearAnswerCount++;
            }
            if ($quizTfFirstCorrectIsTrue === null && ($q['format'] ?? '') === 'tf') {
                $quizTfFirstCorrectIsTrue = tfQuestionCorrectIsTrue($q);
            }
        }
        $usedTopicLabels = array_values(array_unique($usedTopicLabels));
        $allQuestions = array_merge($allQuestions, $categoryQuestions);
        logQuizGenInfo("Accepted: $category");
    }

    shuffle($allQuestions);

    foreach ($allQuestions as $i => &$q) {
        $q['position'] = $i + 1;
    }
    unset($q);

    return $allQuestions;
}

/**
 * Sports needs extra retries — World Cup host/year questions often need several reformulations.
 */
function maxAttemptsForCategory(string $category): int {
    return $category === 'Sports' ? MAX_ATTEMPTS_SPORTS : MAX_ATTEMPTS_PER_CATEGORY;
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
 * remains a risk, which validateOneQuestion() still checks for. When the retry
 * cap is reached, the last model output is accepted (best effort).
 */
function generateCategoryQuestions(
    string $category, int $mcCount, int $tfCount, array $headlinesByRegion, string $today, array $avoidQuestions,
    array $usedTopicLabels = [], int $yearAnswerCount = 0, ?string $tfCorrectPreference = null
): array {
    $total = $mcCount + $tfCount;

    $formatInstruction = $tfCount > 0
        ? "Produce exactly $total question(s): exactly $mcCount in \"mc\" format (4 options, exactly 1 correct) and exactly $tfCount in \"tf\" format (2 options, \"True\" and \"False\", exactly 1 correct — phrased as a single declarative true/false statement, ideally starting with \"True or False:\". NEVER phrase a tf question as a which/what/who/when/where question, since that can't be sensibly answered with just True or False). Do NOT default to True as the correct answer every time — when False is correct, write a plausible but factually wrong statement (swap a number, date, place, name, or attribute; not something absurd)."
        : "Produce exactly $total question(s), ALL in \"mc\" format (4 options each, exactly 1 correct). "
            . "CRITICAL: This category has zero true/false slots — every question MUST be format \"mc\". "
            . "Do NOT use format \"tf\". Do NOT start any question with \"True or False:\". "
            . "Write which/what/when MC questions with four content-based answer options (names, places, numbers, or years — not True/False).";

    $categoryGuidance = buildCategoryGuidance($category);
    $validatorRules = buildValidatorRulesBlock($category);
    $yearDiversityRule = buildYearAnswerDiversityPromptRule($yearAnswerCount);
    $tfCorrectRule = buildTfCorrectAnswerPromptRule($tfCorrectPreference);
    $difficultyRule = $category === 'General Knowledge'
        ? 'Difficulty: genuinely challenging — average players should get some wrong. For academic GK (science, inventions, civics), avoid the single most famous fact. For pop culture (film, TV, music), well-known classics are fine. Prefer names, places, and numbers over a run of year-only answers when possible.'
        : 'Difficulty: genuinely challenging — average players should get some wrong. Do NOT ask questions whose answer is the single most famous fact about a topic (e.g. capital cities, a country\'s national animal/bird, "the" founding treaty of a nation, the primary language of a country, a famous landmark\'s most basic fact). These are trivially guessable. Ask about specific details, numbers, names, places, or lesser-known angles instead — but only facts you are confident are real and verifiable from standard reference sources, not obscure one-off records you are unsure about.';

    $systemPrompt = <<<PROMPT
You are writing question(s) for the "$category" section of a daily quiz for a New Zealand audience.

$categoryGuidance

$validatorRules

$formatInstruction

For any "mc" question, include one answer option that sounds almost plausible but is subtly absurd on reflection — not obviously silly, the kind that makes you second-guess yourself. Aim for roughly 1 in 3 of the mc questions to have this.

$difficultyRule

Answer-type variety: across the quiz, prefer a mix of names, places, and numbers — not a run of year-only MC answers. Year options are fine in moderation; do not default every "when" question to four calendar years if a name, place, or other fact works.

$yearDiversityRule

$tfCorrectRule

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
    $lastCandidate = null;
    $bestCandidate = null;
    $bestErrorCount = PHP_INT_MAX;
    $bestTfPreferenceCandidate = null;
    $bestTfPreferenceErrorCount = PHP_INT_MAX;
    $maxAttempts = maxAttemptsForCategory($category);

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $headlineBlock = buildCategoryHeadlineBlock($category, $headlinesByRegion, $excludedHeadlines);
        $avoidBlock = buildQuestionAvoidBlock($avoidQuestions, $failedQuestions);
        $topicsBlock = buildUsedTopicsAvoidBlock($usedTopicLabels);
        $yearBlock = buildYearAnswerAvoidBlock($yearAnswerCount);
        $gkAngleBlock = $category === 'General Knowledge'
            ? "\n\nPop culture (exactly ONE of your three questions): suggested angle — "
                . pickGkPopCultureAngle()
                . '. The other two questions must be science, inventions, geography facts, or civics — not film/TV/music/games.'
                . buildGkFranchiseAvoidBlock($avoidQuestions)
            : '';
        $attemptUserPrompt = "Today is $today.$headlineBlock$avoidBlock$topicsBlock$yearBlock$gkAngleBlock\n\nGenerate the question(s) now.";
        if ($retryNote !== '') {
            $attemptUserPrompt .= "\n\nNOTE: $retryNote";
        }

        $payload = json_encode([
            'model'           => QUIZ_GENERATION_MODEL,
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
        if (is_array($candidate) && isset($candidate['questions']) && is_array($candidate['questions'])) {
            $lastCandidate = $candidate;
        }

        $errors = validateCategoryQuestions(
            $candidate, $category, $mcCount, $tfCount, $usedTopicLabels, $yearAnswerCount, $tfCorrectPreference
        );
        if (!$errors) {
            $batchQuestions = $candidate['questions'] ?? [];
            $errors = factCheckCategoryQuestions($batchQuestions, $category, $headlinesByRegion);
            if (!$errors && isset($candidate['questions'])) {
                $candidate['questions'] = $batchQuestions;
            }
        }
        if ($errors) {
            $errorCount = count($errors);
            if (is_array($candidate) && isset($candidate['questions'])) {
                if ($errorCount < $bestErrorCount) {
                    $bestErrorCount = $errorCount;
                    $bestCandidate = $candidate;
                }
                if ($tfCount > 0 && $tfCorrectPreference !== null && !errorsIncludeTfPreferenceViolation($errors)) {
                    if ($errorCount < $bestTfPreferenceErrorCount) {
                        $bestTfPreferenceErrorCount = $errorCount;
                        $bestTfPreferenceCandidate = $candidate;
                    }
                }
            }

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
            $retryNote .= "\n\nFORMAT REMINDER: This batch requires exactly $mcCount mc question(s)"
                . ($tfCount > 0 ? " and exactly $tfCount tf question(s)." : " and zero tf questions — do NOT use format \"tf\".");
            $retryNote .= buildRetryHintForErrors(
                $errorList, $category, $mcCount, $tfCount, $attempt, $yearAnswerCount
            );
            if (preg_match('/repeats topic/i', $errorList) && $usedTopicLabels) {
                $retryNote .= buildUsedTopicsAvoidBlock($usedTopicLabels);
            }
            if (preg_match('/year-answer|calendar-year option/i', $errorList)) {
                $retryNote .= buildYearAnswerAvoidBlock($yearAnswerCount);
            }
            if (preg_match('/pop culture question|exactly one pop culture|overused pop culture topic/i', $errorList)) {
                if (preg_match('/exactly one pop culture|got \d+\./i', $errorList)) {
                    $retryNote .= "\n\nPOP CULTURE MIX FIX: General Knowledge needs EXACTLY ONE film/TV/music/literature/game question. "
                        . 'The other two must be science, inventions, geography facts, or civics — not entertainment.';
                } elseif (preg_match('/overused pop culture topic/i', $errorList)) {
                    $retryNote .= "\n\nOVERUSED FRANCHISE FIX: Do not use Harry Potter, Finding Nemo, or Finding Dory. "
                        . 'Pick a different entertainment topic (e.g. Abbey Road, Friends, classic cinema outside Disney/Pixar).';
                } else {
                    $retryNote .= "\n\nPOP CULTURE FIX: Include exactly one film, TV, music, literature, or video game question (global only — no NZ/AU). "
                        . 'The other two questions must NOT be entertainment.';
                }
            }
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
        if ($bestTfPreferenceCandidate !== null && $bestTfPreferenceErrorCount < PHP_INT_MAX) {
            $fallbackCandidate = $bestTfPreferenceCandidate;
            $pickLabel = 'best (tf preference met)';
            $pickErrorCount = $bestTfPreferenceErrorCount;
        } elseif ($bestCandidate !== null && $bestErrorCount < PHP_INT_MAX) {
            $fallbackCandidate = $bestCandidate;
            $pickLabel = 'best';
            $pickErrorCount = $bestErrorCount;
        } else {
            $fallbackCandidate = $lastCandidate;
            $pickLabel = 'last';
            $pickErrorCount = null;
        }
        if ($fallbackCandidate !== null && isset($fallbackCandidate['questions']) && is_array($fallbackCandidate['questions'])) {
            logQuizGenError(
                "[$category] All $maxAttempts attempts failed validation — accepting $pickLabel attempt"
                . ($pickErrorCount !== null ? " ($pickErrorCount violation(s))" : '')
                . ' (best effort).'
            );
            bumpQuizGenStat('category_cap_fallbacks');
            $result = $fallbackCandidate['questions'];
        } else {
            logQuizGenError(
                "[$category] All $maxAttempts attempts failed with no model output — using emergency fallback questions."
            );
            bumpQuizGenStat('category_emergency_fallbacks');
            $result = buildEmergencyCategoryQuestions($category, $mcCount, $tfCount);
        }
    }

    foreach ($result as &$q) {
        $q['category'] = $category;
    }
    unset($q);

    return $result;
}

/**
 * Minimal structurally valid questions when the API never returned usable JSON.
 * Logged as emergency fallback — a bad quiz beats no quiz.
 *
 * @return list<array{question: string, format: string, image_query: string, options: list<array{text: string, correct: bool}>}>
 */
function buildEmergencyCategoryQuestions(string $category, int $mcCount, int $tfCount): array {
    $questions = [];
    for ($i = 1; $i <= $mcCount; $i++) {
        $questions[] = [
            'question'    => "Which option best fits this $category topic? (auto-generated placeholder $i)",
            'format'      => 'mc',
            'image_query' => 'trivia quiz',
            'options'     => [
                ['text' => 'Option A', 'correct' => true],
                ['text' => 'Option B', 'correct' => false],
                ['text' => 'Option C', 'correct' => false],
                ['text' => 'Option D', 'correct' => false],
            ],
        ];
    }
    for ($i = 1; $i <= $tfCount; $i++) {
        $questions[] = [
            'question'    => "True or False: This is a placeholder $category statement ($i).",
            'format'      => 'tf',
            'image_query' => 'trivia quiz',
            'options'     => [
                ['text' => 'True', 'correct' => true],
                ['text' => 'False', 'correct' => false],
            ],
        ];
    }
    return $questions;
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
 * Topics (treaties, landmarks, etc.) already used in earlier categories this quiz.
 */
function buildUsedTopicsAvoidBlock(array $usedTopicLabels): string {
    $topics = array_values(array_unique(array_filter($usedTopicLabels)));
    if (!$topics) {
        return '';
    }
    $list = implode("\n", array_map(fn($t) => '- ' . $t, $topics));
    return "\n\nTopics already used elsewhere in THIS quiz — do NOT mention these in any question or option:\n$list";
}

/**
 * Warns the model away from franchises that dominate test runs and recent quizzes.
 */
function buildGkFranchiseAvoidBlock(array $avoidQuestions): string {
    $labels = ['Harry Potter', 'Finding Nemo'];
    foreach ($avoidQuestions as $q) {
        foreach (gkOverusedPopCultureLabelsInText($q) as $label) {
            $labels[] = $label;
        }
    }
    $labels = array_values(array_unique($labels));
    $list = implode(', ', $labels);
    return "\n\nDo NOT use these overused entertainment topics: $list.";
}

/** @return string[] */
function gkOverusedPopCultureLabelsInText(string $text): array {
    $found = [];
    foreach (GK_OVERUSED_POP_CULTURE_PATTERNS as $pattern => $label) {
        if (preg_match($pattern, $text)) {
            $found[] = $label;
        }
    }
    return array_values(array_unique($found));
}

function questionUsesOverusedGkPopCulture(array $q): ?string {
    $text = triviaRegionCombinedText($q);
    foreach (GK_OVERUSED_POP_CULTURE_PATTERNS as $pattern => $label) {
        if (preg_match($pattern, $text)) {
            return $label;
        }
    }
    return null;
}

function countPopCultureQuestions(array $questions): int {
    $n = 0;
    foreach ($questions as $q) {
        if (questionIsPopCulture($q)) {
            $n++;
        }
    }
    return $n;
}

/** True when the marked tf option is True; false when False is marked correct. */
function tfQuestionCorrectIsTrue(array $q): bool {
    foreach ($q['options'] ?? [] as $opt) {
        if (!empty($opt['correct'])) {
            return strcasecmp(trim($opt['text'] ?? ''), 'true') === 0;
        }
    }
    return true;
}

/**
 * Per-category tf guidance — first slot random, second slot opposite for quiz balance.
 */
function buildTfCorrectAnswerPromptRule(?string $tfCorrectPreference): string {
    if ($tfCorrectPreference === 'false') {
        return <<<'RULE'
TRUE/FALSE REQUIREMENT: Your tf question MUST have False as the correct answer.
Write a declarative statement that sounds believable but is factually wrong — swap a number, date, place, name, or attribute. Do NOT write something obviously absurd.
RULE;
    }
    if ($tfCorrectPreference === 'true') {
        return <<<'RULE'
TRUE/FALSE REQUIREMENT: Your tf question MUST have True as the correct answer.
Write a verifiably true factual statement.
RULE;
    }
    return '';
}

/** @param string[] $errors */
function errorsIncludeTfPreferenceViolation(array $errors): bool {
    foreach ($errors as $error) {
        if (preg_match('/must have (False|True) as the correct answer/i', $error)) {
            return true;
        }
    }
    return false;
}

/**
 * Soft/hard year-answer guidance injected into the system prompt for this category call.
 */
function buildYearAnswerDiversityPromptRule(int $yearAnswerCount): string {
    if ($yearAnswerCount >= MAX_YEAR_ANSWER_QUESTIONS) {
        return 'YEAR CAP REACHED: Earlier categories already used '
            . MAX_YEAR_ANSWER_QUESTIONS
            . ' year-option questions. Do NOT use calendar years as answer options — ask about names, places, colours, nicknames, or other non-year facts.';
    }
    if ($yearAnswerCount >= 2) {
        return "Year-answer budget: $yearAnswerCount of up to "
            . MAX_YEAR_ANSWER_QUESTIONS
            . ' year-option questions are already used in this quiz. Prefer non-year answers (names, places, numbers that are not years) unless a year question is clearly the best fit.';
    }
    return '';
}

/**
 * User-prompt reminder when the quiz is approaching or at the year-answer cap.
 */
function buildYearAnswerAvoidBlock(int $yearAnswerCount): string {
    if ($yearAnswerCount >= MAX_YEAR_ANSWER_QUESTIONS) {
        return "\n\nYEAR ANSWER CAP: This quiz already has $yearAnswerCount questions with year options "
            . '(max ' . MAX_YEAR_ANSWER_QUESTIONS . '). Do NOT produce any MC question whose options are calendar years.';
    }
    if ($yearAnswerCount >= 2) {
        $remaining = MAX_YEAR_ANSWER_QUESTIONS - $yearAnswerCount;
        return "\n\nYear-answer budget: $yearAnswerCount already used; at most $remaining more year-option question(s) allowed in this quiz. Prefer names/places when possible.";
    }
    return '';
}

/** Rotates pop culture sub-genre suggestions for General Knowledge variety. */
function pickGkPopCultureAngle(): string {
    $angles = ['film', 'television', 'music', 'literature', 'video games'];
    return $angles[array_rand($angles)];
}

/**
 * True when an MC question uses calendar years as its answer options
 * (year stem, year marked correct, or mostly year options).
 */
function questionIsYearAnswerQuestion(array $q): bool {
    if (($q['format'] ?? '') !== 'mc') {
        return false;
    }
    if (questionHasYearStemPattern($q['question'] ?? '')) {
        return true;
    }
    $yearOpts = 0;
    foreach ($q['options'] ?? [] as $opt) {
        $text = trim($opt['text'] ?? '');
        if (!preg_match('/^\d{4}$/', $text)) {
            continue;
        }
        $yearOpts++;
        if (!empty($opt['correct'])) {
            return true;
        }
    }
    return $yearOpts >= 3;
}

function countYearAnswerQuestions(array $questions): int {
    $n = 0;
    foreach ($questions as $q) {
        if (questionIsYearAnswerQuestion($q)) {
            $n++;
        }
    }
    return $n;
}

/** True when a question is film/TV/music/literature/video-game entertainment trivia. */
function questionIsPopCulture(array $q): bool {
    $combined = mb_strtolower(triviaRegionCombinedText($q));
    if (preg_match('/\b(world cup|premiership|afl|nrl|super rugby|olympics|test match)\b/i', $combined)) {
        return false;
    }
    $patterns = [
        '/\b(film|films|movie|movies|cinema|oscar|academy award|emmy|grammy|tony award)\b/i',
        '/\b(tv|television|sitcom|series|netflix|disney|marvel|pixar|animated)\b/i',
        '/\b(album|song|songs|band|bands|musician|singer|rapper|composer|soundtrack)\b/i',
        '/\b(novel|author|book series|harry potter|lord of the rings|shakespeare)\b/i',
        '/\b(video game|playstation|xbox|nintendo|minecraft|pokemon)\b/i',
        '/\b(actor|actress|director|celebrity|pop star|rock band)\b/i',
        '/\b(star wars|beatles|rolling stones|taylor swift|beyonc|madonna|elvis)\b/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $combined)) {
            return true;
        }
    }
    return false;
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
            return 'These questions MUST be based only on the NZ headlines block below — genuinely current, real stories. Must NOT be about Australia. Phrase naturally — do not say "according to recent news" or "as reported today". The marked correct answer must stay close to what the headlines actually say — do not add speculative detail (e.g. "under suspicious circumstances") that headlines do not support. For image_query use generic scene words (e.g. "emergency response") — never repeat an option word like "Flooding" or "Landslide".';
        case 'Aussie Current Events':
            return 'These questions MUST be based only on the Australian headlines block below — genuinely current, real stories. Must NOT be about New Zealand. Phrase naturally — do not say "according to recent news" or "as reported today". The marked correct answer must stay close to what the headlines actually say — do not add speculative detail that headlines do not support. For image_query use generic scene words — never repeat an option word from the answers.';
        case 'NZ Trivia':
            return 'General New Zealand trivia — established, verifiable facts that would be true regardless of today\'s news. Do NOT base these on current headlines, even indirectly. Must NOT be about Australia (no Great Barrier Reef, kangaroos, Australian cities, or other AU-only topics). Use widely documented facts only — NOT obscure micro-records (e.g. "first descent" of a specific river, one-off local sporting feats). For year questions, put the year in the answer options, not in the question stem.';
        case 'Australia Trivia':
            return 'General Australia trivia — established, verifiable facts that would be true regardless of today\'s news. Do NOT base these on current headlines, even indirectly. Must NOT be about New Zealand (no All Blacks, Māori topics, NZ cities, or other NZ-only topics). Avoid the Sydney Opera House, Australia Day, and "national animal/bird" angles. Do NOT name a city or holiday in the question if it is the correct answer (e.g. do not mention "Sydney Festival" if Sydney is an option). For year questions, put the year in the answer options, not in the question stem.';
        case 'Sports':
            return <<<'GUIDE'
General sports trivia (NZ/Australian teams and leagues are fine) — established, verifiable facts, NOT tied to today's news.

Sports has strict automatic rejection rules — follow exactly:
- The word "first" must NEVER appear in any question (not "first win", "first time", "first World Cup", "first ever", "first to qualify"). Rephrase as a plain event/year question with no "first".
- For year MC questions: describe the event in the question; put years ONLY in the four options. Do not embed a year in the question stem.
- No all-time records (most premierships, longest streak, winning percentage, per capita).
- At least ONE of your two questions MUST be domestic league trivia with NO World Cup, Olympics host, or championship win-year angle:
  GOOD: "Which AFL club is known for black and white vertical stripes?"
  GOOD: "What is the home ground of the Canterbury Crusaders?"
  GOOD: "Which NRL team plays home games at Suncorp Stadium?"
- The other question MAY ask "In which year was [tournament] held in [place]?" ONLY if you are certain the place and year match (verify before answering).
  GOOD: "In which year was the Rugby World Cup held in South Africa?"
  BAD:  "In which year did the All Blacks first win the Rugby World Cup?"
  BAD:  "In which year did Australia win the Cricket World Cup, when the final was against Pakistan?"
- NEVER ask which year a team WON a World Cup, Grand Final, premiership, or Ashes — these fail fact-check constantly.
- NEVER use "hosted" or "host" for World Cup / tournament questions — use "held in [place]" instead, with the year only in the options.
  BAD:  "In which year did New Zealand host the Cricket World Cup?"
- Do NOT combine an edition count with a host country in one question (e.g. "third time, held in the UK") — pick one angle only.
- Do NOT combine a host-place claim with a final-opponent claim (e.g. "held in X, final against Y") — pick host-year OR domestic trivia only.
- For Cricket World Cup: 2011 was India/Sri Lanka/Bangladesh; 2015 was Australia/New Zealand; 2019 was England/Wales.
- Do NOT invent obscure team mascots — only well-known colours, nicknames, or stadium facts you are certain about.
GUIDE;
        case 'Geography':
            return 'Must be about the rest of the world — NOT New Zealand or Australia. No Great Barrier Reef, Sydney, or other NZ/AU references. No "longest river in the world" (Nile vs Amazon) questions. No Sydney Opera House. Do NOT claim a mountain range "extends across both Europe and Asia" — the Himalayas are Asia-only; the Urals/Caucasus mark the Europe–Asia boundary. For year questions, put the year in the answer options, not in the question stem.';
        case 'History':
            return 'Must be about the rest of the world — NOT New Zealand or Australia. Use standard names for treaties and events (e.g. "Treaty of Paris", "Congress of Vienna" — never invent names like "Treaty of Vienna"). No "first country to…" superlatives. TF statements must be plain factual claims, not joke premises. For year questions, put the year in the answer options, not in the question stem.';
        case 'General Knowledge':
            return <<<'GUIDE'
Global general knowledge — NOT New Zealand or Australia (no NZ/AU cities, artists, or shows).

Topic mix (3 questions per batch):
- Exactly ONE must be pop culture / entertainment: film, television, music, literature, or video games from anywhere except NZ/AU.
- The other TWO must be non-entertainment: science, inventions, geography facts, or civics — not film/TV/music/literature/games.

Pop culture GOOD examples:
- "Which band released the album Abbey Road?"
- "In which city is the sitcom Friends primarily set?"
- "True or False: The film Titanic won the Academy Award for Best Picture."

Pop culture rules:
- Use well-documented, classic entertainment facts you are confident about — obscure deep cuts fail fact-check.
- No NZ or AU films, bands, or TV shows (those belong in regional trivia categories).
- Do NOT use Harry Potter, Finding Nemo, Finding Dory, or Hogwarts — these are overused defaults; pick a different franchise or artist.
- Famous entertainment facts are OK here (unlike treaty/history angles where the most famous fact is discouraged).
- When the correct answer is a film/book/album title, do NOT name characters, bands, or distinctive title words in the question stem (e.g. do not say "Harry" if the answer is Harry Potter and the Philosopher's Stone).

Other GK rules:
- No treaties duplicated from History, no superlatives, no disputed rankings.
- For year questions, put the year in the answer options, not in the question stem.
GUIDE;
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
- Answer giveaway: distinctive words from the correct option (character names, title words, demonyms) must not appear in the question text or image_query — not just the full phrase.
- image_query: 2–4 words, no option text, no correct answer hint.
- tf format: a declarative "True or False: …" statement — never a which/what/who/when question. Vary the correct answer — when False is correct, write a plausible but factually wrong claim (wrong number, date, place, or attribute).
- Geography / History / General Knowledge: zero NZ or Australia content (no cities, landmarks, or teams from NZ/AU).
- Clichés: no "capital of [country]", no "national animal/bird/symbol", no "longest river in the world", no Sydney Opera House.
- Evergreen categories: no "recently", "this year", "last year", or the current/previous calendar year in the question.
- Wrong dates in the question stem are rejected by fact-check — if asking "in which year", keep the year out of the question text.
RULES;

    if ($category === 'Sports') {
        $rules .= "\n- Sports reminder: if you are about to write \"first\", stop and rephrase without it.";
        $rules .= "\n- Sports tournament hosts: never write \"[country] hosted [World Cup]\" — use \"In which year was [tournament] held in [place]?\" with years in the options only.";
        $rules .= "\n- Sports win-year: never ask which year a team WON a World Cup/premiership/final — use domestic league colours, stadiums, or host-place year questions instead.";
        $rules .= "\n- At least one Sports question must be domestic league trivia (AFL/NRL/Super Rugby colours, nickname, or home ground) with no World Cup or win-year angle.";
    }

    if ($category === 'General Knowledge') {
        $rules .= "\n- General Knowledge: exactly ONE question must be pop culture (film, TV, music, literature, or video games) with no NZ/AU content; the other two must NOT be entertainment.";
        $rules .= "\n- General Knowledge: do NOT use Harry Potter, Finding Nemo, Finding Dory, or Hogwarts.";
        $rules .= "\n- General Knowledge: prefer classic, well-documented entertainment facts over obscure deep cuts.";
    }

    return $rules;
}

/**
 * Targeted rewrite hints appended to retry notes based on which validators fired.
 */
function buildRetryHintForErrors(
    string $errorList, string $category, int $mcCount, int $tfCount, int $attempt = 1,
    int $yearAnswerCount = 0
): string {
    $hints = [];
    $preferNonYear = $yearAnswerCount >= 2;

    if (preg_match('/phrased as true\/false|has \d+ mc question|has \d+ tf question/i', $errorList)) {
        if ($tfCount === 0) {
            $hints[] = $preferNonYear
                ? 'MC ONLY: Rewrite every question as format "mc" with four content options (prefer names/places, not years). '
                    . 'Do NOT use format "tf" or "True or False:" phrasing — this category has no true/false slot.'
                : 'MC ONLY: Rewrite every question as format "mc" with four content options. '
                    . 'Do NOT use format "tf" or "True or False:" phrasing — this category has no true/false slot. '
                    . 'Example: "In which year did Victoria become a separate colony?" with year options.';
        } else {
            $hints[] = "FORMAT SPLIT: Output exactly $mcCount mc question(s) (4 options each) "
                . "and exactly $tfCount tf question(s) (True/False options). Check the format field on each question.";
        }
    }

    if (preg_match('/superlative pattern/i', $errorList)) {
        if ($category === 'Sports') {
            $hints[] = $preferNonYear
                ? 'SPORTS REPHRASE REQUIRED: Remove the word "first" from every question. '
                    . 'Prefer "Which team is known for [colours/nickname]?" or a home-ground question — avoid year options if possible.'
                : 'SPORTS REPHRASE REQUIRED: Remove the word "first" from every question. '
                    . 'Ask "In which year was [event] held in [place]?" or "Which team is known for [colours/nickname]?" '
                    . 'Example: instead of "first Rugby World Cup win", ask "In which year was the Rugby World Cup held in South Africa?"';
        } elseif ($category === 'General Knowledge') {
            $hints[] = 'Remove all "first/most/longest/record" framing. Ask a plain factual MC question — pop culture, science, or invention angles all work.';
        } else {
            $hints[] = $preferNonYear
                ? 'Remove all "first/most/longest/record" framing. Ask a plain factual MC question with name/place options (not years).'
                : 'Remove all "first/most/longest/record" framing. Ask a plain "In which year did…?" question with the year only in the options, or a name/place question.';
        }
    }

    if (preg_match('/year-answer cap|calendar-year option/i', $errorList)) {
        $hints[] = 'YEAR VARIETY FIX: Too many questions in this quiz already use year options. '
            . 'Rewrite with names, places, colours, nicknames, or other non-year facts — do not use four calendar years as options.';
    }

    if (preg_match('/pop culture question|exactly one pop culture|overused pop culture topic/i', $errorList)) {
        if (preg_match('/exactly one pop culture|got \d+\./i', $errorList)) {
            $hints[] = 'POP CULTURE MIX FIX: General Knowledge needs EXACTLY ONE film/TV/music/literature/game question. '
                . 'The other two must be science, inventions, geography, or civics — not entertainment.';
        } elseif (preg_match('/overused pop culture topic/i', $errorList)) {
            $hints[] = 'OVERUSED FRANCHISE FIX: Do not use Harry Potter, Finding Nemo, or Finding Dory — '
                . 'pick a different entertainment topic (e.g. Abbey Road, Friends, classic cinema outside Disney/Pixar).';
        } else {
            $hints[] = 'POP CULTURE FIX: Include exactly one film, TV, music, literature, or video game question (global only — no NZ/AU). '
                . 'The other two questions must NOT be entertainment.';
        }
    }

    if (preg_match('/must have False as correct|must have True as correct/i', $errorList)) {
        $hints[] = 'TRUE/FALSE FIX: When False must be correct, write a believable but wrong fact (swap a number, date, place, or name). '
            . 'When True must be correct, write a verifiably true statement. Mark the matching option as correct.';
    }

    if (preg_match('/gives away|image_query/i', $errorList)) {
        if (preg_match('/correct answer "(True|False)"/i', $errorList)) {
            if ($tfCount === 0) {
                $hints[] = 'MC ONLY: Remove "True or False:" phrasing. Rewrite as a which/what/when question with four content options.';
            } else {
                $hints[] = 'FORMAT FIX: True/False statements must use format "tf" (not "mc"). '
                    . 'Set format to "tf" with True/False options, or rewrite as a plain MC question.';
            }
        } else {
            $hints[] = 'GIVEAWAY FIX: The question or image_query names the correct answer (including character or title words). '
                . 'Rephrase without those words; use a generic image_query (e.g. "animated ocean scene" not "Nemo fish").';
        }
    }

    if (preg_match('/NZ Trivia only|Australia Trivia only|no New Zealand topic|no Australian topic/i', $errorList)) {
        $hints[] = 'REGION FIX: Match the category country — NZ Trivia must mention New Zealand; Australia Trivia must mention Australia.';
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

    if (preg_match('/tournament host phrasing|hosted.*World Cup/i', $errorList)) {
        $hints[] = 'HOST PHRASING FIX: Do not use "hosted" for World Cups. '
            . 'Ask "In which year was the [Cricket/Rugby] World Cup held in [country]?" and verify the year matches that host nation (e.g. NZ co-hosted CWC in 2015, not 2011).';
    }

    if (preg_match('/win-year|won the.*World Cup|final against|final was contested|banned win-year/i', $errorList)) {
        $hints[] = 'WIN-YEAR FIX: Do NOT ask which year a team won a World Cup or beat someone in a final. '
            . 'Use AFL/NRL/Super Rugby colours, a home stadium, or a verified "held in [place]?" host-year question instead.';
    }

    if (preg_match('/domestic league question/i', $errorList)) {
        $hints[] = 'DOMESTIC FIX: At least one Sports question must be AFL/NRL/Super Rugby team colours, nickname, or home ground — no World Cup, Olympics, or win-year.';
    }

    if ($category === 'Sports' && $attempt >= 3 && preg_match('/\[fact-check premise\]|World Cup|held in|hosted|Rugby|Cricket/i', $errorList)) {
        $hints[] = 'SPORTS ESCALATION: Abandon World Cup host/win questions for this batch. '
            . 'Output one domestic league question (colours/nickname/home ground) and one simple non-tournament fact you are 100% certain about.';
    }

    if (preg_match('/last-in-region superlative|only-country-in-region/i', $errorList)) {
        $hints[] = $category === 'General Knowledge'
            ? 'SUPERLATIVE FIX: Do not ask "last/only country in Europe/world to…" — ask pop culture, science, or a plain verifiable fact instead.'
            : 'SUPERLATIVE FIX: Do not ask "last/only country in Europe/world to…" — ask a plain verifiable fact (e.g. a treaty, inventor, or city landmark).';
    }

    if (preg_match('/\[fact-check distractor\]/i', $errorList)) {
        $hints[] = 'ANSWER FIX: The marked correct year/fact is wrong — a different option is actually correct. '
            . 'Verify all four options and mark the one that matches established fact.';
    }

    if (preg_match('/\[fact-check\]:/i', $errorList) && !preg_match('/\[fact-check distractor\]/i', $errorList)) {
        $hints[] = 'FACT-CHECK FIX: The marked answer is not supported by sources. Change the marked answer or rewrite the question entirely.';
    }

    if (preg_match('/repeats topic/i', $errorList)) {
        $hints[] = 'DUPLICATE TOPIC: This treaty/event was already used elsewhere in the quiz — pick a completely different subject (see blocklist above).';
    }

    if (preg_match('/geography extent|Europe and Asia/i', $errorList)) {
        $hints[] = 'GEOGRAPHY FIX: The Himalayas are in Asia only. The Urals/Caucasus mark the Europe–Asia boundary — do not ask which range spans both continents.';
    }

    if (preg_match('/largest-on-earth|largest coral reef/i', $errorList)) {
        $hints[] = 'SUPERLATIVE FIX: Do not use "largest in the world/on Earth" framing — ask a specific verifiable fact about a place instead.';
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
function validateCategoryQuestions(
    ?array $candidate, string $category, int $mcCount, int $tfCount, array $usedTopicLabels = [],
    int $yearAnswerCount = 0, ?string $tfCorrectPreference = null
): array {
    $total = $mcCount + $tfCount;
    if (!isset($candidate['questions']) || count($candidate['questions']) !== $total) {
        return ["expected $total question(s) for '$category', got: " . json_encode($candidate)];
    }

    $errors = [];
    $actualMc = 0;
    $actualTf = 0;
    $batchTopicLabels = $usedTopicLabels;
    foreach ($candidate['questions'] as $i => $q) {
        $errors = array_merge($errors, validateOneQuestion($q, $category, $i + 1, $batchTopicLabels));
        foreach (duplicateTopicsInQuestion($q) as $topic) {
            $batchTopicLabels[] = $topic;
        }
        $batchTopicLabels = array_values(array_unique($batchTopicLabels));
        if ($q['format'] === 'mc') $actualMc++;
        elseif ($q['format'] === 'tf') $actualTf++;
    }
    if ($actualMc !== $mcCount) {
        $errors[] = "'$category' has $actualMc mc question(s) (expected $mcCount)";
    }
    if ($actualTf !== $tfCount) {
        $errors[] = "'$category' has $actualTf tf question(s) (expected $tfCount)";
    }

    if ($tfCount > 0 && $tfCorrectPreference !== null) {
        foreach ($candidate['questions'] as $i => $q) {
            if (($q['format'] ?? '') !== 'tf') {
                continue;
            }
            $qNum = $i + 1;
            $correctIsTrue = tfQuestionCorrectIsTrue($q);
            if ($tfCorrectPreference === 'false' && $correctIsTrue) {
                $errors[] = "question $qNum tf must have False as the correct answer — rewrite as a plausible but factually false statement (wrong number, date, place, or attribute)";
            } elseif ($tfCorrectPreference === 'true' && !$correctIsTrue) {
                $errors[] = "question $qNum tf must have True as the correct answer — rewrite as a verifiably true factual statement";
            }
        }
    }

    $batchYearCount = countYearAnswerQuestions($candidate['questions']);
    if ($yearAnswerCount >= MAX_YEAR_ANSWER_QUESTIONS && $batchYearCount > 0) {
        $errors[] = "year-answer cap: quiz already has $yearAnswerCount calendar-year option questions "
            . '(max ' . MAX_YEAR_ANSWER_QUESTIONS . ") — this batch has $batchYearCount more; use names/places instead";
    } elseif ($yearAnswerCount + $batchYearCount > MAX_YEAR_ANSWER_QUESTIONS) {
        $allowed = max(0, MAX_YEAR_ANSWER_QUESTIONS - $yearAnswerCount);
        $errors[] = "year-answer cap: batch would bring the quiz to "
            . ($yearAnswerCount + $batchYearCount) . ' calendar-year option questions (max '
            . MAX_YEAR_ANSWER_QUESTIONS . "); at most $allowed year-option question(s) allowed here";
    }

    if ($category === 'Sports' && $total >= 2) {
        $hasDomestic = false;
        foreach ($candidate['questions'] as $q) {
            if (questionIsSportsDomesticLeague($q['question'] ?? '')) {
                $hasDomestic = true;
                break;
            }
        }
        if (!$hasDomestic) {
            $errors[] = "'Sports' batch must include at least one domestic league question (AFL/NRL/Super Rugby colours, nickname, or home ground) with no World Cup or win-year angle";
        }
    }

    if ($category === 'General Knowledge' && $total >= 3) {
        $popCount = countPopCultureQuestions($candidate['questions']);
        if ($popCount === 0) {
            $errors[] = "'General Knowledge' batch must include exactly one pop culture question (film, TV, music, literature, or video games) with no NZ/AU content";
        } elseif ($popCount > 1) {
            $errors[] = "'General Knowledge' batch must have exactly one pop culture question — got $popCount. "
                . 'The other two must be science, inventions, geography facts, or civics (not film/TV/music/games).';
        }
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
function validateOneQuestion(array $q, string $category, int $qNum, array $usedTopicLabels = []): array {
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

    $yearStemQuestion = questionHasYearStemPattern($q['question']);

    foreach (SUPERLATIVE_QUESTION_PATTERNS as $pattern => $reason) {
        if ($yearStemQuestion && str_contains($reason, 'first') && $category !== 'Sports') {
            continue;
        }
        if (preg_match($pattern, $q['question'])) {
            $errors[] = "question $qNum (\"{$q['question']}\") matches superlative pattern: $reason";
            break;
        }
    }

    foreach (duplicateTopicsInQuestion($q) as $topic) {
        if (in_array($topic, $usedTopicLabels, true)) {
            $errors[] = "question $qNum repeats topic \"$topic\" already used elsewhere in this quiz";
        }
    }

    if ($category === 'General Knowledge') {
        $overused = questionUsesOverusedGkPopCulture($q);
        if ($overused !== null) {
            $errors[] = "question $qNum uses overused pop culture topic ($overused) — pick a different film, show, band, or book";
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
    $regionText = triviaRegionCombinedText($q);

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
    } elseif ($category === 'NZ Trivia') {
        $hit = textContainsKeyword($combined, AU_KEYWORDS);
        if ($hit !== null) {
            $errors[] = "question $qNum (category '$category') is Australian-specific (matched \"$hit\") — NZ Trivia only";
        }
        if (preg_match('/\bgreat barrier reef\b/i', $combined)) {
            $errors[] = "question $qNum (category '$category') is about the Great Barrier Reef — Australia Trivia only";
        }
        if (textContainsKeyword($regionText, NZ_KEYWORDS) === null) {
            $errors[] = "question $qNum (category '$category') has no New Zealand topic — NZ Trivia must mention NZ places, people, or culture";
        }
    } elseif ($category === 'Australia Trivia') {
        $hit = textContainsKeyword($combined, NZ_KEYWORDS);
        if ($hit !== null) {
            $errors[] = "question $qNum (category '$category') is NZ-specific (matched \"$hit\") — Australia Trivia only";
        }
        if (textContainsKeyword($regionText, AU_KEYWORDS) === null) {
            $errors[] = "question $qNum (category '$category') has no Australian topic — Australia Trivia must mention Australia, its places, or culture";
        }
    }

    if ($category === 'Sports' && questionUsesBannedTournamentHostPhrasing($q['question'])) {
        $errors[] = "question $qNum (\"{$q['question']}\") uses banned tournament host phrasing — rephrase as 'In which year was [tournament] held in [place]?' (no 'hosted' / '[country] host … World Cup')";
    }

    if ($category === 'Sports' && questionUsesBannedSportsWinYearPhrasing($q['question'])) {
        $errors[] = "question $qNum (\"{$q['question']}\") uses banned win-year phrasing — use domestic league colours/stadium or a verified 'held in [place]?' host-year question instead";
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

/** Stopwords skipped when extracting distinctive tokens from MC answer text. */
const ANSWER_GIVEAWAY_STOPWORDS = [
    'the', 'and', 'with', 'from', 'that', 'this', 'have', 'were', 'been', 'their', 'which',
    'film', 'book', 'part', 'series', 'true', 'false',
];

/**
 * Distinctive 4+ letter tokens from a correct MC answer (e.g. harry, nemo, potter).
 *
 * @return string[]
 */
function answerGiveawayTokens(string $correctText): array {
    $answer = mb_strtolower(trim($correctText));
    if ($answer === 'true' || $answer === 'false') {
        return [];
    }

    preg_match_all('/\p{L}{4,}/u', $answer, $matches);
    $tokens = [];
    foreach ($matches[0] as $word) {
        if (in_array($word, ANSWER_GIVEAWAY_STOPWORDS, true)) {
            continue;
        }
        $tokens[] = $word;
    }

    return array_values(array_unique($tokens));
}

/**
 * Detects whether haystack text hints at the correct MC answer — verbatim match,
 * demonym forms (French → France), distinctive answer tokens, or close orthographic stems.
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

    foreach (answerGiveawayTokens($correctText) as $token) {
        if (preg_match('/\b' . preg_quote($token, '/') . '\b/ui', $text)) {
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

/** True when the question stem asks for a year in the MC options (superlative "first" in the stem is OK). */
function questionHasYearStemPattern(string $question): bool {
    return (bool) preg_match('/^\s*In which year (?:did|was|were)\b/i', $question);
}

/** @return string[] Human-readable topic labels found in question text or options. */
function duplicateTopicsInQuestion(array $q): array {
    $parts = [trim($q['question'] ?? '')];
    foreach ($q['options'] ?? [] as $opt) {
        $parts[] = trim($opt['text'] ?? '');
    }
    return duplicateTopicsInText(implode(' ', array_filter($parts)));
}

/** Question stem plus all option text — for region/topic checks that should see answer choices. */
function triviaRegionCombinedText(array $q): string {
    $parts = [trim($q['question'] ?? '')];
    foreach ($q['options'] ?? [] as $opt) {
        $parts[] = trim($opt['text'] ?? '');
    }
    return implode(' ', array_filter($parts));
}

/** @return string[] */
function duplicateTopicsInText(string $text): array {
    $found = [];
    foreach (DUPLICATE_QUIZ_TOPIC_PATTERNS as $pattern => $label) {
        if (preg_match($pattern, $text)) {
            $found[] = $label;
        }
    }
    return $found;
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

/**
 * Sports questions that assert "[country] hosted … World Cup" often pair the wrong year with the host.
 */
function questionUsesBannedTournamentHostPhrasing(string $question): bool {
    if (!preg_match('/\b(hosted?|hosts?)\b/i', $question)) {
        return false;
    }
    return (bool) preg_match('/\b(world cup|cricket world cup|rugby world cup|icc|olympics|olympic games)\b/i', $question);
}

/**
 * Win-year and final-opponent year questions fail fact-check often (e.g. CWC final vs Pakistan → 1999 not 1996).
 */
function questionUsesBannedSportsWinYearPhrasing(string $question): bool {
    if (preg_match(
        '/\b(win|won|winning|triumph(ed)?|lifted|capture(d)?)\b.{0,55}\b('
        . 'world cup|cricket world cup|rugby world cup|icc|premiership|grand final|ashes|sheffield shield'
        . ')\b/i',
        $question
    )) {
        return true;
    }
    if (preg_match('/\b(in which year|what year)\b/i', $question)
        && preg_match(
            '/\b(final (was )?(contested|played) against|against .{0,45} in the (final|decider)|beat .{0,45} in the final)\b/i',
            $question
        )) {
        return true;
    }
    return false;
}

/**
 * Domestic league angles that steer away from World Cup host/win deadlocks.
 */
function questionIsSportsDomesticLeague(string $question): bool {
    if (questionIsSportsWorldCupOrWinYear($question)) {
        return false;
    }
    if (preg_match('/\b(AFL|NRL|Super Rugby|A-League|Big Bash|Sheffield Shield|State of Origin|ANZAC Test)\b/i', $question)) {
        return true;
    }
    if (preg_match('/\b(home ground|home stadium|nicknamed|known for|colours|colors|stripes|jersey)\b/i', $question)
        && preg_match('/\b(club|team|side|franchise|Crusaders|Waratahs|Blues|Highlanders|Chiefs|All Blacks|Wallabies|Magpies|Tigers|Roosters|Storm|Broncos)\b/i', $question)) {
        return true;
    }
    return false;
}

function questionIsSportsWorldCupOrWinYear(string $question): bool {
    if (questionUsesBannedSportsWinYearPhrasing($question)) {
        return true;
    }
    if (questionUsesBannedTournamentHostPhrasing($question)) {
        return true;
    }
    if (questionNeedsSportsHostPremiseCheck($question)) {
        return true;
    }
    return (bool) preg_match('/\b(world cup|cricket world cup|rugby world cup|icc|olympics|olympic games)\b/i', $question);
}

/**
 * "Held in [place]" tournament questions need host-country vs year verification.
 */
function questionNeedsSportsHostPremiseCheck(string $question): bool {
    if (!preg_match('/\bheld in\b/i', $question)) {
        return false;
    }
    return (bool) preg_match('/\b(world cup|cricket|rugby|icc|olympics|olympic games|tournament)\b/i', $question);
}

function buildSportsTournamentHostQuery(string $question, string $markedAnswer): string {
    $year = trim($markedAnswer);
    if ($year !== '' && preg_match('/^\d{4}$/', $year)) {
        if (preg_match('/\b(cricket|icc)\b/i', $question)) {
            return "$year cricket world cup host country";
        }
        if (preg_match('/\brugby\b/i', $question)) {
            return "$year rugby world cup host country";
        }
        if (preg_match('/\b(football|soccer|fifa)\b/i', $question)) {
            return "$year fifa world cup host country";
        }
        return "$year world cup host country";
    }
    return buildFactCheckQuery($question) . ' world cup host';
}

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
    string $question, string $markedAnswer, array $allOptions, string $format, array $snippets,
    bool $headlineGrounding = false
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
If valid is false and snippets clearly support a different listed option instead, put that option's exact text in correct_option (empty string if valid is true or no listed option matches).

Respond with strict JSON: {"valid": true/false, "issue": "short reason if invalid, else empty string", "correct_option": "exact text of a listed option snippets support if marked is wrong, else empty string"}
PROMPT;

    if ($headlineGrounding) {
        $systemPrompt .= <<<'PROMPT'


Headline mode (snippets are unrelated news headlines from the past day):
- The question is based on ONE story. Valid if ANY headline supports the marked answer.
- Do NOT reject because other headlines mention different, unrelated stories — that is expected.
- Do NOT reject because the marked answer paraphrases a headline with different wording (e.g. "Flooding" vs "wild weather", "Hutt Valley" vs "landslip near petrol station", "South Island" vs "South Islanders in clean-up after floods").
- Do NOT reject because no headline names every detail in the answer (e.g. a suburb not spelled out in the headline).
- Reject ONLY if no headline relates to the question topic at all, OR a headline clearly contradicts the marked answer.
- If rejecting and another listed option matches the headline, put that option's exact text in correct_option.
PROMPT;
    }

    $userPrompt = "Question: $question\nFormat: $format\nOptions: $optionsBlock\nMarked correct: \"$markedAnswer\"\n\nSearch snippets:\n$snippetBlock";

    $result = callOpenAIJsonVerifier($systemPrompt, $userPrompt, true);
    if ($result === null) {
        return ['valid' => true, 'issue' => '', 'correct_option' => ''];
    }

    $valid = (bool)($result['valid'] ?? true);
    $issue = trim($result['issue'] ?? '');
    $correctOption = trim((string)($result['correct_option'] ?? ''));

    if (!$valid && $issue !== '' && factCheckIssueIsSilenceOnly($issue)) {
        return ['valid' => true, 'issue' => '', 'correct_option' => ''];
    }

    if (!$valid && $issue !== '' && factCheckIssueIsSpecificityMismatch($issue, $markedAnswer, $snippets)) {
        return ['valid' => true, 'issue' => '', 'correct_option' => ''];
    }

    return [
        'valid' => $valid,
        'issue' => $issue,
        'correct_option' => $correctOption,
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

function callOpenAIJsonVerifier(string $systemPrompt, string $userPrompt, bool $requestCorrectOption = false): ?array {
    $properties = [
        'valid' => ['type' => 'boolean'],
        'issue' => ['type' => 'string'],
    ];
    $required = ['valid', 'issue'];
    if ($requestCorrectOption) {
        $properties['correct_option'] = ['type' => 'string'];
        $required[] = 'correct_option';
    }

    $schema = [
        'type' => 'json_schema',
        'json_schema' => [
            'name'   => 'fact_check',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required'             => $required,
                'additionalProperties' => false,
            ],
        ],
    ];

    $payload = json_encode([
        'model'           => QUIZ_FACT_CHECK_MODEL,
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
function questionNeedsPremiseCheck(string $question, string $format, string $category = ''): bool {
    if ($category === 'Sports' && questionNeedsSportsHostPremiseCheck($question)) {
        return true;
    }
    if ($category === 'Geography' && questionHasGeographyExtentClaim($question)) {
        return true;
    }
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

function questionHasGeographyExtentClaim(string $question): bool {
    if (preg_match('/\bextends across\b/i', $question)) {
        return true;
    }
    return (bool) preg_match(
        '/\b(both|between|across)\b.{0,25}\b(Europe and Asia|Asia and Europe)\b/i',
        $question
    );
}

function verifyQuestionPremiseWithSnippets(
    string $question, array $snippets, bool $tournamentHostCheck = false, string $markedAnswer = ''
): array {
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

    if ($tournamentHostCheck) {
        $systemPrompt .= <<<'PROMPT'

Tournament host mode:
- The question names a host country or city for a World Cup / major tournament (e.g. "held in New Zealand").
- If a marked year is provided, verify that place was actually a host for that tournament in THAT year according to the snippets.
- Reject when snippets show a different host country for that year (e.g. 2011 Cricket World Cup was in India/Sri Lanka/Bangladesh, not New Zealand).
- Reject when snippets show the named place hosted in a different year than the marked answer.
- If no marked year is provided, verify only explicit factual claims in the question stem (not which option is correct).
PROMPT;
    }

    if (questionHasGeographyExtentClaim($question)) {
        $systemPrompt .= <<<'PROMPT'

Geography extent mode:
- The question claims a geographic feature spans or connects Europe and Asia (or "both continents").
- Reject if snippets show the named feature lies wholly in one continent (e.g. Himalayas are in Asia, not Europe).
- The conventional Europe–Asia boundary is the Ural Mountains, Ural River, Caspian Sea, Caucasus — not the Himalayas.
PROMPT;
    }

    $userPrompt = "Question text to verify:\n$question\n\nSearch snippets:\n$snippetBlock";
    if ($tournamentHostCheck && preg_match('/^\d{4}$/', trim($markedAnswer))) {
        $userPrompt .= "\n\nMarked correct answer year: $markedAnswer — verify this year matches the host place named in the question.";
    }

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
- If valid is false, put the supported option's exact text in correct_option (empty string if valid is true).

Respond with strict JSON: {"valid": true/false, "issue": "short reason if invalid, else empty string", "correct_option": "exact text of the supported listed option if marked is wrong, else empty string"}
PROMPT;

    $userPrompt = "Question: $question\nOptions: $optionsBlock\nMarked correct: \"$markedAnswer\"\n\nSearch snippets:\n$snippetBlock";

    $result = callOpenAIJsonVerifier($systemPrompt, $userPrompt, true);
    if ($result === null) {
        return ['valid' => true, 'issue' => '', 'correct_option' => ''];
    }

    $valid = (bool)($result['valid'] ?? true);
    $issue = trim($result['issue'] ?? '');
    $correctOption = trim((string)($result['correct_option'] ?? ''));

    if (!$valid && $issue !== '' && factCheckIssueIsSilenceOnly($issue)) {
        return ['valid' => true, 'issue' => '', 'correct_option' => ''];
    }

    return [
        'valid' => $valid,
        'issue' => $issue,
        'correct_option' => $correctOption,
    ];
}

/**
 * Fact-checks a single question (premise, answer, distractor). Used during category
 * validation and the pre-publish final gate. May auto-correct the marked answer
 * when snippets support a different option already in the list.
 *
 * @return string[] Violation descriptions (empty if pass, skipped, or corrected).
 */
function factCheckOneQuestion(
    array &$q, int $qNum, string $category, array $headlinesByRegion, string $logLabel = ''
): array {
    if ($logLabel === '') {
        $logLabel = "[$category]";
    }

    if (!defined('TAVILY_API_KEY') || TAVILY_API_KEY === '') {
        if (!in_array($category, CURRENT_EVENTS_CATEGORIES, true)) {
            return [];
        }
    }

    $markedAnswer = '';
    foreach ($q['options'] as $opt) {
        if ($opt['correct']) {
            $markedAnswer = $opt['text'];
            break;
        }
    }

    $tournamentHostCheck = $category === 'Sports' && questionNeedsSportsHostPremiseCheck($q['question']);

    if ($category === 'NZ Current Events') {
        $snippets = array_map(fn($h) => 'Headline: ' . $h, $headlinesByRegion['NZ'] ?? []);
    } elseif ($category === 'Aussie Current Events') {
        $snippets = array_map(fn($h) => 'Headline: ' . $h, $headlinesByRegion['AU'] ?? []);
    } else {
        $query = $tournamentHostCheck
            ? buildSportsTournamentHostQuery($q['question'], $markedAnswer)
            : buildFactCheckQuery($q['question']);
        $snippets = tavilySearch($query);
        if ($snippets === null) {
            bumpQuizGenStat('fact_check_skips');
            logQuizGenError("$logLabel [fact-check] Tavily unavailable — skipped fact-check for question $qNum");
            return [];
        }
    }

    if (!$snippets) {
        bumpQuizGenStat('fact_check_skips');
        logQuizGenError("$logLabel [fact-check] No snippets — skipped fact-check for question $qNum");
        return [];
    }

    $errors = [];

    if (questionNeedsPremiseCheck($q['question'], $q['format'], $category)) {
        logQuizGenInfo("$logLabel question $qNum [fact-check premise]: checking question text…");
        $premiseVerdict = verifyQuestionPremiseWithSnippets(
            $q['question'], $snippets, $tournamentHostCheck, $markedAnswer
        );
        if (!$premiseVerdict['valid']) {
            $reason = $premiseVerdict['issue'] !== ''
                ? $premiseVerdict['issue']
                : 'question contains an inaccurate date or statistic';
            $errors[] = "question $qNum [fact-check premise]: question text rejected — $reason";
            return $errors;
        }
        logQuizGenInfo("$logLabel question $qNum [fact-check premise]: passed");
    }

    $verdict = verifyAnswerWithSnippets(
        $q['question'], $markedAnswer, $q['options'], $q['format'], $snippets,
        in_array($category, CURRENT_EVENTS_CATEGORIES, true)
    );

    if (!$verdict['valid']) {
        $correctOption = resolveFactCheckCorrectOption($verdict, $q['options']);
        if ($correctOption !== '' && tryApplyFactCheckCorrection($q, $qNum, $category, $correctOption, $logLabel, $markedAnswer)) {
            return [];
        }
        $reason = $verdict['issue'] !== '' ? $verdict['issue'] : 'marked answer not supported by sources';
        $errors[] = "question $qNum [fact-check]: marked answer \"$markedAnswer\" rejected — $reason";
        return $errors;
    }

    if ($q['format'] === 'mc' && !in_array($category, CURRENT_EVENTS_CATEGORIES, true)) {
        $distractorVerdict = verifyNoDistractorIsCorrectWithSnippets(
            $q['question'], $markedAnswer, $q['options'], $snippets
        );
        if (!$distractorVerdict['valid']) {
            $correctOption = resolveFactCheckCorrectOption($distractorVerdict, $q['options']);
            if ($correctOption !== '' && tryApplyFactCheckCorrection($q, $qNum, $category, $correctOption, $logLabel, $markedAnswer)) {
                return [];
            }
            $reason = $distractorVerdict['issue'] !== ''
                ? $distractorVerdict['issue']
                : 'a wrong option is better supported by sources than the marked answer';
            $errors[] = "question $qNum [fact-check distractor]: $reason";
        }
    }

    return $errors;
}

/** Flip the marked-correct flag to an option already in the list. */
function setQuestionCorrectOption(array &$q, string $correctText): bool {
    $found = false;
    foreach ($q['options'] as &$opt) {
        $match = strcasecmp(trim($opt['text']), trim($correctText)) === 0;
        $opt['correct'] = $match;
        if ($match) {
            $found = true;
        }
    }
    unset($opt);
    return $found;
}

/**
 * Resolve which listed option snippets support from a verifier verdict or issue text.
 */
function resolveFactCheckCorrectOption(array $verdict, array $options): string {
    $fromField = trim((string)($verdict['correct_option'] ?? ''));
    if ($fromField !== '' && optionTextIsInList($fromField, $options)) {
        return $fromField;
    }
    return extractSupportedOptionFromIssue($verdict['issue'] ?? '', $options) ?? '';
}

function optionTextIsInList(string $text, array $options): bool {
    foreach ($options as $opt) {
        if (strcasecmp(trim($opt['text']), trim($text)) === 0) {
            return true;
        }
    }
    return false;
}

/** Match option text mentioned in a fact-check issue string. Prefers longest match. */
function extractSupportedOptionFromIssue(string $issue, array $options): ?string {
    if ($issue === '') {
        return null;
    }
    $best = null;
    $bestLen = 0;
    foreach ($options as $opt) {
        $text = trim($opt['text']);
        if ($text === '') {
            continue;
        }
        if (preg_match('/["\']' . preg_quote($text, '/') . '["\']/i', $issue)
            || stripos($issue, $text) !== false) {
            $len = mb_strlen($text);
            if ($len > $bestLen) {
                $best = $text;
                $bestLen = $len;
            }
        }
    }
    return $best;
}

/** After flipping the marked answer, ensure question/image_query do not give it away. */
function questionHasAnswerGiveawayViolations(array $q, string $category, int $qNum): array {
    $errors = [];
    $correctText = '';
    foreach ($q['options'] as $opt) {
        if ($opt['correct']) {
            $correctText = $opt['text'];
            break;
        }
    }
    if ($correctText === '') {
        return ['question $qNum has no marked correct answer after correction'];
    }
    if (($q['format'] ?? '') === 'mc' && answerGiveawayInText($correctText, $q['question'])) {
        $errors[] = "question $qNum gives away its own answer — correct answer \"$correctText\" is hinted in the question text (\"{$q['question']}\")";
    }
    $imageQuery = trim($q['image_query'] ?? '');
    if ($imageQuery !== '' && ($q['format'] ?? '') === 'mc' && answerGiveawayInText($correctText, $imageQuery)) {
        $errors[] = "question $qNum image_query gives away the correct answer — \"$correctText\" is hinted in image_query \"$imageQuery\"";
    }
    return $errors;
}

function tryApplyFactCheckCorrection(
    array &$q, int $qNum, string $category, string $correctOption, string $logLabel, string $oldMarked
): bool {
    if (strcasecmp(trim($correctOption), trim($oldMarked)) === 0) {
        return false;
    }
    if (!setQuestionCorrectOption($q, $correctOption)) {
        return false;
    }
    if (questionHasAnswerGiveawayViolations($q, $category, $qNum)) {
        setQuestionCorrectOption($q, $oldMarked);
        return false;
    }
    bumpQuizGenStat('answer_corrections');
    logQuizGenInfo("$logLabel question $qNum [fact-check]: corrected marked answer from \"$oldMarked\" to \"$correctOption\"");
    return true;
}

/**
 * @return string[] Violation descriptions (empty if all questions pass).
 */
function factCheckCategoryQuestions(array &$questions, string $category, array $headlinesByRegion): array {
    $errors = [];
    foreach ($questions as $i => &$q) {
        $errors = array_merge(
            $errors,
            factCheckOneQuestion($q, $i + 1, $category, $headlinesByRegion)
        );
    }
    unset($q);
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
