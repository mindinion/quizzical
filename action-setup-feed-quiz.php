<?php
/**
 * action-setup-feed-quiz.php
 *
 * Adds QuizFeed columns for quiz-level discussion (feed v2). Idempotent.
 * Requires a logged-in session. Run once before using quiz discussion on v2 feed.
 */

require_once 'require_auth.php';
require_once __DIR__ . '/quiz-feed-helper.php';

header('Content-Type: application/json');

$error = null;
$ok = quizFeedEnsureDiscussionColumns($conn, $error);

$response = [
    'ok'    => $ok,
    'ready' => quizFeedHasDiscussionColumns($conn, true),
];
if (!$ok && $error) {
    $response['error'] = $error;
}

echo json_encode($response);
