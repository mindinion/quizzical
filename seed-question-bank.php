<?php
/**
 * seed-question-bank.php
 *
 * Populates QuizQuestionBank from OpenTDB and OpenTriviaQA.
 *
 * Usage:
 *   php seed-question-bank.php --full       Initial bulk import
 *   php seed-question-bank.php --incremental   Weekly top-up (OpenTDB only)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/dblogin.php';
require_once __DIR__ . '/question-bank.php';

$full = in_array('--full', $argv, true);
$incremental = in_array('--incremental', $argv, true);
if (!$full && !$incremental) {
    fwrite(STDERR, "Usage: php seed-question-bank.php --full|--incremental\n");
    exit(1);
}

if (!bankTableExists($conn)) {
    fwrite(STDERR, "QuizQuestionBank table missing. Run question-bank-migration.sql first.\n");
    exit(1);
}

echo "Seeding question bank (" . ($full ? 'full' : 'incremental') . ")…\n";

if ($full) {
    echo "OpenTriviaQA import…\n";
    $otqa = bankSeedOpenTriviaQa($conn);
    foreach ($otqa as $cat => $n) {
        echo "  OpenTriviaQA $cat: +$n\n";
    }
}

echo "OpenTDB import…\n";
$otdb = bankSeedOpenTdb($conn, $full);
foreach ($otdb as $cat => $n) {
    echo "  OpenTDB $cat: +$n\n";
}

$counts = bankTotalCounts($conn);
echo "\nPool totals:\n";
foreach (BANK_CATEGORY_TARGETS as $cat => $_) {
    $total = $counts[$cat]['total'] ?? 0;
    $avail = $counts[$cat]['available'] ?? 0;
    echo "  $cat: $total total, $avail available (90-day cooldown)\n";
}

$health = bankPoolHealth($conn);
foreach ($health as $cat => $h) {
    $runway = $h['runway_days'];
    if ($runway < 30) {
        echo "WARNING: $cat runway only {$runway} days\n";
    }
}

$conn->close();
echo "Done.\n";
