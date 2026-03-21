<?php
	setcookie("session", "nonsense", -1);
	header( "Location: index.php" );
?>