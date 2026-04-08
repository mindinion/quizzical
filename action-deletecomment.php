<?php
/**
 * action-deletecomment.php
 *
 * Soft-deletes a Comment row by setting its status to 'deleted'.
 * Authorization is enforced by comparing the comment's owner (user_id) against
 * the requesting userid — only the original commenter can delete their own comment.
 * Records are never physically removed from the database.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	$commentid = isset($_GET['id'])     ? (int)$_GET['id']     : 0;
	$userid    = isset($_GET['userid']) ? (int)$_GET['userid'] : 0;

	if (!$commentid || !$userid) exit;

	$result = $conn->query("SELECT user_id FROM Comment WHERE id = $commentid LIMIT 1");
	if (!$result || $result->num_rows === 0) exit;

	$row = $result->fetch_assoc();
	if ((int)$row['user_id'] === $userid) {
		$conn->query("UPDATE Comment SET status = 'deleted' WHERE id = $commentid");
	}
?>
