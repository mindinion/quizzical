<?php
/**
 * action-getgroups.php
 *
 * Returns a JSON array of all active groups. Used to populate group selection UI elements
 * (e.g., the group switcher in the main view and the profile settings dropdown).
 * Only groups with status = 'active' are returned.
 */

	ini_set('error_reporting', E_STRICT);

	require_once 'dblogin.php';
	require_once 'security.php';

	$q = "SELECT id, name FROM Groups WHERE status = 'active'";

	$query = $conn->query($q);

	class Group {
		public $id = "";
		public $name = "";
	}

	$count_groups = 0;

	// Use an indexed array with a counter so the JSON output is a proper array
	while($row = mysqli_fetch_assoc($query)) {
		$groups[] = new Group;
		$groups[$count_groups]->id = $row['id'];
		$groups[$count_groups]->name = $row['name'];
		$count_groups++;
	}

	echo JSON_ENCODE($groups);

?>
