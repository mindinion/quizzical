<?php

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['date'])) $date = sanitizeString($_GET['date']);
	if (isset($_GET['type'])) $type = sanitizeString($_GET['type']);
	if (isset($_GET['userid'])) $userid = sanitizeString($_GET['userid']);

	$q = "SELECT score FROM Results WHERE DATE_FORMAT(date,'%Y-%m-%d') = '$date' AND type = '$type' AND user = $userid and status = 'active';";
	$result = $conn->query($q);


	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$score = $row['score'];
    	}
	} else {
    	echo "failed";
    }
    echo $score;
?>