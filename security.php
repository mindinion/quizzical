<?php
	function sanitizeString($var) {
		$var = htmlentities($var);
		$var = strip_tags($var);
		return $var;
	}
	function sanitizeMySQL($var) {
		global $db_server;
		$var = mysqli_real_escape_string($db_server, $var);
		$var = sanitizeString($var);
		return $var;
	}
?>
