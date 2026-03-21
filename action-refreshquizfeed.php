<?php

	error_reporting(E_ALL);
	ini_set('display_errors', '1');
	
	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';
	
	if (isset($_GET['pause'])) $pause = $_GET['pause'];
		
	// See the current counts
	$postsInitialCountQuery = $conn->query("select MAX(id) from QuizFeed WHERE status='active';");
	$postsInitialCount = mysql_result($postsInitialCountQuery, 0, "MAX(id)");
	$postsCurrentCount = $postsInitialCount;
	//$commentsInitialCountQuery = $conn->query("select MAX(id) from Comment WHERE status='active';");
	$commentsInitialCountQuery = $conn->query("select MAX(id) from Comment;");
	$commentsInitialCount = mysql_result($commentsInitialCountQuery, 0, "MAX(id)");
	$commentsCurrentCount = $commentsInitialCount;
	$digsInitialCountQuery = $conn->query("select MAX(id) from Digs WHERE status='active';");
	$digsInitialCount = mysql_result($digsInitialCountQuery, 0, "MAX(id)");
	$digsCurrentCount = $digsInitialCount;
	 	
	
	
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
	
	 	echo 1;
	
	
	

	
?>