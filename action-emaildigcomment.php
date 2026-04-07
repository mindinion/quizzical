<?php
/**
 * action-emaildigcomment.php
 *
 * Sends an email notification to a user when someone "digs" (likes) their comment.
 * Joins the Digs, Comment, and Users tables to retrieve the digger's name and the
 * comment author's email address, then sends a plain-text notification email.
 * Note: the query is run twice (a bug — the first call result is discarded).
 */

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'config.php';

	date_default_timezone_set($timezone);

	$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

	// Retrieve digger name, comment author email, and the comment text in one query.
	// Note: the first $conn->query($q) call is redundant; its result is never used.
	$q = "SELECT Digger.first_name as diggerfirstname, Digger.last_name as diggerlastname, Commentor.email as commenteremail, Comment.comment as thecomment FROM Digs INNER JOIN Comment ON Digs.commentid = Comment.id INNER JOIN Users AS Digger ON Digs.userid = Digger.id INNER JOIN Users AS Commentor ON Comment.user_id = Commentor.id WHERE Digs.id = $id;";
	$conn->query($q);
	$result = $conn->query($q);
	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$diggerNameFirst	= $row['diggerfirstname'];
			$diggerNameLast		= $row['diggerlastname'];
			$email				= $row['commenteremail'];
			$comment			= $row['thecomment'];
    	}
	}

	$diggerName = htmlspecialchars($diggerNameFirst . ' ' . $diggerNameLast);
	$emailHtml = mailHtml('
		<p style="margin:0 0 8px;font-size:15px;color:#333;"><strong>' . $diggerName . '</strong> digs your comment:</p>
		<p style="margin:0 0 24px;font-size:14px;color:#666;background:#fff8f0;border-left:4px solid #e67300;padding:12px 16px;border-radius:4px;">' . htmlspecialchars($comment) . '</p>
		<a href="http://quizzical.co.nz" style="display:inline-block;background:#e67300;color:#fff;font-family:verdana,arial,sans-serif;font-size:14px;font-weight:bold;text-decoration:none;padding:12px 28px;border-radius:20px;">View on Quizzical</a>
	');
	sendMail($conn, $email, $diggerName . " digs your comment", $emailHtml, false, true);

	 


		
		
?>