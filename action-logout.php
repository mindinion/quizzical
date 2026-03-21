<?php
/**
 * action-logout.php
 *
 * Logs the user out by expiring the session cookie (setting expiry to -1 makes it
 * expire immediately) and redirecting to the index page.
 * The session record in the _SESSION database table is not explicitly removed here.
 */

	// Expire the session cookie immediately
	setcookie("session", "", -1);
	header( "Location: index.html" );
?>