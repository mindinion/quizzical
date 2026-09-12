<?php
/**
 * Build nested QuizFeed post objects (shared by action-getresults and action-getfeed).
 */

require_once __DIR__ . '/ai-quiz-stats.php';
require_once __DIR__ . '/category-meta.php';
require_once __DIR__ . '/quiz-feed-helper.php';

class FeedPost {
    public $postid = "";
    public $poster_filename = "";
    public $poster_first_name = "";
    public $poster_last_name = "";
    public $poster_id = "";
    public $post_timestamp = "";
    public $post_comment = "";
    public $result = null;
    public $comments = [];
    public $digs = [];
    public $attachments = [];
    public $specialty_category = null;
    public $specialty_emoji = null;
    public $ai_quiz_id = null;
    public $quiz_type = null;
    public $quiz_date = null;
    public $is_quiz_shell = false;
}

class FeedResult {
    public $resultid = "";
    public $result_date = "";
    public $result_score = "";
    public $result_max = "";
    public $result_type = "";
    public $result_quiz_id = null;
    public $result_quiz_date = null;
}

class FeedComment {
    public $commentid = "";
    public $comment_first_name = "";
    public $comment_last_name = "";
    public $comment_pic_filename = "";
    public $comment_comment = "";
    public $comment_timestamp = "";
    public $comment_user_id = "";
    public $digs = [];
    public $attachments = [];
}

class FeedAttachment {
    public $attachid = "";
    public $filename = "";
    public $original_name = "";
    public $file_type = "";
}

class FeedDig {
    public $digid = "";
    public $dig_first_name = "";
    public $dig_user_id = "";
}

/**
 * @return list<FeedPost>
 */
