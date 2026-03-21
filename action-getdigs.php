<?php	

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';
	
	if (isset($_GET['cidmin'])) $cidmin = sanitizeString($_GET['cidmin']);
	if (isset($_GET['pidmin'])) $pidmin = sanitizeString($_GET['pidmin']);

	

	if ($cidmin != null) {
		$q = "SELECT GROUP_CONCAT(first_name SEPARATOR ', ') AS names, GROUP_CONCAT(userid SEPARATOR ',') AS userids, Digs.id, commentid, postid FROM Digs INNER JOIN Users ON Digs.userid = Users.id WHERE commentid >= $cidmin AND Digs.status = 'active' GROUP BY commentid;";
	} elseif ($pidmin != null) {
		$q = "SELECT GROUP_CONCAT(first_name SEPARATOR ', ') AS names, GROUP_CONCAT(userid SEPARATOR ',') AS userids, Digs.id, commentid, postid FROM Digs INNER JOIN Users ON Digs.userid = Users.id WHERE postid >= $pidmin AND Digs.status = 'active' GROUP BY postid;";
	}	
	
	$result = $conn->query($q);		
	
	if (mysqli_num_rows($result) > 0) {    	
		while($row = mysqli_fetch_assoc($result)) {        	
			$newDigs = new StdClass();
			$newDigs->id = $row['id'];
			$newDigs->commentid = $row['commentid'];
			$newDigs->postid = $row['postid'];
			$newDigs->names = $row['names'];	
			$newDigs->userids = $row['userids'];	
			$digs[] = $newDigs;
    	} 
	} 
    
    echo JSON_ENCODE($digs);
    
?>