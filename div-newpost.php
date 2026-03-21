<span>
	<input type="number" name="score" id="NewResultScore" min="0">

	<script>
		//window.pause = 5;
		//refreshQuizfeed();

		// If a key is pressed, show the extra result elements if the result isn't empty, otherwise hide them
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
		document.getElementById("NewResultScore").addEventListener("change", function(){
		if (document.activeElement.value != undefined) {
				document.getElementById('NewScoreLabelType').style.display = 'block';
				document.getElementById('NewScoreType').style.display = 'block';
				document.getElementById('NewScoreLabelWhen').style.display = 'block';
				document.getElementById('NewScoreDate').style.display = 'block';
				document.getElementById('NewResultSubmit').style.display = 'block';
				document.getElementById('NewCommentTextArea').style.display = 'none';
				document.getElementById('NewCommentSubmit').style.display = 'none';
				document.getElementById('QuizFeed').style.top = '70px';

			}
		}, false);

		refreshNewPost(<?php echo $userid ?>);

	</script>
	
	<div id="NewScoreLabelScore"><div id = "MiddleText" >Out Of</div></div>
	
	<input type="number" name="questions" id ="NewResultTotal" value=15 min="0">
	<div id="NewScoreLabelType">Type</div>
	<select name="type" id="NewScoreType">
		<option value="Morning">Morning</option>
		<option value="Afternoon">Afternoon</option>
		<option value="Daily">Daily</option>
		<option value="Biz">Biz</option>
		<option value="Sports">Sports</option>
		<option value="Kids">Kids</option>
		<option value="Other">Other</option>
	</select>
	<div id="NewScoreLabelWhen">When</div>
	<select name="date" id="NewScoreDate">
		<option value="Today">Today</option>
		<option value="Yesterday">Yesterday</option>
		
		<?php
		$days = 2;
		while ($days <= 14) {
			$aDate = date('Y-m-d',strtotime("-" . $days . " days"));
			$aDateDisplay = date ('l, M-d', strtotime("-" . $days . " days")) ;
			?> <option value="<?php echo $aDate ?>"><?php echo $aDateDisplay ?></option> <?php
			$days++;
		}
		?>
	</select>
	<div id="NewResultSubmit" onclick="addResult();">Post</div>

	
	
	<div id="NewCommentBox">
		<textarea class ="NewComment" placeholder="Or, post a new comment here..." id="NewCommentTextArea"></textarea>
		<script>
			document.getElementById("NewCommentTextArea").addEventListener("keyup", function(){ 
				if (document.activeElement.value != "") {
					document.getElementById('NewCommentSubmit').style.display = 'block';
					document.getElementById('QuizFeed').style.top = '100px';
				} else {
					document.getElementById('NewCommentSubmit').style.display = 'none';
					document.getElementById('QuizFeed').style.top = '70px';
			}
			});

		</script>
	</div>
	<br><br><br><br>
	<div id="NewCommentSubmit" onclick="postComment();">Add Comment</div>
	<br>
</span>