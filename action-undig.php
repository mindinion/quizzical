<?php

	require_once 'dblogin.php';
	require_once 'security.php';
	
	if (isset($_GET['id'])) $id = $_GET['id'];
	if (isset($_GET['userid'])) $userid = $_GET['userid'];
	if (isset($_GET['commentid'])) $commentid = $_GET['commentid'];
	if (isset($_GET['postid'])) $postid = $_GET['postid'];

	
	if ($commentid != null) {
		$q = "SELECT id, userid FROM Digs WHERE commentid = $commentid and userid = $userid";
	} elseif ($postid !=null) {
		$q = "SELECT id, userid FROM Digs WHERE postid = $postid and userid = $userid";
	} else {
		$q = "SELECT id, userid FROM Digs WHERE id = $id;";
	}	

	// Check to see if the dig exists and is for the user that generated the request. If it is, remove it
	$result = $conn->query($q);		
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {   
			if ($row['userid'] == $userid) {
				$id = $row['id'];
				$q = "UPDATE Digs SET status='deleted' WHERE id = $id";
				$deleted = $conn->query($q);
			} else {
				$deleted = "Unauthorised";
			}
    	} 
	} else {
		$deleted = "Not found";
	}
    

	// Return the result of the update
	echo $deleted;

	
?>