function feedBuildPostsFromQueryResult(mysqli_result $result): array {
    $results = [];
    $count_posts = 0;
    $count_comments = 0;
    $count_postdigs = 0;
    $count_commentdigs = 0;
    $count_postattach = 0;
    $count_commentattach = 0;
    $lastpostid = null;
    $lastresultid = null;
    $lastcommentid = null;
    $lastcommentpostid = null;
    $lastPost = 0;
    $lastComment = 0;
    $lastdigpostid = null;
    $lastdigpostidpost = null;
    $lastdigcommentid = null;
    $lastdigcommentidcomment = null;
    $lastpostattachpost = null;
    $lastcommentattachcomment = null;
    $seenPostAttachIds = [];
    $seenCommentAttachIds = [];

    if (mysqli_num_rows($result) > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $postid = $row['post_id'];
            $resultid = $row['result_id'];
            $commentid = $row['comment_id'];
            $digpostid = $row['post_dig_id'];
            $digcommentid = $row['comment_dig_id'];
            $postattachid = $row['post_attach_id'];
            $commentattachid = $row['comment_attach_id'];

            if ($postid != null) {
                if ($lastpostid != $postid) {
                    $lastpostid = $postid;
                    $results[$count_posts] = new FeedPost();
                    $results[$count_posts]->poster_filename = $row['poster_filename'];
                    $results[$count_posts]->poster_first_name = $row['poster_first_name'];
                    $results[$count_posts]->poster_last_name = $row['poster_last_name'];
                    $results[$count_posts]->poster_id = $row['poster_id'];
                    $results[$count_posts]->postid = $postid;
                    $results[$count_posts]->post_timestamp = $row['post_timestamp'];
                    $results[$count_posts]->post_comment = $row['post_comment'];
                    if (isset($row['feed_ai_quiz_id'])) {
                        $results[$count_posts]->ai_quiz_id = $row['feed_ai_quiz_id'] !== null
                            ? (int)$row['feed_ai_quiz_id'] : null;
                        $results[$count_posts]->quiz_type = $row['feed_quiz_type'];
                        $results[$count_posts]->quiz_date = $row['feed_quiz_date'];
                        $results[$count_posts]->is_quiz_shell = (
                            $resultid === null
                            && ($results[$count_posts]->ai_quiz_id || $results[$count_posts]->quiz_type)
                        );
                    }
                    $lastPost = $count_posts;
                    $count_postdigs = 0;
                    $count_comments = 0;
                    $count_postattach = 0;
                    $newComment = [];
                }

                if ($resultid != null) {
                    if ($lastresultid != $resultid) {
                        $lastresultid = $resultid;
                        $newResult = new FeedResult();
                        $newResult->resultid = $resultid;
                        $newResult->result_date = $row['result_date'];
                        $newResult->result_score = $row['result_score'];
                        $newResult->result_max = $row['result_max'];
                        $newResult->result_type = $row['result_type'];
                        $newResult->result_quiz_id = $row['result_quiz_id'] !== null
                            ? (int)$row['result_quiz_id'] : null;
                        $newResult->result_quiz_date = $row['result_quiz_date'];
                        $results[$lastPost]->result = $newResult;
                    }
                }

                if ($commentid != null) {
                    if ($lastcommentid != $commentid) {
                        $lastcommentid = $commentid;
                        if ($lastcommentpostid != $postid) {
                            $newComment = [];
                        }
                        $newComment[$count_comments] = new FeedComment();
                        $newComment[$count_comments]->commentid = $commentid;
                        $newComment[$count_comments]->comment_first_name = $row['comment_first_name'];
                        $newComment[$count_comments]->comment_last_name = $row['comment_last_name'];
                        $newComment[$count_comments]->comment_pic_filename = $row['comment_pic_filename'];
                        $newComment[$count_comments]->comment_comment = $row['comment_comment'];
                        $newComment[$count_comments]->comment_timestamp = $row['comment_timestamp'];
                        $newComment[$count_comments]->comment_user_id = $row['comment_user_id'];
                        $results[$lastPost]->comments = $newComment;
                        $lastcommentpostid = $postid;
                        $lastComment = $count_comments;
                        $count_comments++;
                        $count_commentdigs = 0;
                        $count_commentattach = 0;
                    }
                }

                if ($digpostid != null) {
                    if ($lastdigpostid != $digpostid) {
                        $lastdigpostid = $digpostid;
                        if ($lastdigpostidpost != $postid) {
                            $newPostDig = [];
                        }
                        $newPostDig[$count_postdigs] = new FeedDig();
                        $newPostDig[$count_postdigs]->digid = $digpostid;
                        $newPostDig[$count_postdigs]->dig_first_name = $row['post_dig_first_name'];
                        $newPostDig[$count_postdigs]->dig_user_id = $row['post_dig_user_id'];
                        $results[$lastPost]->digs = $newPostDig;
                        $lastdigpostidpost = $postid;
                        $count_postdigs++;
                    }
                }

                if ($digcommentid != null) {
                    if ($lastdigcommentid != $digcommentid) {
                        $lastdigcommentid = $digcommentid;
                        if ($lastdigcommentidcomment != $commentid) {
                            $newCommentDig = [];
                        }
                        $newCommentDig[$count_commentdigs] = new FeedDig();
                        $newCommentDig[$count_commentdigs]->digid = $digcommentid;
                        $newCommentDig[$count_commentdigs]->dig_first_name = $row['comment_dig_first_name'];
                        $newCommentDig[$count_commentdigs]->dig_user_id = $row['comment_dig_user_id'];
                        $results[$lastPost]->comments[$lastComment]->digs = $newCommentDig;
                        $lastdigcommentidcomment = $commentid;
                        $count_commentdigs++;
                    }
                }

                if ($postattachid != null) {
                    if ($lastpostattachpost != $postid) {
                        $newPostAttach = [];
                        $count_postattach = 0;
                        $seenPostAttachIds = [];
                        $lastpostattachpost = $postid;
                    }
                    if (!isset($seenPostAttachIds[$postattachid])) {
                        $seenPostAttachIds[$postattachid] = true;
                        $newPostAttach[$count_postattach] = new FeedAttachment();
                        $newPostAttach[$count_postattach]->attachid = $postattachid;
                        $newPostAttach[$count_postattach]->filename = $row['post_attach_filename'];
                        $newPostAttach[$count_postattach]->original_name = $row['post_attach_original'];
                        $newPostAttach[$count_postattach]->file_type = $row['post_attach_type'];
                        $results[$lastPost]->attachments = $newPostAttach;
                        $count_postattach++;
                    }
                }

                if ($commentattachid != null) {
                    if ($lastcommentattachcomment != $commentid) {
                        $newCommentAttach = [];
                        $count_commentattach = 0;
                        $seenCommentAttachIds = [];
                        $lastcommentattachcomment = $commentid;
                    }
                    if (!isset($seenCommentAttachIds[$commentattachid])) {
                        $seenCommentAttachIds[$commentattachid] = true;
                        $newCommentAttach[$count_commentattach] = new FeedAttachment();
                        $newCommentAttach[$count_commentattach]->attachid = $commentattachid;
                        $newCommentAttach[$count_commentattach]->filename = $row['comment_attach_filename'];
                        $newCommentAttach[$count_commentattach]->original_name = $row['comment_attach_original'];
                        $newCommentAttach[$count_commentattach]->file_type = $row['comment_attach_type'];
                        $results[$lastPost]->comments[$lastComment]->attachments = $newCommentAttach;
                        $count_commentattach++;
                    }
                }
            }
            $count_posts++;
        }
    }

    return array_values($results);
}

