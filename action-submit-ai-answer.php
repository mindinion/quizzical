<?php
/**
 * action-submit-ai-answer.php
 *
 * Records a single answer in the AI quiz wizard and returns whether it was correct.
 * Idempotent: if the user has already answered this question, returns the stored result.
 *
 * Params (GET):
 *   quiz_id          — int
 *   question_id      — int
 *   chosen_option_id — int
 *
 * Response:
 *   { correct: bool, correct_option_id: int, score: int, completed: bool }
 *
 * When completed is true the caller should prompt the user to post their result
 * via action-newresult.php (type = "AI Morning" or "AI Afternoon").
 */

require_once 'require_auth.php';
// $userid set by require_auth.php

$quizId         = isset($_GET['quiz_id'])          ? (int)$_GET['quiz_id']          : 0;
$questionId     = isset($_GET['question_id'])       ? (int)$_GET['question_id']      : 0;
$chosenOptionId = isset($_GET['chosen_option_id'])  ? (int)$_GET['chosen_option_id'] : 0;

if (!$quizId || !$questionId || !$chosenOptionId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

// Verify the question belongs to this quiz
$stmt = $conn->prepare("SELECT id FROM AIQuestion WHERE id = ? AND quiz_id = ?");
$stmt->bind_param('ii', $questionId, $quizId);
$stmt->execute();
if ($stmt->get_result()->num_rows === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Question not found in quiz']);
    exit;
}
$stmt->close();

// Idempotency: return stored result if already answered
$stmt = $conn->prepare(
    "SELECT a.is_correct, a.chosen_option_id,
            (SELECT id FROM AIOption WHERE question_id = ? AND is_correct = 1 LIMIT 1) AS correct_option_id
     FROM AIAnswer a
     WHERE a.user_id = ? AND a.question_id = ?"
);
$stmt->bind_param('iii', $questionId, $userid, $questionId);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    echo json_encode([
        'correct'           => (bool)$existing['is_correct'],
        'correct_option_id' => (int)$existing['correct_option_id'],
        'already_answered'  => true,
    ]);
    exit;
}

// Verify the chosen option belongs to this question and find the correct one
$stmt = $conn->prepare("SELECT id, is_correct FROM AIOption WHERE question_id = ?");
$stmt->bind_param('i', $questionId);
$stmt->execute();
$optionsResult = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$isCorrect       = false;
$correctOptionId = null;
$optionValid     = false;

foreach ($optionsResult as $opt) {
    if ((int)$opt['id'] === $chosenOptionId) $optionValid = true;
    if ($opt['is_correct']) $correctOptionId = (int)$opt['id'];
    if ((int)$opt['id'] === $chosenOptionId && $opt['is_correct']) $isCorrect = true;
}

if (!$optionValid) {
    http_response_code(400);
    echo json_encode(['error' => 'Option does not belong to this question']);
    exit;
}

// Store the answer
$isCorrectInt = $isCorrect ? 1 : 0;
$stmt = $conn->prepare(
    "INSERT INTO AIAnswer (user_id, quiz_id, question_id, chosen_option_id, is_correct) VALUES (?, ?, ?, ?, ?)"
);
$stmt->bind_param('iiiii', $userid, $quizId, $questionId, $chosenOptionId, $isCorrectInt);
$stmt->execute();
$stmt->close();

// Count how many questions the user has now answered for this quiz
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS cnt, SUM(is_correct) AS score FROM AIAnswer WHERE user_id = ? AND quiz_id = ?"
);
$stmt->bind_param('ii', $userid, $quizId);
$stmt->execute();
$progress = $stmt->get_result()->fetch_assoc();
$stmt->close();

$answeredCount = (int)$progress['cnt'];
$score         = (int)$progress['score'];
$completed     = $answeredCount >= 15;

echo json_encode([
    'correct'           => $isCorrect,
    'correct_option_id' => $correctOptionId,
    'score'             => $score,
    'answered'          => $answeredCount,
    'completed'         => $completed,
]);
