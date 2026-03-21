<?php
/**
 * action-undig.php
 *
 * Removes a "dig" (like) by soft-deleting the Digs row (status = 'deleted').
 * The dig to remove can be identified three ways, tried in priority order:
 *   1. By commentid + userid — undig a specific comment by the requesting user
 *   2. By postid + userid   — undig a specific post by the requesting user
 *   3. By dig id directly   — undig by the exact Digs row id
 * Authorization is enforced by confirming the dig's userid matches the requesting userid.
 * Echoes the query result on success, "Unauthorised" if the user doesn't own the dig,
 * or "Not found" if no matching dig exists.
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['id'])) $id = $_GET['id'];
	if (isset($_GET['userid'])) $userid = $_GET['userid'];
	if (isset($_GET['commentid'])) $commentid = $_GET['commentid'];
	if (isset($_GET['postid'])) $postid = $_GET['postid'];

	// Select the lookup strategy based on which identifiers were provided
	if (($commentid ?? null) != null) {
		$q = "SELECT id, userid FROM Digs WHERE commentid = $commentid and userid = $userid";
	} elseif (($postid ?? null) != null) {
		$q = "SELECT id, userid FROM Digs WHERE postid = $postid and userid = $userid";
	} else {
		$q = "SELECT id, userid FROM Digs WHERE id = $id;";
	}

	// Verify ownership before soft-deleting
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

	echo $deleted;


?>