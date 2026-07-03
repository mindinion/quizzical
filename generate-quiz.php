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

// Same-day sibling quiz (other slot) so we don't repeat its topics
$siblingQuestions = fetchSiblingQuestions($conn, $today, $quizType);

try {
    $questions = generateQuizQuestions($type, $today, $siblingQuestions);
} catch (RuntimeException $e) {
    logQuizGenError($e->getMessage());
    exit(1);
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
    }

    $stmtQ->close();
    $stmtO->close();
    $conn->commit();
    echo "Generated $quizType quiz (ID $quizId) for $today — " . count($questions) . " questions.\n";

} catch (Exception $e) {
    $conn->rollback();
    logQuizGenError("DB insert failed: " . $e->getMessage());
    exit(1);
}

$conn->close();
exit(0);

// ---------------------------------------------------------------------------

function fetchSiblingQuestions(mysqli $conn, string $today, string $quizType): array {
    $otherType = ($quizType === 'Morning') ? 'Afternoon' : 'Morning';
    $stmt = $conn->prepare(
        "SELECT q.question_text FROM AIQuestion q
         JOIN AIQuiz qz ON qz.id = q.quiz_id
         WHERE qz.date = ? AND qz.type = ?"
    );
    $stmt->bind_param('ss', $today, $otherType);
    $stmt->execute();
    $result = $stmt->get_result();
    $questions = array_map(fn($row) => $row['question_text'], $result->fetch_all(MYSQLI_ASSOC));
    $stmt->close();
    return $questions;
}
