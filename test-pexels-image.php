<?php
/**
 * test-pexels-image.php
 *
 * CLI/server diagnostic for Pexels image fetch.
 * Usage: php test-pexels-image.php [search query]
 */

require_once __DIR__ . '/quiz-generator.php';

$query = $argv[1] ?? 'rugby stadium crowd';

echo "PEXELS_API_KEY: " . (defined('PEXELS_API_KEY') && PEXELS_API_KEY !== '' ? 'set (' . strlen(PEXELS_API_KEY) . ' chars)' : 'NOT SET') . "\n";
echo "curl: " . (function_exists('curl_init') ? 'yes' : 'NO') . "\n";
echo "gd: " . (extension_loaded('gd') ? 'yes' : 'no (will try raw save fallback)') . "\n";
echo "quiz-images dir writable: " . (is_writable(quizImagesRoot()) || @mkdir(quizImagesRoot(), 0755, true) ? 'yes' : 'NO') . "\n";
echo "Query: \"$query\"\n\n";

$destDir = quizImagesRoot() . '/preview/test-' . date('Ymd-His');
$result = fetchQuestionImage($query, $destDir, 'test');

if ($result) {
    echo "OK — saved to {$result['path']}\n";
    echo "Web path: " . relativeQuizImagePath($result['path']) . "\n";
    echo "Attribution: {$result['attribution']}\n";
    exit(0);
}

echo "FAILED — check generate-quiz.log or run with QUIZ_GEN_VERBOSE_LOG for details above.\n";
exit(1);
