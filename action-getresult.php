<?php

	error_reporting(E_ALL);
	ini_set('display_errors', '1');
	
	require_once 'dblogin.php';
	require_once 'security.php';
	
	if (isset($_GET['userid'])) $userid = $_GET['userid'];
	
	// First have a look to see if they'vew done the daily quiz for today, then send back what their default quiz type should be
	$q = "SELECT type FROM Results WHERE user = $userid and DATE(date) = DATE(SYSDATE()) and status = 'active' ORDER BY date DESC;";
	$result = $conn->query($q);		

	$i = 0;
	$daily = 0;
	$afternoon = 0;
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {
			$type = $row['type'];
			if ($type == "Morning") $daily = 1;
			if ($type == "Afternoon") $afternoon = 1;
		}
	}
	
	if ($daily == 0) echo 'Morning';
	else if ($afternoon == 0) echo 'Afternoon';
	else echo 'Other';
	

	
?>