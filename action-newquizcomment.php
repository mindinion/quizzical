<?php
/**
 * action-newquizcomment.php
 *
 * Top-level comment on a quiz (discussion thread). Creates quiz shell if needed.
 */

require_once 'require_auth.php';
require_once __DIR__ . '/quiz-feed-helper.php';

header('Content-Type: application/json');

$comment   = isset($_GET['comment']) ? $conn->real_escape_string($_GET['comment']) : '';
$quizId    = isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0;
$quizType  = isset($_GET['type']) ? $conn->real_escape_string($_GET['type']) : '';
$quizDate  = isset($_GET['date']) ? $conn->real_escape_string($_GET['date']) : '';
$timezone  = isset($_GET['timezone']) ? $conn->real_escape_string($_GET['timezone']) : 'Pacific/Auckland';

if ($comment === '') {
    echo json_encode(['error' => 'empty']);
    exit;
}

if (!quizFeedHasDiscussionColumns($conn)) {
    echo json_encode(['error' => 'migration_required']);
    exit;
}

$aiQuizId = null;
$typeForShell = null;
$dateForShell = null;

if ($quizId > 0) {
    $aiQuizId = $quizId;
    $zq = $conn->query("SELECT type, date FROM AIQuiz WHERE id = $quizId LIMIT 1");
    if ($zq && $row = $zq->fetch_assoc()) {
        $typeForShell = 'Quizzical ' . $row['type'];
        $dateForShell = $row['date'];
    }
} else {
    $typeForShell = $quizType;
    $dateForShell = $quizDate;
}

$postId = ensureQuizShell($conn, $userid, $aiQuizId, $typeForShell, $dateForShell, $timezone);
if ($postId <= 0) {
    echo json_encode(['error' => 'shell_failed']);
    exit;
}

$conn->query(
    "INSERT INTO Comment (user_id, quizfeed_id, comment) VALUES ('$userid', '$postId', '$comment')"
);
$commentId = (int)$conn->insert_id;

echo json_encode(['post_id' => $postId, 'comment_id' => $commentId]);
