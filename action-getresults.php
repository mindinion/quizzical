<?php
	ini_set('error_reporting', E_STRICT);

	require_once 'dblogin.php';
	require_once 'security.php';

	if (isset($_GET['groupid'])) $groupid = sanitizeString($_GET['groupid']);
	if (isset($_GET['num'])) $num = sanitizeString($_GET['num']);

	$q = "SELECT
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
		QuizFeed.id AS post_id,
		Comment.id AS comment_id,
		Commenter.first_name AS comment_first_name,
		Commenter.last_name AS comment_last_name,
		Commenter.id AS comment_user_id,
		Comment.comment AS comment_comment,
		Comment.timestamp AS comment_timestamp,
		CommentDigs.id AS comment_dig_id,
		CommentDigger.first_name AS comment_dig_first_name,
		CommentDigs.userid AS comment_dig_user_id,
		PostDigs.id AS post_dig_id,
		PostDigger.first_name AS post_dig_first_name,
		PostDigs.userid AS post_dig_user_id,
		PostDigs.status as post_dig_status,
		CommentDigs.status as comment_dig_status
	FROM
		QuizFeed
		INNER JOIN Users AS Poster ON (QuizFeed.user_id = Poster.id)
		INNER JOIN Memberships ON (Memberships.user_id = Poster.id)
		LEFT JOIN Results ON (QuizFeed.result_id = Results.id)
		LEFT JOIN Comment ON (QuizFeed.id = Comment.quizfeed_id)
		LEFT JOIN Users AS Commenter ON (Comment.user_id = Commenter.id)
		LEFT JOIN Digs As CommentDigs ON (Comment.id = CommentDigs.commentid) AND (CommentDigs.status IS NULL or CommentDigs.status = 'active')
		LEFT JOIN Digs AS PostDigs ON (QuizFeed.id = PostDigs.postid) AND (PostDigs.status IS NULL or PostDigs.status = 'active')
		LEFT JOIN Users AS CommentDigger ON (CommentDigs.userid = CommentDigger.id)
		LEFT JOIN Users AS PostDigger ON (PostDigs.userid = PostDigger.id)
		INNER JOIN (SELECT DISTINCT id FROM QuizFeed WHERE QuizFeed.status = 'active' ORDER BY QuizFeed.timestamp DESC LIMIT 50) AS Last50Feeds ON (QuizFeed.id = Last50Feeds.id)

	WHERE
		Memberships.group_id = $groupid
		AND QuizFeed.status = 'active'
		AND (Results.status IS NULL or Results.status = 'active')
		AND (Comment.status IS NULL or Comment.status = 'active')

	ORDER BY QuizFeed.id DESC, Results.id DESC, Comment.id ASC, CommentDigs.id DESC, PostDigs.id DESC;";


	$result = $conn->query($q);

	class Post {
		public $postid = "";
		public $poster_filename = "";
		public $poster_first_name = "";
		public $poster_last_name = "";
		public $poster_id = "";
		public $post_timestamp = "";
		public $post_comment = "";
		public $result = "";
		public $comments = "";
		public $digs = "";
	}

	class Result  {
		public $resultid = "";
		public $result_date = "";
		public $result_score = "";
		public $result_max = "";
		public $result_type = "";
	}

	class Comment {
		public $commentid = "";
		public $comment_first_name = "";
		public $comment_last_name = "";
		public $comment_comment = "";
		public $comment_timestamp = "";
		public $comment_user_id = "";
		public $digs = "";
	}

	class Dig {
		public $digid = "";
		public $dig_first_name = "";
		public $dig_user_id = "";
	}

 	$count_posts = 0;
 	$count_comments = 0;
 	$count_postdigs = 0;
 	$count_commentdigs = 0;

	if (mysqli_num_rows($result) > 0) {
		while($row = mysqli_fetch_assoc($result)) {
			$postid = $row['post_id'];
			$resultid = $row['result_id'];
			$commentid = $row['comment_id'];
			$digpostid = $row['post_dig_id'];
			$digcommentid = $row['comment_dig_id'];

			// If it's a post (they all should be) then create it
			if ($postid != null) {
				if ($lastpostid != $postid) {
					$lastpostid = $postid;
					$results[$count_posts] = new Post();
					$results[$count_posts]->poster_filename = $row['poster_filename'];
					$results[$count_posts]->poster_first_name = $row['poster_first_name'];
					$results[$count_posts]->poster_last_name = $row['poster_last_name'];
					$results[$count_posts]->poster_id = $row['poster_id'];
					$results[$count_posts]->postid = $postid;
					$results[$count_posts]->post_timestamp = $row['post_timestamp'];
					$results[$count_posts]->post_comment = $row['post_comment'];
					$lastPost = $count_posts;
					$count_postdigs = 0;
					$count_comments = 0;
				}

				// If it's a result then create it, and insert it into the post
				if ($resultid != null) {
					if ($lastresultid != $resultid) {
						$lastresultid = $resultid;
						$newResult = new Result();
						$newResult->resultid = $resultid;
						$newResult->result_date = $row['result_date'];
						$newResult->result_score = $row['result_score'];
						$newResult->result_max = $row['result_max'];
						$newResult->result_type = $row['result_type'];
						$results[$lastPost]->result = $newResult;
					}
				}

				// If it's a comment then create it, and insert it into the post
				if ($commentid != null) {
					if ($lastcommentid != $commentid) {
						$lastcommentid = $commentid;
						if ($lastcommentpostid != $postid) $newComment = [];
						$newComment[$count_comments] = new Comment();
						$newComment[$count_comments]->commentid = $commentid;
						$newComment[$count_comments]->comment_first_name = $row['comment_first_name'];
						$newComment[$count_comments]->comment_last_name = $row['comment_last_name'];
						$newComment[$count_comments]->comment_comment = $row['comment_comment'];
						$newComment[$count_comments]->comment_timestamp = $row['comment_timestamp'];
						$newComment[$count_comments]->comment_user_id = $row['comment_user_id'];
						$results[$lastPost]->comments = $newComment;
						$lastcommentpostid = $postid;
						$lastComment = $count_comments;
						$count_comments++;
						$count_commentdigs = 0;
					}
				}

				// If it's a post dig then create it, and insert it into the post
				if ($digpostid != null) {
					if ($lastdigpostid != $digpostid) {
						$lastdigpostid = $digpostid;
						if ($lastdigpostidpost != $postid) $newPostDig = [];
						$newPostDig[$count_postdigs] = new Dig();
						$newPostDig[$count_postdigs]->digid = $digpostid;
						$newPostDig[$count_postdigs]->dig_first_name = $row['post_dig_first_name'];
						$newPostDig[$count_postdigs]->dig_user_id = $row['post_dig_user_id'];
						$results[$lastPost]->digs = $newPostDig;
						$lastdigpostidpost = $postid;
						$count_postdigs++;
					}
				}

				// If it's a comment dig then create it, and insert it into the comment
				if ($digcommentid != null) {
					if ($lastdigcommentid != $digcommentid) {
						$lastdigcomment = $digcommentid;
						if ($lastdigcommentidcomment != $commentid) $newCommentDig = [];
						$newCommentDig[$count_commentdigs] = new Dig();
						$newCommentDig[$count_commentdigs]->digid = $digcommentid;
						$newCommentDig[$count_commentdigs]->dig_first_name = $row['comment_dig_first_name'];
						$newCommentDig[$count_commentdigs]->dig_user_id = $row['comment_dig_user_id'];
						$results[$lastPost]->comments[$lastComment]->digs = $newCommentDig;
						$lastdigcommentid = $digcommentid;
						$lastdigcommentidcomment = $commentid;
						$lastdigcommentidpost = $postid;
						$count_commentdigs++;
					}
				}



			}
			$count_posts++;

		}
	}

    echo json_encode(array_values($results ?? []));
?>
