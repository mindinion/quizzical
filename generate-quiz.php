<?php
/**
 * generate-quiz.php
 *
 * CLI-only cron script. Assembles 15 questions from QuizQuestionBank (11) plus
 * AI-generated Current Events (4), and inserts into AIQuiz / AIQuestion / AIOption.
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

$quizType = ucfirst($type);

require_once __DIR__ . '/dblogin.php';
require_once __DIR__ . '/quiz-generator.php';

$nztz = new DateTimeZone('Pacific/Auckland');
$today = (new DateTime('now', $nztz))->format('Y-m-d');

$stmt = $conn->prepare("SELECT id FROM AIQuiz WHERE type = ? AND date = ?");
$stmt->bind_param('ss', $quizType, $today);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    echo "Quiz already exists for $quizType on $today — skipping.\n";
    exit(0);
}
$stmt->close();

$recentQuestions = fetchRecentQuestions($conn, $today, 5);
$recentTopicLabels = fetchRecentTopicLabels($conn, $today);

cleanupQuizImages($conn);

try {
    $questions = generateQuizQuestions($type, $today, $recentQuestions, $recentTopicLabels, $conn);
} catch (Throwable $e) {
    logQuizGenError('Quiz generation failed: ' . $e->getMessage());
    notifySuperusersOfFailure($conn, "$quizType quiz for $today failed to generate", $e->getMessage());
    exit(1);
}

$bankIds = [];
foreach ($questions as $q) {
    if (!empty($q['bank_id'])) {
        $bankIds[] = (int)$q['bank_id'];
    }
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("INSERT INTO AIQuiz (type, date) VALUES (?, ?)");
    $stmt->bind_param('ss', $quizType, $today);
    $stmt->execute();
    $quizId = $conn->insert_id;
    $stmt->close();

    $hasBankCols = aiQuestionHasBankColumns($conn);
    if ($hasBankCols) {
        $stmtQ = $conn->prepare(
            "INSERT INTO AIQuestion (quiz_id, position, question_text, category, format, bank_id, source) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
    } else {
        $stmtQ = $conn->prepare(
            "INSERT INTO AIQuestion (quiz_id, position, question_text, category, format) VALUES (?, ?, ?, ?, ?)"
        );
    }
    $stmtO = $conn->prepare("INSERT INTO AIOption (question_id, position, option_text, is_correct) VALUES (?, ?, ?, ?)");
    $stmtImg = $conn->prepare("UPDATE AIQuestion SET image_path = ?, image_attribution = ? WHERE id = ?");

    $imagesRoot = quizImagesRoot() . DIRECTORY_SEPARATOR . $quizId;
    $imagesFetched = 0;

    foreach ($questions as $q) {
        $pos      = (int)$q['position'];
        $text     = $q['question'];
        $category = $q['category'];
        $format   = $q['format'];
        $bankId   = !empty($q['bank_id']) ? (int)$q['bank_id'] : null;
        $source   = $q['source'] ?? ($bankId ? 'bank' : 'ai');

        if ($hasBankCols) {
            $stmtQ->bind_param('iisssis', $quizId, $pos, $text, $category, $format, $bankId, $source);
        } else {
            $stmtQ->bind_param('iisss', $quizId, $pos, $text, $category, $format);
        }
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

    markBankQuestionsUsed($conn, $bankIds);

    $stmtQ->close();
    $stmtO->close();
    $stmtImg->close();
    $conn->commit();

    $stats = getQuizGenStats();
    echo "Generated $quizType quiz (ID $quizId) for $today — " . count($questions) . " questions, $imagesFetched images. "
        . "Bank: " . count($bankIds) . ", CE: " . (count($questions) - count($bankIds)) . ". "
        . "Fact-check: {$stats['categories_retried']} categories retried.\n";

} catch (Exception $e) {
    $conn->rollback();
    logQuizGenError("DB insert failed: " . $e->getMessage());
    notifySuperusersOfFailure($conn, "$quizType quiz for $today failed to save", $e->getMessage());
    exit(1);
}

$conn->close();
exit(0);

function aiQuestionHasBankColumns(mysqli $conn): bool {
    $r = $conn->query("SHOW COLUMNS FROM AIQuestion LIKE 'bank_id'");
    return $r && $r->num_rows > 0;
}

function notifySuperusersOfFailure(mysqli $conn, string $summary, string $detail): void {
    $result = $conn->query("SELECT email FROM Users WHERE superuser = 1");
    if (!$result) return;

    $subject = "Quizzical: $summary";
    $body = "$summary\n\n$detail\n\nCheck logs/generate-quiz.log on the server for the full attempt history.";

    while ($row = $result->fetch_assoc()) {
        sendMail($conn, $row['email'], $subject, $body, true);
    }
}