function feedPostsSelectSql(mysqli $conn): array {
    $hasLink = resultsHasAiQuizId($conn);
    $hasShell = quizFeedHasDiscussionColumns($conn);

    if ($hasLink) {
        $quizSelect = "Results.ai_quiz_id AS result_quiz_id, DATE_FORMAT(AIQuiz.date, '%Y-%m-%d') AS result_quiz_date,";
        $quizJoin = "LEFT JOIN AIQuiz ON (AIQuiz.id = Results.ai_quiz_id)";
    } else {
        $quizSelect = "NULL AS result_quiz_id, NULL AS result_quiz_date,";
        $quizJoin = "";
    }

    $shellSelect = $hasShell
        ? "QuizFeed.ai_quiz_id AS feed_ai_quiz_id, QuizFeed.quiz_type AS feed_quiz_type, QuizFeed.quiz_date AS feed_quiz_date,"
        : "NULL AS feed_ai_quiz_id, NULL AS feed_quiz_type, NULL AS feed_quiz_date,";

    return [$quizSelect, $quizJoin, $shellSelect, $hasShell];
}

/**
 * @return list<FeedPost>
 */
function feedFetchPostsForGroup(mysqli $conn, int $groupId, string $postFilterSql): array {
    [$quizSelect, $quizJoin, $shellSelect] = feedPostsSelectSql($conn);

    $q = "SELECT
        $quizSelect
        $shellSelect
        QuizFeed.id AS post_id,
        Results.id AS result_id,
        Poster.pic_filename AS poster_filename,
        Poster.first_name AS poster_first_name,
        Poster.last_name AS poster_last_name,
        Poster.id AS poster_id,
        QuizFeed.timestamp AS post_timestamp,
        Results.date AS result_date,
        Results.score AS result_score,
        Results.max AS result_max,
        Results.type AS result_type,
        QuizFeed.comment AS post_comment,
        Comment.id AS comment_id,
        Commenter.first_name AS comment_first_name,
        Commenter.last_name AS comment_last_name,
        Commenter.pic_filename AS comment_pic_filename,
        Commenter.id AS comment_user_id,
        Comment.comment AS comment_comment,
        Comment.timestamp AS comment_timestamp,
        CommentDigs.id AS comment_dig_id,
        CommentDigger.first_name AS comment_dig_first_name,
        CommentDigs.userid AS comment_dig_user_id,
        PostDigs.id AS post_dig_id,
        PostDigger.first_name AS post_dig_first_name,
        PostDigs.userid AS post_dig_user_id,
        PostAttach.id AS post_attach_id,
        PostAttach.filename AS post_attach_filename,
        PostAttach.original_name AS post_attach_original,
        PostAttach.file_type AS post_attach_type,
        CommentAttach.id AS comment_attach_id,
        CommentAttach.filename AS comment_attach_filename,
        CommentAttach.original_name AS comment_attach_original,
        CommentAttach.file_type AS comment_attach_type
    FROM QuizFeed
        INNER JOIN Users AS Poster ON (QuizFeed.user_id = Poster.id)
        INNER JOIN Memberships ON (Memberships.user_id = Poster.id)
        LEFT JOIN Results ON (QuizFeed.result_id = Results.id)
        $quizJoin
        LEFT JOIN Comment ON (QuizFeed.id = Comment.quizfeed_id AND (Comment.status IS NULL OR Comment.status = 'active'))
        LEFT JOIN Users AS Commenter ON (Comment.user_id = Commenter.id)
        LEFT JOIN Digs AS CommentDigs ON (Comment.id = CommentDigs.commentid)
            AND (CommentDigs.status IS NULL OR CommentDigs.status = 'active')
        LEFT JOIN Digs AS PostDigs ON (QuizFeed.id = PostDigs.postid)
            AND (PostDigs.status IS NULL OR PostDigs.status = 'active')
        LEFT JOIN Users AS CommentDigger ON (CommentDigs.userid = CommentDigger.id)
        LEFT JOIN Users AS PostDigger ON (PostDigs.userid = PostDigger.id)
        LEFT JOIN Attachments AS PostAttach ON (PostAttach.post_id = QuizFeed.id)
        LEFT JOIN Attachments AS CommentAttach ON (CommentAttach.comment_id = Comment.id)
    WHERE Memberships.group_id = $groupId
        AND QuizFeed.status = 'active'
        AND (Results.status IS NULL OR Results.status = 'active')
        AND ($postFilterSql)
    ORDER BY QuizFeed.id DESC, Comment.id ASC, CommentDigs.id DESC, PostDigs.id DESC,
        PostAttach.id ASC, CommentAttach.id ASC";

    $result = $conn->query($q);
    if (!$result) {
        return [];
    }
    return feedBuildPostsFromQueryResult($result);
}

