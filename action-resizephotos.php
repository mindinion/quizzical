<?php
/**
 * action-resizephotos.php
 *
 * One-off script to batch-convert all existing profile photos to 150x150 JPEG.
 * Run once via browser, then delete the file.
 *
 * Usage: visit /action-resizephotos.php?secret=resizeme
 */

if (!isset($_GET['secret']) || $_GET['secret'] !== 'resizeme') {
	http_response_code(403);
	exit('Forbidden');
}

require_once 'dblogin.php';

$dir     = __DIR__;
$pattern = $dir . '/user*.{jpg,jpeg,png,gif}';
$files   = glob($pattern, GLOB_BRACE);

$results = [];

foreach ($files as $filepath) {
	$basename = basename($filepath);

	// Detect MIME type
	$finfo    = finfo_open(FILEINFO_MIME_TYPE);
	$mimeType = finfo_file($finfo, $filepath);
	finfo_close($finfo);

	switch ($mimeType) {
		case 'image/jpeg': $src = imagecreatefromjpeg($filepath); break;
		case 'image/png':  $src = imagecreatefrompng($filepath);  break;
		case 'image/gif':  $src = imagecreatefromgif($filepath);  break;
		default:
			$results[] = "$basename — skipped (unrecognised type: $mimeType)";
			continue 2;
	}

	if (!$src) {
		$results[] = "$basename — skipped (GD could not open file)";
		continue;
	}

	// Resize to 150x150
	$out = imagecreatetruecolor(150, 150);
	imagecopyresampled($out, $src, 0, 0, 0, 0, 150, 150, imagesx($src), imagesy($src));
	imagedestroy($src);

	// Extract userid from filename (e.g. user42.png → 42)
	preg_match('/user(\d+)/', $basename, $matches);
	$userid = isset($matches[1]) ? (int)$matches[1] : 0;

	// Always save as JPEG
	$newFilename = 'user' . $userid . '.jpg';
	$newPath     = $dir . '/' . $newFilename;

	if (imagejpeg($out, $newPath, 85)) {
		imagedestroy($out);
		// Remove old file if it was a different format
		if ($filepath !== $newPath) {
			unlink($filepath);
		}
		// Update DB
		if ($userid) {
			$conn->query("UPDATE Users SET pic_filename = '$newFilename' WHERE id = $userid");
		}
		$results[] = "$basename → $newFilename ✓";
	} else {
		imagedestroy($out);
		$results[] = "$basename — failed (could not write file)";
	}
}

if (empty($files)) {
	$results[] = "No user photo files found.";
}
?>
<!DOCTYPE html>
<html>
<head><title>Resize Photos</title>
<style>body { font-family: monospace; padding: 20px; } li { margin: 4px 0; }</style>
</head>
<body>
<h2>Photo resize complete</h2>
<ul>
<?php foreach ($results as $r): ?>
	<li><?= htmlspecialchars($r) ?></li>
<?php endforeach; ?>
</ul>
<p><strong>Done. Delete this file from the server.</strong></p>
</body>
</html>
