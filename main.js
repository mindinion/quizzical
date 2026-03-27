
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
				downloadRankings(7);
				refreshNewPost();	
			} else {
				alert("Result Not Deleted");

			}
		});
	}
	
	

	function expandFeed() {
		var elem = document.getElementById("QuizFeed");
		if (elem.scrollTop == elem.scrollHeight - elem.offsetHeight) {
			// We have scrolled to the bottom of the QuizFeed, so increment the cookie that counts how many items to show			
			//alert(window.feedItems);
			var feeditems = Number(getCookie("feedItems"));
			feeditems = feeditems + 10;
			document.cookie="feedItems=" + feeditems;
			getResults();		
		}
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
					downloadRankings(7);
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
				downloadRankings(7);
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
				downloadRankings(7);
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
				downloadRankings(7);
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
				downloadRankings(7);
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
					downloadRankings(7);
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
				downloadRankings(7);
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
		var groupid = getSetting("group_id");
		var limit = 50;
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
			{ 
				groupid:groupid,
				limit:limit
			},	
			function(data, status) { 
				if (sessionStorage.getItem("results") != data || firstTime == 1 ) {
					displayResults(data,0);
				} else {
					resultsObj = JSON.parse(data);
					results =  $.map(resultsObj, function(el) { return el });
					loadBubbles(results);
				}
				$('*[data-loader=quizfeed]').remove();
				sessionStorage.results = data;
			}
		);
	}
	
	
	
	function displayResults(resultsJson,preLoad) {
	
		// First clear the current quizfeed
		$("#QuizFeed").html("");
			
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
			if (resultId == null) {
				$('*[data-quizfeed="' + quizfeedId + '"]').html("<div id=QuizFeedInfo data-quizfeedinfo=" + quizfeedId + " class=NoBubble>");
				$('*[data-quizfeedinfo="' + quizfeedId + '"]').html("<div id=QuizFeedInfoPhoto><img src='" + picFilename + "'height=50 width=50></img>");
				$('*[data-quizfeedinfo="' + quizfeedId + '"]').append("<div id=QuizFeedInfoText class=nobubble data-quizfeedtext=" + quizfeedId + ">");

			} else {
				$('*[data-quizfeed="' + quizfeedId + '"]').html("<div id=QuizFeedInfo data-quizfeedinfo=" + quizfeedId + " >");
				$('*[data-quizfeedinfo="' + quizfeedId + '"]').html("<div id=QuizFeedInfoPhoto><img src='" + picFilename + "'height=50 width=50></img>");
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
	
	
	function getSettings() {
	$.get("action-getsettings.php", 
		{ 
			userid:getCookie("userid")
		},	
		
		function(data, status) { 
			sessionStorage.usersettings = data;		
			downloadResults();	
			downloadRankings(7);
			refreshNewPost();
		});
	}
	
	function getSetting(type) {
		obj = JSON.parse(sessionStorage.usersettings);
		return eval ( "obj." + type ) ;
	}
	
	function downloadRankings(period) {
		var groupid = getSetting("group_id");
		var rankings = sessionStorage.getItem("rankings");
		
		// Show the preloader
		$("#UserRankings").append("<img src = 'ajax-loader.gif' data-loader=rankings class=Loader></img>");
		
		//First display the cached results, if we have them
		if (rankings != null) {
			displayRankings(rankings);
		} 

		
		// Now download and display the most recent rankings
		$.get("action-getrankings.php", 
			{ 
				groupid:groupid,
				period:period
			},	
			function(data, status) { 
				if (sessionStorage.getItem("rankings") != data) {
					displayRankings(data);
				} else {
					rankingsObj = JSON.parse(data);
					rankings =  $.map(rankingsObj, function(el) { return el });
				}
				$('*[data-loader=rankings]').remove();
				sessionStorage.rankings = data;
			}
		);
	}
	
	function displayRankings(rankingsJson) {
		$("#UserRankings").html("");
		if (rankingsJson == null) return;
			
		rankingsObj = JSON.parse(rankingsJson);
		rankings =  $.map(rankingsObj, function(el) { return el });
		placingnum = 0;
		
		$("#UserRankings").html("");
		
		rankings.forEach(function(ranking) {
			placingnum++;
			var name = ranking.first_name + " " + ranking.last_name;
			var average = ranking.average;
			var pic_filename = ranking.pic_filename;
			var userid = ranking.userid;
			if (placingnum.toString().slice(-1) == 1) {
				placing = placingnum + "st";
			} else if (placingnum.toString().slice(-1) == 2) {
				placing = placingnum + "nd";
			} else if (placingnum.toString().slice(-1) == 3) {
				placing = placingnum + "rd";
			} else {
				placing = placingnum + "th";
			}
    		$("#UserRankings").append("<span id=UserRanking data-ranking=" + userid + ">");
			$('*[data-ranking="' + userid + '"]').append("<div id='RankingPhoto' data-rankingphoto=" + userid + ">");
			$('*[data-rankingphoto="' + userid + '"]').append("<img src='" + pic_filename + "' class='UserLogo'></img>");
			$('*[data-rankingphoto="' + userid + '"]').append("<div id='RankingPlace'>" + placing + "<br><div id=PlacingScore>" + average + "%</div></div id='RankingPlace'>");
			$('*[data-ranking="' + userid + '"]').append("</div id='RankingPhoto'>");
    		$("#UserRankings").append("	</span id='UserRanking'>");
		

			
		});
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
	
	function activateListeners() {
		// If entering a score, reveal the rest of the score fields
		document.getElementById("NewResultScore").addEventListener("keyup", function(){ 
			if (document.activeElement.value != "") {
				document.getElementById('NewScoreLabelType').style.display = 'block';
				document.getElementById('NewScoreType').style.display = 'block';
				document.getElementById('NewScoreLabelWhen').style.display = 'block';
				document.getElementById('NewScoreDate').style.display = 'block';
				document.getElementById('NewResultSubmit').style.display = 'block';
				document.getElementById('NewCommentTextArea').style.display = 'none';
				document.getElementById('NewCommentSubmit').style.display = 'none';
				document.getElementById('QuizFeed').style.top = '70px';
				refreshNewPost();
			} else {
				document.getElementById('NewScoreLabelType').style.display = 'none';
				document.getElementById('NewScoreType').style.display = 'none';
				document.getElementById('NewScoreLabelWhen').style.display = 'none';
				document.getElementById('NewScoreDate').style.display = 'none';
				document.getElementById('NewResultSubmit').style.display = 'none';
				document.getElementById('NewCommentTextArea').style.display = 'block';
			}
		}, false);


		// If the result is changed (via the spinner), show the extra result elements ('undefined' caveat stops it from triggering off an empty field)
		document.getElementById("NewResultScore").addEventListener("change", function() {
		if (document.activeElement.value != undefined) {
				document.getElementById('NewScoreLabelType').style.display = 'block';
				document.getElementById('NewScoreType').style.display = 'block';
				document.getElementById('NewScoreLabelWhen').style.display = 'block';
				document.getElementById('NewScoreDate').style.display = 'block';
				document.getElementById('NewResultSubmit').style.display = 'block';
				document.getElementById('NewCommentTextArea').style.display = 'none';
				document.getElementById('NewCommentSubmit').style.display = 'none';
				document.getElementById('QuizFeed').style.top = '70px';
				refreshNewPost();
			}
		}, false);
	
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
		window.newFile = "";
		if ($("#NewPost").css("display") == "none") {
			hideProfile();
			return;
		}
		$("#QuizFeed").html("");
		$("#NewPost").hide();
		$("#MainContent").css("top","80px");
		$("#Profile").show();
		
		// Populate the fields
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
		downloadResults();
	}
	
	
	function saveProfile() {

		$("#ProfileWarning").hide();

		if ($("#oldpass").val() == "") {
			$("#ProfileWarning").html("⚠&nbsp; Please enter your current password").show();
			$("#oldpass").one("focus", function() { $("#ProfileWarning").hide(); });
			$("#MainContent").scrollTop($("#MainContent")[0].scrollHeight);
			return;
		}

		if ($("#PassA").val() != $("#PassB").val()) {
			$("#ProfileWarning").html("⚠&nbsp; New passwords don't match").show();
			$("#PassA").one("focus", function() { $("#ProfileWarning").hide(); });
			$("#PassB").one("focus", function() { $("#ProfileWarning").hide(); });
			$("#MainContent").scrollTop($("#MainContent")[0].scrollHeight);
			return;
		}

		var fileInput = document.getElementById("profilepic");

		if (fileInput && fileInput.files.length > 0) {
			// Upload photo first, then save profile data
			$('#Profile').append("<div id=SaveProfileLoading><img src=ajax-loader.gif></img></div>");
			var formData = new FormData();
			formData.append("file", fileInput.files[0]);
			formData.append("userid", getCookie("userid"));
			$.ajax({
				url: "action-uploadphoto.php",
				type: "POST",
				data: formData,
				processData: false,
				contentType: false,
				success: function(response) {
					$("#SaveProfileLoading").remove();
					var result = JSON.parse(response);
					doSaveProfile(result.filename || "");
				},
				error: function() {
					$("#SaveProfileLoading").remove();
					doSaveProfile("");
				}
			});
		} else {
			doSaveProfile("");
		}
	}

	function doSaveProfile(photoFilename) {
		var profile = {
			email: $("#email").val(),
			firstname: $("#namefirst").val(),
			lastname: $("#namelast").val(),
			password: $("#oldpass").val(),
			passwordNew: $("#PassA").val(),
			notifyEmail: $("#notifyresult").is(":checked") ? 1 : 0,
			notifyMessage: $("#notifymessage").is(":checked") ? 1 : 0,
			timezone: $("#timezone").val(),
			groupid: $("#defaultgroup").val(),
			userid: getCookie("userid"),
			photo: photoFilename
		};
		var profileJson = JSON.stringify(profile);

		$('#Profile').append("<div id=SaveProfileLoading><img src=ajax-loader.gif></img></div>");

		$.ajax({
			type: "POST",
			url: "action-saveprofile.php",
			data: { json: profileJson },
			statusCode: {
				400: function() {
					$("#SaveProfileLoading").remove();
					$("#ProfileWarning").html("⚠&nbsp; Your current password is incorrect").show();
					$("#oldpass").one("focus", function() { $("#ProfileWarning").hide(); });
					$("#MainContent").scrollTop($("#MainContent")[0].scrollHeight);
				},
				200: function() {
					$("#SaveProfileLoading").remove();
					getSettings();
					$("#ProfileSuccess").html("✓&nbsp; Your changes have been saved").show();
					$("#MainContent").scrollTop($("#MainContent")[0].scrollHeight);
					setTimeout(function() {
						$("#ProfileSuccess").hide();
						hideProfile();
						downloadResults();
						downloadRankings(7);
					}, 2000);
				}
			}
		});
	}
	
	function profilePhotoPreview(input) {
		if (input.files && input.files[0]) {
			var reader = new FileReader();
			reader.onload = function(e) {
				$("#photo-preview").attr("src", e.target.result).show();
				$("#photo-label-text").text(input.files[0].name);
			};
			reader.readAsDataURL(input.files[0]);
		}
	}

	$( document ).ready(function() {
		console.log("made it");
		getSettings();
		activateListeners();
		$("#userid").val(getSetting("user_id"));
	});
	
	
	
	
	//getResults();
	
	//downloadResults();
		
