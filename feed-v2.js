/**
 * Quiz-centric feed (v2). Loaded alongside main.js.
 * Rollback: set localStorage quizzical_feed_v2 to 0, or remove this script tag.
 */

function parseFeedItems(data) {
	if (data == null) return [];
	if (Array.isArray(data)) return data;
	if (typeof data === 'string') {
		try {
			return JSON.parse(data);
		} catch (e) {
			return [];
		}
	}
	return [];
}

function displayQuizFeed(resultsJson, preLoad, append) {
	if (!append) {
		$('#QuizFeed').html('');
	}

	var items = parseFeedItems(resultsJson);
	var myuserid = getSetting('user_id');

	items.forEach(function(item) {
		if (item.feed_type === 'quiz') {
			renderQuizCard(item, myuserid);
		} else {
			renderV2StandalonePost(item, myuserid);
		}
	});

	if (!append) {
		var composerHtml = '<div id="CommentComposer"><textarea id="NewCommentTextArea" placeholder="Post a comment to the group..."></textarea><div id="ComposerActions"><label id="ComposerAttachBtn">Attach<input type="file" multiple id="ComposerFileInput" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt" style="display:none"></label><button id="NewCommentSubmit" onclick="postComment()" style="display:none">Post</button></div><div id="ComposerAttachPreview" style="display:none"></div></div>';
		$('#QuizFeed').prepend(composerHtml);
	}

	applyFeedRankBadges();
}

function renderV2StandalonePost(result, myuserid) {
	renderClassicFeedPost(result, myuserid, $('#QuizFeed'), {
		postId: result.postid,
		userId: result.poster_id,
		picFilename: result.poster_filename,
		nameFirst: result.poster_first_name,
		nameLast: result.poster_last_name,
		ts: result.post_timestamp,
		comment: result.post_comment,
		attachments: result.attachments,
		comments: result.comments,
		digs: result.digs,
		specialty_emoji: result.specialty_emoji,
		specialty_category: result.specialty_category,
		score: null,
		max: null,
		itemClass: ''
	});
}

function renderClassicFeedPost(opts, myuserid, $container, fields) {
	var quizfeedId = fields.postId;
	var picFilename = fields.picFilename;
	var picSrc = (picFilename && picFilename !== 'null') ? picFilename + '?t=' + Date.now() : 'profileicon.png';
	var userId = fields.userId;
	var nameFirst = fields.nameFirst;
	var nameLast = fields.nameLast;
	var ts = fields.ts;
	var comment = fields.comment;
	var itemClass = fields.itemClass || '';

	$container.append('<div id="QuizFeedItem" class="' + itemClass + '" data-quizfeed="' + quizfeedId + '">');
	$('*[data-quizfeed="' + quizfeedId + '"]').html(
		'<div id="QuizFeedInfo" data-quizfeedinfo="' + quizfeedId + '" class="NoBubble">'
	);
	$('*[data-quizfeedinfo="' + quizfeedId + '"]').html(
		'<div id="QuizFeedInfoPhoto" data-userid="' + userId + '"><img src="' + picSrc + '" height="50" width="50" loading="lazy" onerror="this.onerror=null;this.src=\'profileicon.png\'"></div>'
	);
	$('*[data-quizfeedinfo="' + quizfeedId + '"]').append(
		'<div id="QuizFeedInfoText" data-quizfeedtext="' + quizfeedId + '" class="NoBubble">'
	);

	var ago = ts ? moment.tz(ts, getSetting('timezone')).fromNow() : '';
	var specialtyHtml = feedSpecialtyBadge(fields.specialty_emoji, fields.specialty_category, userId);

	$('*[data-quizfeedtext="' + quizfeedId + '"]').append(
		'<div id="QuizFeedInfoName" data-quizfeedname="' + quizfeedId + '">' + nameFirst + ' ' + nameLast + specialtyHtml
	);
	if (ago) {
		$('*[data-quizfeedname="' + quizfeedId + '"]').append(
			'<div id="QuizFeedInfoTimestamp" data-quizfeedts="' + quizfeedId + '">' + ago + ' '
		);
	}
	if (userId == myuserid) {
		$('*[data-quizfeedname="' + quizfeedId + '"]').append(
			'<a href="javascript:deletePost(' + quizfeedId + ')" class="Underline"> Delete </a>'
		);
	}
	if (fields.score != null && fields.max != null) {
		var scoreLine = 'Scored ' + fields.score + '/' + fields.max;
		if (fields.quizRank) {
			scoreLine = formatQuizRankLabel(fields.quizRank) + ' · ' + scoreLine;
		}
		var rankClass = fields.quizRank && fields.quizRank <= 3 ? ' QuizCard-scoreRank-' + fields.quizRank : '';
		$('*[data-quizfeedtext="' + quizfeedId + '"]').append(
			'<div id="QuizFeedInfoStatus" class="' + rankClass.trim() + '">' + scoreLine + '</div>'
		);
	}
	if (comment) {
		$('*[data-quizfeedtext="' + quizfeedId + '"]').append(
			'<div id="QuizFeedInfoComment" class="Primary">' + autoLinkUrls(comment) + '</div>'
		);
	}
	$('*[data-quizfeedtext="' + quizfeedId + '"]').append(renderAttachments(fields.attachments));
	$('*[data-quizfeedtext="' + quizfeedId + '"]').append(
		'<span id="QuizFeedInfoReplyLink" onclick="showReplyBox(' + quizfeedId + ');"> Comment </span>'
	);
	$('*[data-quizfeedtext="' + quizfeedId + '"]').append(
		' - <span id="QuizFeedInfoDigLink" data-dig="' + quizfeedId + '" onclick="digPost(' + quizfeedId + ');">Dig </span>'
	);
	if (userId == myuserid) {
		$('*[data-quizfeedtext="' + quizfeedId + '"]').append(
			' - <span id="QuizFeedInfoDelete" data-deleteid="' + quizfeedId + '" onclick="deletePost(' + quizfeedId + ');">Delete </span>'
		);
	}
	$('*[data-quizfeedtext="' + quizfeedId + '"]').append(renderReplyComposer(quizfeedId));

	if (fields.comments && fields.comments.length > 0) {
		var $commentsWrap = $('<div class="Comments"></div>');
		fields.comments.forEach(function(c) {
			$commentsWrap.append(renderCommentCard(c));
		});
		$('*[data-quizfeedtext="' + quizfeedId + '"]').append($commentsWrap);
		fields.comments.forEach(function(c) {
			applyCommentDigs(c, myuserid);
		});
	}

	v2ApplyPostDigs(quizfeedId, fields.digs, myuserid);
}

