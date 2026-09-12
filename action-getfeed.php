<?php
/**
 * action-getfeed.php
 *
 * Quiz-centric feed (v2): quiz cards with ranked results + standalone posts,
 * sorted by last activity. Classic feed remains action-getresults.php.
 */

ini_set('error_reporting', E_STRICT);

require_once 'require_auth.php';
require_once __DIR__ . '/feed-post-lib.php';

header('Content-Type: application/json');

$groupid = isset($_GET['groupid']) ? (int)$_GET['groupid'] : 0;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$viewerTimezone = isset($_GET['timezone']) && $_GET['timezone'] !== ''
    ? $_GET['timezone'] : 'Pacific/Auckland';
$limit = 50;
$windowDays = 90;

if ($groupid <= 0) {
    echo json_encode([]);
    exit;
}

$hasShell = quizFeedHasDiscussionColumns($conn);
$memberTotal = feedGroupMemberCount($conn, $groupid);

// Result posts + standalone posts + quiz shells in the feed window
$standaloneFilter = $hasShell
    ? "(QuizFeed.result_id IS NULL AND QuizFeed.ai_quiz_id IS NULL AND (QuizFeed.quiz_type IS NULL OR QuizFeed.quiz_type = ''))"
    : "(QuizFeed.result_id IS NULL)";
$shellFilter = $hasShell
    ? "(QuizFeed.result_id IS NULL AND (QuizFeed.ai_quiz_id IS NOT NULL OR (QuizFeed.quiz_type IS NOT NULL AND QuizFeed.quiz_type != '')))"
    : "(1=0)";

$postFilter = "(
    (QuizFeed.result_id IS NOT NULL AND QuizFeed.timestamp >= DATE_SUB(NOW(), INTERVAL $windowDays DAY))
    OR ($standaloneFilter AND QuizFeed.timestamp >= DATE_SUB(NOW(), INTERVAL $windowDays DAY))
    OR ($shellFilter)
)";

$allPosts = feedFetchPostsForGroup($conn, $groupid, $postFilter);
$allPosts = feedApplySpecialties($conn, $groupid, $allPosts);

$shellPostsByKey = [];
$standalonePosts = [];
$resultPostsByKey = [];

foreach ($allPosts as $post) {
    if (!empty($post->is_quiz_shell)) {
        $key = $post->ai_quiz_id
            ? 'ai:' . $post->ai_quiz_id
            : 'legacy:' . $post->quiz_type . ':' . $post->quiz_date;
        $shellPostsByKey[$key] = $post;
        continue;
    }
    if ($post->result === null) {
        $standalonePosts[] = $post;
        continue;
    }
    $row = [
        'result_quiz_id'   => $post->result->result_quiz_id,
        'result_quiz_date' => $post->result->result_quiz_date,
        'result_type'      => $post->result->result_type,
        'result_date'      => $post->result->result_date,
    ];
    $keyInfo = feedQuizKeyFromResultRow($row);
    $key = $keyInfo['key'];
    if (!isset($resultPostsByKey[$key])) {
        $resultPostsByKey[$key] = [
            'meta'  => $keyInfo,
            'posts' => [],
        ];
    }
    $resultPostsByKey[$key]['posts'][] = $post;
}

$quizCards = [];

foreach ($resultPostsByKey as $key => $bundle) {
    $meta = $bundle['meta'];
    $posts = $bundle['posts'];

    usort($posts, function ($a, $b) {
        $sa = (int)$a->result->result_score;
        $sb = (int)$b->result->result_score;
        if ($sa !== $sb) {
            return $sb - $sa;
        }
        return strcmp($a->post_timestamp, $b->post_timestamp);
    });

    $activity = [];
    $resultsOut = [];
    $rank = 1;
    foreach ($posts as $post) {
        $activity[] = $post->post_timestamp;
        foreach ($post->comments as $c) {
            $activity[] = feedServerTimestampToUser($c->comment_timestamp, $viewerTimezone);
        }
        $resultsOut[] = [
            'rank'              => $rank++,
            'post_id'           => (int)$post->postid,
            'result_id'         => (int)$post->result->resultid,
            'poster_id'         => (int)$post->poster_id,
            'poster_filename'   => $post->poster_filename,
            'poster_first_name' => $post->poster_first_name,
            'poster_last_name'  => $post->poster_last_name,
            'score'             => (int)$post->result->result_score,
            'max'               => (int)$post->result->result_max,
            'post_comment'      => $post->post_comment,
            'post_timestamp'    => $post->post_timestamp,
            'comments'          => $post->comments,
            'digs'              => $post->digs,
            'attachments'       => $post->attachments,
            'specialty_category'=> $post->specialty_category,
            'specialty_emoji'   => $post->specialty_emoji,
        ];
    }

    $shell = $shellPostsByKey[$key] ?? null;
    $quizPostId = $shell ? (int)$shell->postid : 0;
    if ($shell) {
        $activity[] = $shell->post_timestamp;
        foreach ($shell->comments as $c) {
            $activity[] = feedServerTimestampToUser($c->comment_timestamp, $viewerTimezone);
        }
    }

    $aiId = $meta['ai_quiz_id'];
    $qType = $meta['quiz_type'];
    $qDate = $meta['quiz_date'];
    if ($aiId && (!$qDate || !$qType)) {
        $zq = $conn->query("SELECT type, date FROM AIQuiz WHERE id = " . (int)$aiId . " LIMIT 1");
        if ($zq && $zrow = $zq->fetch_assoc()) {
            $qType = 'Quizzical ' . $zrow['type'];
            $qDate = $qDate ?: $zrow['date'];
        }
    }

    $myStatus = null;
    foreach ($resultsOut as $r) {
        if ((int)$r['poster_id'] === (int)$userid) {
            $myStatus = [
                'score'     => $r['score'],
                'max'       => $r['max'],
                'post_id'   => $r['post_id'],
                'result_id' => $r['result_id'],
            ];
            break;
        }
    }

    $quizCards[] = [
        'feed_type'     => 'quiz',
        'feed_key'      => $key,
        'quiz_id'       => $aiId,
        'quiz_type'     => $qType,
        'quiz_date'     => $qDate,
        'title'         => feedQuizTitle($conn, $aiId, $qType, $qDate),
        'last_activity' => feedMaxActivityTimestamp($activity),
        'participation' => ['done' => count($resultsOut), 'total' => $memberTotal],
        'my_status'     => $myStatus,
        'quiz_post_id'  => $quizPostId,
        'quiz_comments' => $shell ? $shell->comments : [],
        'quiz_digs'     => $shell ? $shell->digs : [],
        'results'       => $resultsOut,
    ];
}

