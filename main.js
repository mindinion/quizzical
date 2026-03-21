
// Initialise the number of results in the quiz feed
document.cookie="feedItems=50";

// When the window loads, load the quiz feed	
	window.onload = function() {
		$('#QuizFeed').load('div-quizfeed.php');

	}

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
		document.getElementById('DeletingNotifyPost').style.display = 'block';
		disableSite();	
		$.get("action-deletepost.php", {
			id:id
		},
		function(data,status){
			document.getElementById('DeletingNotifyPost').style.display = 'none';
			enableSite();
			if (status == "success") {
				$('#QuizFeed').load('div-quizfeed.php');
				$('#UserRankings').load('div-userrankings.php');
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
			$('#QuizFeed').load('div-quizfeed.php');		
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
	
	
		function showReplyBox (boxDiv ) {
		if ($("#" + boxDiv).css("display") == "none") {
			document.getElementById(boxDiv).style.display = 'block';
			document.getElementById(boxDiv).focus();
		} else {
			document.getElementById(boxDiv).style.display = 'none';
		}
	}
	
	function sendComment (boxDiv, quizFeedId) {
		disableSite();

		document.getElementById('LoadingNotifyComment').style.display = 'block';
		$.get("action-newcomment.php", {
				quizFeedId:quizFeedId,
				comment:document.getElementById(boxDiv).value
			},
			function(data,status){
				if (status == "success") {
					$('#QuizFeed').load('div-quizfeed.php');
					document.getElementById('LoadingNotifyComment').style.display = 'none';
					enableSite();
					
					// Email notifications
					$.get("action-emailcomment.php", 
						{ postId:data },
						function(response) {  }
					);
					
				} else {
					alert("Comment Not Added");
				}
			}
		);
	}
	
	function digPost(id) {
		disableSite();
		document.getElementById('SavingDig').style.display = 'block';
		$.get("action-newdig.php", 
			{ postid:id },
			function(data, status) { 
				$('#QuizFeed').load('div-quizfeed.php');
				document.getElementById('SavingDig').style.display = 'none';
				enableSite();
				$.get("action-emaildig.php", 
					{ id:data },
					function(response) {  }
				);
			}
		);
	}
	
	function undigPost(id) {
		disableSite();
		document.getElementById('SavingDig').style.display = 'block';
		$.get("action-undig.php", 
			{ id:id },
			function(response) { 
				$('#QuizFeed').load('div-quizfeed.php');
				document.getElementById('SavingDig').style.display = 'none';
				enableSite();
			}
		);
	}
	
	function digComment(id) {
		disableSite();
		document.getElementById('SavingDig').style.display = 'block';
		$.get("action-newdig.php", 
			{ commentid:id },
			function(data, status) { 
				$('#QuizFeed').load('div-quizfeed.php');
				document.getElementById('SavingDig').style.display = 'none';
				enableSite();
 				$.get("action-emaildigcomment.php", 
					{ id:data },
					function(response) {  }
				);
			}
		);
	}
	
	function postComment () {
		disableSite();
	
		document.getElementById('LoadingNotifyPost').style.display = 'block';
		$.get("action-newcommentpost.php", {
				comment:document.getElementById("NewCommentTextArea").value
			},
			function(data,status){
				if (status == "success") {
					$('#QuizFeed').load('div-quizfeed.php');
					document.getElementById('LoadingNotifyPost').style.display = 'none';
					enableSite();
					
					//Email notifications
					$.get("action-emailpost.php", 
						{ postId:data },
						function(response) {  }
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
		document.getElementById('LoadingNotifyResult').style.display = 'block';
		disableSite();
		if ( document.getElementById("NewScoreDate").value == "Today" ) {
			$dateOption = "today";
		} else if ( document.getElementById("NewScoreDate").value == "Yesterday" ) {
			$dateOption =  "yesterday";
		} else {
			$dateOption =  "other";
		}		
		$.get("action-newresult.php", {
			type:document.getElementById("NewScoreType").value,
			score:document.getElementById("NewResultScore").value,
			questions:document.getElementById("NewResultTotal").value,
			date:document.getElementById("NewScoreDate").value,
			dateOption:$dateOption
		},
		function(data){
			if (data > 0) {
				$('#QuizFeed').load('div-quizfeed.php');
				$('#UserRankings').load('div-userrankings.php');
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
					function(response) {  }
				);
				refreshNewPost();
			} else {
				alert("You have already added a result for that quiz today!");
			}
			document.getElementById('LoadingNotifyResult').style.display = 'none';
			enableSite();
		});
	}
	
	function profileHide() {
		$('#QuizFeed').load('div-quizfeed.php');
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
				$('#QuizFeed').load('div-quizfeed.php');
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
	}
	
	// Run long-polling PHP script to refresh quizfeed
	function refreshQuizfeed() {
		$.get("action-refreshquizfeed.php", 
			{pause:window.pause},
			function(data, status) {
				if (data == 1) {
					$('#QuizFeed').load('div-quizfeed.php');
					refreshQuizfeed();		
				}
			}
		);	
	}
	
	