function v2ApplyPostDigs(quizfeedId, digs, myuserid) {
	if (!digs || digs.length === 0) return;
	var names = '';
	var context = 'digs';
	var digCount = 0;
	var mineFlag = 0;
	digs.forEach(function(dig) {
		var mine = dig.dig_user_id == myuserid ? 1 : 0;
		var handle = mine ? 'You ' : dig.dig_first_name;
		names += handle;
		if (digCount == digs.length - 2) names += ' and ';
		else if (digCount == digs.length - 1) names += '';
		else names += ', ';
		digCount++;
		if (mine) mineFlag = 1;
	});
	if (digCount > 1 || mineFlag > 0) context = 'dig';
	if (mineFlag) {
		$('*[data-dig="' + quizfeedId + '"]').replaceWith(
			'<span id="QuizFeedInfoDigLink" data-dig="' + quizfeedId + '" onclick="undigPost(' + quizfeedId + ');">Undig </span>'
		);
	}
	$('*[data-quizfeedtext="' + quizfeedId + '"]').append('<div id="Digs">' + names + ' ' + context + ' this</div>');
}

function formatQuizRankLabel(rank) {
	var n = parseInt(rank, 10);
	if (!n) return '';
	var mod100 = n % 100;
	var suffix = 'th';
	if (mod100 < 11 || mod100 > 13) {
		var mod10 = n % 10;
		if (mod10 === 1) suffix = 'st';
		else if (mod10 === 2) suffix = 'nd';
		else if (mod10 === 3) suffix = 'rd';
	}
	return n + suffix;
}

function formatPlayedCount(count) {
	var n = parseInt(count, 10) || 0;
	if (n === 0) return 'No scores yet';
	if (n === 1) return '1 played';
	return n + ' played';
}

function renderQuizCard(quiz, myuserid) {
	var cardKey = quiz.feed_key || ('quiz-' + (quiz.quiz_id || quiz.quiz_date));
	var $group = $('<div class="QuizCard-group" data-quiz-card="' + cardKey + '"></div>');
	$('#QuizFeed').append($group);

	var played = quiz.participation ? quiz.participation.done : 0;
	var ago = quiz.last_activity
		? moment.tz(quiz.last_activity, getSetting('old_timezone')).tz(getSetting('timezone')).fromNow()
		: '';
	var isQuizzical = /^Quizzical /i.test(quiz.quiz_type || '');
	var qType = quiz.quiz_type || '';
	var qDate = quiz.quiz_date || '';
	var qId = quiz.quiz_id || 0;

	var $header = $('<div id="QuizFeedItem" class="QuizCard-header"></div>');
	var $info = $('<div id="QuizFeedInfo" class="NoBubble"></div>');
	var $text = $('<div id="QuizFeedInfoText" class="NoBubble"></div>');
	$text.append('<div class="QuizCard-title">' + escapeHtml(quiz.title || 'Quiz') + '</div>');
	var metaParts = [formatPlayedCount(played)];
	if (ago) metaParts.push(ago);
	$text.append('<div class="QuizCard-meta">' + metaParts.join(' · ') + '</div>');
	if (isQuizzical) {
		var actionLabel = quiz.my_status
			? 'Review your score (' + quiz.my_status.score + '/' + quiz.my_status.max + ')'
			: 'Play this quiz';
		var $action = $('<a href="javascript:void(0)" class="QuizCard-headerLink">' + actionLabel + '</a>');
		$action.on('click', function() {
			openQuizFromFeedCard(qType, qDate, qId);
		});
		$text.append($action);
	}
	$info.append($text);
	$header.append($info);
	$group.append($header);

	if (!quiz.results || quiz.results.length === 0) {
		$group.append('<div class="QuizCard-emptyHint">No scores yet — be the first.</div>');
	} else {
		(quiz.results || []).forEach(function(r) {
			renderClassicFeedPost(null, myuserid, $group, {
				postId: r.post_id,
				userId: r.poster_id,
				picFilename: r.poster_filename,
				nameFirst: r.poster_first_name,
				nameLast: r.poster_last_name,
				ts: r.post_timestamp,
				comment: r.post_comment,
				attachments: r.attachments,
				comments: r.comments,
				digs: r.digs,
				specialty_emoji: r.specialty_emoji,
				specialty_category: r.specialty_category,
				score: r.score,
				max: r.max,
				quizRank: r.rank,
				itemClass: 'QuizCard-score'
			});
		});
	}

	renderQuizCardDiscussion(quiz, myuserid, $group);
}

