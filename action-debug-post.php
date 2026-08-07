<?php
/**
 * action-debug-post.php
 *
 * TEMPORARY read-only diagnostic for "Something went wrong posting your result".
 * Replays the decision logic of action-newresult.php for a user and quiz without
 * inserting anything, and reports the PHP output/error state that could corrupt the
 * JSON response. Auth: QA_RUN_TOKEN.
 *
 * GET: token, email, quiz_id
 */

require_once 'dblogin.php';
require_once 'config.php';
require_once 'qa-run-lib.php';
require_once __DIR__ . '/ai-quiz-stats.php';

header('Content-Type: application/json');

$token = $_REQUEST['token'] ?? null;
if (!qaTokenIsValid(is_string($token) ? $token : null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$email  = isset($_REQUEST['email']) ? $conn->real_escape_string($_REQUEST['email']) : '';
$quizId = isset($_REQUEST['quiz_id']) ? (int)$_REQUEST['quiz_id'] : 0;

$u = $conn->query("SELECT id, timezone FROM Users WHERE email = '$email' LIMIT 1");
if (!$u || $u->num_rows === 0) { echo json_encode(['error' => 'User not found']); exit; }
$user = $u->fetch_assoc();
$uid = (int)$user['id'];

$out = [
    'php' => [
        'display_errors'      => ini_get('display_errors'),
        'error_reporting'     => error_reporting(),
        'headers_sent'        => headers_sent(),
        'default_timezone'    => date_default_timezone_get(),
    ],
    'has_ai_quiz_id_column' => resultsHasAiQuizId($conn),
    'user' => $user,
];

// Recent results exactly as stored
$out['recent_results'] = [];
$r = $conn->query(
    "SELECT id, type, score, max, date, status, ai_quiz_id FROM Results
     WHERE user = $uid AND type LIKE 'Quizzical%' AND date >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
     ORDER BY date DESC"
);
while ($r && $row = $r->fetch_assoc()) { $out['recent_results'][] = $row; }

// Replay the two duplicate checks for the requested quiz
if ($quizId > 0) {
    $qz = $conn->query("SELECT id, type, date FROM AIQuiz WHERE id = $quizId LIMIT 1");
    $quiz = $qz ? $qz->fetch_assoc() : null;
    $out['quiz'] = $quiz;

    if ($quiz) {
        $type = 'Quizzical ' . $quiz['type'];
        $dt = date('Y-m-d H:i:s');
        if (!empty($user['timezone'])) {
            $prev = date_default_timezone_get();
            @date_default_timezone_set($user['timezone']);
            $dt = date('Y-m-d H:i:s');
            date_default_timezone_set($prev);
        }
        $out['would_post_at'] = $dt;

        $linkSql = "SELECT id FROM Results WHERE user='$uid' AND ai_quiz_id='$quizId' AND status = 'active' LIMIT 1;";
        $res = $conn->query($linkSql);
        $out['per_quiz_check'] = [
            'sql'   => $linkSql,
            'ok'    => (bool)$res,
            'error' => $conn->error,
            'rows'  => $res ? $res->num_rows : null,
        ];

        $legacySql = "SELECT id FROM Results WHERE user='$uid' AND type='$type' AND DAY(date) = DAY('$dt') and MONTH(date) = MONTH('$dt') and YEAR(date) = YEAR('$dt') and Results.status = 'active';";
        $res2 = $conn->query($legacySql);
        $out['legacy_per_day_check'] = [
            'sql'   => $legacySql,
            'ok'    => (bool)$res2,
            'error' => $conn->error,
            'rows'  => $res2 ? $res2->num_rows : null,
        ];

        $out['would_infer_quiz_id'] = aiInferQuizIdForResult($conn, $uid, $type);

        // Answer state for this quiz
        $a = $conn->query(
            "SELECT COUNT(*) AS answers, SUM(is_correct) AS score FROM AIAnswer WHERE user_id = $uid AND quiz_id = $quizId"
        );
        $out['answers'] = $a ? $a->fetch_assoc() : null;
    }
}

echo json_encode($out, JSON_PRETTY_PRINT);
