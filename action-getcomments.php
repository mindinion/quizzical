<?php	

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';
	
	if (isset($_GET['groupid'])) $groupid = sanitizeString($_GET['groupid']);
	if (isset($_GET['quizfeedidmin'])) $quizfeedidmin = sanitizeString($_GET['quizfeedidmin']);

	$q = "SELECT Users.first_name, Users.last_name, Comment.id, Comment.user_id, Comment.quizfeed_id, Comment.comment, Comment.timestamp from Comment INNER JOIN Memberships ON Comment.user_id = Memberships.user_id  INNER JOIN Users ON Comment.user_id = Users.id WHERE Memberships.group_id = $groupid AND quizfeed_id > $quizfeedidmin;";
	$result = $conn->query($q);		
	
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {        	
			$newComment = new StdClass();
			$newComment->commentid = $row['id'];
			$newComment->name_first = $row['first_name'];
			$newComment->name_last = $row['last_name'];
			$newComment->user_id = $row['user_id'];
			$newComment->quizfeed_id = $row['quizfeed_id'];
			$newComment->comment = $row['comment'];
			$newComment->timestamp = $row['timestamp'];	
			$comments[] = $newComment;
    	} 
	} 
    
    echo JSON_ENCODE($comments);
    
?>