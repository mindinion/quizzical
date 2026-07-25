
// Initialise the number of results in the quiz feed
document.cookie="feedItems=50";


// Retrieve a cookie	
	function getCookie(c_name) {
    	var i,x,y,ARRcookies=document.cookie.split(";");
    	for (i=0;i<ARRcookies.length;i++) {
    		x=ARRcookies[i].substr(0,ARRcookies[i].indexOf("="));
      		y=ARRcookies[i].substr(ARRcookies[i].indexOf("=")+1);
      		x=x.replace(/^\s+|\s+$/g,"");
      		if (x==c_name) {
       			return unescape(y);
      		}
     	}
    }

// Switch between weekly and monthly ranking
	function switchRanking(rType) {
		document.cookie = "rankingstype=" + rType;
		while ( getCookie('rankingstype') != rType) { }
		$('#UserRankings').load('div-userrankings.php');
		
	}
	
	function showGroups() {
		document.getElementById('Groups').style.display = 'block';
	}
		
	function showDate() {
		document.getElementById('dt').style.display = 'block';
		document.getElementById('ResultComment').style.top = "260px";
	}
	
	function hideDate() {
		document.getElementById('dt').style.display = 'none';
		document.getElementById('ResultComment').style.top = "230px";

	}
	
	// When clicking outside the change groups dialog, close it
	$(document).click(function(event) { 
		if(!$(event.target).closest('#Groups').length) {
			if($('#Groups').is(":visible")) {
				$('#Groups').hide()
			}
		}      
	})
	
	function deletePost(id) {
		$('*[data-quizfeedtext="' + id + '"]').append("<div id=CommentLoadingPost><img src='ajax-loader.gif'></img></div>");
		$.get("action-deletepost.php", {
			id:id,
			userid:getSetting("user_id")
		},
		function(data,status){
			if (status == "success") {
				downloadResults(1);
			} else {
				alert("Result Not Deleted");
			}
		});
	}

	function deleteComment(id) {
		$.get("action-deletecomment.php", {
			id: id,
			userid: getSetting("user_id")
		},
		function(_data, status) {
			if (status == "success") {
				downloadResults(1);
			}
		});
	}
	
	

	var feedOffset = 0;
	var feedLoading = false;
	var feedExhausted = false;

	function expandFeed() {
		var elem = document.getElementById("MainContent");
		if (!feedLoading && !feedExhausted && elem.scrollTop + elem.offsetHeight >= elem.scrollHeight - 200) {
			loadMoreResults();
		}
	}

	function loadMoreResults() {
		if (feedLoading || feedExhausted) return;
		feedLoading = true;
		var groupid = getSetting("group_id");
		$("#QuizFeed").append("<img src='ajax-loader.gif' data-loader=quizfeedmore class=Loader></img>");
		$.get("action-getresults.php",
			{ groupid: groupid, offset: feedOffset },
			function(data) {
				$('*[data-loader=quizfeedmore]').remove();
				var newResults = JSON.parse(data);
				feedLoading = false;
				if (newResults.length === 0) {
					feedExhausted = true;
					return;
				}
				feedOffset += newResults.length;
				displayResults(data, 0, true);
			}
		);
	}
	
	var mouse = {x: 0, y: 0};
	document.addEventListener('mousemove', function(e){ 
		mouse.x = e.clientX || e.pageX; 
		mouse.y = e.clientY || e.pageY 
	}, false);
	

	function disableSite() {
		var docHeight = $(document).height();
  		$("body").append("<div id='overlay'></div>");
		$("#overlay")
		  .height(docHeight)
		  .css({
			 'opacity' : 0.4,
			 'position': 'absolute',
			 'top': 0,
			 'left': 0,
			 'background-color': 'black',
			 'width': '100%',
			 'display' : 'block',
			 'z-index': 5000
		  });

	};
	
	function enableSite() {
		document.getElementById('overlay').style.display = 'none';
	}
	
	function profileShow() {
		$('#QuizFeed').load('div-profile.php');
	}
	
	
	function showReplyBox (id ) {
		var $textarea = $('*[data-replyboxid="' + id + '"]');
		var $attachBtn = $('*[data-replyattachid="' + id + '"]');
		var $postBtn = $('*[data-replypostbtnid="' + id + '"]');
		if ($textarea.css("display") == "none") {
			$textarea.css("display","block").focus();
			$attachBtn.show();
			$postBtn.show();
		} else {
			$textarea.css("display","none");
			$attachBtn.hide();
			$postBtn.hide();
		}
	}
	
	function sendComment (e,quizFeedId) {
		//console.log(e.keycode);
		if (e.keyCode != 13) return;
		comment = $('*[data-replyboxid="' + quizFeedId + '"]').val();
		$('*[data-replyboxid="' + quizFeedId + '"]').blur();
		console.log(quizFeedId);
		console.log(comment);
		var replyAttachs = window['replyAttachments_' + quizFeedId] || [];
		$('*[data-quizfeedtext="' + quizFeedId + '"]').append("<div id=CommentLoadingPost><img src='ajax-loader.gif'></img></div>");
		$.get("action-newcomment.php", {
				quizFeedId:quizFeedId,
				comment:comment,
				userid:getSetting("user_id")
			},
			function(data,status){
				console.log(data);
				if (status == "success") {
					var linkCalls = replyAttachs.map(function(ra) {
						return $.post("action-linkattachment.php", { attachment_id: ra.id, comment_id: data });
					});
					window['replyAttachments_' + quizFeedId] = [];
					clearReplyAttachment(quizFeedId);
					$.when.apply($, linkCalls).always(function() { downloadResults(1);
							});	
					
					// Email notifications 
					$.get("action-emailcomment.php", 
						{ 
							postId:quizFeedId,
							userid:getSetting("user_id")
						},
						function(response) {  }
					);
					
				} else {
					alert("Comment Not Added");
				}
			}
		);
	}
	
	function digPost(id) {
		$('*[data-quizfeedtext="' + id + '"]').append("<div id=CommentLoadingPost><img src='ajax-loader.gif'></img></div>");		
		$.get("action-newdig.php", 
			{ 
				postid:id,
				userid:getSetting("user_id")
			},
			function(data, status) { 
				downloadResults(1);
							 		
				$.get("action-emaildig.php", 
					{ id:data },
					function(data, status) {  
						console.log(data);
					}
				);
				
			}
		);
	}
	
	function undigPost(postid) {
		$('*[data-quizfeedtext="' + postid + '"]').append("<div id=CommentLoadingPost><img src='ajax-loader.gif'></img></div>");		
		$.get("action-undig.php", 
			{	postid:postid,
				userid:getCookie("userid")
			},
			function(response) { 
				downloadResults(1);
			}
		);
	}
	
	function undig(id) {
		disableSite();
		document.getElementById('SavingDig').style.display = 'block';
		$.get("action-undig.php", 
			{ 
				id:id,
				userid:getCookie("userid")
			},
			function(response) { 
				getResults();
				document.getElementById('SavingDig').style.display = 'none';
				enableSite();
			}
		);
	}
	
	function undigComment(commentid) {
		$('*[data-comment="' + commentid + '"]').append("<div id=CommentLoadingPost><img src='ajax-loader.gif'></img></div>");
		$.get("action-undig.php", 
			{ 
				commentid:commentid,
				userid:getCookie("userid")
			},
			function(response) { 
				downloadResults(1);
			}
		);
	}

	
	function digComment(id) {
		$('*[data-comment="' + id + '"]').append("<div id=CommentLoadingPost><img src='ajax-loader.gif'></img></div>");
		$.get("action-newdig.php", 
			{ 
				commentid:id,
				userid:getSetting("user_id")
			},
			function(data, status) { 
				console.log(data);
				downloadResults(1);
				$.get("action-emaildigcomment.php",
				{ 
					id:data,
					userid:getSetting("user_id")
				},
				function(response) {  }
				);
			}
		);
	}
	
	function postComment () {
		var commentText = $('#NewCommentTextArea').val();
		var pendingAttachs = window.composerAttachments || [];

		$.get("action-newcommentpost.php", {
				comment: commentText,
				userid:getSetting("user_id"),
				timezone:getSetting("timezone")
			},
			function(data,status){
				if (status == "success") {
					var postId = parseInt(data);
					var feedOpts = { isResult: false, comment: commentText };
					feedOpts.attachments = pendingAttachs.map(function(a) { return { file_type: a.file_type, filename: a.filename, original_name: a.original_name, attachid: a.id }; });
					pendingAttachs.forEach(function(a) {
						$.post("action-linkattachment.php", { attachment_id: a.id, post_id: postId });
					});
					prependFeedItem(postId, feedOpts);
					window.composerAttachments = [];
					clearComposerAttachment();

					//Email notifications
					$.get("action-emailpost.php",
						{ postId:data },
						function(data) { console.log(data);  }
					);

					$('#NewCommentTextArea').val('');
					$('#NewCommentSubmit').hide();

				} else {
					alert("Comment Not Added");
				}
			});
	}
	
	function addResult() {
		if ( document.getElementById("NewScoreDate").value == "Today" ) {
			$dateOption = "today";
		} else if ( document.getElementById("NewScoreDate").value == "Yesterday" ) {
			$dateOption =  "yesterday";
		} else {
			$dateOption =  "other";
		}		
		
		// SHow the loading image
		$("#QuizFeed").prepend("<div id=QuizfeedLoading><img src='ajax-loader.gif'></img></div>");
		
		$.get("action-newresult.php", {
			type:document.getElementById("NewScoreType").value,
			score:document.getElementById("NewResultScore").value,
			questions:document.getElementById("NewResultTotal").value,
			date:document.getElementById("NewScoreDate").value,
			dateOption:$dateOption,
			userid:getSetting("user_id"),
			timezone:getSetting("timezone")
			
		},
		function(data){
			if (data > 0) {
				downloadResults(1);
				rankingsLoaded = false;
				if ($('#RankingsPanel').is(':visible')) loadRankings(rankingsCurrentPeriod);
				document.getElementById("NewScoreType").value = 'Quizzical Morning';
				document.getElementById("NewResultScore").value = '';
				document.getElementById("NewResultTotal").value = '15';
				document.getElementById("NewScoreDate").value = 'Today';
				document.getElementById('NewScoreLabelType').style.display = 'none';
				document.getElementById('NewScoreType').style.display = 'none';
				document.getElementById('NewScoreLabelWhen').style.display = 'none';
				document.getElementById('NewScoreDate').style.display = 'none';
				document.getElementById('NewResultSubmit').style.display = 'none';
				document.getElementById('NewCommentTextArea').style.display = 'block';
				$.get("action-emailresult.php", 
					{ resultId:data },
					function(response) { console.log(response); }
				);
			} else {
				alert("You have already added a result for that quiz today!");
			}
		});
	}
	
	function profileHide() {
		getResults();
	}
	
	function saveProfile() {
		// First make sure the passwords match (if provided)
		var p1 = document.getElementById("PassA").value;
		var p2 = document.getElementById("PassB").value;
		if (p1 != null && p2 != null && p1 != p2) {
			alert ("Passwords do not match!");
			return false;
		}
		
		// Save the profiles
		document.getElementById('SavingNotifyProfile').style.display = 'block';
		disableSite();	
    	var url = "action-saveprofile.php"; // the script where you handle the form input.
		$.post(url, 
			{		
				newemail:document.getElementById("email").value,
				newfirstname:document.getElementById("namefirst").value,
				newlastname:document.getElementById("namelast").value,
				passwordA:document.getElementById("PassA").value,
				passwordB:document.getElementById("PassB").value,
				defaultgroup:document.getElementById("defaultgroup").value,
				notifyresults:document.getElementById("notifyresult").value,
				notifymessages:document.getElementById("notifymessage").value,
				timezone:document.getElementById("timezone").value,
				profilepic:document.getElementById("profilepic").value
			},
			function(data, status) {
				getResults();
				$('#UserRankings').load('div-userrankings.php');
				document.getElementById('SavingNotifyProfile').style.display = 'none';
				enableSite();
				alert(profilepic);
				alert (data);		
			}
		);
	}
	
	
		
	
	
	
	
	function downloadResults(dontPreload) {
		feedOffset = 0;
		feedExhausted = false;
		feedLoading = false;

		var groupid = getSetting("group_id");
		var results = sessionStorage.getItem("results");

		// Show the preloader
		$("#QuizFeed").append("<img src = 'ajax-loader.gif' data-loader=quizfeed class=Loader></img>");

		// First display the cached results, if we have them
		if (results != null && dontPreload == null) {
			displayResults(results,1);
		} else {
			var firstTime = 1;
		}

		// Now download and display the most recent results
		$.get("action-getresults.php",
			{ groupid: groupid, offset: 0 },
			function(data) {
				if (sessionStorage.getItem("results") != data || firstTime == 1 ) {
					displayResults(data,0);
				} else {
					resultsObj = JSON.parse(data);
					results =  $.map(resultsObj, function(el) { return el });
					loadBubbles(results);
				}
				$('*[data-loader=quizfeed]').remove();
				sessionStorage.results = data;
				feedOffset = JSON.parse(data).length;
			}
		);
	}
	
	
	
	function autoLinkUrls(text) {
		var escaped = $("<div>").text(text).html();
		return escaped.replace(/(https?:\/\/[^\s<>"]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
	}

	function renderAttachments(attachments) {
		if (!attachments || !attachments.length) return '';
		var html = '';
		attachments.forEach(function(a) {
			if (a.file_type === 'image') {
				html += '<div class="attachment-wrap"><img src="action-getattachment.php?id=' + a.attachid + '" class="attachment-thumb" onclick="openAttachModal(\'action-getattachment.php?id=' + a.attachid + '\')"></div>';
			} else {
				html += '<div class="attachment-wrap"><a href="action-getattachment.php?id=' + a.attachid + '" class="attachment-link">&#x1F4CE; ' + a.original_name + '</a></div>';
			}
		});
		return html;
	}

	function openAttachModal(src) {
		$('#AttachModalImg').attr('src', src);
		$('#AttachModal').fadeIn(150);
	}

	function closeAttachModal() {
		$('#AttachModal').fadeOut(150);
		$('#AttachModalImg').attr('src', '');
	}

	function displayResults(resultsJson, preLoad, append) {

		// Clear the feed unless we're appending more items
		if (!append) $("#QuizFeed").html("");
			
		resultsObj = JSON.parse(resultsJson);
		results =  $.map(resultsObj, function(el) { return el });
	
	
		var myuserid = getSetting("user_id");
		
		results.forEach(function(result) {
		
			// Grab the post data
			var quizfeedId = result.postid;
			var picFilename = result.poster_filename;
			var nameFirst = result.poster_first_name;
			var nameLast = result.poster_last_name;
			var userId = result.poster_id;
			var timestamp = result.post_timestamp;
			var ts = result.post_timestamp;
			var comment = result.post_comment;

			// Grab the result data (if applicable)
			if (result.result != null) {
				var resultId = result.result.resultid;
				var date = result.result.result_date;
				var score = result.result.result_score;
				var max = result.result.result_max;
				var type = result.result.result_type;
			}
	
			// Create the main quizitem DOM
    		$("#QuizFeed").append("<div id=QuizFeedItem data-quizfeed=" + quizfeedId + ">");
    				
			// Make room for the bubble if it is a result post
			var picSrc = (picFilename && picFilename !== 'null') ? picFilename + '?t=' + Date.now() : 'profileicon.png';
			var photoImg = "<img src='" + picSrc + "' height=50 width=50 loading='lazy' onerror=\"this.onerror=null;this.src='profileicon.png'\">";
			var photoHtml = "<div id=QuizFeedInfoPhoto data-userid=" + userId + ">" + photoImg;
			if (resultId == null) {
				$('*[data-quizfeed="' + quizfeedId + '"]').html("<div id=QuizFeedInfo data-quizfeedinfo=" + quizfeedId + " class=NoBubble>");
				$('*[data-quizfeedinfo="' + quizfeedId + '"]').html(photoHtml);
				$('*[data-quizfeedinfo="' + quizfeedId + '"]').append("<div id=QuizFeedInfoText class=nobubble data-quizfeedtext=" + quizfeedId + ">");

			} else {
				$('*[data-quizfeed="' + quizfeedId + '"]').html("<div id=QuizFeedInfo data-quizfeedinfo=" + quizfeedId + " >");
				$('*[data-quizfeedinfo="' + quizfeedId + '"]').html(photoHtml);
				$('*[data-quizfeedinfo="' + quizfeedId + '"]').append("<div id=QuizFeedInfoText data-quizfeedtext=" + quizfeedId + ">");
			}
		
			// Convert the timestamp into something intelligible
			var timestamp_local = moment.tz(ts, getSetting("timezone"));
			var timestamp_server = moment.tz(ts, getSetting("old_timezone"));
			var diff = 0 ;
			//var diff = -timestamp_local._offset ;
			//var diff = timestamp_local._offset - timestamp_server._offset;
			var newTimestamp = timestamp_local.add(diff, "minutes" );
			//var ago = moment(newTimestamp, "YYYY-MM-DD hh::mm:ss").fromNow();
			var ago = moment(newTimestamp, "YYYY-MM-DD hh::mm:ss").fromNow();
			
			//console.log(timestamp_local);
			//console.log(timestamp_local);
			//console.log(timestamp_server);
			//console.log(ts);


			// Create the different quizitem elements
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<div id=QuizFeedInfoName data-quizfeedname=" + quizfeedId + ">" + nameFirst + " " + nameLast );
			$('*[data-quizfeedname="' + quizfeedId + '"]').append("<div id=QuizFeedInfoTimestamp data-quizfeedts=" + quizfeedId + ">" + ago );
			if (resultId != null) $('*[data-quizfeedtext="' + quizfeedId + '"]').append("<div id=QuizFeedInfoStatus>Scored " + score + "/" + max + " in the " + Date.parse(date).toString("MMM dd") + " " + formatFeedQuizType(type) + " quiz </div id=QuizFeedInfoStatus>");
			$('*[data-quizfeedname="' + quizfeedId + '"]').append("<a href='javascript:deletePost(" + quizfeedId + ") class=Underline> Delete </a>" );
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<div id=QuizFeedInfoComment class=Primary>" + autoLinkUrls(comment || '') + "</div id=QuizFeedInfoComment>");    				
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append(renderAttachments(result.attachments));
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<span id=QuizFeedInfoReplyLink onclick='showReplyBox(" + quizfeedId + ");'> Comment </span>");    				
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("- <span id=QuizFeedInfoDigLink data-dig=" + quizfeedId + " onclick='digPost(" + quizfeedId + ");'>Dig </span>");    				
			
			if (userId == getSetting("user_id"))
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("- <span id=QuizFeedInfoDelete data-deleteid=" + quizfeedId + " onclick='deletePost(" + quizfeedId + ");'>Delete </span>");    				
			
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<textarea rows=3 class=QuizFeedInfoReplyInput onkeydown='sendComment(event," + quizfeedId + ")' data-replyboxid=" + quizfeedId + " style=display:none;></textarea>");    				
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<label class='reply-attach-btn' data-replyattachid=" + quizfeedId + " style='display:none'>Attach<input type='file' multiple class='reply-file-input' data-replyfileid=" + quizfeedId + " accept='image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt' style='display:none'></label>");
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<button class='reply-post-btn' data-replypostbtnid=" + quizfeedId + " onclick='sendComment({keyCode:13}," + quizfeedId + ")' style='display:none'>Post</button>");
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<div id='ReplyAttachPreview_" + quizfeedId + "' style='display:none'></div>");

			// Grab the comments data for the post and display them
			if (result.comments != null && result.comments.length > 0) {
				var comments = result.comments;
				comments.forEach(function(comment) {
					var commentid = comment.commentid;
					var text = comment.comment_comment;
					var name = comment.comment_first_name + " " + comment.comment_last_name;
					var commentUserId = comment.comment_user_id;
					var ts = comment.comment_timestamp;
					var commentAgo = ts ? moment.tz(ts, getSetting("old_timezone")).tz(getSetting("timezone")).fromNow() : '';
					var deleteLink = (commentUserId == getSetting("user_id")) ? " - <span class='QuizFeedInfoDelete' onclick='deleteComment(" + commentid + ");'>Delete</span>" : "";
					var commentAttachHtml = renderAttachments(comment.attachments);
					$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<div class=Comments><div class=CommentRow><div class=CommentName>" + name + "<div class='comment-meta'><span class='comment-timestamp'>" + commentAgo + "</span>" + deleteLink + "</div></div><div class='QuizFeedInfoComment OtherUser' data-comment=" + commentid + "><div class=CommentText>" + autoLinkUrls(text || '') + "</div>" + commentAttachHtml + "<div class=DigsComment data-commentid=" + commentid +"><span class='DigCommentLink' data-linkcommentid=" + commentid + " > </span></div></div></div>");
				
					// Grab the digs data for each comment and display them
					window.res = comment.digs;
					if (comment.digs != null && comment.digs.length > 0) {
						var digs = comment.digs;
						var names = "";
						var context = "digs";
						var digCount = 0;
						var mineFlag = 0;
						digs.forEach(function(dig) {
							if (dig.digstatus != "deleted" ) {
								mine = 0;
								digUserId = dig.dig_user_id;
								if (digUserId == myuserid) mine = 1;
								if (mine ) handle = "You "; 
									else handle = dig.dig_first_name;
								names += handle;
								if (digCount == (digs.length-2)) {				// If the current dig is the second last dig, write 'and'
									names += " and ";
								} else if (digCount == (digs.length-1)) { 		// If the current dig is the last dig, write ''
									names += "";
								} else {										// Otherwise, write ','
									names += ", ";
								}	
								digCount++;
								mineFlag = mine;
							}
						});
						if (digCount > 1 || mineFlag > 0) context = "dig";
				
						// Determine dig/undig, depending on whether a dig is theirs or not
						if (mine) {
							digLink = "<span id='DigCommentLink' data-linkcommentid=" + commentid + " onclick='undigComment(" + commentid + ");'> Undig</span>";
						} else {
							digLink = "<span id='DigCommentLink' data-linkcommentid=" + commentid + " onclick='digComment(" + commentid + "," + myuserid + ");'> Dig</span>";
						}
						
						// Show the digs, as well as the link to dig/undig
						$('*[data-comment="' + commentid + '"]').append("<div class=DigsComment>" + names + " "  + context + " this</div>");
								
						$('*[data-linkcommentid="' + commentid + '"]').html(digLink);
					} else {
						digLink = "<span id='DigCommentLink' data-linkcommentid=" + commentid + " onclick='digComment(" + commentid + "," + myuserid + ");'> Dig</span>";
						$('*[data-linkcommentid="' + commentid + '"]').html(digLink);
					}
							
				
				
				});
			}
			
			// Grab the digs data for the post and display them
			if (result.digs != null && result.digs.length > 0) {
				var digs = result.digs;
				var names = "";
				var context = "digs";
				var digCount = 0;
				var mineFlag = 0;
				var mine = 0;
				digs.forEach(function(dig) {
					mine = 0;
					digUserId = dig.dig_user_id;
					if (digUserId == myuserid) mine = 1;
					if (mine ) handle = "You "; 
						else handle = dig.dig_first_name;
					names += handle;					
					if (digCount == (digs.length-2)) {
						names += " and ";
					} else if (digCount == (digs.length-1)) {
						names += "";
					} else {
						names += ", ";
					}					
					
					digCount++;
					mineFlag = mine;
				});
				if (digCount > 1 || mineFlag > 0) context = "dig";
				
				// First add the 'undig' button
				if (mine) $('*[data-dig="' + quizfeedId + '"]').replaceWith("<span id=QuizFeedInfoDigLink data-dig=" + quizfeedId + " onclick='undigPost(" + quizfeedId + ");'>Undig </span>");

				// Display the dig(s)
				$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<div id=Digs>" + names + " "  + context + " this</div>");
			}

		});
		
		// Inject the comment composer at the top of the feed
		if (!append) {
			var composerHtml = '<div id="CommentComposer"><textarea id="NewCommentTextArea" placeholder="Post a comment to the group..."></textarea><div id="ComposerActions"><label id="ComposerAttachBtn">Attach<input type="file" multiple id="ComposerFileInput" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt" style="display:none"></label><button id="NewCommentSubmit" onclick="postComment()" style="display:none">Post</button></div><div id="ComposerAttachPreview" style="display:none"></div></div>';
			$('#QuizFeed').prepend(composerHtml);
		}
		applyFeedRankBadges();
		if (preLoad != 1) loadBubbles(results);

	}
	
	
	function prependFeedItem(postId, opts) {
		var myId = getSetting('user_id');
		var picFilename = getSetting('pic_filename');
		var picSrc = (picFilename && picFilename !== 'null') ? picFilename + '?t=' + Date.now() : 'profileicon.png';

		var $item = $('<div>').attr({ id: 'QuizFeedItem', 'data-quizfeed': postId });
		var $info = $('<div>').attr({ id: 'QuizFeedInfo', 'data-quizfeedinfo': postId });
		if (!opts.isResult) $info.addClass('NoBubble');

		var $photo = $('<div>').attr({ id: 'QuizFeedInfoPhoto', 'data-userid': myId });
		$photo.append($('<img>').attr({ src: picSrc, height: 50, width: 50 }).on('error', function() { this.src = 'profileicon.png'; }));
		$info.append($photo);

		var $text = $('<div>').attr({ id: 'QuizFeedInfoText', 'data-quizfeedtext': postId });
		if (!opts.isResult) $text.addClass('nobubble');

		var $name = $('<div>').attr({ id: 'QuizFeedInfoName', 'data-quizfeedname': postId });
		$name.append(document.createTextNode(getSetting('first_name') + ' ' + getSetting('last_name')));
		$name.append($('<div>').attr({ id: 'QuizFeedInfoTimestamp', 'data-quizfeedts': postId }).text('just now'));
		$name.append($('<a>').attr('href', 'javascript:deletePost(' + postId + ')').addClass('Underline').text(' Delete '));
		$text.append($name);

		if (opts.isResult) {
			var dateStr = Date.parse(opts.quizDate).toString('MMM dd');
			$text.append($('<div>').attr('id', 'QuizFeedInfoStatus').text('Scored ' + opts.score + '/' + opts.total + ' in the ' + dateStr + ' ' + formatFeedQuizType(opts.quizType) + ' quiz'));
		}

		var $commentDiv = $('<div>').attr({ id: 'QuizFeedInfoComment', 'class': 'Primary' }).html(autoLinkUrls(opts.comment || ''));
		$text.append($commentDiv);
		if (opts.attachments && opts.attachments.length) {
			$text.append($(renderAttachments(opts.attachments)));
		}
		$text.append($('<span>').attr('id', 'QuizFeedInfoReplyLink').on('click', function() { showReplyBox(postId); }).html('&nbsp;Comment&nbsp;'));
		$text.append(document.createTextNode('- '));
		$text.append($('<span>').attr({ id: 'QuizFeedInfoDigLink', 'data-dig': postId }).on('click', function() { digPost(postId); }).text('Dig '));
		$text.append(document.createTextNode('- '));
		$text.append($('<span>').attr({ id: 'QuizFeedInfoDelete', 'data-deleteid': postId }).on('click', function() { deletePost(postId); }).text('Delete '));
		$text.append($('<textarea>').attr({ rows: 3, 'class': 'QuizFeedInfoReplyInput', 'data-replyboxid': postId, style: 'display:none;' }).on('keydown', function(e) { sendComment(e, postId); }));
		var $replyAttachLabel = $('<label>').addClass('reply-attach-btn').attr({ 'data-replyattachid': postId, style: 'display:none' }).html('Attach');
		var $replyFileInput = $('<input>').attr({ type: 'file', multiple: true, 'class': 'reply-file-input', 'data-replyfileid': postId, accept: 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt', style: 'display:none' });
		$replyAttachLabel.append($replyFileInput);
		$text.append($replyAttachLabel);
		$text.append($('<button>').addClass('reply-post-btn').attr({ 'data-replypostbtnid': postId, style: 'display:none' }).text('Post').on('click', function() { sendComment({ keyCode: 13 }, postId); }));
		$text.append($('<div>').attr({ id: 'ReplyAttachPreview_' + postId, style: 'display:none' }));

		$info.append($text);
		$item.append($info);

		var $composer = $('#CommentComposer');
		if ($composer.length) {
			$composer.after($item);
		} else {
			$('#QuizFeed').prepend($item);
		}

		applyFeedRankBadges();
	}

	function loadBubbles(results) {
		window.res = results;
		
		results.forEach(function(result) {
			if (result.result != null && result.poster_id != getCookie("userid"))
				$('*[data-quizfeed="' + result.postid + '"]').append("<div id=QuizFeedBubble class=Loading><img src = 'ajax-loader.gif' data-loader=bubble data-postid=" + result.postid + " class=Loader></img></div>");
		});
		
		results.forEach(function(result) {
			if (result.result != null && result.poster_id != getCookie("userid")) {
				var quizType = result.result.result_type || '';
				var quizDate = (result.result.result_date || '').split(' ')[0];
				var isQuizzical = /^Quizzical /i.test(quizType);
				$.get("action-checkresult.php", 
					{ 
						date: quizDate,
						type: quizType,
						userid: getCookie("userid")
					},	
					function(data, status) { 
						var score = data.replace(/(\r\n|\n|\r)/gm, "");
						$('*[data-postid="' + result.postid + '"]').remove();
						var message;
						var extraClass = ' quiz-bubble-clickable';
						if (score != "failed") {
							message = "You scored " + score + "/" + result.result.result_max + " in this quiz.<br><span class=\"bubble-action\">Tap to review</span>";
						} else {
							message = "You haven't done this quiz yet.<br><span class=\"bubble-action\">Tap to play</span>";
						}
						var $bubble = $("<div id=QuizFeedBubble class=\"ScreenHide" + (isQuizzical ? extraClass : "") + "\">" + message + "</div>");
						if (isQuizzical) {
							$bubble.attr('data-quiz-type', quizType);
							$bubble.attr('data-quiz-date', quizDate);
							$bubble.on('click', function() {
								openQuizFromFeed($(this).data('quiz-type'), $(this).data('quiz-date'));
							});
						}
						$('*[data-quizfeed="' + result.postid + '"]').append($bubble);
					}
				);	
			}		
		});
	}

	function openQuizFromFeed(quizType, quizDate) {
		if (!quizType || !quizDate) return;
		var normalised = String(quizType).replace(/^Quizzical\s+/i, '');
		var match = quizList.find(function(q) {
			return q.type === normalised && q.date === quizDate;
		});
		if (match) {
			openAIQuiz(match, !!match.done);
			return;
		}
		$.get('action-resolve-ai-quiz.php', { type: quizType, date: quizDate }, function(raw) {
			var data = (typeof raw === 'object') ? raw : JSON.parse(raw);
			if (data.error) {
				alert('Quiz not available — it may be older than 7 days.');
				return;
			}
			var quiz = {
				quiz_id: data.quiz_id,
				type: data.type,
				date: data.date,
				done: !!data.done,
				score: data.score,
				max: data.max,
				source: 'ai'
			};
			openAIQuiz(quiz, quiz.done);
		});
	}
	
	
	function applyFeedRankBadges() {
		if (!Object.keys(feedTopRankers).length) return;
		$('#QuizFeed [data-userid]').each(function() {
			var uid = parseInt($(this).data('userid'));
			var rank = feedTopRankers[uid];
			if (!rank || $(this).find('.feed-rank-wrap').length) return;
			var rankClass = rank === 1 ? 'rank-gold' : rank === 2 ? 'rank-silver' : 'rank-bronze';
			var $img = $(this).find('img');
			$img.wrap('<div class="feed-rank-wrap ' + rankClass + '">');
			$img.after('<span class="feed-rank-badge">' + rank + '</span>');
		});
	}

	function fetchFeedRankers() {
		var groupid = getSetting('group_id');
		$.get('action-getrankings.php', { groupid: groupid, period: 'weekly', typefilter: 'quizzical' }, function(data) {
			feedTopRankers = {};
			JSON.parse(data).slice(0, 3).forEach(function(r, i) { feedTopRankers[r.userid] = i + 1; });
			applyFeedRankBadges();
		});
	}

	function getSettings() {
	sessionStorage.removeItem('usersettings');
	$.get("action-getsettings.php",
		{
			userid:getCookie("userid")
		},

		function(data, status) {
			sessionStorage.usersettings = data;
			fetchFeedRankers();
			downloadResults();
			loadQuizList();
			if (getSetting("superuser") == 1) $('#TestQuizButton').show();
		});
	}
	
	function getSetting(type) {
		obj = JSON.parse(sessionStorage.usersettings);
		return eval ( "obj." + type ) ;
	}
	
	var rankingsCurrentPeriod = 'weekly';
	var rankingsLoaded = false;
	var currentPbs = [];
	var feedTopRankers = {};
	var rankingsTypeFilter = 'quizzical';
	var quizList = [];
	var selectedQuiz = null;

	function switchTab(tab) {
		if (tab === 'feed') {
			$('#QuizFeed').show();
			$('#RankingsPanel').hide();
			$('#TabFeed').addClass('tab-active');
			$('#TabRankings').removeClass('tab-active');
		} else {
			$('#QuizFeed').hide();
			$('#RankingsPanel').show();
			$('#TabFeed').removeClass('tab-active');
			$('#TabRankings').addClass('tab-active');
			if (!rankingsLoaded) {
				loadRankings(rankingsCurrentPeriod);
			}
		}
	}

	function switchPeriod(period) {
		rankingsCurrentPeriod = period;
		rankingsLoaded = false;
		$('.period-tab').removeClass('period-active');
		$('#Period-' + period).addClass('period-active');
		loadRankings(period);
	}

	function switchTypeFilter(filter) {
		rankingsTypeFilter = filter;
		rankingsLoaded = false;
		$('.type-tab').removeClass('type-active');
		$('#TypeTab-' + filter).addClass('type-active');
		loadRankings(rankingsCurrentPeriod);
	}

	function toggleTypeFilter() {
		var order = ['quizzical', 'stuff', 'all'];
		var idx = order.indexOf(rankingsTypeFilter);
		switchTypeFilter(order[(idx + 1) % order.length]);
	}

	function loadRankings(period) {
		var groupid = getSetting("group_id");
		var myUserid = parseInt(getCookie("userid"));
		$('#RankingsMain').html("<img src='ajax-loader.gif' class='Loader'>");
		$('#RankingsMostImproved').html('');
		$('#RankingsPersonalBests').html('');
		$.when(
			$.get("action-getrankings.php", { groupid: groupid, period: period, typefilter: rankingsTypeFilter }),
			$.get("action-getstreaks.php", { groupid: groupid, typefilter: rankingsTypeFilter }),
			$.get("action-getpersonalbests.php", { groupid: groupid, period: period, typefilter: rankingsTypeFilter })
		).done(function(rankingsResp, streaksResp, pbResp) {
			var rankings = JSON.parse(rankingsResp[0]);
			var streaks  = JSON.parse(streaksResp[0]);
			var pbs      = JSON.parse(pbResp[0]);
			rankingsLoaded = true;
			renderRankings(rankings, streaks, pbs, period, myUserid);
		});
	}

	function renderRankings(rankings, streaks, pbs, period, myUserid) {
		currentPbs = pbs;
		var streakMap = {};
		streaks.forEach(function(s) { streakMap[s.userid] = s.streak; });

		// Main Rankings Table
		var html = '<div class="rankings-table">';
		html += '<div class="rankings-header-row">';
		html += '<span class="rh-place">#</span>';
		html += '<span class="rh-name">Name</span>';
		html += '<span class="rh-avg">Avg</span>';
		if (period !== 'alltime') html += '<span class="rh-trend">Trend <span class="info-icon" data-tip="Change in average % vs the previous ' + (period === 'weekly' ? 'week' : period === 'yearly' ? 'year' : 'month') + '">i</span></span>';
		if (period !== 'alltime') html += '<span class="rh-part">Days <span class="info-icon" data-tip="Days you posted a result in this period">i</span></span>';
		html += '</div>';

		// For alltime: split into active (last result within 1 year) and ghosts
		var renderRows = rankings;
		var ghostRows = [];
		if (period === 'alltime') {
			var oneYearAgo = new Date();
			oneYearAgo.setFullYear(oneYearAgo.getFullYear() - 1);
			renderRows = rankings.filter(function(r) {
				return r.last_result_date && new Date(r.last_result_date) >= oneYearAgo;
			});
			ghostRows = rankings.filter(function(r) {
				return !r.last_result_date || new Date(r.last_result_date) < oneYearAgo;
			});
		}

		function buildRankingRow(r, idx) {
			var place = idx + 1;
			var placeSuffix = place === 1 ? 'st' : place === 2 ? 'nd' : place === 3 ? 'rd' : 'th';
			var placeLabel  = place + placeSuffix;
			var streak      = streakMap[r.userid] || 0;
			var streakBadge = streak >= 3 ? '<span class="streak-badge" data-tip="' + streak + '-day streak — results posted on ' + streak + ' consecutive days">&#x1F525; ' + streak + '</span>' : '';
			var rowClass    = 'rankings-row' + (r.userid === myUserid ? ' rankings-row-me' : '');

			var trendHtml = '';
			if (period !== 'alltime') {
				if (r.prev_avg_pct !== null) {
					var diff = r.avg_pct - r.prev_avg_pct;
					if (diff > 0)       trendHtml = '<span class="trend-up">&#x25B2; +' + diff + '%</span>';
					else if (diff < 0)  trendHtml = '<span class="trend-down">&#x25BC; -' + Math.abs(diff) + '%</span>';
					else                trendHtml = '<span class="trend-neutral">&#x2013;</span>';
				} else {
					trendHtml = '<span class="trend-new">new</span>';
				}
			}

			var partHtml = period !== 'alltime'
				? '<span class="participation">' + r.days_active + '/' + r.period_days + '</span>'
				: '';

			var s = '<div class="' + rowClass + '" data-userid="' + r.userid + '" data-expandtype="rankings" data-firstname="' + r.first_name + '">';
			s += '<span class="r-place">' + placeLabel + '</span>';
			s += '<span class="r-photo-name">';
			s += '<img src="' + (r.pic_filename || 'profileicon.png') + '?t=' + Date.now() + '" class="r-photo" loading="lazy" onerror="this.onerror=null;this.src=\'profileicon.png\'">';
			s += '<span class="r-name">' + r.first_name + ' ' + r.last_name + streakBadge + '</span>';
			s += '</span>';
			s += '<span class="r-avg">' + r.avg_pct + '%</span>';
			if (period !== 'alltime') s += '<span class="r-trend">' + trendHtml + '</span>';
			if (period !== 'alltime') s += '<span class="r-part">' + partHtml + '</span>';
			s += '</div>';
			return s;
		}

		renderRows.forEach(function(r, idx) { html += buildRankingRow(r, idx); });

		html += '</div>';

		if (ghostRows.length > 0) {
			html += '<div class="ghosts-toggle" data-target="GhostsSection" data-count="' + ghostRows.length + '">&#x25BC; Ghosts (' + ghostRows.length + ')</div>';
			html += '<div id="GhostsSection" style="display:none"><div class="rankings-table">';
			ghostRows.forEach(function(r, idx) { html += buildRankingRow(r, idx); });
			html += '</div></div>';
		}

		$('#RankingsMain').html(html);

		// Most Improved (weekly / monthly only)
		if (period !== 'alltime') {
			var improved = rankings
				.filter(function(r) { return r.prev_avg_pct !== null && r.avg_pct > r.prev_avg_pct; })
				.map(function(r)    { return { r: r, diff: r.avg_pct - r.prev_avg_pct }; })
				.sort(function(a, b){ return b.diff - a.diff; })
				.slice(0, 3);

			if (improved.length > 0) {
				var miHtml = '<div class="rankings-section-title">Most Improved <span class="info-icon" data-tip="Players whose average % improved the most vs the prior period">i</span></div>';
				miHtml += '<div class="most-improved-list">';
				improved.forEach(function(item) {
					miHtml += '<div class="mi-row" data-userid="' + item.r.userid + '" data-expandtype="mi" data-firstname="' + item.r.first_name + '">';
					miHtml += '<img src="' + (item.r.pic_filename || 'profileicon.png') + '?t=' + Date.now() + '" class="r-photo" loading="lazy" onerror="this.onerror=null;this.src=\'profileicon.png\'">';
					miHtml += '<span class="mi-name">' + item.r.first_name + ' ' + item.r.last_name + '</span>';
					miHtml += '<span class="mi-arrow trend-up">&#x25B2; +' + item.diff + '%</span>';
					miHtml += '</div>';
				});
				miHtml += '</div>';
				$('#RankingsMostImproved').html(miHtml);
			}
		}

		// Personal Bests: each user's highest single score, top 5 group-wide
		var userBests = {};
		pbs.forEach(function(p) {
			if (!userBests[p.userid] || p.best_pct > userBests[p.userid].best_pct) {
				userBests[p.userid] = p;
			}
		});

		var ghostUserIds = {};
		ghostRows.forEach(function(r) { ghostUserIds[r.userid] = true; });

		var allBestsList = Object.keys(userBests)
			.map(function(uid) { return userBests[uid]; })
			.sort(function(a, b) { return b.best_pct - a.best_pct; });

		var activeBestsList = allBestsList.filter(function(p) { return !ghostUserIds[p.userid]; }).slice(0, 5);
		var ghostBestsList  = allBestsList.filter(function(p) { return  ghostUserIds[p.userid]; }).slice(0, 5);

		function buildPbRow(p) {
			var s = '<div class="pb-row" data-userid="' + p.userid + '" data-expandtype="pb" data-firstname="' + p.first_name + '" data-pb-type="' + p.type + '" data-pb-date="' + p.date + '" data-pb-score="' + p.score + '" data-pb-max="' + p.max + '" data-pb-pct="' + p.best_pct + '">';
			s += '<span class="pb-name">' + p.first_name + ' ' + p.last_name + '</span>';
			s += '<span class="pb-type">' + p.type + '</span>';
			s += '<span class="pb-date">' + formatResultDate(p.date, period) + '</span>';
			s += '<span class="pb-score">' + p.best_pct + '%</span>';
			s += '</div>';
			return s;
		}

		if (activeBestsList.length > 0 || ghostBestsList.length > 0) {
			var pbHtml = '<div class="rankings-section-title">Personal Bests <span class="info-icon" data-tip="The highest single score ever recorded by each player in this group">i</span></div>';
			pbHtml += '<div class="pb-list">';
			activeBestsList.forEach(function(p) { pbHtml += buildPbRow(p); });
			pbHtml += '</div>';

			if (ghostBestsList.length > 0) {
				pbHtml += '<div class="ghosts-toggle" data-target="GhostsSectionPB" data-count="' + ghostBestsList.length + '">&#x25BC; Ghosts (' + ghostBestsList.length + ')</div>';
				pbHtml += '<div id="GhostsSectionPB" style="display:none"><div class="pb-list">';
				ghostBestsList.forEach(function(p) { pbHtml += buildPbRow(p); });
				pbHtml += '</div></div>';
			}

			$('#RankingsPersonalBests').html(pbHtml);
		}
	}


	function toggleDetail($row, userid, expandtype, period) {
		var detailId = 'detail-' + expandtype + '-' + userid;
		var $existing = $('#' + detailId);
		if ($existing.length) {
			$existing.slideToggle(150);
			$row.toggleClass('row-expanded');
			return;
		}
		var $detail = $('<div class="detail-panel" id="' + detailId + '"></div>');
		$detail.html('<div class="detail-loading"><img src="ajax-loader.gif" class="Loader"></div>');
		$row.after($detail);
		$row.addClass('row-expanded');
		$.get('action-getuserresults.php',
			{ groupid: getSetting('group_id'), userid: userid, period: period, typefilter: rankingsTypeFilter },
			function(data) {
				$detail.html(renderResultsDetail(JSON.parse(data), period));
			}
		);
	}

	function formatResultDate(dateStr, period) {
		var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
		var parts = dateStr.split('-');
		var s = parseInt(parts[2]) + ' ' + months[parseInt(parts[1]) - 1];
		if (period === 'alltime' || period === 'yearly') s += ' ' + parts[0];
		return s;
	}

	function renderResultsDetail(results, period) {
		if (!results || results.length === 0) return '<div class="detail-empty">No results for this period</div>';
		var html = '<table class="detail-table">';
		html += '<tr><th>Date</th><th>Type</th><th>Score</th><th>%</th></tr>';
		results.forEach(function(r) {
			var dateStr = formatResultDate(r.date, period);
			html += '<tr>';
			html += '<td>' + dateStr + '</td>';
			html += '<td>' + r.type + '</td>';
			html += '<td>' + r.score + '/' + r.max + '</td>';
			html += '<td class="detail-pct">' + r.pct + '%</td>';
			html += '</tr>';
		});
		html += '</table>';
		return html;
	}

	function renderPbDetail(userPbs, period) {
		if (!userPbs || userPbs.length === 0) return '<div class="detail-empty">No results found</div>';
		var html = '<table class="detail-table">';
		html += '<tr><th>Type</th><th>Date</th><th>Score</th><th>%</th></tr>';
		userPbs.forEach(function(p) {
			var dateStr = formatResultDate(p.date, period);
			html += '<tr>';
			html += '<td>' + p.type + '</td>';
			html += '<td>' + dateStr + '</td>';
			html += '<td>' + p.score + '/' + p.max + '</td>';
			html += '<td class="detail-pct">' + p.best_pct + '%</td>';
			html += '</tr>';
		});
		html += '</table>';
		return html;
	}

	function togglePbDetail($row, userid) {
		var detailId = 'detail-pb-' + userid;
		var $existing = $('#' + detailId);
		if ($existing.length) {
			$existing.slideToggle(150);
			$row.toggleClass('row-expanded');
			return;
		}
		var type    = $row.data('pb-type');
		var date    = $row.data('pb-date');
		var score   = $row.data('pb-score');
		var max     = $row.data('pb-max');
		var pct     = $row.data('pb-pct');
		var dateStr = formatResultDate(date, rankingsCurrentPeriod);
		var html = '<table class="detail-table">';
		html += '<tr><th>Type</th><th>Date</th><th>Score</th><th>%</th></tr>';
		html += '<tr><td>' + type + '</td><td>' + dateStr + '</td><td>' + score + '/' + max + '</td><td class="detail-pct">' + pct + '%</td></tr>';
		html += '</table>';
		var $detail = $('<div class="detail-panel" id="' + detailId + '"></div>');
		$detail.html(html);
		$row.after($detail);
		$row.addClass('row-expanded');
	}


	function formatFeedQuizType(quizType) {
		if (!quizType) return '';
		return quizType.replace(/^Quizzical /, '');
	}

	function getNextQuizMessage() {
		if (typeof moment === 'undefined' || !moment.tz) {
			return 'No quizzes available yet — check back at 9am or 3pm NZT.';
		}
		var now = moment.tz('Pacific/Auckland');
		var hour = now.hour();
		if (hour < 9) return 'No quizzes yet — today\u2019s morning quiz generates at 9am NZT.';
		if (hour < 15) return 'No quizzes yet — today\u2019s afternoon quiz generates at 3pm NZT.';
		return 'No quizzes yet — tomorrow\u2019s morning quiz generates at 9am NZT.';
	}

	function findQuizToTake() {
		var today = (typeof moment !== 'undefined' && moment.tz)
			? moment.tz('Pacific/Auckland').format('YYYY-MM-DD')
			: null;
		if (today) {
			var todayUndone = quizList.find(function(q) { return q.date === today && !q.done; });
			if (todayUndone) return todayUndone;
		}
		return quizList.find(function(q) { return !q.done; }) || null;
	}

	function startTodaysQuiz() {
		var quiz = findQuizToTake();
		if (!quiz) return;
		selectedQuiz = quiz;
		openAIQuiz(quiz, false);
	}

	function loadQuizList() {
		$.get('action-getquizzes.php', function(data) {
			quizList = JSON.parse(data);
			renderQuizDropdown();
		});
	}

	function renderQuizDropdown() {
		var $sel = $('#QuizDropdown');
		var $take = $('#TakeQuizButton');
		var $empty = $('#QuizEmptyMessage');
		$sel.empty();

		if (!quizList.length) {
			$sel.hide();
			$take.hide();
			$empty.text(getNextQuizMessage()).show();
			return;
		}

		$sel.show();
		$empty.hide();
		$('<option>').val('').text('Select a quiz...').prop('disabled', true).prop('selected', true).attr('hidden', true).appendTo($sel);
		quizList.forEach(function(q, i) {
			var label = q.type + ' \u2013 ' + formatQuizDate(q.date);
			if (q.done) label += ' \u2713 ' + q.score + '/' + q.max + ' (review)';
			$('<option>').val(i).text(label).appendTo($sel);
		});

		var toTake = findQuizToTake();
		if (toTake) {
			$take.show();
		} else {
			$take.hide();
		}
	}

	function formatQuizDate(dateStr) {
		var parts = dateStr.split('-');
		var d = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
		var today = new Date(); today.setHours(0, 0, 0, 0);
		var yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
		if (d.getTime() === today.getTime()) return 'Today';
		if (d.getTime() === yesterday.getTime()) return 'Yesterday';
		var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
		var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
		return days[d.getDay()] + ' ' + d.getDate() + ' ' + months[d.getMonth()];
	}


	function openQuizCard() {
		var idx = $('#QuizDropdown').val();
		if (idx === '' || idx === null) return;
		selectedQuiz = quizList[parseInt(idx)];
		if (!selectedQuiz) return;
		openAIQuiz(selectedQuiz, selectedQuiz.done);
	}

	// ── AI Quiz Wizard ──────────────────────────────────────────────────────

	var aiQuizData       = null;
	var aiCurrentIdx     = 0;
	var aiScore          = 0;
	var aiAnswerPending  = false;
	var aiReviewMode     = false;

	function openAIQuiz(quiz, reviewMode) {
		$.get('action-get-ai-quiz.php', { quiz_id: quiz.quiz_id }, function(raw) {
			aiQuizData      = (typeof raw === 'object') ? raw : JSON.parse(raw);
			aiReviewMode    = reviewMode || aiQuizData.review_mode;
			aiScore         = aiReviewMode && aiQuizData.final_score != null
				? aiQuizData.final_score
				: (aiQuizData.score_so_far || 0);
			aiAnswerPending = false;

			var labelPrefix = aiReviewMode ? 'Review: ' : '';
			var label = labelPrefix + aiQuizData.type + ' \u2013 ' + formatQuizDate(aiQuizData.date);
			$('#AIQuizTitle').text(label);
			$('#AIQuizBody').show();
			$('#AIQuizScoreScreen').hide();
			$('#AIQuizNext').hide().text('Next \u2192');
			$('#AIQuizModal').show();
			$('#TabBar').hide();

			if (aiReviewMode) {
				aiCurrentIdx = 0;
				renderAIQuestion(0);
			} else {
				aiCurrentIdx = aiQuizData.answered_count || 0;
				if (aiCurrentIdx >= 15) {
					showAIScoreScreen();
				} else {
					renderAIQuestion(aiCurrentIdx);
				}
			}
		});
	}

	function renderAIQuestion(idx) {
		var q = aiQuizData.questions[idx];
		var total = aiQuizData.questions.length;

		$('#AIQuizProgress').text((idx + 1) + ' / ' + total);
		$('#AIQuizProgressFill').css('width', ((idx / total) * 100) + '%');
		$('#AIQuizCategory').text(q.category);
		if (q.image_url) {
			$('#AIQuizImage').html(
				'<img src="' + escapeHtml(q.image_url) + '" alt="" loading="lazy" onerror="this.parentNode.style.display=\'none\'">'
				+ (q.image_attribution ? '<div class="ai-quiz-image-credit">' + escapeHtml(q.image_attribution) + '</div>' : '')
			).show();
		} else {
			$('#AIQuizImage').empty().hide();
		}
		$('#AIQuizQuestion').text(q.question_text);
		$('#AIQuizFeedback').hide().removeClass('feedback-correct feedback-wrong').text('');
		$('#AIQuizNext').hide();

		var html = '';
		q.options.forEach(function(opt) {
			html += '<div class="ai-option" data-optid="' + opt.id + '">' + escapeHtml(opt.text) + '</div>';
		});
		$('#AIQuizOptions').html(html);

		// If already answered (resume or review), show the reveal immediately
		if (q.answered) {
			revealAnswer(q.chosen_option_id, q.correct_option_id, q.is_correct, true, q.option_stats, q.total_answers);
			if (aiReviewMode) {
				$('#AIQuizNext').show().text(aiCurrentIdx >= total - 1 ? 'See score \u2192' : 'Next \u2192');
			} else if (aiCurrentIdx >= total - 1) {
				showAIScoreScreen();
			} else {
				$('#AIQuizNext').show().text('Next \u2192');
			}
		} else if (!aiReviewMode) {
			$('#AIQuizOptions').on('click', '.ai-option', function() {
				if (aiAnswerPending) return;
				aiAnswerPending = true;
				var optId = parseInt($(this).data('optid'));
				submitAIAnswer(q.id, optId);
			});
		}
	}

	function submitAIAnswer(questionId, chosenOptionId) {
		$.get('action-submit-ai-answer.php', {
			quiz_id:          aiQuizData.quiz_id,
			question_id:      questionId,
			chosen_option_id: chosenOptionId
		}, function(raw) {
			var resp = (typeof raw === 'object') ? raw : JSON.parse(raw);
			console.log('submitAIAnswer resp:', resp);
			if (resp.correct) aiScore++;
			revealAnswer(chosenOptionId, resp.correct_option_id, resp.correct, false, resp.option_stats, resp.total_answers);

			if (resp.completed) {
				setTimeout(showAIScoreScreen, 1200);
			} else {
				$('#AIQuizNext').show().text('Next \u2192');
			}
		});
	}

	function revealAnswer(chosenId, correctId, wasCorrect, isResume, optionStats, totalAnswers) {
		$('#AIQuizOptions').off('click', '.ai-option');
		var statsByOpt = {};
		if (optionStats && optionStats.length) {
			optionStats.forEach(function(s) { statsByOpt[s.option_id] = s.pct; });
		}
		var showStats = totalAnswers >= 3;
		$('#AIQuizOptions .ai-option').each(function() {
			var optId = parseInt($(this).data('optid'));
			if (optId === correctId) {
				$(this).addClass('ai-option-correct');
			} else if (optId === chosenId && !wasCorrect) {
				$(this).addClass('ai-option-wrong');
			} else {
				$(this).addClass('ai-option-neutral');
			}
			if (showStats && statsByOpt[optId] !== undefined) {
				var $stat = $(this).find('.ai-option-stat');
				if (!$stat.length) {
					$stat = $('<span class="ai-option-stat"></span>');
					$(this).append($stat);
				}
				$stat.text(statsByOpt[optId] + '% picked');
			}
		});

		if (!isResume) {
			var $fb = $('#AIQuizFeedback');
			if (wasCorrect) {
				$fb.text('Correct!').addClass('feedback-correct').show();
			} else {
				$fb.text('Not quite.').addClass('feedback-wrong').show();
			}
		}

		aiAnswerPending = false;
	}

	function nextAIQuestion() {
		if (aiReviewMode) {
			if (aiCurrentIdx >= aiQuizData.questions.length - 1) {
				showAIScoreScreen();
				return;
			}
			aiCurrentIdx++;
			renderAIQuestion(aiCurrentIdx);
			return;
		}

		aiCurrentIdx++;
		aiQuizData.questions[aiCurrentIdx - 1].answered = true;
		renderAIQuestion(aiCurrentIdx);
	}

	function showAIScoreScreen() {
		$('#AIQuizBody').hide();
		var maxScore = aiQuizData.final_max || 15;
		var pct = Math.round((aiScore / maxScore) * 100);
		$('#AIQuizScoreHeading').text(aiReviewMode ? 'Your score' : 'Quiz complete!');
		$('#AIQuizScoreDisplay').text(aiScore + ' / ' + maxScore);
		$('#AIQuizComment').val('').toggle(!aiReviewMode);
		$('#AIQuizPostBtn').prop('disabled', false).text('Post to Feed').toggle(!aiReviewMode);
		$('#AIQuizSkipPost').text('Close').show();
		$('#AIQuizScoreScreen').show();
		$('#AIQuizProgressFill').css('width', '100%');
		setTimeout(function() {
			$('#AIQuizScoreFill').css('width', pct + '%');
		}, 100);
	}

	function postAIResult() {
		var comment  = $('#AIQuizComment').val().trim();
		var quizType = 'Quizzical ' + aiQuizData.type;
		$('#AIQuizPostBtn').prop('disabled', true).text('Posting…');
		$.get('action-newresult.php', {
			type:       quizType,
			score:      aiScore,
			questions:  15,
			dateOption: 'today',
			comment:    comment
		}, function(raw) {
			var resp = null;
			try { resp = JSON.parse(raw); } catch(e) {}
			if (resp && resp.post_id > 0) {
				closeAIQuiz();
				downloadResults(1);
				rankingsLoaded = false;
				if ($('#RankingsPanel').is(':visible')) loadRankings(rankingsCurrentPeriod);
				loadQuizList();
				fetchFeedRankers();
				$.get('action-emailresult.php', { resultId: resp.result_id }, function() {});
			} else {
				alert('Something went wrong posting your result. Try again.');
				$('#AIQuizPostBtn').prop('disabled', false).text('Post to Feed');
			}
		});
	}

	function closeAIQuiz() {
		$('#AIQuizModal').hide();
		$('#AIQuizBody').show();
		$('#AIQuizScoreScreen').hide();
		$('#AIQuizOptions').off('click', '.ai-option');
		$('#AIQuizNext').hide().text('Next \u2192');
		$('#TabBar').show();
		aiQuizData      = null;
		aiCurrentIdx    = 0;
		aiScore         = 0;
		aiAnswerPending = false;
		aiReviewMode    = false;
		selectedQuiz    = null;
		$('#QuizDropdown').val('');
	}

	function escapeHtml(str) {
		return $('<div>').text(str).html();
	}

	// ── End AI Quiz Wizard ───────────────────────────────────────────────────

	// ── Test Quiz Generation (superuser only, no DB write) ──────────────────

	var testQuizEventSource = null;
	var testQuizStreamFinished = false;

	function closeTestQuizStream() {
		if (testQuizEventSource) {
			testQuizEventSource.close();
			testQuizEventSource = null;
		}
	}

	function openTestQuiz() {
		closeTestQuizStream();
		testQuizStreamFinished = false;

		$('#TestQuizModal').css('display', 'flex');
		$('#TestQuizSetup').show();
		$('#TestQuizLoading').hide();
		$('#TestQuizError').hide();
		$('#TestQuizLog').hide().empty();
		$('#TestQuizQuestions').empty();
		$('#TestQuizGenerateBtn').prop('disabled', false);
	}

	function startTestQuizGeneration() {
		closeTestQuizStream();
		testQuizStreamFinished = false;

		var skipPhotos = $('#TestQuizSkipPhotos').is(':checked');
		var url = 'action-test-generate-quiz.php?type=morning&stream=1';
		if (skipPhotos) {
			url += '&no_images=1';
		}

		$('#TestQuizSetup').hide();
		$('#TestQuizLoading').show();
		$('#TestQuizError').hide();
		$('#TestQuizQuestions').empty();
		$('#TestQuizGenerateBtn').prop('disabled', true);
		initTestQuizLogPanel();

		testQuizEventSource = new EventSource(url);
		testQuizEventSource.onmessage = function(e) {
			var msg = JSON.parse(e.data);
			if (msg.type === 'log') {
				$('#TestQuizLoading').hide();
				appendTestQuizLogLine(msg.entry);
			} else if (msg.type === 'status') {
				$('#TestQuizLoading').hide();
				appendTestQuizLogLine({ time: formatTestQuizTime(new Date()), level: 'INFO', message: msg.message });
			} else if (msg.type === 'done') {
				testQuizStreamFinished = true;
				closeTestQuizStream();
				$('#TestQuizLoading').hide();
				$('#TestQuizGenerateBtn').prop('disabled', false);
				renderTestQuizLog(msg.log, msg.stats, skipPhotos);
				renderTestQuiz(msg.questions);
			} else if (msg.type === 'error') {
				testQuizStreamFinished = true;
				closeTestQuizStream();
				$('#TestQuizLoading').hide();
				$('#TestQuizGenerateBtn').prop('disabled', false);
				renderTestQuizLog(msg.log, msg.stats, skipPhotos);
				$('#TestQuizError').text(msg.error || 'Generation failed.').show();
			}
		};
		testQuizEventSource.onerror = function() {
			if (testQuizStreamFinished || !testQuizEventSource) return;
			closeTestQuizStream();
			$('#TestQuizLoading').hide();
			$('#TestQuizGenerateBtn').prop('disabled', false);
			$('#TestQuizError').text('Connection lost during generation.').show();
		};
	}

	function formatTestQuizTime(d) {
		var p = function(n) { return (n < 10 ? '0' : '') + n; };
		return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' '
			+ p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
	}

	function initTestQuizLogPanel() {
		var $log = $('#TestQuizLog');
		$log.empty().show();
		$log.append($('<div>').addClass('test-quiz-log-summary').attr('id', 'TestQuizLogSummary').text('Generating…'));
		$log.append($('<pre>').addClass('test-quiz-log-text').attr('id', 'TestQuizLogText'));
	}

	function appendTestQuizLogLine(entry) {
		if (!entry) return;
		var line = '[' + entry.time + '] ' + entry.level + ': ' + entry.message + '\n';
		$('#TestQuizLogText').append(
			$('<span>').addClass(entry.level === 'ERROR' ? 'test-quiz-log-error' : 'test-quiz-log-info').text(line)
		);
		var el = document.getElementById('TestQuizLogText');
		if (el) el.scrollTop = el.scrollHeight;
	}

	function finishTestQuizLog(stats, log, skipPhotos) {
		var retried = stats && stats.categories_retried ? stats.categories_retried : 0;
		var skipped = stats && stats.fact_check_skips ? stats.fact_check_skips : 0;
		var images = stats && stats.preview_images_fetched != null ? stats.preview_images_fetched : null;
		var summary = 'Categories retried: ' + retried + ' · Fact-check skips: ' + skipped;
		if (skipPhotos) {
			summary += ' · Images: skipped';
		} else if (images !== null) {
			summary += ' · Images: ' + images + '/15';
		}
		if (!log || !log.length) {
			summary += ' · Clean run';
		}
		$('#TestQuizLogSummary').text(summary);
	}

	function renderTestQuizLog(log, stats, skipPhotos) {
		initTestQuizLogPanel();
		if (log && log.length) {
			log.forEach(function(entry) { appendTestQuizLogLine(entry); });
		}
		finishTestQuizLog(stats, log, skipPhotos);
	}

	function closeTestQuiz() {
		testQuizStreamFinished = true;
		closeTestQuizStream();
		$('#TestQuizModal').hide();
	}

	function renderTestQuiz(questions) {
		var html = '';
		questions.forEach(function(q, idx) {
			html += '<div class="test-quiz-question">';
			html += '<div class="test-quiz-category">' + escapeHtml(q.category) + '</div>';
			html += '<div class="test-quiz-text">' + (idx + 1) + '. ' + escapeHtml(q.question) + '</div>';
			if (q.image_url) {
				html += '<div class="test-quiz-image"><img src="' + escapeHtml(q.image_url) + '" alt="" loading="lazy"></div>';
			}
			html += '<div class="test-quiz-options">';
			q.options.forEach(function(opt) {
				var cls = opt.correct ? ' class="test-quiz-option-correct"' : '';
				var mark = opt.correct ? '&#x2713; ' : '&#x2013; ';
				html += '<div' + cls + '>' + mark + escapeHtml(opt.text) + '</div>';
			});
			html += '</div></div>';
		});
		$('#TestQuizQuestions').html(html);
	}

	// ── End Test Quiz Generation ─────────────────────────────────────────────

	function clearComposerAttachment() {
		window.composerAttachments = [];
		$('#ComposerAttachPreview').empty().hide();
		$('#ComposerFileInput').val('');
		$('#ComposerAttachBtn').show();
	}

	function clearReplyAttachment(quizFeedId) {
		window['replyAttachments_' + quizFeedId] = [];
		$('#ReplyAttachPreview_' + quizFeedId).empty().hide();
		$('[data-replyattachid="' + quizFeedId + '"]').show();
	}

	function uploadAttachment(file, onSuccess) {
		function doUpload(blob, filename) {
			var fd = new FormData();
			fd.append('file', blob, filename);
			fd.append('userid', getCookie('userid'));
			$.ajax({
				url: 'action-uploadattachment.php',
				type: 'POST',
				data: fd,
				processData: false,
				contentType: false,
				success: function(resp) {
					var a = typeof resp === 'string' ? JSON.parse(resp) : resp;
					if (a.error) { alert('Upload failed: ' + a.error); return; }
					onSuccess(a);
				},
				error: function() { alert('Upload failed \u2014 please try again'); }
			});
		}

		var MAX_PX = 1920;
		if (file.type.indexOf('image/') !== 0) {
			doUpload(file, file.name);
			return;
		}
		var img = new Image();
		var blobUrl = URL.createObjectURL(file);
		img.onload = function() {
			URL.revokeObjectURL(blobUrl);
			var w = img.naturalWidth, h = img.naturalHeight;
			if (w <= MAX_PX && h <= MAX_PX) {
				doUpload(file, file.name);
				return;
			}
			var scale = MAX_PX / Math.max(w, h);
			var canvas = document.createElement('canvas');
			canvas.width  = Math.round(w * scale);
			canvas.height = Math.round(h * scale);
			canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
			canvas.toBlob(function(blob) {
				doUpload(blob, file.name.replace(/\.[^.]+$/, '.jpg'));
			}, 'image/jpeg', 0.85);
		};
		img.src = blobUrl;
	}

		function activateListeners() {
		// Infinite scroll — load more results when user reaches the bottom
		document.getElementById("MainContent").addEventListener("scroll", expandFeed, false);

		// Info icon tooltips — tap/click to show, tap anywhere else to dismiss
		$(document).on('click', '.info-icon, .streak-badge[data-tip]', function(e) {
			e.stopPropagation();
			var tip = $(this).data('tip');
			var offset = $(this).offset();
			var left = Math.min(offset.left - 50, $(window).width() - 220);
			$('#InfoTooltip').text(tip)
				.css({ top: offset.top + 20, left: Math.max(10, left) })
				.fadeIn(150);
		});
		$(document).on('click', function() {
			$('#InfoTooltip').fadeOut(100);
		});

		// Ghosts section toggle (works for both rankings and PB ghost sections)
		$(document).on('click', '.ghosts-toggle', function() {
			var $section = $('#' + $(this).data('target'));
			var isOpen = $section.is(':visible');
			var count = $(this).data('count');
			$(this).html((isOpen ? '&#x25BC;' : '&#x25B2;') + ' Ghosts (' + count + ')');
			$section.slideToggle(200);
		});

		// Drill-down: tap any rankings/MI/PB row to expand results
		$(document).on('click', '.rankings-row[data-userid], .mi-row[data-userid], .pb-row[data-userid]', function(e) {
			if ($(e.target).hasClass('info-icon') || $(e.target).hasClass('streak-badge')) return;
			var $row = $(this);
			var userid = parseInt($row.data('userid'));
			var expandtype = $row.data('expandtype');
			if (expandtype === 'pb') {
				togglePbDetail($row, userid);
			} else {
				toggleDetail($row, userid, expandtype, rankingsCurrentPeriod);
			}
		});

		// Open quiz card when a quiz is selected from the dropdown
		$('#QuizDropdown').on('change', openQuizCard);

		// Composer: file selected — show spinner, upload, then show preview
		$(document).on('change', '#ComposerFileInput', function() {
			var files = Array.prototype.slice.call(this.files);
			if (!files.length) return;
			$(this).val('');
			if (!window.composerAttachments) window.composerAttachments = [];
			var remaining = 10 - window.composerAttachments.length;
			if (remaining <= 0) return;
			var $preview = $('#ComposerAttachPreview').show();
			files.slice(0, remaining).forEach(function(file) {
				var $spinner = $('<img>').attr({ src: 'ajax-loader.gif', 'class': 'attach-spinner' });
				var blobUrl = file.type.indexOf('image/') === 0 ? URL.createObjectURL(file) : null;
				$preview.append($spinner);
				uploadAttachment(file, function(a) {
					window.composerAttachments.push(a);
					$spinner.remove();
					var $item = $('<span>').addClass('attach-preview-item');
					if (a.file_type === 'image') {
						$item.append($('<img>').attr({ src: blobUrl || ('action-getattachment.php?id=' + a.id), 'class': 'attach-preview-thumb' }));
					} else {
						$item.append($('<span>').addClass('attach-filename').text(a.original_name));
					}
					$item.append($('<span>').addClass('attach-remove').html('&times;').on('click', function() {
						var idx = window.composerAttachments.indexOf(a);
						if (idx > -1) window.composerAttachments.splice(idx, 1);
						if (blobUrl) URL.revokeObjectURL(blobUrl);
						$item.remove();
						if (!window.composerAttachments.length) $('#ComposerAttachPreview').hide();
						$('#ComposerAttachBtn').show();
					}));
					$preview.append($item);
					if (window.composerAttachments.length >= 10) $('#ComposerAttachBtn').hide();
				});
			});
		});

		// Reply boxes: file selected — show spinner, upload, then show preview
		$(document).on('change', '.reply-file-input', function() {
			var files = Array.prototype.slice.call(this.files);
			var quizFeedId = $(this).data('replyfileid');
			if (!files.length) return;
			$(this).val('');
			if (!window['replyAttachments_' + quizFeedId]) window['replyAttachments_' + quizFeedId] = [];
			var remaining = 10 - window['replyAttachments_' + quizFeedId].length;
			if (remaining <= 0) return;
			var $preview = $('#ReplyAttachPreview_' + quizFeedId).show();
			files.slice(0, remaining).forEach(function(file) {
				var $spinner = $('<img>').attr({ src: 'ajax-loader.gif', 'class': 'attach-spinner' });
				var blobUrl = file.type.indexOf('image/') === 0 ? URL.createObjectURL(file) : null;
				$preview.append($spinner);
				uploadAttachment(file, function(a) {
					window['replyAttachments_' + quizFeedId].push(a);
					$spinner.remove();
					var $item = $('<span>').addClass('attach-preview-item');
					if (a.file_type === 'image') {
						$item.append($('<img>').attr({ src: blobUrl || ('action-getattachment.php?id=' + a.id), 'class': 'attach-preview-thumb' }));
					} else {
						$item.append($('<span>').addClass('attach-filename').text(a.original_name));
					}
					$item.append($('<span>').addClass('attach-remove').html('&times;').on('click', function() {
						var arr = window['replyAttachments_' + quizFeedId];
						var idx = arr.indexOf(a);
						if (idx > -1) arr.splice(idx, 1);
						if (blobUrl) URL.revokeObjectURL(blobUrl);
						$item.remove();
						if (!arr.length) $('#ReplyAttachPreview_' + quizFeedId).hide();
						$('[data-replyattachid="' + quizFeedId + '"]').show();
					}));
					$preview.append($item);
					if (window['replyAttachments_' + quizFeedId].length >= 10) $('[data-replyattachid="' + quizFeedId + '"]').hide();
				});
			});
		});
		// If a comment is provided, show the submit button (delegated — element is injected dynamically)
		$(document).on('input', '#NewCommentTextArea', function() {
			$('#NewCommentSubmit').toggle($(this).val().trim().length > 0);
		});
			
	}
	
	function showProfile() {
		croppedBlob = null;
		document.getElementById("profilepic").value = "";
		$("#photo-preview").hide().attr("src", "");
		$("#photo-label-text").text("Choose a photo...");
		if ($("#Profile").is(":visible")) {
			hideProfile();
			return;
		}
		$("#QuizFeed").hide();
		$("#RankingsPanel").hide();
		$("#MainContent").css("top", "");
		$("#Profile").show();
		$("#TabBar").hide();
		
		// Populate the fields
		$("#ProfileTitleName").text(getSetting("first_name") + " " + getSetting("last_name"));
		$("#email").val(getSetting("email"));
		$("#namefirst").val(getSetting("first_name"));
		$("#namelast").val(getSetting("last_name"));
		$("#oldpass").val("");
		$("#PassA").val("");
		$("#PassB").val("");
		$("#notifyresult").prop("checked", getSetting("notify_results") == 1);
		$("#notifymessage").prop("checked", getSetting("notify_message") == 1);

		$("#timezone").empty();
		var timezones = ["NZ", "Australia/Melbourne", "Australia/Brisbane"];
		timezones.forEach(function(timezone) {
			if (getSetting("timezone") == timezone) {
				$("#timezone").append("<option value='" + timezone + "' selected>" + timezone + "</option>");
			} else {
				$("#timezone").append("<option value='" + timezone + "'>" + timezone + "</option>");
			}
		});
		
		// Download the groups and display them
		$("#defaultgroup").html("");
		$("#defaultgroup").prop('disabled', true);
		$("#defaultgroup").append("<option id=LoadingGroups>Loading Groups...</option>");
		$.get("action-getgroups.php", 
			function(data, status) { 
				$("#LoadingGroups").remove();
				$("#defaultgroup").prop('disabled', false);
				groupsObj = JSON.parse(data);
				groups =  $.map(groupsObj, function(el) { return el });
				groups.forEach(function(group) {
				if (getSetting("group_id") == group.id) {
					$("#defaultgroup").append("<option value='" + group.id + "' selected>" + group.name + "</option>");
				} else {
					$("#defaultgroup").append("<option value='" + group.id + "'>" + group.name + "</option>");
				}
			});
			}
		);
		
	}
	
	function hideProfile() {
		$("#QuizFeed").show();
		$("#RankingsPanel").show();
		$("#MainContent").css("top", "");
		$("#Profile").hide();
		$("#TabBar").show();
		downloadResults();
	}
	
	
	var cropper = null;
	var croppedBlob = null;

	function saveDetails() {
		if (croppedBlob) {
			$('#Profile').append("<div id=SaveProfileLoading><img src=ajax-loader.gif></img></div>");
			var formData = new FormData();
			formData.append("file", croppedBlob, "profile.jpg");
			formData.append("userid", getCookie("userid"));
			$.ajax({
				url: "action-uploadphoto.php",
				type: "POST",
				data: formData,
				processData: false,
				contentType: false,
				dataType: "json",
				success: function(result) {
					$("#SaveProfileLoading").remove();
					doSaveDetails(result.filename || "");
				},
				error: function() {
					$("#SaveProfileLoading").remove();
					doSaveDetails("");
				}
			});
		} else {
			doSaveDetails("");
		}
	}

	function doSaveDetails(photoFilename) {
		var profile = {
			email: $("#email").val(),
			firstname: $("#namefirst").val(),
			lastname: $("#namelast").val(),
			notifyEmail: $("#notifyresult").is(":checked") ? 1 : 0,
			notifyMessage: $("#notifymessage").is(":checked") ? 1 : 0,
			timezone: $("#timezone").val(),
			groupid: $("#defaultgroup").val(),
			userid: getCookie("userid"),
			photo: photoFilename
		};

		$('#Profile').append("<div id=SaveProfileLoading><img src=ajax-loader.gif></img></div>");

		$.ajax({
			type: "POST",
			url: "action-saveprofile.php",
			data: { json: JSON.stringify(profile) },
			success: function() {
				$("#SaveProfileLoading").remove();
				$("#ProfileSuccess").show();
				setTimeout(function() {
					$("#ProfileSuccess").hide();
					hideProfile();
					getSettings();
				}, 2000);
			},
			error: function() {
				$("#SaveProfileLoading").remove();
				alert("Sorry, your changes could not be saved. Please try again.");
			}
		});
	}

	function savePassword() {
		var currentError = $(".field-error:visible").attr("id") || "";
		$(".field-error").hide();
		$(".prof-input").removeClass("input-error");

		if ($("#oldpass").val() == "") {
			showFieldError("oldpass-error", "oldpass", "Please enter your current password", currentError === "oldpass-error");
			return;
		}

		if ($("#PassA").val() == "") {
			showFieldError("passmatch-error", "PassA", "Please enter a new password", currentError === "passmatch-error");
			return;
		}

		if ($("#PassA").val() != $("#PassB").val()) {
			showFieldError("passmatch-error", "PassA PassB", "Passwords don't match", currentError === "passmatch-error");
			return;
		}

		$('#Profile').append("<div id=SaveProfileLoading><img src=ajax-loader.gif></img></div>");

		$.ajax({
			type: "POST",
			url: "action-savepassword.php",
			data: { json: JSON.stringify({
				passwordOld: $("#oldpass").val(),
				passwordNew: $("#PassA").val(),
				userid: getCookie("userid")
			})},
			statusCode: {
				400: function() {
					$("#SaveProfileLoading").remove();
					showFieldError("oldpass-error", "oldpass", "Incorrect password", false);
				},
				200: function() {
					$("#SaveProfileLoading").remove();
					$("#oldpass").val("");
					$("#PassA").val("");
					$("#PassB").val("");
					$("#PasswordSuccess").show();
					setTimeout(function() { $("#PasswordSuccess").hide(); }, 3000);
				}
			}
		});
	}
	
	function showFieldError(errorId, fieldIds, message, isRepeat) {
		var el = $("#" + errorId).html(message).show()[0];
		if (isRepeat) {
			// Re-trigger the animation so the user sees the form ran again
			el.style.animation = "none";
			void el.offsetWidth;
			el.style.animation = "";
		}
		$.each(fieldIds.split(" "), function(_, id) {
			$("#" + id).addClass("input-error").one("focus", function() {
				$("#" + errorId).hide();
				$.each(fieldIds.split(" "), function(__, fid) { $("#" + fid).removeClass("input-error"); });
			});
		});
	}

	function profilePhotoPreview(input) {
		if (input.files && input.files[0]) {
			var reader = new FileReader();
			reader.onload = function(e) {
				var img = document.getElementById("CropImage");
				img.src = e.target.result;
				document.getElementById("CropModal").style.display = "flex";
				if (cropper) { cropper.destroy(); }
				cropper = new Cropper(img, {
					aspectRatio: 1,
					viewMode: 1,
					dragMode: "move",
					autoCropArea: 1,
					cropBoxMovable: false,
					cropBoxResizable: false,
					toggleDragModeOnDblclick: false,
					responsive: true
				});
			};
			reader.readAsDataURL(input.files[0]);
		}
	}

	function cropRotate(degrees) {
		if (cropper) cropper.rotate(degrees);
	}

	function confirmCrop() {
		if (!cropper) return;
		cropper.getCroppedCanvas({ width: 150, height: 150 }).toBlob(function(blob) {
			croppedBlob = blob;
			var url = URL.createObjectURL(blob);
			$("#photo-preview").attr("src", url).show();
			$("#photo-label-text").text("Change photo...");
			document.getElementById("CropModal").style.display = "none";
			cropper.destroy();
			cropper = null;
		}, "image/jpeg", 0.9);
	}

	function cancelCrop() {
		document.getElementById("CropModal").style.display = "none";
		if (cropper) { cropper.destroy(); cropper = null; }
		document.getElementById("profilepic").value = "";
	}

	function showWelcome(force) {
		var WELCOME_VERSION = 'v6';
		if (!force && localStorage.getItem('quizzical_welcome') === WELCOME_VERSION) return;
		$.get('welcome-v6.html', function(html) {
			$('#WelcomeBody').html(html);
			$('#WelcomeOverlay').fadeIn(200);
		});
	}

	function dismissWelcome() {
		localStorage.setItem('quizzical_welcome', 'v6');
		$('#WelcomeOverlay').fadeOut(200);
	}

	$( document ).ready(function() {
		if (!document.getElementById("MainContent")) return;
		getSettings();
		activateListeners();
		$("#userid").val(getCookie("userid"));
		showWelcome();
	});
	
	
	
	
	//getResults();
	
	//downloadResults();
		
