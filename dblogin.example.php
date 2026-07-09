<?php
/**
 * dblogin.example.php
 *
 * Copy to dblogin.php and fill in real values. dblogin.php is gitignored and
 * is NOT overwritten by deploy — keep all secrets here.
 */

	// Third-party API keys
	define('OPENAI_API_KEY', 'your-openai-key');
	define('PEXELS_API_KEY', 'your-pexels-key');   // https://www.pexels.com/api/
	define('TAVILY_API_KEY', 'your-tavily-key');   // https://tavily.com

	$db_hostname = 'localhost';
	$db_database = 'your_database';
	$db_username = 'your_username';
	$db_password = 'your_password';
	$conn = new mysqli($db_hostname, $db_username, $db_password, $db_database);
	if ($conn->connect_error) {
		die('Connection failed: ' . $conn->connect_error);
	}
	$db_server = $conn;

	function mysql_result($result, $row, $field = 0) {
		mysqli_data_seek($result, $row);
		$datarow = mysqli_fetch_array($result, MYSQLI_BOTH);
		if (is_string($field) && strpos($field, '.') !== false) {
			$parts = explode('.', $field);
			$field = $parts[1];
		}
		return $datarow[$field];
	}
?>
