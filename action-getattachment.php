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
    $safeName = rawurlencode($origName);
    header('Content-Disposition: attachment; filename="' . $origName . '"; filename*=UTF-8\'\'' . $safeName);
}

readfile($filepath);
?>
