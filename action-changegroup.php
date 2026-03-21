<?php
/**
 * action-changegroup.php
 *
 * Updates the active group stored in the PHP session when a user switches groups.
 * Reads the new group id and name from GET parameters, stores them in the session,
 * then redirects back to main.php so the quiz feed reloads for the selected group.
 */

	session_start();
	$groupid = $_GET["id"];
	$groupname = $_GET["name"];

	// Persist the selected group across page loads via session
	$_SESSION["groupid"] = $groupid;
	$_SESSION["groupname"] = $groupname;

	header("Location: main.php");
?>