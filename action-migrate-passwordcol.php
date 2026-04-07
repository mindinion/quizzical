<?php
require_once 'dblogin.php';
$result = $conn->query("ALTER TABLE Users MODIFY password_hash VARCHAR(255) NOT NULL");
echo $result ? "OK — password_hash column widened to VARCHAR(255)" : "Error: " . $conn->error;
?>
