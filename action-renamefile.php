<?php
/**
 * action-renamefile.php
 *
 * Renames a file on the server filesystem and updates the user's profile photo filename
 * in the database to match. Used as part of the profile photo upload flow, where the
 * uploaded file may be saved under a temporary name and then renamed to its final name.
 * var_dump($result) is left in — this is a debugging artifact.
 *
 * Security note: $from and $to are taken directly from GET parameters with no sanitization
 * or path restriction, which could allow arbitrary file renames on the server.
 */

	error_reporting(-1);
	ini_set('display_errors', 'On');

	if (isset($_GET['from'])) $from = $_GET['from'];
	if (isset($_GET['to'])) $to = $_GET['to'];
	if (isset($_GET['userid'])) $userid = $_GET['userid'];

	// Rename the file on the filesystem first
	rename($from,$to);

	// Update the stored filename in the Users table to keep it in sync
	require_once 'security.php';
	require_once 'dblogin.php';
	$q = "UPDATE Users SET pic_filename='$to' WHERE id = $userid";
	$result = $conn->query($q);
	var_dump($result);

?>
