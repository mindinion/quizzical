<?php
/**
 * action-debug-quizstate.php
 *
 * TEMPORARY read-only diagnostic for the "quiz opens on the score screen" bug.
 * Auth: QA_RUN_TOKEN (same as action-qa-generate-quiz.php). Writes nothing.
 *
 * GET/POST: token, email (user to inspect), days (default 14)
 */

require_once 'dblogin.php';
require_once 'config.php';
require_once 'qa-run-lib.php';

header('Content-Type: application/json');

$token = $_REQUEST['token'] ?? null;
if (!qaTokenIsValid(is_string($token) ? $token : null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$email = isset($_REQUEST['email']) ? $conn->real_escape_string($_REQUEST['email']) : '';
$days  = isset($_REQUEST['days']) ? max(1, min(60, (int)$_REQUEST['days'])) : 14;

$userRow = $conn->query("SELECT id, email, timezone FROM Users WHERE email = '$email' LIMIT 1");
if (!$userRow || $userRow->num_rows === 0) {
    echo json_encode(['error' => 'User not found', 'email' => $email]);
    exit;
}
$user = $userRow->fetch_assoc();
$uid  = (int)$user['id'];

$out = [
    'user'       => $user,
    'server_now' => date('Y-m-d H:i:s'),
    'nz_now'     => (new DateTime('now', new DateTimeZone('Pacific/Auckland')))->format('Y-m-d H:i:s'),
];

$out['quizzes'] = [];
$q = $conn->query(
    "SELECT z.id, z.type, z.date,
            (SELECT CONCAT(r.score, '/', r.max, ' posted ', r.date) FROM Results r
              WHERE r.user = $uid AND r.status = 'active' AND r.ai_quiz_id = z.id LIMIT 1) AS linked_result,
            (SELECT COUNT(*) FROM AIQuestion q WHERE q.quiz_id = z.id) AS question_count,
            (SELECT COUNT(*) FROM AIAnswer a WHERE a.quiz_id = z.id AND a.user_id = $uid) AS my_answers,
            (SELECT SUM(a.is_correct) FROM AIAnswer a WHERE a.quiz_id = z.id AND a.user_id = $uid) AS my_score,
            (SELECT MAX(a.answered_at) FROM AIAnswer a WHERE a.quiz_id = z.id AND a.user_id = $uid) AS last_answer_at
     FROM AIQuiz z
     WHERE z.date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
     ORDER BY z.date DESC, z.type ASC"
);
while ($q && $row = $q->fetch_assoc()) { $out['quizzes'][] = $row; }

$out['results'] = [];
$r = $conn->query(
    "SELECT id, type, score, max, date, status
     FROM Results
     WHERE user = $uid AND type LIKE 'Quizzical%'
       AND date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
     ORDER BY date DESC"
);
while ($r && $row = $r->fetch_assoc()) { $out['results'][] = $row; }

// Answers this user has recorded against quizzes, grouped, to spot orphans
$out['answer_groups'] = [];
$a = $conn->query(
    "SELECT a.quiz_id, COUNT(*) AS answers,
            SUM(CASE WHEN q.id IS NULL THEN 1 ELSE 0 END) AS orphan_questions,
            SUM(CASE WHEN q.quiz_id <> a.quiz_id THEN 1 ELSE 0 END) AS mismatched_quiz,
            MIN(a.answered_at) AS first_at, MAX(a.answered_at) AS last_at
     FROM AIAnswer a
     LEFT JOIN AIQuestion q ON q.id = a.question_id
     WHERE a.user_id = $uid
     GROUP BY a.quiz_id
     ORDER BY a.quiz_id DESC
     LIMIT 40"
);
while ($a && $row = $a->fetch_assoc()) { $out['answer_groups'][] = $row; }

echo json_encode($out, JSON_PRETTY_PRINT);
