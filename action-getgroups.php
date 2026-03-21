<?php	
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

	while($row = mysqli_fetch_assoc($query)) {
		$groups[] = new Group;
		$groups[$count_groups]->id = $row['id'];
		$groups[$count_groups]->name = $row['name'];
		$count_groups++;
	}
	
	echo JSON_ENCODE($groups);
	
?>
