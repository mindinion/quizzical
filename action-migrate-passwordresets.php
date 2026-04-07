<?php
/**
 * action-migrate-passwordresets.php
 *
 * One-off migration: creates the PasswordResets table.
 * Run once, then delete this file.
 */

	require_once 'dblogin.php';

	$sql = "CREATE TABLE IF NOT EXISTS PasswordResets (
		id      INT AUTO_INCREMENT PRIMARY KEY,
		token   VARCHAR(64)  NOT NULL UNIQUE,
		email   VARCHAR(255) NOT NULL,
		expires DATETIME     NOT NULL,
		INDEX (token),
		INDEX (email)
	)";

	if ($conn->query($sql)) {
		echo "PasswordResets table created successfully.";
	} else {
		echo "Error: " . $conn->error;
	}
?>
