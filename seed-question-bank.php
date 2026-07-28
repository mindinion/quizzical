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
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/question-bank.php';

$full = in_array('--full', $argv, true);
$incremental = in_array('--incremental', $argv, true);
if (!$full && !$incremental) {
    fwrite(STDERR, "Usage: php seed-question-bank.php --full|--incremental\n");
    exit(1);
}

if (!bankTableExists($conn)) {
    $msg = "QuizQuestionBank table missing. Run question-bank-migration.sql first.\n";
    fwrite(STDERR, $msg);
    notifySeedSuperusers($conn, 'Question bank seed failed', trim($msg), true);
    exit(1);
}

$modeLabel = $full ? 'full' : 'incremental';
$log = [];

try {
    $log[] = "Seeding question bank ($modeLabel)…";

    $otqa = [];
    if ($full) {
        $log[] = 'OpenTriviaQA import…';
        $otqa = bankSeedOpenTriviaQa($conn);
        foreach ($otqa as $cat => $n) {
            $log[] = "  OpenTriviaQA $cat: +$n";
        }
    }

    $log[] = 'OpenTDB import…';
    $otdb = bankSeedOpenTdb($conn, $full);
    foreach ($otdb as $cat => $n) {
        $log[] = "  OpenTDB $cat: +$n";
    }

    $counts = bankTotalCounts($conn);
    $log[] = '';
    $log[] = 'Pool totals:';
    foreach (BANK_CATEGORY_TARGETS as $cat => $_) {
        $total = $counts[$cat]['total'] ?? 0;
        $avail = $counts[$cat]['available'] ?? 0;
        $log[] = "  $cat: $total total, $avail available (90-day cooldown)";
    }

    $health = bankPoolHealth($conn);
    $warnings = [];
    foreach ($health as $cat => $h) {
        $runway = $h['runway_days'];
        if ($runway < 30) {
            $warnings[] = "WARNING: $cat runway only {$runway} days";
        }
    }
    foreach ($warnings as $w) {
        $log[] = $w;
    }

    $added = 0;
    foreach ($otdb as $n) {
        $added += (int)$n;
    }
    if ($full) {
        foreach ($otqa as $n) {
            $added += (int)$n;
        }
    }

    $subject = "Quizzical: question bank $modeLabel seed complete (+$added new)";
    notifySeedSuperusers($conn, $subject, implode("\n", $log), !empty($warnings));
} catch (Throwable $e) {
    $detail = $e->getMessage();
    $log[] = "ERROR: $detail";
    echo implode("\n", $log) . "\n";
    notifySeedSuperusers($conn, "Question bank $modeLabel seed failed", implode("\n", $log), true);
    $conn->close();
    exit(1);
}

foreach ($log as $line) {
    echo $line . "\n";
}
echo "Done.\n";

$conn->close();
exit(0);

function notifySeedSuperusers(mysqli $conn, string $subject, string $body, bool $isFailure): void {
    $result = $conn->query('SELECT email FROM Users WHERE superuser = 1');
    if (!$result) {
        return;
    }
    while ($row = $result->fetch_assoc()) {
        sendMail($conn, $row['email'], $subject, $body, true);
    }
    if ($isFailure) {
        fwrite(STDERR, $body . "\n");
    }
}
