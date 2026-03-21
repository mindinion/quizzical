<?php
	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';

	if (isset($_GET['pause'])) $pause = $_GET['pause'];

	// See the current counts
	$postsInitialCountQuery = mysqli_query($db_server, "select MAX(id) from QuizFeed WHERE status='active';");
	$postsInitialCount = mysql_result($postsInitialCountQuery, 0, "MAX(id)");
	$postsCurrentCount = $postsInitialCount;
	$commentsInitialCountQuery = mysqli_query($db_server, "select MAX(id) from Comment;");
	$commentsInitialCount = mysql_result($commentsInitialCountQuery, 0, "MAX(id)");
	$commentsCurrentCount = $commentsInitialCount;
	$digsInitialCountQuery = mysqli_query($db_server, "select MAX(id) from Digs WHERE status='active';");
	$digsInitialCount = mysql_result($digsInitialCountQuery, 0, "MAX(id)");
	$digsCurrentCount = $digsInitialCount;

	while ( ($postsCurrentCount == $postsInitialCount) && ($commentsCurrentCount == $commentsInitialCount) && ($digsCurrentCount == $digsInitialCount) ) {
		sleep($pause);
		$postsCurrentCountQuery = mysqli_query($db_server, "select MAX(id) from QuizFeed WHERE status='active';");
		$postsCurrentCount = mysql_result($postsCurrentCountQuery, 0, "MAX(id)");
		$commentsCurrentCountQuery = mysqli_query($db_server, "select MAX(id) from Comment;");
		$commentsCurrentCount = mysql_result($commentsCurrentCountQuery, 0, "MAX(id)");
		$digsCurrentCountQuery = mysqli_query($db_server, "select MAX(id) from Digs WHERE status='active';");
		$digsCurrentCount = mysql_result($digsCurrentCountQuery, 0, "MAX(id)");
	}

	echo 1;
?>
