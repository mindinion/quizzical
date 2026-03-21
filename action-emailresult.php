<?php	

	require_once 'dblogin.php';
	require_once 'security.php';
	
	date_default_timezone_set($timezone);
	
	if (isset($_GET['resultId'])) $resultId = sanitizeString($_GET['resultId']);
	else $resultId = "";
	
	if ($resultId == "") $response = "No input";
	
	// Get the details of the quiz
	$q = "SELECT default_group, Users.id as 'userid', first_name, last_name, type FROM Results INNER JOIN Users ON Results.user = Users.id WHERE Results.id = $resultId;";
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
	
	// Find out the people we need to email, and email them
	$q = "SELECT id,email FROM Users WHERE notify_results = 1 and Users.default_group = $groupid;";
	$result = $conn->query($q);		
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {
			$email = $row['email'];
			$id = $row['id'];
			if ($id != $userid) {
				mail($email,"New Result Posted", "There has been a new " . $type . " quiz result posted on Quizzical, by " . $firstName . " " . $lastName . ".", "From: Quizzical");
				$response++;
			}
		}
	}
	
	echo $response ?? '';
	
	 		
?>