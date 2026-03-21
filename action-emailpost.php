<?php	

	require_once 'dblogin.php';
	require_once 'security.php';
	

	date_default_timezone_set($timezone);
	
	if (isset($_GET['postId'])) $postId = sanitizeString($_GET['postId']);
	else $postId = "";
	
	if ($postId == "") $response = "No input";
	
	// Get the details of the result
	$q = "SELECT default_group, Users.id as 'userid', first_name, last_name FROM QuizFeed INNER JOIN Users ON QuizFeed.user_id = Users.id WHERE QuizFeed.id = $postId ;";
	$result = $conn->query($q);		
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {
			$firstName = $row['first_name'];
			$lastName = $row['last_name'];
			$type = $row['type'];
			$userid = $row['userid'];
			$groupid = $row['default_group'];
		}
	}
		
	// Email users the notification
	$q = "SELECT id,email FROM Users WHERE notify_message = 1 and Users.default_group = $groupid;";
	$result = $conn->query($q);		
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {
			$email = $row['email'];
			$id = $row['id'];
			if ($id != $userid) {
			mail($email,"New Quizzical Post", "There has been a new message posted on Quizzical, by " . $firstName . " " . $lastName . ".", "From: Quizzical");
				$response++;
			}
		}
	}
	
	echo $response;	
		
?>