<?php
/**
 * getsettings.php
 *
 * Central session/settings bootstrap included by most PHP files in the application.
 * Performs the following on every request:
 *
 *   1. Authentication: reads the 'session' cookie and validates it against the _SESSION
 *      table. Redirects to index.html if no valid session is found.
 *
 *   2. Group resolution: reads the active group from the PHP session if already set,
 *      otherwise queries the user's default group from the database and caches it in
 *      the session for subsequent requests.
 *
 *   3. User settings: fetches the user's name, email, notification preferences, and
 *      timezone from the database.
 *
 *   4. Timezone offset: calculates $tzDiff (in seconds) between the server's default
 *      timezone and the user's timezone. Used in the legacy PHP-rendered feed to adjust
 *      displayed timestamps.
 *
 *   5. Device detection: sets $portable = 1 for mobile devices (iPod, iPhone, Android),
 *      used to conditionally adjust the UI. iPad is detected separately but does not set
 *      $portable (tablet-specific handling in the feed).
 */

	require_once 'dblogin.php';
	require_once 'security.php';

	// Validate the session cookie against the _SESSION table; redirect if invalid
	$token = $_COOKIE['session'];
	$getUserId = $conn->query("SELECT userid FROM _SESSION WHERE token = '$token'");
	if (mysqli_num_rows($getUserId)) {
		$userid = mysql_result($getUserId, 0, 'userid');
	} else {
		header( "Location: index.html" );
	}

	// Use the session-cached group id if available to avoid a DB round-trip on every request
	if(isset($_SESSION['groupid']) && !empty($_SESSION['groupid'])) {
		$groupid = $_SESSION['groupid'];
	} else {
		$getGroup = $conn->query("SELECT Users.default_group, Groups.name, Users.timezone FROM Users INNER JOIN Groups ON (Users.default_group = Groups.id) WHERE Users.id = $userid;");
		$groupid = mysql_result($getGroup, 0, 'default_group');
		$groupname = mysql_result($getGroup, 0, 'name');
		// Cache in session so subsequent pages don't need to re-query
		$_SESSION["groupid"] = $groupid;
		$_SESSION["groupname"] = $groupname;
	}

	// Fetch full user settings (name, email, preferences, timezone)
	$getSettings = $conn->query("SELECT * FROM Users INNER JOIN Groups ON (Users.default_group = Groups.id) WHERE Users.id = $userid;");
	$timezone = mysql_result($getSettings, 0, 'timezone');
	$nameFirst = mysql_result($getSettings, 0, 'first_name');
	$nameLast = mysql_result($getSettings, 0, 'last_name');
	$email = mysql_result($getSettings, 0, 'email');
	$notifyResults = mysql_result($getSettings, 0, 'notify_results');
	$notifyMessages = mysql_result($getSettings, 0, 'notify_message');
	$superuser = mysql_result($getSettings, 0, 'superuser');

	// Re-query timezone specifically (overwrites the value from above — effectively a no-op)
	$getSettings = $conn->query("SELECT timezone FROM Users WHERE Users.id = $userid;");
	$timezone = mysql_result($getSettings, 0, 'timezone');

	// Calculate the offset in seconds between the server timezone and the user's timezone.
	// $tzDiff is used by the legacy feed renderer to convert stored UTC-like timestamps.
	$oldTimezone = date_default_timezone_get();
	$oldTS = date("Y-m-d H:i:s");
	date_default_timezone_set($timezone);
	$newTS = date("Y-m-d H:i:s");
	$tzDiff = StrToTime($oldTS) - StrToTime($newTS);

	// Detect the client device type via User-Agent string for conditional UI rendering
	$iPod    = stripos($_SERVER['HTTP_USER_AGENT'],"iPod");
	$iPhone  = stripos($_SERVER['HTTP_USER_AGENT'],"iPhone");
	$iPad    = stripos($_SERVER['HTTP_USER_AGENT'],"iPad");
	$Android = stripos($_SERVER['HTTP_USER_AGENT'],"Android");
	$webOS   = stripos($_SERVER['HTTP_USER_AGENT'],"webOS");
	// $portable flags phone-sized devices; iPad is handled separately in feed rendering
	if ( $iPod || $iPhone || $Android ) {
		$portable = 1;
	} else {
		$portable = 0;
	}
?>
