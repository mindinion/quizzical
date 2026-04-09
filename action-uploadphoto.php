<?php
/**
 * action-uploadphoto.php
 *
 * Handles profile photo uploads. Validates the uploaded file is a genuine image
 * using MIME type detection, resizes it to 150x150 using GD, saves as a JPEG,
 * updates the Users table, and returns JSON with the filename.
 */

require_once 'require_auth.php';
// $userid set by require_auth.php from validated session

header('Content-Type: application/json');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
	echo json_encode(['error' => 'No file uploaded or upload error']);
	exit;
}

// Validate it is actually an image using MIME type, not just the file extension
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $_FILES['file']['tmp_name']);
finfo_close($finfo);

$allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
if (!in_array($mimeType, $allowedTypes)) {
	echo json_encode(['error' => 'File must be a JPEG, PNG or GIF image']);
	exit;
}

// Load into GD
switch ($mimeType) {
	case 'image/jpeg': $src = imagecreatefromjpeg($_FILES['file']['tmp_name']); break;
	case 'image/png':  $src = imagecreatefrompng($_FILES['file']['tmp_name']);  break;
	case 'image/gif':  $src = imagecreatefromgif($_FILES['file']['tmp_name']);  break;
}

if (!$src) {
	echo json_encode(['error' => 'Could not process image']);
	exit;
}

// Resize to 150x150
$out = imagecreatetruecolor(150, 150);
imagecopyresampled($out, $src, 0, 0, 0, 0, 150, 150, imagesx($src), imagesy($src));
imagedestroy($src);

// Always save as JPEG
$filename   = 'user' . $userid . '.jpg';
$uploadPath = __DIR__ . '/' . $filename;

if (imagejpeg($out, $uploadPath, 85)) {
	imagedestroy($out);
	$conn->query("UPDATE Users SET pic_filename = '$filename' WHERE id = $userid");
	echo json_encode(['filename' => $filename]);
} else {
	imagedestroy($out);
	echo json_encode(['error' => 'Could not save file — check server write permissions']);
}
?>
