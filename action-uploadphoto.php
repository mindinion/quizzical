<?php
/**
 * action-uploadphoto.php
 *
 * Handles profile photo uploads. Moves the uploaded file to the public web root,
 * naming it "user{userid}.{extension}" (e.g., "user42.jpg"), then updates the
 * Users table with the new filename.
 *
 * A debug email is sent to the developer for every upload — this should be removed
 * before production deployment.
 *
 * Note: There is no file type validation beyond extracting the extension from the
 * original filename, which could be spoofed. mime_content_type() or similar should
 * be used to verify the file is actually an image.
 */

// Note: In PHP versions earlier than 4.1.0, $HTTP_POST_FILES should be used instead of $_FILES.

require_once 'dblogin.php';
require_once 'security.php';

if (isset($_POST['userid'])) $userid = addslashes($_POST['userid']);
else $userid = "";

$uploaddir = '/home/quizz245/public_html/';
// Extract the file extension from the original uploaded filename
$extension = substr(strrchr(basename($_FILES['file']['name']),'.'),1);
// Build the destination path and the filename stored in the database
$uploadfile = $uploaddir . 'user' . $userid . "." . $extension;
$filename = 'user' . $userid . "." . $extension;

if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadfile)) {
	// Update the user's photo reference in the database to point to the new file
	$q = "UPDATE Users SET pic_filename = '$filename' WHERE id = '$userid' ;";
	$result = $conn->query($q);
    $r = "File is valid, and was successfully uploaded.\n";
} else {
    $r = "Possible file upload attack!\n";
}

// Debug email — remove before going to production
mail("widdakay@gmail.com","Quizzical: Debugging", $uploadfile, "From: Quizzical");


?>