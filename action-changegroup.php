<?php
/**
 * action-changegroup.php
 *
 * Updates the active group stored in the PHP session when a user switches groups.
 * Validates the session (require_auth.php) and confirms the user is actually a
 * member of the requested group before storing it. Group name is read from the DB,
 * not from the GET parameter, so the caller cannot inject an arbitrary name.
 * Redirects to main.php after updating the session.
 */

session_start();
require_once 'require_auth.php';
// $userid set by require_auth.php from validated session

$requestedGroupId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$requestedGroupId) {
	header("Location: main.php");
	exit;
}

// Verify this user is actually a member of the requested group
$result = $conn->query(
	"SELECT Groups.id, Groups.name
	 FROM Memberships
	 INNER JOIN Groups ON Memberships.group_id = Groups.id
	 WHERE Memberships.user_id = $userid AND Memberships.group_id = $requestedGroupId
	 LIMIT 1"
);

if (!$result || $result->num_rows === 0) {
	header("Location: main.php");
	exit;
}

$row = $result->fetch_assoc();
$_SESSION['groupid']   = $row['id'];
$_SESSION['groupname'] = $row['name'];

header("Location: main.php");
