<?php
/**
 * action-newdig.php
 *
 * Records a new "dig" (like) on either a QuizFeed post or a comment.
 * Exactly one of postid or commentid should be supplied:
 *   - If postid is empty, the dig is recorded against the commentid.
 *   - If commentid is empty, the dig is recorded against the postid.
 * Echoes the auto-incremented id of the newly created Digs row, which the caller
 * uses to track the dig for a potential future undig action.
 */

	require_once 'require_auth.php';

	$postid    = isset($_GET['postid'])    ? (int)$_GET['postid']    : 0;
	$commentid = isset($_GET['commentid']) ? (int)$_GET['commentid'] : 0;
	// $userid set by require_auth.php from validated session

	// Determine whether this dig is for a comment or a post based on which id was omitted
	if ($postid == 0)    $q = "INSERT INTO Digs (userid, commentid) VALUES ($userid, $commentid)";
	else                 $q = "INSERT INTO Digs (userid, postid) VALUES ($userid, $postid)";

	$conn->query($q);
	$last_id = mysqli_insert_id($conn);

	echo $last_id;

?>