function renderQuizCardDiscussion(quiz, myuserid, $group) {
	var shellId = quiz.quiz_post_id || 0;
	var $item = $('<div id="QuizFeedItem" class="QuizCard-discussion"></div>');
	var $info = $('<div id="QuizFeedInfo" class="NoBubble"></div>');
	$info.append('<div id="QuizFeedInfoPhoto" class="QuizCard-discussionSpacer" aria-hidden="true"></div>');
	var $text = $('<div id="QuizFeedInfoText" class="NoBubble QuizCard-discussionText"></div>');

	if (shellId > 0) {
		$text.attr('data-quizfeedtext', shellId);
	}

	$text.append('<div class="QuizCard-discussionLabel">Discuss this quiz</div>');

	if (quiz.quiz_comments && quiz.quiz_comments.length) {
		var $comments = $('<div class="Comments"></div>');
		quiz.quiz_comments.forEach(function(c) {
			$comments.append(renderCommentCard(c));
		});
		$text.append($comments);
	}

	if (shellId > 0) {
		$text.append(
			'<span id="QuizFeedInfoReplyLink" onclick="showReplyBox(' + shellId + ');"> Comment </span>'
			+ ' - <span id="QuizFeedInfoDigLink" data-dig="' + shellId + '" onclick="digPost(' + shellId + ');">Dig </span>'
		);
		$text.append(renderReplyComposer(shellId));
	} else {
		var qid = quiz.quiz_id || 0;
		$text.append(
			'<span id="QuizFeedInfoReplyLink" onclick="showQuizDiscussionComposer(' + qid + ');"> Comment </span>'
		);
		$text.append(
			'<div class="QuizCard-newDiscussion" data-new-discussion-quiz="' + qid + '" style="display:none">'
			+ '<textarea rows="2" class="QuizFeedInfoReplyInput" placeholder="Comment on this quiz..." '
			+ 'data-quiz-disc-input="' + qid + '"></textarea>'
			+ '<button type="button" class="reply-post-btn" onclick="sendQuizDiscussionComment(' + qid + ','
			+ JSON.stringify(quiz.quiz_type) + ',' + JSON.stringify(quiz.quiz_date) + ')">Post</button>'
			+ '</div>'
		);
	}

	$info.append($text);
	$item.append($info);
	$group.append($item);

	if (shellId > 0 && quiz.quiz_digs && quiz.quiz_digs.length) {
		v2ApplyPostDigs(shellId, quiz.quiz_digs, myuserid);
	}
	if (quiz.quiz_comments) {
		quiz.quiz_comments.forEach(function(c) {
			applyCommentDigs(c, myuserid);
		});
	}
}

function openQuizFromFeedCard(quizType, quizDate, quizId) {
	openQuizFromFeed(quizType, quizDate, quizId);
}

function showQuizDiscussionComposer(quizId) {
	$('.QuizCard-newDiscussion[data-new-discussion-quiz="' + quizId + '"]').show().find('textarea').focus();
}

function sendQuizDiscussionComment(quizId, quizType, quizDate) {
	var $input = $('textarea[data-quiz-disc-input="' + quizId + '"]');
	var comment = ($input.val() || '').trim();
	if (!comment) return;
	$input.prop('disabled', true);
	$.get('action-newquizcomment.php', {
		comment: comment,
		quiz_id: quizId || 0,
		type: quizType || '',
		date: quizDate || '',
		timezone: getSetting('timezone')
	}, function(raw) {
		var data = typeof raw === 'object' ? raw : JSON.parse(raw);
		if (data.error) {
			alert(data.error === 'migration_required'
				? 'Quiz discussion needs a one-time setup — open action-setup-feed-quiz.php while logged in.'
				: 'Could not post comment.');
			$input.prop('disabled', false);
			return;
		}
		downloadResults(1);
	}).fail(function() {
		alert('Could not post comment.');
		$input.prop('disabled', false);
	});
}
