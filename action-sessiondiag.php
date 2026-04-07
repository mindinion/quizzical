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

// Test the exact query getsettings.php runs
echo "<h3>Session lookup (exact getsettings.php query):</h3><pre>";
$token = isset($_COOKIE['session']) ? $conn->real_escape_string($_COOKIE['session']) : '';
echo "Token (first 20 chars): " . substr($token, 0, 20) . "\n";
$lookup = $conn->query("SELECT userid FROM _SESSION WHERE token = '$token' LIMIT 1");
if ($lookup && $lookup->num_rows > 0) {
    $r = $lookup->fetch_assoc();
    echo "MATCH FOUND — userid: " . $r['userid'] . "\n";
} else {
    echo "NO MATCH — session would redirect to index.html\n";
    echo "Query error: " . $conn->error . "\n";
}
echo "</pre>";
?>
