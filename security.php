<?php
/**
 * security.php
 *
 * Provides input sanitization helper functions used throughout the application.
 *
 * sanitizeString() — general-purpose sanitizer for any string value. Encodes HTML
 * special characters and strips any remaining HTML tags, making the value safe to
 * insert into HTML output and reducing SQL injection risk.
 *
 * sanitizeMySQL() — stricter sanitizer intended for values going into SQL queries.
 * Calls MySQLi's real_escape_string() to escape SQL-significant characters, then
 * applies sanitizeString() on top for additional defense.
 *
 * Note: These functions do not replace parameterized queries / prepared statements,
 * which are the preferred approach for SQL injection prevention.
 */

	/**
	 * Sanitizes a string for safe HTML output by encoding entities and stripping tags.
	 *
	 * @param string $var The raw input string.
	 * @return string The sanitized string.
	 */
	function sanitizeString($var) {
		$var = htmlentities($var);
		$var = strip_tags($var);
		return $var;
	}

	/**
	 * Sanitizes a string for use in a MySQL query. Escapes SQL special characters
	 * using the active connection, then applies HTML sanitization.
	 *
	 * @param string $var The raw input string.
	 * @return string The sanitized string safe for SQL insertion.
	 */
	function sanitizeMySQL($var) {
		global $conn;
		$var = $conn->real_escape_string($var);
		$var = sanitizeString($var);
		return $var;
	}
?>
