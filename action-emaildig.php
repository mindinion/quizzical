<?php	

	require_once 'dblogin.php';
	require_once 'security.php';
		
	if (isset($_GET['id'])) $id = sanitizeString($_GET['id']);
	
	// Get the details of the post
	$q = "SELECT Digger.first_name as diggerfirstname, Digger.last_name as diggerlastname, Digs.postid as postid, Poster.email as posteremail, QuizFeed.comment as quizfeedcomment, QuizFeed.result_id as quizfeedresult, Results.Score as score, Results.max as max, Results.type as type, QuizFeed.timestamp as quizfeedtimestamp FROM Digs INNER JOIN QuizFeed ON Digs.postid = QuizFeed.id INNER JOIN Users as Poster ON QuizFeed.user_id = Poster.id INNER JOIN Users as Digger ON Digs.userid = Digger.id LEFT JOIN Results ON QuizFeed.result_id = Results.id WHERE Digs.id = $id;";
	mysqli_query($db_server, $q);	
	$result = mysqli_query($db_server, $q);		
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {        	
			$diggerNameFirst	= $row['diggerfirstname'];
			$diggerNameLast		= $row['diggerlastname'];
			$email				= $row['posteremail'];
			$comment			= $row['quizfeedcomment'];
			$score				= $row['score'];
			$max				= $row['max'];
			$type				= $row['type'];
			$timestamp			= Date(strtotime($row['quizfeedtimestamp']));
			$quizfeedresultid	= $row['quizfeedresult'];
    	} 
	} 

	$emailSubject = $diggerNameFirst . " " . $diggerNameLast . " Digs Your Shit";
	if ($quizfeedresultid != "" ) {
		$emailContent = $diggerNameFirst . " " . $diggerNameLast . " digs your following result: 
		
" . $score . " out of " . $max . " in the " . $type . " quiz on " . DATE("l",$timestamp) . ", " . DATE("d",$timestamp) . " " . DATE("F",$timestamp) . " " . DATE("Y",$timestamp)
		;
	}
	else {
		$emailContent = $diggerNameFirst . " " . $diggerNameLast . " digs your following post:
		
" . $comment
		;
	}
	
	mail($email,$emailSubject,$emailContent, "From: Quizzical");
	//echo "Subject: " . $emailSubject ;
	//echo "COntent: " . $emailContent;

	 echo $timestamp;


		
		
?>