<?php
/**
 * action-migrate-attachments.php
 *
 * One-time migration script. Creates the Attachments table used by the
 * file/link attachment feature. Run once in the browser, then delete this file.
 */

require_once 'dblogin.php';

$sql = "CREATE TABLE IF NOT EXISTS Attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NULL,
    comment_id INT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql)) {
    echo "Attachments table created successfully (or already exists).";
} else {
    echo "Error: " . $conn->error;
}
?>
