
<?php
	require_once 'dblogin.php';
	require_once 'getsettings.php';
	
	//SESSION_START();	
	$feedItems = $_COOKIE["feedItems"];
	$queryQuizFeed = mysql_query("SELECT Results.id, Users.pic_filename, Users.first_name, Users.last_name, Users.id, QuizFeed.timestamp, Results.date, Results.score, Results.max, Results.type, QuizFeed.comment, QuizFeed.id FROM QuizFeed INNER JOIN Users ON (QuizFeed.user_id = Users.id) INNER JOIN Memberships ON (Memberships.user_id = Users.id) LEFT JOIN Results ON (QuizFeed.result_id = Results.id) WHERE Memberships.group_id = 2 and QuizFeed.status = 'active' ORDER BY QuizFeed.timestamp DESC LIMIT $feedItems;");
	
	$num = mysql_numrows($queryQuizFeed);
	$i = 0;
	while ($i < $num) {
		$photo = mysql_result($queryQuizFeed,$i,"Users.pic_filename");
		$name = mysql_result($queryQuizFeed,$i,"Users.first_name") . " " . mysql_result($queryQuizFeed,$i,"Users.last_name");
		$timestamp = mysql_result($queryQuizFeed,$i,"QuizFeed.timestamp");
		$quizDate = mysql_result($queryQuizFeed,$i,"Results.date");
		$score = mysql_result($queryQuizFeed,$i,"Results.score");
		$max = mysql_result($queryQuizFeed,$i,"Results.max");
		$quizType = mysql_result($queryQuizFeed,$i,"Results.type");
		$comment = mysql_result($queryQuizFeed,$i,"QuizFeed.comment");
		$id = mysql_result($queryQuizFeed,$i,"QuizFeed.id");
		$quizzer = mysql_result($queryQuizFeed,$i,"Users.id");
		$resultId = mysql_result($queryQuizFeed,$i,"Results.id");	
		
		$feedDate = strtotime($timestamp) - $tzDiff;;
		
		
		$today = strtotime(date("y-m-d H:i:s"));
		$yesterday = strtotime("yesterday"); 
		$todayDay = date('d', $today);
		$timenow = date("H:i:s");
		$resultDay = date('d', $feedDate);
		$yesterdayDay = date('d', $yesterday);
		$daysDiff = floor(($today-$feedDate) /60/60/24);
		$hoursDiff = floor(($today-$feedDate) /60/60);
		$minutesDiff = floor(($today-$feedDate) /60);
		$secondsDiff = (floor($today-$feedDate));
		
		//Seconds ago
		if ($secondsDiff < 60) {
			if ($secondsDiff == 1) $when = $secondsDiff . "  second ago";
			else $when = $secondsDiff . "  seconds ago";
		}
		
		// Minutes Ago
		else if ($minutesDiff < 60) {
			if ($minutesDiff == 1) $when = $minutesDiff . "  minute ago";
			else $when = $minutesDiff . "  minutes ago";
		}
		
		// Hours Ago
		else if ($hoursDiff < 24) {
			if ($hoursDiff == 1) $when = $hoursDiff . "  hour ago";
			else $when = $hoursDiff . "  hours ago";
		}
				
		// Today
		else if (($daysDiff< 2) and ($todayDay==$resultDay)) {
			$when = "Today";
		}
		
		// Yesterday
		else if (($daysDiff < 2) and ($resultDay==$yesterdayDay)) {
			$when = "Yesterday";
		}
		
		// Day before yesterday
		else if ($daysDiff < 2) {
			$when = "1 Day Ago";
		}
		
		// Two days or more
		else {
			$when = floor($daysDiff) . " Days Ago";
		}
						
		// Check to see if we need to show a bubble
		if ($quizzer != $userid && $resultId != null && $iPad != 1) {	
				$showBubble = 1;
			} else {	
				$showBubble = 0;
			} 
		?>
			
		<div id="QuizFeedItem">			
			<?php
			if ($showBubble == 1) {	
				?> <div id="QuizFeedInfo"> <?php
			} else {	
				?> <div id="QuizFeedInfo" class="NoBubble"> <?php
			} ?>
				<div id="QuizFeedInfoPhoto">
					<img src="<?php echo $photo ?>" height="50" width="50"></img>
				</div id="QuizFeedInfoPhoto">
					<?php
					if ($showBubble == 1) {	
						?> <div id="QuizFeedInfoText"> <?php
					} else {	
						?> <div id="QuizFeedInfoText" class="NoBubble"> <?php
					} ?>
					<div id="QuizFeedInfoName"><?php echo $name ?>
						<span id="QuizFeedInfoTimestamp"><?php echo $when ?></span id="QuizFeedInfoTimestamp">
						<?php 	
							if ($quizzer == $userid) {
								?> <a href="javascript:deletePost('<?php echo $id ?>');" class="Underline"> Delete </a> <?php
							}
						?>
					</div id="QuizFeedInfoName">
					<?php
					if ($score != null) {
						?> <div id="QuizFeedInfoStatus">Scored <?php echo $score ?>/<?php echo $max ?> in the <?php echo Date("M-j",strtotime( $quizDate) ). " " . $quizType ?> Quiz </div id="QuizFeedInfoStatus">	
					<?php } ?>
					<div id="QuizFeedInfoComment" class="Primary"><?php echo $comment ?></div id="QuizFeedInfoComment">					
					
					<?php
					$queryComments = mysql_query("SELECT Comment.id,first_name, last_name, comment, timestamp FROM Comment INNER JOIN Users ON Comment.user_id = Users.id WHERE quizfeed_id = '$id' ORDER BY timestamp ASC;");
					
					$numJ = mysql_numrows($queryComments);
					$j = 0;
					if ($numJ > 0) echo '<div id="Comments">';
					while ($j < $numJ) {
						$commenter = mysql_result($queryComments,$j,"Users.first_name") . " " . mysql_result($queryComments,$j,"Users.last_name");
						$theComment = mysql_result($queryComments,$j,"Comment.comment");
						$commentid = mysql_result($queryComments,$j,"Comment.id");
						// Check for digs
						?>
						<div id="CommentRow">
						<div id="QuizFeedInfoReplyName"><?php echo $commenter ?></div id="QuizFeedInfoReplyName">	
						<div id="QuizFeedInfoComment" class="OtherUser"><?php echo $theComment;
						$queryDigs = mysql_query("SELECT Digs.id, userid, CONCAT(first_name, ' ', last_name) as name FROM Digs INNER JOIN Users ON Digs.userid = Users.id WHERE Digs.commentid = $commentid and Digs.status = 'active';");
						$digsNum = mysql_numrows($queryDigs);
						$dug = 0;
						$diggers = "";
						if ($digsNum > 0) {
							$k = 0;
							while ($k < $digsNum) {
								if (mysql_result($queryDigs,$k,"userid") == $userid) {
									$diggers = $diggers . "You";
									$dug = 1;
									$digid = mysql_result($queryDigs,$k,"Digs.id");
								}
								else $diggers = $diggers . mysql_result($queryDigs,$k,"name"); 
								if      ($digsNum > 1 && $k == ($digsNum - 2)) $diggers = $diggers . " and ";
								else if ($digsNum > 1 && $k != ($digsNum - 1)) $diggers = $diggers . ", ";
								$k++;
							}
							if ( $digsNum == 1 && $diggers != "You") $diggers = $diggers . " digs this.";
							else $diggers = $diggers . " dig this.";
						}
						?>	
						<div id='DigsComment'>
						<?php
						if ($dug == 0) echo "<div id='DigCommentLink' onclick='digComment($commentid);'>Dig</div>";
						else           echo "<div id='DigCommentLink' onclick='undigPost($digid);'>Undig</div>";
						echo $diggers;
						echo "</div id='DigsComment'>";
						?>
						
						<?php
						?></div id="QuizFeedInfoComment"></div id="CommentRow"><?php
						$j++;
					}
					if ($numJ > 0) echo "</div id='Comments'>";
					$queryDigs = mysql_query("SELECT Digs.id, userid, CONCAT(first_name, ' ', last_name) as name FROM Digs INNER JOIN Users ON Digs.userid = Users.id WHERE Digs.postid = $id and Digs.status = 'active';");
					$digsNum = mysql_numrows($queryDigs);
					$diggers = "";
					$dug = 0;
					if ($digsNum > 0) {
						echo "<div id='Digs'>";
						$k = 0;
						while ($k < $digsNum) {
							if (mysql_result($queryDigs,$k,"userid") == $userid) {
								echo "You";
								$dug = 1;
								$digid = mysql_result($queryDigs,$k,"Digs.id");
								$diggers = "You";
							}
							else echo mysql_result($queryDigs,$k,"name"); 
							if      ($digsNum > 1 && $k == ($digsNum - 2)) echo " and ";
							else if ($digsNum > 1 && $k != ($digsNum - 1)) echo ", ";
							$k++;
						}
						if ( $digsNum == 1 && $diggers != "You") echo " digs this.";
						else echo " dig this.";
						echo "</br></div id='Digs'>";
					}
						?>	
					<span id="QuizFeedInfoReplyLink" onclick="showReplyBox('<?php echo "ReplyBox" . $id?>');"> Comment </span>
					<span id="QuizFeedInfoReplyLink"> | </span>
					<?php 
					if ($dug != 1) echo "<span id='QuizFeedInfoDigLink' onclick='digPost($id);'> Dig</span>"; 
					else echo "<span id='QuizFeedInfoDigLink' onclick='undigPost($digid);'> Undig</span>"; 					
					?>
					<textarea rows="3" class ="QuizFeedInfoReplyInput" onchange="sendComment('<?php echo "ReplyBox" . $id?>',<?php echo $id ?>)" id="<?php echo "ReplyBox" . $id?>"></textarea>
				</div id="QuizFeedInfoText">
			</div id="QuizFeedInfo">
				<?php
				if ($showBubble) {
					$check = mysql_query("SELECT score, max FROM Results WHERE user='$userid' AND type='$quizType' AND DAY(date) = DAY('$quizDate') and MONTH(date) = MONTH('$quizDate') and YEAR(date) = YEAR('$quizDate');");			
					if (mysql_num_rows($check) > 0) {
						$yourScore = mysql_result($check,0,"score");
						$yourMax = mysql_result($check,0,"max");
						?>
						<div id="QuizFeedBubble" class="ScreenHide">
						You got <?php echo $yourScore . " out of " . $yourMax ?> in this quiz
						<br><br>
						<span id="BubbleUnderline" onclick="showReplyBox('<?php echo "ReplyBox" . $id?>');">Comment<span>
						</div id="QuizFeedBubble">
						<?php					
					} else {
						?>
						<div id="QuizFeedBubble">
						You haven't yet done this quiz
						<br><br>
						<a href=http://www.stuff.co.nz/national/quizzes?label=Quizzes target="_blank" style="text-decoration:none;"><span id="BubbleUnderline">Do Now</span></a><br>
						<span id="BubbleUnderline" onclick="showReplyBox('<?php echo "ReplyBox" . $id?>');">Comment<span>
						</div id="QuizFeedBubble">
						<?php					
					}
				}
				
				
				
				
				
				?>
				
				
				
		</div id="QuizFeedItem">
		
		<br><br><br>
		<?php 
		$i++;
	}
	mysql_close($db_server); 
?>
		