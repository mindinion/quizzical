<?php
/**
 * action-getdigs.php
 *
 * Returns a JSON array of active "digs" (likes) for either comments or posts,
 * depending on which minimum-id parameter is supplied:
 *   - cidmin: return digs on comments with id >= cidmin, grouped by comment
 *   - pidmin: return digs on posts with id >= pidmin, grouped by post
 * GROUP_CONCAT is used to aggregate multiple digger names and user ids into
 * comma-separated strings so the client receives one record per post/comment.
 */

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';

	if (isset($_GET['cidmin'])) $cidmin = sanitizeString($_GET['cidmin']);
	if (isset($_GET['pidmin'])) $pidmin = sanitizeString($_GET['pidmin']);

	// Build query based on whether we are fetching comment digs or post digs
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
			// Comma-separated digger names and ids for client-side display
			$newDigs->names = $row['names'];
			$newDigs->userids = $row['userids'];
			$digs[] = $newDigs;
    	}
	}

    echo JSON_ENCODE($digs);

?>