<?php
	ini_set('error_reporting', E_STRICT);

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';

	if (isset($_GET['email'])) $email = sanitizeString($_GET['email']);

	$q = "SELECT id FROM Users WHERE email = '$email';";

	$user = mysqli_query($db_server, $q);

	while($row = mysqli_fetch_assoc($user)) {
		$id = $row['id'];
	}

	if ($id != null) {
		echo "Exists";
	} else {
		echo "Free";
	}

?>