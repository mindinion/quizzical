<?php
/**
 * action-qa-generate-quiz.php
 *
 * HTTP trigger for agent-driven QA optimization passes on the server.
 * Requires QA_RUN_TOKEN in dblogin.php (gitignored). Does not write to AIQuiz.
 *
 * POST JSON or form fields:
 *   token   — must match QA_RUN_TOKEN
 *   type    — morning|afternoon (default morning)
 *   runs    — 1–3 (default 1; use 1 per request to avoid gateway timeouts)
 *   lookback — days (default 5)
 */

require_once __DIR__ . '/dblogin.php';
require_once __DIR__ . '/qa-run-lib.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}

$token = $body['token'] ?? ($_SERVER['HTTP_X_QA_TOKEN'] ?? '');
if (!qaTokenIsValid(is_string($token) ? $token : null)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden — set QA_RUN_TOKEN in dblogin.php']);
    exit;
}

$type = isset($body['type']) ? strtolower(trim((string)$body['type'])) : 'morning';
if ($type !== 'morning' && $type !== 'afternoon') {
    $type = 'morning';
}

$runs = isset($body['runs']) ? (int)$body['runs'] : 1;
$runs = max(1, min(3, $runs));

$lookback = isset($body['lookback']) ? (int)$body['lookback'] : 5;
$lookback = max(0, min(14, $lookback));

$skipImages = !empty($body['no_images']);

try {
    $summary = qaExecutePass($conn, $type, $runs, 'logs/qa', $lookback, $skipImages);
    $conn->close();
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    $conn->close();
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
