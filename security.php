<?php
	function sanitizeString($var) {
		$var = htmlentities($var);
		$var = strip_tags($var);
		return $var;
	}
	function sanitizeMySQL($var) {
		global $conn;
		$var = $conn->real_escape_string($var);
		$var = sanitizeString($var);
		return $var;
	}
?>
