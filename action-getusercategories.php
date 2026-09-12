<?php
/**
 * action-getusercategories.php
 *
 * Top category stats for one user (last 30 days), for feed specialty popover.
 * Params: groupid, userid
 */

require_once 'require_auth.php';
require_once 'security.php';
require_once __DIR__ . '/ai-quiz-stats.php';
require_once __DIR__ . '/category-meta.php';

$groupid = isset($_GET['groupid']) ? (int)sanitizeString($_GET['groupid']) : 0;
$userid  = isset($_GET['userid']) ? (int)sanitizeString($_GET['userid']) : 0;

if (!$groupid || !$userid || !aiQuestionHasColumn($conn, 'source_category')) {
    echo json_encode(['categories' => []]);
    exit;
}

$stats = categoryFetchUserStats($conn, $groupid, 'monthly', [$userid]);
$rows = $stats[$userid] ?? [];

usort($rows, function ($a, $b) {
    if ($a['avg_pct'] !== $b['avg_pct']) {
        return $b['avg_pct'] <=> $a['avg_pct'];
    }
    if ($a['answers'] !== $b['answers']) {
        return $b['answers'] <=> $a['answers'];
    }
    return strcmp($a['category'], $b['category']);
});

$top = array_slice($rows, 0, 3);
$out = [];
foreach ($top as $row) {
    $out[] = [
        'category' => $row['category'],
        'emoji'    => categoryEmoji($row['category']),
        'avg_pct'  => (int)$row['avg_pct'],
        'answers'  => (int)$row['answers'],
    ];
}

echo json_encode(['categories' => $out, 'period' => 'monthly']);
