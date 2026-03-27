<?php
/**
 * action-uploadphoto.php
 *
 * Handles profile photo uploads. Validates the uploaded file is a genuine image
 * using MIME type detection (not just extension), saves it as user{userid}.{ext}
 * in the web root, updates the Users table, and returns JSON with the filename.
 */

require_once 'dblogin.php';
require_once 'security.php';

header('Content-Type: application/json');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
	echo json_encode(['error' => 'No file uploaded or upload error']);
	exit;
}

$userid = isset($_POST['userid']) ? (int)$_POST['userid'] : 0;
if (!$userid) {
	echo json_encode(['error' => 'Invalid user']);
	exit;
}

// Validate it is actually an image using MIME type, not just the file extension
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $_FILES['file']['tmp_name']);
finfo_close($finfo);

$allowedTypes = [
	'image/jpeg' => 'jpg',
	'image/png'  => 'png',
	'image/gif'  => 'gif',
];

if (!array_key_exists($mimeType, $allowedTypes)) {
	echo json_encode(['error' => 'File must be a JPEG, PNG or GIF image']);
	exit;
}

$extension  = $allowedTypes[$mimeType];
$filename   = 'user' . $userid . '.' . $extension;
$uploadPath = __DIR__ . '/' . $filename;

if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadPath)) {
	$conn->query("UPDATE Users SET pic_filename = '$filename' WHERE id = $userid");
	echo json_encode(['filename' => $filename]);
} else {
	echo json_encode(['error' => 'Could not save file — check server write permissions']);
}
?>
