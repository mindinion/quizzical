<?php
/**
 * action-getusercategories.php
 *
 * Group #1 categories for one user (last 30 days), for feed specialty popover.
 * Params: groupid, userid
 */

require_once 'require_auth.php';
require_once 'security.php';
require_once __DIR__ . '/ai-quiz-stats.php';
require_once __DIR__ . '/category-meta.php';

$groupid = isset($_GET['groupid']) ? (int)sanitizeString($_GET['groupid']) : 0;
$userid  = isset($_GET['userid']) ? (int)sanitizeString($_GET['userid']) : 0;

if (!$groupid || !$userid || !aiQuestionHasColumn($conn, 'source_category')) {
    echo json_encode(['leader_in' => [], 'period' => 'monthly']);
    exit;
}

$leaderIn = categoryFetchUserLeaderCategories($conn, $groupid, $userid, 'monthly');

echo json_encode([
    'leader_in'   => $leaderIn,
    'period'      => 'monthly',
    'min_answers' => categoryMinAnswers('monthly'),
]);
