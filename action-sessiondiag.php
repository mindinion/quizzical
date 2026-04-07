<?php
/**
 * action-sessiondiag.php — TEMP diagnostic, delete after use
 */
require_once 'dblogin.php';

// Show _SESSION table schema
echo "<h3>_SESSION columns:</h3><pre>";
$cols = $conn->query("SHOW COLUMNS FROM _SESSION");
while ($r = $cols->fetch_assoc()) { print_r($r); }
echo "</pre>";

// Show recent rows
echo "<h3>Recent _SESSION rows (last 5):</h3><pre>";
$rows = $conn->query("SELECT id, userid, LENGTH(token) as token_len, LEFT(token,20) as token_start, location, client FROM _SESSION ORDER BY id DESC LIMIT 5");
while ($r = $rows->fetch_assoc()) { print_r($r); }
echo "</pre>";

// Show cookie info
echo "<h3>Cookies:</h3><pre>";
echo "session cookie present: " . (isset($_COOKIE['session']) ? 'YES (length=' . strlen($_COOKIE['session']) . ')' : 'NO') . "\n";
echo "userid cookie present:  " . (isset($_COOKIE['userid'])  ? 'YES (value=' . $_COOKIE['userid'] . ')' : 'NO') . "\n";
echo "</pre>";
?>
