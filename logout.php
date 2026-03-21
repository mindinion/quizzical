<?php
/**
 * logout.php
 *
 * Logs the user out by overwriting the session cookie with a garbage value ("nonsense")
 * and setting its expiry to -1 (immediate expiry), then redirects to index.php.
 * The session record in the _SESSION database table is not cleaned up here.
 *
 * Note: This redirects to index.php, while action-logout.php redirects to index.html —
 * the two logout paths target slightly different entry points.
 */

	// Invalidate the session cookie immediately
	setcookie("session", "nonsense", -1);
	header( "Location: index.php" );
?>