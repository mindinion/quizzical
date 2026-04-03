
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
				refreshNewPost();
			} else {
				alert("Result Not Deleted");

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
		if ($('*[data-replyboxid="' + id + '"]').css("display") == "none") {
			$('*[data-replyboxid="' + id + '"]').css("display","block");    	
			$('*[data-replyboxid="' + id + '"]').focus();  
		} else {
			$('*[data-replyboxid="' + id + '"]').css("display","none");
		}	
	}
	
	function sendComment (e,quizFeedId) {
		//console.log(e.keycode);
		if (e.keyCode != 13) return;
		comment = $('*[data-replyboxid="' + quizFeedId + '"]').val();
		$('*[data-replyboxid="' + quizFeedId + '"]').blur();
		console.log(quizFeedId);
		console.log(comment);
		$('*[data-quizfeedtext="' + quizFeedId + '"]').append("<div id=CommentLoadingPost><img src='ajax-loader.gif'></img></div>");
		$.get("action-newcomment.php", {
				quizFeedId:quizFeedId,
				comment:comment,
				userid:getSetting("user_id")
			},
			function(data,status){
				console.log(data);
				//$('*[data-quizfeedtext="' + quizFeedId + '"]').remove();
				if (status == "success") {
					downloadResults(1);
					refreshNewPost();	
					
					// Email notifications 
					$.get("action-emailcomment.php", 
						{ 
							postId:data,
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
				refreshNewPost();
							 		
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
				refreshNewPost();
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
				refreshNewPost();					
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
				refreshNewPost();		 				
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
	
		// SHow the loading image
		$("#QuizFeed").prepend("<div id=QuizfeedLoadingPost><img src='ajax-loader.gif'></img></div>");
		
		$.get("action-newcommentpost.php", {
				comment:document.getElementById("NewCommentTextArea").value,
				userid:getSetting("user_id"),
				timezone:getSetting("timezone")
			},
			function(data,status){
				if (status == "success") {
					console.log(data);
					downloadResults(1);
					refreshNewPost();				
					
					//Email notifications
					$.get("action-emailpost.php", 
						{ postId:data },
						function(data) { console.log(data);  }
					);
							
					document.getElementById("NewCommentTextArea").value = '';
					document.getElementById('NewCommentSubmit').style.display = 'none';
					document.getElementById('NewCommentSubmit').style.display = 'none';
					document.getElementById('QuizFeed').style.top = '70px';
		
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
				refreshNewPost();			
				document.getElementById("NewScoreType").value = 'Daily';
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
	
	
		
	// When loading, find out what score they are likely to enter
	function refreshNewPost(userid) {
		$.get("action-getresult.php", {
				userid:userid
			},
			function(data,status){
				if (status == "success") {
					document.getElementById('NewScoreType').value=data;
				} 
			}
		);
		
		// Populate the list of dates for the last two weeks
		var days = 2;
		while (days <= 14) {
			aDate = moment().subtract(days, 'days').format('YYYY-MM-DD');
			aDateDisplay = moment().subtract(days, 'days').format('dddd, MMM-DD');
			$("#NewScoreDate").append("<option value='" + aDate + "'>" + aDateDisplay + "</option>");
			days++;		
		}	
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
			if (resultId != null)  $('*[data-quizfeedtext="' + quizfeedId + '"]').append("<div id=QuizFeedInfoStatus>Scored " + score + "/" + max + " in the " + Date.parse(date).toString("MMM dd") + " " + type + " quiz </div id=QuizFeedInfoStatus>");
			$('*[data-quizfeedname="' + quizfeedId + '"]').append("<a href='javascript:deletePost(" + quizfeedId + ") class=Underline> Delete </a>" );
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<div id=QuizFeedInfoComment class=Primary>" + comment + "</div id=QuizFeedInfoComment>");    				
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<span id=QuizFeedInfoReplyLink onclick='showReplyBox(" + quizfeedId + ");'> Comment </span>");    				
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("- <span id=QuizFeedInfoDigLink data-dig=" + quizfeedId + " onclick='digPost(" + quizfeedId + ");'>Dig </span>");    				
			
			if (userId == getSetting("user_id"))
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("- <span id=QuizFeedInfoDelete data-deleteid=" + quizfeedId + " onclick='deletePost(" + quizfeedId + ");'>Delete </span>");    				
			
			$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<textarea rows=3 class=QuizFeedInfoReplyInput onkeydown='sendComment(event," + quizfeedId + ")' data-replyboxid=" + quizfeedId + " style=display:none;></textarea>");    				

			// Grab the comments data for the post and display them
			if (result.comments != null && result.comments.length > 0) {
				var comments = result.comments;
				comments.forEach(function(comment) {
					var commentid = comment.commentid;
					var text = comment.comment_comment;
					var name = comment.comment_first_name + " " + comment.comment_last_name;
					var timestamp = comment.comment_timestamp;
					$('*[data-quizfeedtext="' + quizfeedId + '"]').append("<div id=Comments><div id=CommentRow><div id=QuizFeedInfoReplyName>" + name + "</div><div id=QuizFeedInfoComment class=OtherUser data-comment=" + commentid + "><div id=CommentText>" + text + "</div><div id=DigsComment data-commentid=" + commentid +"><span id='DigCommentLink' data-linkcommentid=" + commentid + " > </span></div></div></div>");
				
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
						$('*[data-comment="' + commentid + '"]').append("<div id=DigsComment>" + names + " "  + context + " this</div>");		
								
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
		
		applyFeedRankBadges();
		if (preLoad != 1) loadBubbles(results);

	}
	
	
	function loadBubbles(results) {
		window.res = results;
		
		// First populate each bubble with the preloader
		results.forEach(function(result) {
			if (result.result != null && result.poster_id != getCookie("userid"))
				$('*[data-quizfeed="' + result.postid + '"]').append("<div id=QuizFeedBubble class=Loading><img src = 'ajax-loader.gif' data-loader=bubble data-postid=" + result.postid + " class=Loader></img></div>");
		});
		
		//Now populate each bubble with the data
		results.forEach(function(result) {
			if (result.result != null && result.poster_id != getCookie("userid")) {
				$.get("action-checkresult.php", 
					{ 
						date:result.result.result_date.split(" ")[0],
						type:result.result.result_type,
						userid:getCookie("userid")
					},	
					function(data, status) { 
						var score = someText = data.replace(/(\r\n|\n|\r)/gm,"");
						$('*[data-postid="' + result.postid + '"]').remove();
						if (score != "failed" ) {
							var message = "You scored " + score + "/" + result.result.result_max + " in this quiz";
							$('*[data-quizfeed="' + result.postid + '"]').append("<div id=QuizFeedBubble class=ScreenHide>" + message + "</div>");
						} else {
							var message = "You haven't done this quiz yet";
							$('*[data-quizfeed="' + result.postid + '"]').append("<div id=QuizFeedBubble class=ScreenHide>" + message + "</div>");
						}
					}
				);	
			}		
				
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
		$.get('action-getrankings.php', { groupid: groupid, period: 'weekly', typefilter: 'main' }, function(data) {
			feedTopRankers = {};
			JSON.parse(data).slice(0, 3).forEach(function(r, i) { feedTopRankers[r.userid] = i + 1; });
			applyFeedRankBadges();
		});
	}

	function getSettings() {
	$.get("action-getsettings.php", 
		{ 
			userid:getCookie("userid")
		},	
		
		function(data, status) { 
			sessionStorage.usersettings = data;
			fetchFeedRankers();
			downloadResults();
			loadQuizList();
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
	var rankingsTypeFilter = 'main';
	var quizList = [];
	var quizTypeFilter = 'main';
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
		switchTypeFilter(rankingsTypeFilter === 'main' ? 'all' : 'main');
	}

	function loadRankings(period) {
		var groupid = getSetting("group_id");
		var myUserid = parseInt(getCookie("userid"));
		$('#RankingsMain').html("<img src='ajax-loader.gif' class='Loader'>");
		$('#RankingsMostImproved').html('');
		$('#RankingsPersonalBests').html('');
		$.when(
			$.get("action-getrankings.php", { groupid: groupid, period: period, typefilter: rankingsTypeFilter }),
			$.get("action-getstreaks.php", { groupid: groupid }),
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

	function refreshNewPost() {
		$.get("action-getresult.php", {
				userid:getSetting("user_id")
			},
			function(data,status){
				console.log(data);
				if (status == "success") {
					document.getElementById('NewScoreType').value=data;
				} 
			}
		);
	}
	

	function loadQuizList() {
		$.get('action-getquizzes.php', {
			userid: getSetting('user_id'),
			typefilter: quizTypeFilter
		}, function(data) {
			quizList = JSON.parse(data);
			renderQuizDropdown();
		});
	}

	function renderQuizDropdown() {
		var $sel = $('#QuizDropdown');
		$sel.empty().append('<option value="">Select a quiz to play...</option>');
		if (!quizList.length) {
			$sel.append('<option value="" disabled>No quizzes found</option>');
			return;
		}
		quizList.forEach(function(q, i) {
			var label = q.type + ' \u2013 ' + formatQuizDate(q.date);
			if (q.done) label += ' \u2713';
			var $opt = $('<option>').val(i).text(label);
			if (q.done) $opt.prop('disabled', true);
			$sel.append($opt);
		});
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

	function setQuizTypeFilter(filter) {
		quizTypeFilter = filter;
		$('.quiz-type-btn').removeClass('quiz-type-active');
		$('#QuizType' + (filter === 'main' ? 'Main' : 'All')).addClass('quiz-type-active');
		loadQuizList();
	}

	function openQuizCard() {
		var idx = $('#QuizDropdown').val();
		if (idx === '' || idx === null) return;
		selectedQuiz = quizList[parseInt(idx)];
		if (!selectedQuiz || selectedQuiz.done) return;
		var label = selectedQuiz.type + ' \u2013 ' + formatQuizDate(selectedQuiz.date);
		$('#QuizCardTitle').text(label);
		$('#QuizIframe').attr('src', selectedQuiz.url);
		$('#QuizScore').val('');
		$('#QuizTotal').val('10');
		$('#QuizCard').show();
		$('#TabBar').hide();
		$('#QuizDropdown').val('');
	}

	function closeQuizCard() {
		$('#QuizCard').hide();
		$('#QuizIframe').attr('src', '');
		$('#TabBar').show();
		selectedQuiz = null;
	}

	function submitQuizResult() {
		var score = $('#QuizScore').val();
		var total = $('#QuizTotal').val();
		if (!score || score === '') { alert('Please enter your score'); return; }
		if (!selectedQuiz) return;
		$.get('action-newresult.php', {
			type: selectedQuiz.type,
			score: score,
			questions: total,
			date: selectedQuiz.date,
			dateOption: 'other',
			userid: getSetting('user_id'),
			timezone: getSetting('timezone')
		}, function(data) {
			if (parseInt(data) > 0) {
				closeQuizCard();
				downloadResults(1);
				rankingsLoaded = false;
				if ($('#RankingsPanel').is(':visible')) loadRankings(rankingsCurrentPeriod);
				loadQuizList();
				$.get('action-emailresult.php', { resultId: data }, function(r) { console.log(r); });
			} else {
				alert('You have already logged a result for that quiz!');
			}
		});
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
		// If a comment is provided, show the submit button
		document.getElementById("NewCommentTextArea").addEventListener("keyup", function(){ 
			if (document.activeElement.value != "") {
				document.getElementById('NewCommentSubmit').style.display = 'block';
				document.getElementById('QuizFeed').style.top = '100px';
			} else {
				document.getElementById('NewCommentSubmit').style.display = 'none';
				document.getElementById('QuizFeed').style.top = '70px';
		}
		});
			
	}
	
	function showProfile() {
		croppedBlob = null;
		document.getElementById("profilepic").value = "";
		$("#photo-preview").hide().attr("src", "");
		$("#photo-label-text").text("Choose a photo...");
		if ($("#NewPost").css("display") == "none") {
			hideProfile();
			return;
		}
		$("#QuizFeed").html("");
		$("#NewPost").hide();
		$("#MainContent").css("top","80px");
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
		$("#NewPost").show();
		$("#MainContent").css("top","145px");
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

	$( document ).ready(function() {
		console.log("made it");
		getSettings();
		activateListeners();
		$("#userid").val(getSetting("user_id"));
	});
	
	
	
	
	//getResults();
	
	//downloadResults();
		
