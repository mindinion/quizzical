<?php
// In PHP versions earlier than 4.1.0, $HTTP_POST_FILES should be used instead
// of $_FILES.

require_once 'dblogin.php';
require_once 'security.php';

if (isset($_POST['userid'])) $userid = addslashes($_POST['userid']);
else $userid = "";

$uploaddir = '/home/quizz245/public_html/';
$extension = substr(strrchr(basename($_FILES['file']['name']),'.'),1);
$uploadfile = $uploaddir . 'user' . $userid . "." . $extension;
$filename = 'user' . $userid . "." . $extension;


if (move_uploaded_file($_FILES['file']['tmp_name'], $uploadfile)) {
	$q = "UPDATE Users SET pic_filename = '$filename' WHERE id = '$userid' ;";
	$result = mysqli_query($db_server, $q);
    $r = "File is valid, and was successfully uploaded.\n";
} else {
    $r = "Possible file upload attack!\n";
}

mail("widdakay@gmail.com","Quizzical: Debugging", $uploadfile, "From: Quizzical");


?>