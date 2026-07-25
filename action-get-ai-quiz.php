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
require_once __DIR__ . '/ai-quiz-stats.php';

$quizId = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
if ($quizId <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing quiz_id']); exit; }

// Load quiz header
$stmt = $conn->prepare("SELECT id, type, date, status FROM AIQuiz WHERE id = ? AND status = 'active'");
$stmt->bind_param('i', $quizId);
$stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quiz) { http_response_code(404); echo json_encode(['error' => 'Quiz not found']); exit; }

$resultType = 'Quizzical ' . $quiz['type'];
$stmt = $conn->prepare(
    "SELECT score, max FROM Results WHERE user = ? AND status = 'active' AND type = ? AND date = ? LIMIT 1"
);
$stmt->bind_param('iss', $userid, $resultType, $quiz['date']);
$stmt->execute();
$postedResult = $stmt->get_result()->fetch_assoc();
$stmt->close();
$reviewMode = (bool)$postedResult;

// Load all questions (join bank for difficulty on older quizzes without a saved snapshot)
$hasBankCols = aiQuestionHasBankColumns($conn);
$hasDifficultyCol = aiQuestionHasColumn($conn, 'difficulty');

if ($hasBankCols && $hasDifficultyCol) {
    $qSql = "SELECT q.id, q.position, q.question_text, q.category, q.format, q.image_path, q.image_attribution,
                    q.difficulty AS saved_difficulty, b.difficulty AS bank_difficulty
             FROM AIQuestion q
             LEFT JOIN QuizQuestionBank b ON b.id = q.bank_id
             WHERE q.quiz_id = ? ORDER BY q.position";
} elseif ($hasBankCols) {
    $qSql = "SELECT q.id, q.position, q.question_text, q.category, q.format, q.image_path, q.image_attribution,
                    b.difficulty AS bank_difficulty
             FROM AIQuestion q
             LEFT JOIN QuizQuestionBank b ON b.id = q.bank_id
             WHERE q.quiz_id = ? ORDER BY q.position";
} else {
    $qSql = "SELECT id, position, question_text, category, format, image_path, image_attribution
             FROM AIQuestion WHERE quiz_id = ? ORDER BY position";
}

$stmt = $conn->prepare($qSql);
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

// In review mode, prefetch correct options so every question can show answers + stats
$correctByQuestion = [];
if ($reviewMode) {
    $stmt = $conn->prepare(
        "SELECT o.question_id, o.id
         FROM AIOption o
         INNER JOIN AIQuestion q ON o.question_id = q.id
         WHERE q.quiz_id = ? AND o.is_correct = 1"
    );
    $stmt->bind_param('i', $quizId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $correctByQuestion[(int)$row['question_id']] = (int)$row['id'];
    }
    $stmt->close();
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

    if (!empty($q['image_path'])) {
        $entry['image_url'] = $q['image_path'];
        $entry['image_attribution'] = $q['image_attribution'] ?? '';
    }

    $difficulty = $q['saved_difficulty'] ?? $q['bank_difficulty'] ?? null;
    if ($difficulty !== null && $difficulty !== '') {
        $entry['difficulty'] = $difficulty;
    }

    if ($answered) {
        $entry['chosen_option_id']  = (int)$answers[$qid]['chosen_option_id'];
        $entry['is_correct']        = (bool)$answers[$qid]['is_correct'];
        $entry['correct_option_id'] = (int)$answers[$qid]['correct_option_id'];
    } elseif ($reviewMode && isset($correctByQuestion[$qid])) {
        $entry['correct_option_id'] = $correctByQuestion[$qid];
    }

    if ($answered || $reviewMode) {
        $stats = aiAnswerOptionStats($conn, $qid);
        $entry['option_stats']  = $stats['option_stats'];
        $entry['total_answers'] = $stats['total_answers'];
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
    'review_mode'    => $reviewMode,
    'final_score'    => $postedResult ? (int)$postedResult['score'] : null,
    'final_max'      => $postedResult ? (int)$postedResult['max'] : 15,
]);
