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

	error_reporting(E_ALL);
	ini_set('display_errors', '1');

	require_once 'dblogin.php';
	require_once 'security.php';

	$postid = "";
	$commentid = "";

	if (isset($_GET['postid'])) $postid = $_GET['postid'];
	if (isset($_GET['commentid'])) $commentid = $_GET['commentid'];
	if (isset($_GET['userid'])) $userid = $_GET['userid'];

	// Determine whether this dig is for a comment or a post based on which id was omitted
	if ($postid == "")    $q = "INSERT INTO Digs (userid, commentid) values ($userid, $commentid);";
	if ($commentid == "") $q = "INSERT INTO Digs (userid, postid) values ($userid, $postid);";

	$conn->query($q);
	$last_id = mysqli_insert_id($conn);

	echo $last_id;

?>