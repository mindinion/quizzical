<?php
/**
 * generate-quiz.php
 *
 * CLI-only cron script. Fetches recent news headlines, calls OpenAI to generate
 * 15 quiz questions, and inserts them into AIQuiz / AIQuestion / AIOption.
 * Generation logic lives in quiz-generator.php, shared with the ad hoc
 * admin preview endpoint (action-test-generate-quiz.php), which does not write to the DB.
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
require_once __DIR__ . '/quiz-generator.php';

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

// Last 3 days of quizzes (both slots) so we don't repeat a topic that's still
// fresh even after it's rotated out of the headline window
$recentQuestions = fetchRecentQuestions($conn, $today, 5);

cleanupQuizImages($conn);

try {
    $questions = generateQuizQuestionsWithFinalGate($type, $today, $recentQuestions);
} catch (Throwable $e) {
    logQuizGenError('Final-gate path failed, falling back to direct generation: ' . $e->getMessage());
    $questions = generateQuizQuestions($type, $today, $recentQuestions);
}

$stats = getQuizGenStats();
if (($stats['category_cap_fallbacks'] ?? 0) > 0
    || ($stats['category_emergency_fallbacks'] ?? 0) > 0
    || ($stats['final_gate_cap_fallbacks'] ?? 0) > 0) {
    logQuizGenError('Quiz published with best-effort fallbacks — review recommended. Stats: ' . json_encode($stats));
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
    $stmtImg = $conn->prepare("UPDATE AIQuestion SET image_path = ?, image_attribution = ? WHERE id = ?");

    $imagesRoot = quizImagesRoot() . DIRECTORY_SEPARATOR . $quizId;
    $imagesFetched = 0;

    foreach ($questions as $q) {
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

        $imageQuery = trim($q['image_query'] ?? '');
        if ($imageQuery !== '') {
            $imageResult = fetchQuestionImage($imageQuery, $imagesRoot, (string)$questionId);
            if ($imageResult) {
                $relPath = relativeQuizImagePath($imageResult['path']);
                $attribution = $imageResult['attribution'];
                $stmtImg->bind_param('ssi', $relPath, $attribution, $questionId);
                $stmtImg->execute();
                $imagesFetched++;
            } else {
                logQuizGenError("Image fetch failed for question $questionId (query: $imageQuery)");
            }
        }
    }

    $stmtQ->close();
    $stmtO->close();
    $stmtImg->close();
    $conn->commit();

    $stats = getQuizGenStats();
    echo "Generated $quizType quiz (ID $quizId) for $today — " . count($questions) . " questions, $imagesFetched images. "
        . "Fact-check: {$stats['categories_retried']} categories retried, {$stats['fact_check_skips']} skipped. "
        . "Final gate: {$stats['final_gate_quiz_attempts']} attempt(s), {$stats['final_gate_rejections']} rejection(s). "
        . "Fallbacks: {$stats['category_cap_fallbacks']} category cap, {$stats['category_emergency_fallbacks']} emergency, {$stats['final_gate_cap_fallbacks']} gate cap.\n";

} catch (Exception $e) {
    $conn->rollback();
    logQuizGenError("DB insert failed: " . $e->getMessage());
    notifySuperusersOfFailure($conn, "$quizType quiz for $today failed to save", $e->getMessage());
    exit(1);
}

$conn->close();
exit(0);

// ---------------------------------------------------------------------------

/**
 * A missing quiz slot is otherwise silent — nobody notices until someone opens
 * the app looking for it. Email every superuser so a full generation failure
 * gets seen within minutes instead of discovered later.
 */
function notifySuperusersOfFailure(mysqli $conn, string $summary, string $detail): void {
    $result = $conn->query("SELECT email FROM Users WHERE superuser = 1");
    if (!$result) return;

    $subject = "Quizzical: $summary";
    $body = "$summary\n\n$detail\n\nCheck logs/generate-quiz.log on the server for the full attempt history.";

    while ($row = $result->fetch_assoc()) {
        sendMail($conn, $row['email'], $subject, $body, true);
    }
}
