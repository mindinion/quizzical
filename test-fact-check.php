<?php
/**
 * test-fact-check.php
 *
 * CLI tool to test web-search fact-checking on any question + marked answer pair.
 *
 * Usage:
 *   php test-fact-check.php "What public holiday in NZ takes place in October?" "Matariki"
 *   php test-fact-check.php "True or False: The NZ flag has three Southern Cross stars." "True"
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/quiz-generator.php';

$question = $argv[1] ?? '';
$answer   = $argv[2] ?? '';

if ($question === '' || $answer === '') {
    fwrite(STDERR, "Usage: php test-fact-check.php \"question text\" \"marked answer\"\n");
    exit(1);
}

$isTf = (bool)preg_match('/^\s*true or false\s*:/i', $question);
$format = $isTf ? 'tf' : 'mc';

if ($format === 'tf') {
    $options = [
        ['text' => 'True',  'correct' => strcasecmp($answer, 'True') === 0],
        ['text' => 'False', 'correct' => strcasecmp($answer, 'False') === 0],
    ];
} else {
    $options = [
        ['text' => $answer, 'correct' => true],
        ['text' => 'Wrong option A', 'correct' => false],
        ['text' => 'Wrong option B', 'correct' => false],
        ['text' => 'Wrong option C', 'correct' => false],
    ];
}

$query = buildFactCheckQuery($question);
echo "Search query: $query\n\n";

$snippets = tavilySearch($query);
if ($snippets === null) {
    echo "Tavily search failed or TAVILY_API_KEY not set.\n";
    exit(1);
}

foreach ($snippets as $i => $snippet) {
    echo '--- Snippet ' . ($i + 1) . " ---\n$snippet\n\n";
}

$verdict = verifyAnswerWithSnippets($question, $answer, $options, $format, $snippets);

echo "Verdict: " . ($verdict['valid'] ? 'VALID (pass)' : 'INVALID (reject)') . "\n";
if (!$verdict['valid'] && $verdict['issue'] !== '') {
    echo "Issue: {$verdict['issue']}\n";
}

exit($verdict['valid'] ? 0 : 2);