function feedApplySpecialties(mysqli $conn, int $groupId, array $posts): array {
    if (!$posts || !aiQuestionHasColumn($conn, 'source_category')) {
        return $posts;
    }
    $posterIds = [];
    foreach ($posts as $post) {
        if (!empty($post->poster_id)) {
            $posterIds[] = (int)$post->poster_id;
        }
    }
    $specialties = categoryFetchFeedSpecialties($conn, $groupId, $posterIds);
    foreach ($posts as $post) {
        $uid = (int)$post->poster_id;
        if (isset($specialties[$uid])) {
            $post->specialty_category = $specialties[$uid]['category'];
            $post->specialty_emoji = $specialties[$uid]['emoji'];
        }
    }
    return $posts;
}

function feedPostToArray(FeedPost $post): array {
    $out = [
        'feed_type'          => 'post',
        'postid'             => $post->postid,
        'poster_filename'    => $post->poster_filename,
        'poster_first_name'  => $post->poster_first_name,
        'poster_last_name'   => $post->poster_last_name,
        'poster_id'          => $post->poster_id,
        'post_timestamp'     => $post->post_timestamp,
        'post_comment'       => $post->post_comment,
        'comments'           => $post->comments,
        'digs'               => $post->digs,
        'attachments'        => $post->attachments,
        'specialty_category' => $post->specialty_category,
        'specialty_emoji'    => $post->specialty_emoji,
        'result'             => $post->result,
    ];
    return $out;
}
