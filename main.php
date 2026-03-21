<?php
/**
 * main.php
 *
 * Entry point redirect. Immediately sends the user to index.html, which is the
 * single-page application shell. All actual content and logic is loaded from there
 * via JavaScript and the various action-* API endpoints.
 */

	header( "Location: index.html" );
?>