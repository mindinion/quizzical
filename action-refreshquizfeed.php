<?php
/**
 * action-refreshquizfeed.php
 *
 * Implements server-side long polling for the quiz feed. The script captures the
 * current MAX ids of posts, comments, and digs, then loops — sleeping for $pause
 * seconds between iterations — until any of those counts increases. When a change
 * is detected it echoes "1" to signal the client to reload the feed.
 *
 * This is a basic long-poll pattern: the HTTP connection is held open on the server
 * until new activity occurs, avoiding constant polling from the client.
 *
 * Note: Comments are checked without the status='active' filter (unlike posts and digs),
 * which means deleted comments still trigger a refresh.
 */

	error_reporting(E_ALL);
	ini_set('display_errors', '1');

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';

	// $pause controls the sleep duration (seconds) between each database poll iteration
	if (isset($_GET['pause'])) $pause = $_GET['pause'];

	// Capture baseline MAX ids to detect any new rows being inserted
	$postsInitialCountQuery = $conn->query("select MAX(id) from QuizFeed WHERE status='active';");
	$postsInitialCount = mysql_result($postsInitialCountQuery, 0, "MAX(id)");
	$postsCurrentCount = $postsInitialCount;
	// Note: Comment query intentionally omits status filter (sees all comments including deleted)
	//$commentsInitialCountQuery = $conn->query("select MAX(id) from Comment WHERE status='active';");
	$commentsInitialCountQuery = $conn->query("select MAX(id) from Comment;");
	$commentsInitialCount = mysql_result($commentsInitialCountQuery, 0, "MAX(id)");
	$commentsCurrentCount = $commentsInitialCount;
	$digsInitialCountQuery = $conn->query("select MAX(id) from Digs WHERE status='active';");
	$digsInitialCount = mysql_result($digsInitialCountQuery, 0, "MAX(id)");
	$digsCurrentCount = $digsInitialCount;

	// Hold the connection open until at least one table gains a new row
	while ( ($postsCurrentCount == $postsInitialCount) && ($commentsCurrentCount == $commentsInitialCount) && ($digsCurrentCount == $digsInitialCount) ) {
		sleep($pause);
		$postsCurrentCountQuery = $conn->query("select MAX(id) from QuizFeed WHERE status='active';");
		$postsCurrentCount = mysql_result($postsCurrentCountQuery, 0, "MAX(id)");
		//$commentsCurrentCountQuery = $conn->query("select MAX(id) from Comment WHERE status='active';");
		$commentsCurrentCountQuery = $conn->query("select MAX(id) from Comment;");
		$commentsCurrentCount = mysql_result($commentsCurrentCountQuery, 0, "MAX(id)");
		$digsCurrentCountQuery = $conn->query("select MAX(id) from Digs WHERE status='active';");
		$digsCurrentCount = mysql_result($digsCurrentCountQuery, 0, "MAX(id)");
	}

	// Signal the client that new content is available
	echo 1;


?>
