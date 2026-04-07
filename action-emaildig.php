<?php
/**
 * action-emaildig.php
 *
 * Sends an email notification to a user when someone "digs" (likes) their QuizFeed post.
 * Retrieves all relevant details from the Digs, QuizFeed, Results, and Users tables in a
 * single JOIN query. The email body differs depending on whether the dug item is a quiz
 * result post or a plain text post.
 */

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'config.php';

	$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
	
	// Single JOIN retrieves digger details, the post, and optionally the linked result
	$q = "SELECT Digger.first_name as diggerfirstname, Digger.last_name as diggerlastname, Digs.postid as postid, Poster.email as posteremail, QuizFeed.comment as quizfeedcomment, QuizFeed.result_id as quizfeedresult, Results.Score as score, Results.max as max, Results.type as type, QuizFeed.timestamp as quizfeedtimestamp FROM Digs INNER JOIN QuizFeed ON Digs.postid = QuizFeed.id INNER JOIN Users as Poster ON QuizFeed.user_id = Poster.id INNER JOIN Users as Digger ON Digs.userid = Digger.id LEFT JOIN Results ON QuizFeed.result_id = Results.id WHERE Digs.id = $id;";
	$result = $conn->query($q);
	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$diggerNameFirst	= $row['diggerfirstname'];
			$diggerNameLast		= $row['diggerlastname'];
			$email				= $row['posteremail'];
			$comment			= $row['quizfeedcomment'];
			$score				= $row['score'];
			$max				= $row['max'];
			$type				= $row['type'];
			// Convert DB timestamp string to Unix timestamp for date formatting
			$timestamp			= Date(strtotime($row['quizfeedtimestamp']));
			$quizfeedresultid	= $row['quizfeedresult'];
    	}
	}

	$diggerName = htmlspecialchars($diggerNameFirst . ' ' . $diggerNameLast);
	if ($quizfeedresultid != "") {
		$detail = htmlspecialchars($score . ' out of ' . $max . ' in the ' . $type . ' quiz on ' . date("l", $timestamp) . ', ' . date("d", $timestamp) . ' ' . date("F", $timestamp) . ' ' . date("Y", $timestamp));
		$blurb = '<p style="margin:0 0 8px;font-size:15px;color:#333;"><strong>' . $diggerName . '</strong> digs your result:</p>
			<p style="margin:0 0 24px;font-size:14px;color:#666;background:#fff8f0;border-left:4px solid #e67300;padding:12px 16px;border-radius:4px;">' . $detail . '</p>';
	} else {
		$blurb = '<p style="margin:0 0 8px;font-size:15px;color:#333;"><strong>' . $diggerName . '</strong> digs your post:</p>
			<p style="margin:0 0 24px;font-size:14px;color:#666;background:#fff8f0;border-left:4px solid #e67300;padding:12px 16px;border-radius:4px;">' . htmlspecialchars($comment) . '</p>';
	}
	$emailHtml = mailHtml($blurb . '<a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>');
	sendMail($conn, $email, $diggerName . " digs your post", $emailHtml, false, true);

	echo $timestamp ?? '';


		
		
?>