<?php
/**
 * action-getuserquizcategories.php
 *
 * Per-category breakdown of one user's answers within a single quiz, for the
 * specialty popover shown on a quiz card's score rows.
 * Params: quiz_id, userid
 */

require_once 'require_auth.php';
require_once 'security.php';
require_once __DIR__ . '/ai-quiz-stats.php';
require_once __DIR__ . '/category-meta.php';

header('Content-Type: application/json');

$quizId = isset($_GET['quiz_id']) ? (int)sanitizeString($_GET['quiz_id']) : 0;
$userId = isset($_GET['userid']) ? (int)sanitizeString($_GET['userid']) : 0;

if (!$quizId || !$userId || !aiQuestionHasColumn($conn, 'source_category')) {
    echo json_encode(['categories' => []]);
    exit;
}

$catExpr = categorySourceCategorySql('q');
$sql = "SELECT
            $catExpr AS source_category,
            COUNT(*) AS answers,
            SUM(a.is_correct) AS correct
        FROM AIAnswer a
        INNER JOIN AIQuestion q ON q.id = a.question_id
        WHERE a.quiz_id = ? AND a.user_id = ?
        GROUP BY $catExpr
        ORDER BY correct DESC, answers DESC, source_category ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['categories' => []]);
    exit;
}
$stmt->bind_param('ii', $quizId, $userId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$categories = [];
foreach ($rows as $row) {
    $category = trim((string)$row['source_category']);
    if ($category === '') {
        continue;
    }
    $categories[] = [
        'category' => $category,
        'emoji'    => categoryEmoji($category),
        'correct'  => (int)$row['correct'],
        'answers'  => (int)$row['answers'],
    ];
}

echo json_encode(['categories' => $categories]);
