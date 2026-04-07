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

	// Compose and send the dig notification to the comment's author
	$emailSubject = $diggerNameFirst . " " . $diggerNameLast . " Digs Your Shit";
	$emailContent = $diggerNameFirst . " " . $diggerNameLast . " digs your following comment:

" . $comment;

	sendMail($conn, $email, $emailSubject, $emailContent);

	 


		
		
?>