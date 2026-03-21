<?php
	session_start();
	$groupid = $_GET["id"];
	$groupname = $_GET["name"];
	$_SESSION["groupid"] = $groupid;
	$_SESSION["groupname"] = $groupname;
	header("Location: main.php");
?>