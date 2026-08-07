<?php
/**
 * action-resolve-ai-quiz.php
 *
 * Maps a Results type + date to an AIQuiz id for feed bubble navigation.
 *
 * Params: type (Morning|Afternoon or Quizzical Morning), date (Y-m-d)
 */

require_once 'require_auth.php';
require_once __DIR__ . '/ai-quiz-stats.php';

$type = isset($_GET['type']) ? trim($_GET['type']) : '';
$date = isset($_GET['date']) ? trim($_GET['date']) : '';

$type = preg_replace('/^Quizzical\s+/i', '', $type);
if (!in_array($type, ['Morning', 'Afternoon'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid type or date']);
    exit;
}

$stmt = $conn->prepare("SELECT id FROM AIQuiz WHERE type = ? AND date = ? AND status = 'active' LIMIT 1");
$stmt->bind_param('ss', $type, $date);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Quiz not found']);
    exit;
}

$quizId = (int)$row['id'];

$done = false;
$resultType = 'Quizzical ' . $type;
$stmt = $conn->prepare(
    "SELECT score, max FROM Results WHERE user = ? AND status = 'active' AND " . aiResultMatchSql($conn) . " LIMIT 1"
);
$stmt->bind_param('iiss', $userid, $quizId, $resultType, $date);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($res) {
    $done = true;
}

echo json_encode([
    'quiz_id' => $quizId,
    'type'    => $type,
    'date'    => $date,
    'done'    => $done,
    'score'   => $done ? (int)$res['score'] : null,
    'max'     => $done ? (int)$res['max'] : null,
]);
