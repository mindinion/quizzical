<?php	

	require_once 'dblogin.php';
	require_once 'security.php';
	
	date_default_timezone_set($timezone);
	
	if (isset($_GET['id'])) $id = sanitizeString($_GET['id']);
	
	// Get the details of the post
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

		
	// Send the appropriate email
	$emailSubject = $diggerNameFirst . " " . $diggerNameLast . " Digs Your Shit";
	$emailContent = $diggerNameFirst . " " . $diggerNameLast . " digs your following comment:
		
" . $comment;
	
	mail($email,$emailSubject,$emailContent, "From: Quizzical");
	//echo "Subject: " . $emailSubject ;
	//echo "COntent: " . $emailContent;

	 


		
		
?>