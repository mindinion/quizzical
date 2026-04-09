<?php
/**
 * require_auth.php
 *
 * Authentication bootstrap for action endpoints.
 * Validates the session cookie against the _SESSION table and sets $userid.
 * Returns HTTP 403 and exits if the session is invalid or missing.
 * Does NOT redirect — action endpoints return data, not HTML pages.
 */

require_once __DIR__ . '/dblogin.php';
require_once __DIR__ . '/security.php';

$token = isset($_COOKIE['session']) ? $conn->real_escape_string($_COOKIE['session']) : '';
if (!$token) {
    http_response_code(403);
    exit;
}

$_authResult = $conn->query("SELECT userid FROM _SESSION WHERE token = '$token' LIMIT 1");
if (!$_authResult || $_authResult->num_rows === 0) {
    http_response_code(403);
    exit;
}

$userid = (int)$_authResult->fetch_assoc()['userid'];
