<?php
/**
 * action-linkattachment.php
 *
 * Called after a post or comment is successfully saved. Sets either post_id or
 * comment_id on an existing Attachments row, linking it to the saved content.
 *
 * Expects POST (or GET) params:
 *   attachment_id  INT  — the Attachments row to update
 *   post_id        INT  — set to link to a QuizFeed post  (mutually exclusive)
 *   comment_id     INT  — set to link to a Comment row    (mutually exclusive)
 */

require_once 'require_auth.php';

header('Content-Type: application/json');

$attachmentId = isset($_REQUEST['attachment_id']) ? (int)$_REQUEST['attachment_id'] : 0;
$postId       = isset($_REQUEST['post_id'])       ? (int)$_REQUEST['post_id']       : 0;
$commentId    = isset($_REQUEST['comment_id'])    ? (int)$_REQUEST['comment_id']    : 0;

if (!$attachmentId || (!$postId && !$commentId)) {
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

if ($postId) {
    $conn->query("UPDATE Attachments SET post_id = $postId WHERE id = $attachmentId");
} else {
    $conn->query("UPDATE Attachments SET comment_id = $commentId WHERE id = $attachmentId");
}

echo json_encode(['ok' => true]);
?>
