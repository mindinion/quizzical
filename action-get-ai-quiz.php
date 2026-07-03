<?php
/**
 * action-get-ai-quiz.php
 *
 * Returns an AI quiz with all 15 questions and their options for the wizard.
 * Correct answers are NOT included in the option objects — they are only revealed
 * by action-submit-ai-answer.php after the user picks.
 *
 * For questions the user has already answered, the response includes which option
 * they chose and which was correct (so the wizard can restore state on resume).
 *
 * Params (GET):
 *   quiz_id  — int, the AIQuiz.id to load
 */

require_once 'require_auth.php';
// $userid set by require_auth.php

$quizId = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
if ($quizId <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing quiz_id']); exit; }

// Load quiz header
$stmt = $conn->prepare("SELECT id, type, date, status FROM AIQuiz WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $quizId);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) { http_response_code(404); echo json_encode(['error' => 'Quiz not found']); exit; }

// Load all questions
$stmt = $conn->prepare(
    "SELECT id, position, question_text, category, format FROM AIQuestion WHERE quiz_id = ? ORDER BY position"
);
$stmt->bind_param('i', $quizId);
$stmt->execute();
$questionsRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Load all options for this quiz (no is_correct — revealed only after answering)
$stmt = $conn->prepare(
    "SELECT o.id, o.question_id, o.position, o.option_text
     FROM AIOption o
     INNER JOIN AIQuestion q ON o.question_id = q.id
     WHERE q.quiz_id = ?
     ORDER BY o.question_id, o.position"
);
$stmt->bind_param('i', $quizId);
$stmt->execute();
$optionsRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Load this user's existing answers, deriving is_correct from AIOption directly
// so stale cached values in AIAnswer.is_correct don't affect the score.
$stmt = $conn->prepare(
    "SELECT a.question_id, a.chosen_option_id,
            o.is_correct,
            (SELECT id FROM AIOption WHERE question_id = a.question_id AND is_correct = 1 LIMIT 1) AS correct_option_id
     FROM AIAnswer a
     INNER JOIN AIOption o ON a.chosen_option_id = o.id
     WHERE a.user_id = ? AND a.quiz_id = ?"
);
$stmt->bind_param('ii', $userid, $quizId);
$stmt->execute();
$answersRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Index answers by question_id
$answers = [];
foreach ($answersRaw as $a) {
    $answers[(int)$a['question_id']] = $a;
}

// Index options by question_id
$optionsByQuestion = [];
foreach ($optionsRaw as $o) {
    $optionsByQuestion[(int)$o['question_id']][] = [
        'id'   => (int)$o['id'],
        'pos'  => (int)$o['position'],
        'text' => $o['option_text'],
    ];
}

// Assemble response
$questions = [];
foreach ($questionsRaw as $q) {
    $qid = (int)$q['id'];
    $answered = isset($answers[$qid]);

    $entry = [
        'id'            => $qid,
        'position'      => (int)$q['position'],
        'question_text' => $q['question_text'],
        'category'      => $q['category'],
        'format'        => $q['format'],
        'options'       => $optionsByQuestion[$qid] ?? [],
        'answered'      => $answered,
    ];

    if ($answered) {
        $entry['chosen_option_id']  = (int)$answers[$qid]['chosen_option_id'];
        $entry['is_correct']        = (bool)$answers[$qid]['is_correct'];
        $entry['correct_option_id'] = (int)$answers[$qid]['correct_option_id'];
    }

    $questions[] = $entry;
}

$answeredCount = count($answers);
$totalScore    = array_sum(array_column($answersRaw, 'is_correct'));

echo json_encode([
    'quiz_id'        => (int)$quiz['id'],
    'type'           => $quiz['type'],
    'date'           => $quiz['date'],
    'questions'      => $questions,
    'answered_count' => $answeredCount,
    'score_so_far'   => $totalScore,
    'completed'      => $answeredCount >= 15,
]);
