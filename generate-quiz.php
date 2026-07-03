<?php
/**
 * generate-quiz.php
 *
 * CLI-only cron script. Fetches recent news headlines, calls OpenAI to generate
 * 15 quiz questions, and inserts them into AIQuiz / AIQuestion / AIOption.
 *
 * Usage:
 *   php generate-quiz.php morning
 *   php generate-quiz.php afternoon
 *
 * Cron (times in NZST UTC+12 — adjust offset for your server timezone):
 *   30 20 * * *  php /path/to/v2/generate-quiz.php morning    # 8:30am NZT next day
 *   30 02 * * *  php /path/to/v2/generate-quiz.php afternoon  # 2:30pm NZT
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$type = isset($argv[1]) ? strtolower(trim($argv[1])) : '';
if ($type !== 'morning' && $type !== 'afternoon') {
    fwrite(STDERR, "Usage: php generate-quiz.php morning|afternoon\n");
    exit(1);
}

$quizType = ucfirst($type); // 'Morning' or 'Afternoon'

require_once __DIR__ . '/dblogin.php';
require_once __DIR__ . '/config.php';

$nztz = new DateTimeZone('Pacific/Auckland');
$today = (new DateTime('now', $nztz))->format('Y-m-d');

// Bail if a quiz already exists for this date + type
$stmt = $conn->prepare("SELECT id FROM AIQuiz WHERE type = ? AND date = ?");
$stmt->bind_param('ss', $quizType, $today);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    echo "Quiz already exists for $quizType on $today — skipping.\n";
    exit(0);
}
$stmt->close();

// --- Fetch recent headlines from free RSS feeds ---
$headlines = fetchHeadlines();

// --- Build the OpenAI prompt ---
$headlineBlock = implode("\n", array_map(fn($h) => '- ' . $h, $headlines));

$systemPrompt = <<<PROMPT
You are generating a daily quiz for a New Zealand audience. The quiz has 15 questions covering:
NZ Trivia, Australia Trivia, Sports, Current Events, Geography, History, General Knowledge.

Target category distribution per quiz (approximate):
- NZ Trivia: 2–3 questions
- Australia Trivia: 2 questions
- Sports: 2 questions
- Current Events: 2 questions (MUST be based on the provided headlines)
- Geography: 2 questions
- History: 2 questions
- General Knowledge: 2–3 questions

Format rules:
- 13 questions must be multiple choice (format: "mc") with exactly 4 options, exactly 1 correct.
- 2 questions must be true/false (format: "tf") with exactly 2 options ("True" and "False"), exactly 1 correct.
- For 3–4 of the MC questions, include one answer option that sounds almost plausible but is subtly absurd — the kind that makes you wonder for a moment before realising it's wrong. Do NOT make it obviously silly or comedic. It should sound like a real answer.

Difficulty: The quiz should be genuinely challenging. A knowledgeable player should score around 10–11 out of 15. An average player should score 7–9. Getting 15/15 should be rare.

Do NOT ask questions that are too easy (e.g., "What is the capital of Australia?") unless you make the wrong answers very plausible.

For Current Events questions, you MUST draw from the provided headlines. Phrase the question naturally — do not say "according to recent news" or "as reported today".

Return exactly 15 questions.
PROMPT;

$userPrompt = "Today is $today. Here are recent news headlines from NZ and Australia:\n\n$headlineBlock\n\nGenerate the quiz now.";

// --- Call OpenAI ---
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
                                'enum' => ['NZ Trivia','Australia Trivia','Sports','Current Events','Geography','History','General Knowledge'],
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

$payload = json_encode([
    'model'           => 'gpt-4o-mini',
    'messages'        => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $userPrompt],
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
    logError("cURL error: $curlError");
    exit(1);
}

$decoded = json_decode($response, true);
if (!isset($decoded['choices'][0]['message']['content'])) {
    logError("Unexpected OpenAI response: $response");
    exit(1);
}

$quizJson = json_decode($decoded['choices'][0]['message']['content'], true);
if (!isset($quizJson['questions']) || count($quizJson['questions']) !== 15) {
    logError("Invalid quiz structure — expected 15 questions, got: " . json_encode($quizJson));
    exit(1);
}

// --- Validate each question ---
foreach ($quizJson['questions'] as $i => $q) {
    $correctCount = 0;
    foreach ($q['options'] as $opt) {
        if ($opt['correct']) $correctCount++;
    }
    if ($correctCount !== 1) {
        logError("Question " . ($i+1) . " has $correctCount correct answers (expected 1)");
        exit(1);
    }
    $expectedOptions = ($q['format'] === 'tf') ? 2 : 4;
    if (count($q['options']) !== $expectedOptions) {
        logError("Question " . ($i+1) . " ({$q['format']}) has " . count($q['options']) . " options (expected $expectedOptions)");
        exit(1);
    }
}

// --- Insert into DB ---
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("INSERT INTO AIQuiz (type, date) VALUES (?, ?)");
    $stmt->bind_param('ss', $quizType, $today);
    $stmt->execute();
    $quizId = $conn->insert_id;
    $stmt->close();

    $stmtQ = $conn->prepare("INSERT INTO AIQuestion (quiz_id, position, question_text, category, format) VALUES (?, ?, ?, ?, ?)");
    $stmtO = $conn->prepare("INSERT INTO AIOption (question_id, position, option_text, is_correct) VALUES (?, ?, ?, ?)");

    foreach ($quizJson['questions'] as $q) {
        $pos      = (int)$q['position'];
        $text     = $q['question'];
        $category = $q['category'];
        $format   = $q['format'];

        $stmtQ->bind_param('iisss', $quizId, $pos, $text, $category, $format);
        $stmtQ->execute();
        $questionId = $conn->insert_id;

        foreach ($q['options'] as $oIdx => $opt) {
            $oPos      = $oIdx + 1;
            $oText     = $opt['text'];
            $isCorrect = $opt['correct'] ? 1 : 0;
            $stmtO->bind_param('iisi', $questionId, $oPos, $oText, $isCorrect);
            $stmtO->execute();
        }
    }

    $stmtQ->close();
    $stmtO->close();
    $conn->commit();
    echo "Generated $quizType quiz (ID $quizId) for $today — " . count($quizJson['questions']) . " questions.\n";

} catch (Exception $e) {
    $conn->rollback();
    logError("DB insert failed: " . $e->getMessage());
    exit(1);
}

$conn->close();
exit(0);

// ---------------------------------------------------------------------------

function fetchHeadlines(): array {
    $feeds = [
        'https://www.rnz.co.nz/rss/national.rss',
        'https://www.abc.net.au/news/feed/51120/rss.xml',
        'https://news.google.com/rss?hl=en-NZ&gl=NZ&ceid=NZ:en',
    ];

    $cutoff   = time() - (36 * 3600); // last 36 hours
    $headlines = [];

    foreach ($feeds as $url) {
        $xml = fetchRss($url);
        if (!$xml) continue;

        // Support both RSS <item> and Atom <entry>
        $items = $xml->channel->item ?? $xml->entry ?? [];
        foreach ($items as $item) {
            $pubDate = (string)($item->pubDate ?? $item->published ?? '');
            if ($pubDate && strtotime($pubDate) < $cutoff) continue;

            $title = trim(strip_tags((string)($item->title ?? '')));
            if ($title) $headlines[] = $title;
        }
    }

    // Deduplicate and cap at 30 headlines for the prompt
    $headlines = array_values(array_unique($headlines));
    return array_slice($headlines, 0, 30);
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

function logError(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] ERROR: $msg\n";
    file_put_contents(__DIR__ . '/logs/generate-quiz.log', $line, FILE_APPEND | LOCK_EX);
    fwrite(STDERR, $line);
}
