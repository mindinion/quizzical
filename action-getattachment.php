<?php
/**
 * action-getattachment.php
 *
 * Proxies attachment files so they are not directly accessible by URL.
 * Checks the user has a valid session before streaming the file.
 *
 * Params:
 *   id  INT  — the Attachments row id
 */

require_once 'dblogin.php';
require_once 'security.php';

// Validate session before serving any file
$token = $_COOKIE['session'] ?? '';
$sessionCheck = $conn->query("SELECT userid FROM _SESSION WHERE token = '" . $conn->real_escape_string($token) . "' LIMIT 1");
if (!$sessionCheck || $sessionCheck->num_rows === 0) {
    http_response_code(403);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { http_response_code(400); exit; }

$result = $conn->query("SELECT filename, original_name, file_type FROM Attachments WHERE id = $id LIMIT 1");
if (!$result || $result->num_rows === 0) { http_response_code(404); exit; }

$row         = $result->fetch_assoc();
$filename    = $row['filename'];
$origName    = $row['original_name'];
$fileType    = $row['file_type'];
$filepath    = __DIR__ . '/uploads/attachments/' . basename($filename);

if (!file_exists($filepath)) { http_response_code(404); exit; }

$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filepath);
finfo_close($finfo);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=86400');

if ($fileType !== 'image') {
    $cleanName = str_replace(["\r", "\n"], '', $origName);
    $safeName  = rawurlencode($cleanName);
    header('Content-Disposition: attachment; filename="' . $cleanName . '"; filename*=UTF-8\'\'' . $safeName);
}

readfile($filepath);
?>