// Today's quizzes with no results yet
foreach (feedTodaysAiQuizzes($conn) as $todayQuiz) {
    $key = 'ai:' . $todayQuiz['quiz_id'];
    if (isset($resultPostsByKey[$key])) {
        continue;
    }
    $shell = $shellPostsByKey[$key] ?? null;
    $activity = [];
    if ($shell) {
        $activity[] = $shell->post_timestamp;
        foreach ($shell->comments as $c) {
            $activity[] = feedServerTimestampToUser($c->comment_timestamp, $viewerTimezone);
        }
    }
    $qType = 'Quizzical ' . $todayQuiz['type'];
    $qDate = $todayQuiz['date'];

    $myStatus = null;
    if (resultsHasAiQuizId($conn)) {
        $mq = $conn->query(
            "SELECT score, max, id FROM Results
             WHERE user = " . (int)$userid . " AND status = 'active' AND ai_quiz_id = "
            . (int)$todayQuiz['quiz_id'] . " LIMIT 1"
        );
        if ($mq && $mrow = $mq->fetch_assoc()) {
            $myStatus = [
                'score'     => (int)$mrow['score'],
                'max'       => (int)$mrow['max'],
                'result_id' => (int)$mrow['id'],
                'post_id'   => 0,
            ];
        }
    }

    $quizCards[] = [
        'feed_type'     => 'quiz',
        'feed_key'      => $key,
        'quiz_id'       => $todayQuiz['quiz_id'],
        'quiz_type'     => $qType,
        'quiz_date'     => $qDate,
        'title'         => feedQuizTitle($conn, $todayQuiz['quiz_id'], $qType, $qDate),
        // With no results or comments yet, a quiz is as recent as its arrival, which
        // also keeps the morning and afternoon quiz from tying on the same date.
        'last_activity' => feedMaxActivityTimestamp($activity)
            ?: (feedServerTimestampToUser($todayQuiz['generated_at'] ?? null, $viewerTimezone)
                ?: ($qDate . ' 00:00:00')),
        'participation' => ['done' => 0, 'total' => $memberTotal],
        'my_status'     => $myStatus,
        'quiz_post_id'  => $shell ? (int)$shell->postid : 0,
        'quiz_comments' => $shell ? $shell->comments : [],
        'quiz_digs'     => $shell ? $shell->digs : [],
        'results'       => [],
        'empty_today'   => true,
    ];
}

$feedItems = [];

foreach ($standalonePosts as $post) {
    $item = feedPostToArray($post);
    $item['last_activity'] = $post->post_timestamp;
    foreach ($post->comments as $c) {
        $ts = feedMaxActivityTimestamp([$item['last_activity'], $c->comment_timestamp]);
        $item['last_activity'] = $ts;
    }
    $feedItems[] = $item;
}

foreach ($quizCards as $card) {
    $feedItems[] = $card;
}

usort($feedItems, function ($a, $b) {
    $ta = $a['last_activity'] ?? '';
    $tb = $b['last_activity'] ?? '';
    if ($ta === $tb) {
        $ka = $a['feed_key'] ?? ('post:' . ($a['postid'] ?? ''));
        $kb = $b['feed_key'] ?? ('post:' . ($b['postid'] ?? ''));
        return strcmp($kb, $ka);
    }
    return strcmp($tb, $ta);
});

$page = array_slice($feedItems, $offset, $limit);
echo json_encode($page);
