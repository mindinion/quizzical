/**
 * Quiz-centric feed (v2). Loaded alongside main.js.
 * Rollback: set localStorage quizzical_feed_v2 to 0, or remove this script tag.
 */

function displayQuizFeed(resultsJson, preLoad, append) {
	if (!append) {
		$('#QuizFeed').html('');
	}

	var items = JSON.parse(resultsJson);
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
	var quizfeedId = result.postid;
	var picFilename = result.poster_filename;
	var picSrc = (picFilename && picFilename !== 'null') ? picFilename + '?t=' + Date.now() : 'profileicon.png';
	var nameFirst = result.poster_first_name;
	var nameLast = result.poster_last_name;
	var userId = result.poster_id;
	var ts = result.post_timestamp;
	var comment = result.post_comment;

	$('#QuizFeed').append('<div id="QuizFeedItem" data-quizfeed="' + quizfeedId + '">');
	$('*[data-quizfeed="' + quizfeedId + '"]').html(
		'<div id="QuizFeedInfo" data-quizfeedinfo="' + quizfeedId + '" class="NoBubble">'
	);
	$('*[data-quizfeedinfo="' + quizfeedId + '"]').html(
		'<div id="QuizFeedInfoPhoto" data-userid="' + userId + '"><img src="' + picSrc + '" height="50" width="50" loading="lazy" onerror="this.onerror=null;this.src=\'profileicon.png\'"></div>'
	);
	$('*[data-quizfeedinfo="' + quizfeedId + '"]').append(
		'<div id="QuizFeedInfoText" data-quizfeedtext="' + quizfeedId + '" class="NoBubble">'
	);

	var ago = moment.tz(ts, getSetting('timezone')).fromNow();
	var specialtyHtml = feedSpecialtyBadge(result.specialty_emoji, result.specialty_category, userId);

	$('*[data-quizfeedtext="' + quizfeedId + '"]').append(
		'<div id="QuizFeedInfoName" data-quizfeedname="' + quizfeedId + '">' + nameFirst + ' ' + nameLast + specialtyHtml
	);
	$('*[data-quizfeedname="' + quizfeedId + '"]').append(
		'<div id="QuizFeedInfoTimestamp" data-quizfeedts="' + quizfeedId + '">' + ago + ' '
	);
	if (userId == myuserid) {
		$('*[data-quizfeedname="' + quizfeedId + '"]').append(
			'<a href="javascript:deletePost(' + quizfeedId + ')" class="Underline"> Delete </a>'
		);
	}
	$('*[data-quizfeedtext="' + quizfeedId + '"]').append(
		'<div id="QuizFeedInfoComment" class="Primary">' + autoLinkUrls(comment || '') + '</div>'
	);
	$('*[data-quizfeedtext="' + quizfeedId + '"]').append(renderAttachments(result.attachments));
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

	if (result.comments && result.comments.length > 0) {
		var $commentsWrap = $('<div class="Comments"></div>');
		result.comments.forEach(function(c) {
			$commentsWrap.append(renderCommentCard(c));
		});
		$('*[data-quizfeedtext="' + quizfeedId + '"]').append($commentsWrap);
		result.comments.forEach(function(c) {
			applyCommentDigs(c, myuserid);
		});
	}

	v2ApplyPostDigs(quizfeedId, result.digs, myuserid);
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

function renderQuizCard(quiz, myuserid) {
	var cardKey = quiz.feed_key || ('quiz-' + (quiz.quiz_id || quiz.quiz_date));
	var $card = $('<div class="QuizFeedItem QuizCard" data-quiz-card="' + cardKey + '"></div>');

	var played = quiz.participation ? quiz.participation.done : 0;
	var total = quiz.participation ? quiz.participation.total : 0;
	var ctaHtml = '';
	if (quiz.my_status) {
		ctaHtml = '<button type="button" class="QuizCard-ctaBtn QuizCard-reviewBtn" onclick="openQuizFromFeedCard('
			+ JSON.stringify(quiz.quiz_type) + ',' + JSON.stringify(quiz.quiz_date) + ',' + (quiz.quiz_id || 0)
			+ ')">Review · ' + quiz.my_status.score + '/' + quiz.my_status.max + '</button>';
	} else {
		ctaHtml = '<button type="button" class="QuizCard-ctaBtn QuizCard-playBtn" onclick="openQuizFromFeedCard('
			+ JSON.stringify(quiz.quiz_type) + ',' + JSON.stringify(quiz.quiz_date) + ',' + (quiz.quiz_id || 0)
			+ ')">Play quiz</button>';
	}

	var ago = quiz.last_activity
		? moment.tz(quiz.last_activity, getSetting('old_timezone')).tz(getSetting('timezone')).fromNow()
		: '';

	var $head = $('<div class="QuizCard-head"></div>');
	$head.append('<div class="QuizCard-title">' + escapeHtml(quiz.title || 'Quiz') + '</div>');
	$head.append('<div class="QuizCard-meta">' + played + ' of ' + total + ' played · ' + ago + '</div>');
	$head.append(ctaHtml);
	$card.append($head);

	var $results = $('<div class="QuizCard-results"></div>');
	(quiz.results || []).forEach(function(r) {
		$results.append(buildQuizResultRow(r, myuserid));
	});
	if (!quiz.results || quiz.results.length === 0) {
		$results.append('<div class="QuizCard-emptyResults">No scores yet — be the first.</div>');
	}
	$card.append($results);

	var shellId = quiz.quiz_post_id || 0;
	var $disc = $('<div class="QuizCard-discussion"></div>');
	$disc.append('<div class="QuizCard-discussionTitle">Quiz discussion</div>');

	if (quiz.quiz_comments && quiz.quiz_comments.length) {
		var $comments = $('<div class="Comments QuizCard-discussionComments"></div>');
		quiz.quiz_comments.forEach(function(c) {
			$comments.append(renderCommentCard(c));
		});
		$disc.append($comments);
	}

	if (shellId > 0) {
		$disc.append(
			'<span class="QuizCard-discussionActions">'
			+ '<span class="QuizFeedInfoReplyLink" onclick="showReplyBox(' + shellId + ');">Comment</span>'
			+ ' - <span id="QuizFeedInfoDigLink" data-dig="' + shellId + '" onclick="digPost(' + shellId + ');">Dig</span>'
			+ '</span>'
		);
		$disc.append(renderReplyComposer(shellId));
		if (quiz.quiz_digs && quiz.quiz_digs.length) {
			v2ApplyPostDigs(shellId, quiz.quiz_digs, myuserid);
		}
	} else {
		var qid = quiz.quiz_id || 0;
		$disc.append(
			'<span class="QuizCard-discussionActions">'
			+ '<span class="QuizFeedInfoReplyLink" onclick="showQuizDiscussionComposer(' + qid + ');">Comment</span>'
			+ '</span>'
		);
		$disc.append(
			'<div class="QuizCard-newDiscussion" data-new-discussion-quiz="' + qid + '" style="display:none">'
			+ '<textarea rows="2" class="QuizFeedInfoReplyInput" placeholder="Comment on this quiz..." '
			+ 'data-quiz-disc-input="' + qid + '"></textarea>'
			+ '<button type="button" class="reply-post-btn" onclick="sendQuizDiscussionComment(' + qid + ','
			+ JSON.stringify(quiz.quiz_type) + ',' + JSON.stringify(quiz.quiz_date) + ')">Post</button>'
			+ '</div>'
		);
	}

	$card.append($disc);
	$('#QuizFeed').append($card);

	if (quiz.quiz_comments) {
		quiz.quiz_comments.forEach(function(c) {
			applyCommentDigs(c, myuserid);
		});
	}
}

function buildQuizResultRow(r, myuserid) {
	var picSrc = (r.poster_filename && r.poster_filename !== 'null')
		? r.poster_filename + '?t=' + Date.now() : 'profileicon.png';
	var isYou = r.poster_id == myuserid;
	var badge = r.rank === 1 ? 'gold' : (r.rank === 2 ? 'silver' : (r.rank === 3 ? 'bronze' : ''));
	var caption = r.post_comment ? escapeHtml(r.post_comment) : '';
	var digHint = r.digs && r.digs.length ? r.digs.length + ' dig' + (r.digs.length === 1 ? '' : 's') : '';

	var $row = $('<div class="QuizCard-resultRow' + (isYou ? ' QuizCard-resultRow-you' : '') + '" data-result-post="' + r.post_id + '"></div>');
	var $summary = $('<button type="button" class="QuizCard-resultSummary"></button>');
	$summary.append('<div class="QuizCard-rankAvatar rank-' + badge + '">' + r.rank + '</div>');
	$summary.append(
		'<div class="QuizCard-resultPhoto" data-userid="' + r.poster_id + '">'
		+ '<img src="' + picSrc + '" width="32" height="32" loading="lazy" onerror="this.onerror=null;this.src=\'profileicon.png\'">'
		+ '</div>'
	);
	var nameHtml = escapeHtml(r.poster_first_name + ' ' + r.poster_last_name);
	if (r.specialty_emoji && r.specialty_category) {
		nameHtml += feedSpecialtyBadge(r.specialty_emoji, r.specialty_category, r.poster_id);
	}
	$summary.append(
		'<div class="QuizCard-resultMain">'
		+ '<div class="QuizCard-resultTop"><span class="QuizCard-resultName">' + nameHtml + '</span>'
		+ '<span class="QuizCard-resultScore">' + r.score + '/' + r.max + '</span></div>'
		+ (caption ? '<div class="QuizCard-resultCaption">' + caption + '</div>' : '')
		+ (digHint ? '<div class="QuizCard-resultDigs">' + digHint + '</div>' : '')
		+ '</div><span class="QuizCard-resultChevron">&#9654;</span>'
	);
	$summary.on('click', function() {
		toggleQuizResultDetail(r, myuserid);
	});
	$row.append($summary);

	var $detail = $('<div class="QuizCard-resultDetail" data-result-detail="' + r.post_id + '" style="display:none"></div>');
	$row.append($detail);
	return $row;
}

function toggleQuizResultDetail(r, myuserid) {
	var postId = r.post_id;
	var $detail = $('.QuizCard-resultDetail[data-result-detail="' + postId + '"]');
	var $row = $('.QuizCard-resultRow[data-result-post="' + postId + '"]');
	if ($detail.is(':visible')) {
		$detail.slideUp(120).empty();
		$row.removeClass('QuizCard-resultRow-expanded');
		return;
	}
	$row.addClass('QuizCard-resultRow-expanded');
	$detail.html(buildResultDetailInner(r, myuserid)).slideDown(120);
	if (r.comments) {
		r.comments.forEach(function(c) {
			applyCommentDigs(c, myuserid);
		});
	}
	v2ApplyPostDigs(postId, r.digs, myuserid);
}

function buildResultDetailInner(r, myuserid) {
	var postId = r.post_id;
	var picSrc = (r.poster_filename && r.poster_filename !== 'null')
		? r.poster_filename + '?t=' + Date.now() : 'profileicon.png';
	var ago = r.post_timestamp
		? moment.tz(r.post_timestamp, getSetting('old_timezone')).tz(getSetting('timezone')).fromNow()
		: '';

	var html = '<div id="QuizFeedItem" class="QuizCard-resultDetailInner" data-quizfeed="' + postId + '">'
		+ '<div id="QuizFeedInfo" data-quizfeedinfo="' + postId + '" class="NoBubble">'
		+ '<div id="QuizFeedInfoPhoto" data-userid="' + r.poster_id + '">'
		+ '<img src="' + picSrc + '" height="50" width="50" onerror="this.onerror=null;this.src=\'profileicon.png\'">'
		+ '</div>'
		+ '<div id="QuizFeedInfoText" data-quizfeedtext="' + postId + '" class="NoBubble">'
		+ '<div id="QuizFeedInfoName" data-quizfeedname="' + postId + '">'
		+ escapeHtml(r.poster_first_name + ' ' + r.poster_last_name)
		+ feedSpecialtyBadge(r.specialty_emoji, r.specialty_category, r.poster_id)
		+ '<div id="QuizFeedInfoTimestamp" data-quizfeedts="' + postId + '">' + ago;

	if (r.poster_id == myuserid) {
		html += ' <a href="javascript:deletePost(' + postId + ')" class="Underline"> Delete </a>';
	}
	html += '</div>';

	if (r.post_comment) {
		html += '<div id="QuizFeedInfoComment" class="Primary">' + autoLinkUrls(r.post_comment) + '</div>';
	}
	html += renderAttachments(r.attachments);
	html += '<span id="QuizFeedInfoReplyLink" onclick="showReplyBox(' + postId + ');"> Comment </span>';
	html += ' - <span id="QuizFeedInfoDigLink" data-dig="' + postId + '" onclick="digPost(' + postId + ');">Dig </span>';
	if (r.poster_id == myuserid) {
		html += ' - <span id="QuizFeedInfoDelete" data-deleteid="' + postId + '" onclick="deletePost(' + postId + ');">Delete </span>';
	}
	html += renderReplyComposer(postId);

	if (r.comments && r.comments.length) {
		html += '<div class="Comments">';
		r.comments.forEach(function(c) {
			html += renderCommentCard(c);
		});
		html += '</div>';
	}

	html += '</div></div></div>';
	return html;
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
