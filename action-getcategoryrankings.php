<?php
/**
 * action-getcategoryrankings.php
 *
 * Per-category leaderboards for a group and period.
 * period: weekly | monthly | yearly | alltime
 */

require_once 'require_auth.php';
require_once 'security.php';
require_once __DIR__ . '/ai-quiz-stats.php';
require_once __DIR__ . '/category-meta.php';

if (isset($_GET['groupid'])) {
    $groupid = (int)sanitizeString($_GET['groupid']);
} else {
    $groupid = 0;
}
$period = isset($_GET['period']) ? sanitizeString($_GET['period']) : 'weekly';
if (!in_array($period, ['weekly', 'monthly', 'yearly', 'alltime'], true)) {
    $period = 'weekly';
}

if (!$groupid || !aiQuestionHasColumn($conn, 'source_category')) {
    echo json_encode(['categories' => [], 'period' => $period, 'min_answers' => categoryMinAnswers($period)]);
    exit;
}

$minAnswers = categoryMinAnswers($period);
$catExpr = categorySourceCategorySql('q');
$window = categoryPeriodWindow($period);

$dateFilter = '';
if ($window['days'] !== null) {
    $days = (int)$window['days'];
    $dateFilter = " AND z.date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)";
}

$sql = "SELECT
            u.id AS userid,
            u.first_name,
            u.last_name,
            u.pic_filename,
            $catExpr AS source_category,
            COUNT(*) AS answers,
            ROUND(AVG(a.is_correct) * 100, 0) AS avg_pct
        FROM AIAnswer a
        INNER JOIN AIQuestion q ON q.id = a.question_id
        INNER JOIN AIQuiz z ON z.id = a.quiz_id AND z.status = 'active'
        INNER JOIN Users u ON u.id = a.user_id
        INNER JOIN Memberships m ON m.user_id = u.id AND m.group_id = ?
        WHERE 1=1
        $dateFilter
        GROUP BY u.id, u.first_name, u.last_name, u.pic_filename, $catExpr
        HAVING answers >= $minAnswers
        ORDER BY source_category ASC, avg_pct DESC, answers DESC, u.first_name ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $groupid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$byCategory = [];
foreach ($rows as $row) {
    $cat = trim((string)$row['source_category']);
    if ($cat === '') {
        continue;
    }
    if (!isset($byCategory[$cat])) {
        $byCategory[$cat] = [];
    }
    $byCategory[$cat][] = [
        'userid'       => (int)$row['userid'],
        'first_name'   => $row['first_name'],
        'last_name'    => $row['last_name'],
        'pic_filename' => $row['pic_filename'],
        'avg_pct'      => (int)$row['avg_pct'],
        'answers'      => (int)$row['answers'],
    ];
}

$categories = [];
foreach (categorySortKeys(array_keys($byCategory)) as $cat) {
    $categories[] = [
        'category'    => $cat,
        'emoji'       => categoryEmoji($cat),
        'min_answers' => $minAnswers,
        'rankings'    => $byCategory[$cat],
    ];
}

echo json_encode([
    'period'      => $period,
    'min_answers' => $minAnswers,
    'categories'  => $categories,
]);
