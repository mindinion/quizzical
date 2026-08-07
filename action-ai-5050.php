<?php
/**
 * action-ai-5050.php
 *
 * Use a 50/50 lifeline on an unanswered MC question (max 2 per quiz).
 * Removes two wrong options; scoring unchanged (full points if correct).
 *
 * Params (GET):
 *   quiz_id, question_id
 *
 * Response:
 *   { eliminated_option_ids: [int, int], remaining: int }
 */

require_once 'require_auth.php';
require_once __DIR__ . '/ai-quiz-stats.php';
require_once __DIR__ . '/ai-lifeline.php';

$quizId = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
$questionId = isset($_GET['question_id']) ? (int)$_GET['question_id'] : 0;

if (!$quizId || !$questionId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

ensureAiLifelineTable($conn);

$stmt = $conn->prepare("SELECT id, type, date, status FROM AIQuiz WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $quizId);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$quiz) {
    http_response_code(404);
    echo json_encode(['error' => 'Quiz not found']);
    exit;
}

$resultType = 'Quizzical ' . $quiz['type'];
$stmt = $conn->prepare(
    "SELECT id FROM Results WHERE user = ? AND status = 'active' AND " . aiResultMatchSql($conn) . " LIMIT 1"
);
$stmt->bind_param('iiss', $userid, $quizId, $resultType, $quiz['date']);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $stmt->close();
    http_response_code(403);
    echo json_encode(['error' => 'Quiz already completed']);
    exit;
}
$stmt->close();

$stmt = $conn->prepare(
    "SELECT id, format FROM AIQuestion WHERE id = ? AND quiz_id = ?"
);
$stmt->bind_param('ii', $questionId, $quizId);
$stmt->execute();
$question = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$question) {
    http_response_code(400);
    echo json_encode(['error' => 'Question not found in quiz']);
    exit;
}
if (($question['format'] ?? '') !== 'mc') {
    http_response_code(400);
    echo json_encode(['error' => '50/50 is only available on multiple-choice questions']);
    exit;
}

$stmt = $conn->prepare(
    'SELECT id FROM AIAnswer WHERE user_id = ? AND question_id = ? LIMIT 1'
);
$stmt->bind_param('ii', $userid, $questionId);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $stmt->close();
    http_response_code(400);
    echo json_encode(['error' => 'Question already answered']);
    exit;
}
$stmt->close();

$stmt = $conn->prepare(
    'SELECT id FROM AILifeline WHERE user_id = ? AND quiz_id = ? AND question_id = ? LIMIT 1'
);
$stmt->bind_param('iii', $userid, $quizId, $questionId);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    $stmt->close();
    http_response_code(400);
    echo json_encode(['error' => '50/50 already used on this question']);
    exit;
}
$stmt->close();

$remainingBefore = aiLifelineRemaining($conn, $userid, $quizId);
if ($remainingBefore <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'No 50/50 uses remaining', 'remaining' => 0]);
    exit;
}

$stmt = $conn->prepare(
    'SELECT id FROM AIOption WHERE question_id = ? AND is_correct = 0 ORDER BY position'
);
$stmt->bind_param('i', $questionId);
$stmt->execute();
$wrongIds = array_map(
    static fn(array $r): int => (int)$r['id'],
    $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
);
$stmt->close();

if (count($wrongIds) < 2) {
    http_response_code(400);
    echo json_encode(['error' => 'Not enough wrong options to eliminate']);
    exit;
}

shuffle($wrongIds);
$elim1 = $wrongIds[0];
$elim2 = $wrongIds[1];

$stmt = $conn->prepare(
    'INSERT INTO AILifeline (user_id, quiz_id, question_id, eliminated_option_id_1, eliminated_option_id_2)
     VALUES (?, ?, ?, ?, ?)'
);
$stmt->bind_param('iiiii', $userid, $quizId, $questionId, $elim1, $elim2);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['error' => 'Could not save lifeline']);
    exit;
}
$stmt->close();

echo json_encode([
    'eliminated_option_ids' => [$elim1, $elim2],
    'remaining'             => aiLifelineRemaining($conn, $userid, $quizId),
]);
