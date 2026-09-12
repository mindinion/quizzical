<?php
/**
 * action-get5050rankings.php
 *
 * 50/50 lifeline stats for a group and period: player success rates with per-user category breakdown.
 * period: weekly | monthly | yearly | alltime
 */

require_once 'require_auth.php';
require_once 'security.php';
require_once __DIR__ . '/ai-quiz-stats.php';
require_once __DIR__ . '/ai-lifeline.php';
require_once __DIR__ . '/category-meta.php';

$groupid = isset($_GET['groupid']) ? (int)sanitizeString($_GET['groupid']) : 0;
$period = isset($_GET['period']) ? sanitizeString($_GET['period']) : 'weekly';
if (!in_array($period, ['weekly', 'monthly', 'yearly', 'alltime'], true)) {
    $period = 'weekly';
}

$empty = [
    'period'   => $period,
    'min_uses' => lifelineMinUses($period),
    'group'    => ['uses' => 0, 'success_pct' => null, 'burned' => 0],
    'players'  => [],
];

if (!$groupid) {
    echo json_encode($empty);
    exit;
}

ensureAiLifelineTable($conn);

$minUses = lifelineMinUses($period);
$window = categoryPeriodWindow($period);
$catExpr = categorySourceCategorySql('q');

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
            a.is_correct
        FROM AILifeline l
        INNER JOIN AIAnswer a ON a.user_id = l.user_id AND a.question_id = l.question_id
        INNER JOIN AIQuestion q ON q.id = l.question_id
        INNER JOIN AIQuiz z ON z.id = l.quiz_id AND z.status = 'active'
        INNER JOIN Users u ON u.id = l.user_id
        INNER JOIN Memberships m ON m.user_id = u.id AND m.group_id = ?
        WHERE 1=1
        $dateFilter";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $groupid);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$rows) {
    echo json_encode($empty);
    exit;
}

$groupUses = 0;
$groupSuccess = 0;
$byUser = [];

foreach ($rows as $row) {
    $correct = (int)$row['is_correct'];
    $uid = (int)$row['userid'];
    $cat = trim((string)$row['source_category']);
    if ($cat === '') {
        $cat = 'General Knowledge';
    }

    $groupUses++;
    $groupSuccess += $correct;

    if (!isset($byUser[$uid])) {
        $byUser[$uid] = [
            'userid'       => $uid,
            'first_name'   => $row['first_name'],
            'last_name'    => $row['last_name'],
            'pic_filename' => $row['pic_filename'],
            'uses'         => 0,
            'successes'    => 0,
            'categories'   => [],
        ];
    }
    $byUser[$uid]['uses']++;
    $byUser[$uid]['successes'] += $correct;

    if (!isset($byUser[$uid]['categories'][$cat])) {
        $byUser[$uid]['categories'][$cat] = ['uses' => 0, 'successes' => 0];
    }
    $byUser[$uid]['categories'][$cat]['uses']++;
    $byUser[$uid]['categories'][$cat]['successes'] += $correct;
}

$players = [];
foreach ($byUser as $user) {
    $categories = [];
    foreach ($user['categories'] as $catName => $stats) {
        $uses = $stats['uses'];
        $categories[] = [
            'category'    => $catName,
            'emoji'       => categoryEmoji($catName),
            'uses'        => $uses,
            'burned'      => $uses - $stats['successes'],
            'success_pct' => (int)round($stats['successes'] / $uses * 100),
        ];
    }
    usort($categories, function ($a, $b) {
        if ($a['uses'] !== $b['uses']) {
            return $b['uses'] <=> $a['uses'];
        }
        return strcmp($a['category'], $b['category']);
    });

    $players[] = [
        'userid'       => $user['userid'],
        'first_name'   => $user['first_name'],
        'last_name'    => $user['last_name'],
        'pic_filename' => $user['pic_filename'],
        'uses'         => $user['uses'],
        'burned'       => $user['uses'] - $user['successes'],
        'success_pct'  => (int)round($user['successes'] / $user['uses'] * 100),
        'qualified'    => $user['uses'] >= $minUses,
        'categories'   => $categories,
    ];
}

usort($players, function ($a, $b) {
    if ($a['qualified'] !== $b['qualified']) {
        return $b['qualified'] <=> $a['qualified'];
    }
    if ($a['success_pct'] !== $b['success_pct']) {
        return $b['success_pct'] <=> $a['success_pct'];
    }
    if ($a['uses'] !== $b['uses']) {
        return $b['uses'] <=> $a['uses'];
    }
    return strcmp($a['first_name'], $b['first_name']);
});

echo json_encode([
    'period'   => $period,
    'min_uses' => $minUses,
    'group'    => [
        'uses'        => $groupUses,
        'burned'      => $groupUses - $groupSuccess,
        'success_pct' => (int)round($groupSuccess / $groupUses * 100),
    ],
    'players'  => $players,
]);
