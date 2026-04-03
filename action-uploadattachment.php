<?php
/**
 * action-uploadattachment.php
 *
 * Pre-upload endpoint for the attachment feature. Called immediately when the
 * user selects a file — before the post is saved. Saves the file to disk,
 * inserts a row into Attachments (with no post_id/comment_id yet), and returns
 * JSON with {id, filename, file_type, original_name} for the caller to store
 * and later link via action-linkattachment.php.
 *
 * Accepted file types: JPEG, PNG, GIF (rendered as image thumbnails),
 * plus PDF, DOC, DOCX, XLS, XLSX, TXT (rendered as download links).
 */

require_once 'dblogin.php';
require_once 'security.php';

header('Content-Type: application/json');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'No file uploaded or upload error']);
    exit;
}

$userid = isset($_POST['userid']) ? (int)$_POST['userid'] : 0;
if (!$userid) {
    echo json_encode(['error' => 'Invalid user']);
    exit;
}

$uploadDir = __DIR__ . '/uploads/attachments/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Detect MIME type from file content, not extension
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $_FILES['file']['tmp_name']);
finfo_close($finfo);

$imageMimes = ['image/jpeg', 'image/png', 'image/gif'];
$fileMimes  = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain',
];

$allowedMimes = array_merge($imageMimes, $fileMimes);
if (!in_array($mimeType, $allowedMimes)) {
    echo json_encode(['error' => 'File type not allowed']);
    exit;
}

$fileType     = in_array($mimeType, $imageMimes) ? 'image' : 'file';
$originalName = basename($_FILES['file']['name']);

// Sanitise the original name to a safe extension only
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$safeExts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
if (!in_array($ext, $safeExts)) {
    echo json_encode(['error' => 'File extension not allowed']);
    exit;
}

$filename   = 'attach_' . time() . '_' . $userid . '.' . $ext;
$uploadPath = $uploadDir . $filename;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $uploadPath)) {
    echo json_encode(['error' => 'Could not save file — check server write permissions']);
    exit;
}

$safeOriginal = $conn->real_escape_string($originalName);
$safeFilename = $conn->real_escape_string($filename);
$safeType     = $conn->real_escape_string($fileType);

$conn->query("INSERT INTO Attachments (filename, original_name, file_type) VALUES ('$safeFilename', '$safeOriginal', '$safeType')");
$attachmentId = mysqli_insert_id($conn);

echo json_encode([
    'id'            => $attachmentId,
    'filename'      => $filename,
    'file_type'     => $fileType,
    'original_name' => $originalName,
]);
?>
