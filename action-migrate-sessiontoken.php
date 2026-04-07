<?php
/**
 * action-migrate-sessiontoken.php — TEMP migration, delete after use
 * Widens the _SESSION.token column to VARCHAR(300) to fit 256-char hex tokens.
 */
require_once 'dblogin.php';

$result = $conn->query("ALTER TABLE _SESSION MODIFY token VARCHAR(300) NOT NULL");
if ($result) {
    echo "OK — token column widened to VARCHAR(300)";
} else {
    echo "FAILED: " . $conn->error;
}
?>
