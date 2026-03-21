<?php	

	require_once 'dblogin.php';
	require_once 'security.php';

	date_default_timezone_set($timezone);
	
	if (isset($_GET['postId'])) $postId = sanitizeString($_GET['postId']);
	if (isset($_GET['userid'])) $userid = sanitizeString($_GET['userid']);	
	
	// Get the details of the post
	$q = "SELECT Users.id as userid, first_name, last_name FROM QuizFeed INNER JOIN Users ON QuizFeed.user_id = Users.id WHERE QuizFeed.id = $postId;";
	$result = mysqli_query($db_server, $q);		
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {
			$firstName = $row['first_name'];
			$lastName = $row['last_name'];
			$posterId = $row['userid'];

		}
	}
	
	// Get the details of the user
	$q = "SELECT first_name, last_name, email FROM Users WHERE id = $posterId;";
	$result = mysqli_query($db_server, $q);		
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {
			$name = $row['first_name'] . " " . $row['last_name'];
			$poster = $row['email'];

		}
	}
	
	// Email the originator of the post
	if ($posterId != $userid) {
		mail($poster,"New Comment", "There is a new comment on your post.", "From: Quizzical");
	}
		
	// Email users the notification
	$q = "SELECT DISTINCT(Comment.user_id), Users.email as useremail FROM QuizFeed INNER JOIN Comment ON QuizFeed.id = Comment.quizfeed_id INNER JOIN Users ON Comment.user_id = Users.id WHERE QuizFeed.id = $postId;";
	$result = mysqli_query($db_server, $q);		
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {
			$email = $row['useremail'];
			$id = $row['user_id'];
			if ($id != $userid && $id != $posterId) {
				mail($email,"New Comment", "There is a new comment on " . $firstName . " " . $lastName . "'s post, by " . $name . ".", "From: Quizzical");
				$response = $i;
			}
		}
	}
	
	echo $response;
	 		
		
?>