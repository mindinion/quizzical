<?php
/**
 * div-userrankings.php
 *
 * Renders the user rankings panel loaded into the SPA via AJAX.
 * Fetches average quiz scores (Daily, Morning, Afternoon types only) for all group members
 * over either the last 7 days (weekly) or 31 days (monthly), determined by the
 * 'rankingstype' cookie set by the client when switching between views.
 *
 * Outputs toggle links for Weekly/Monthly switching, followed by a ranked list of users
 * with their photo, ordinal placing (1st, 2nd, 3rd, Nth), and average percentage score.
 */

	require_once 'dblogin.php';
	require_once 'getsettings.php';

	// Determine the ranking period from the client cookie; default to 7-day (weekly) if not set
	if (!isset($_COOKIE['rankingstype'])) {
		$queryRankings = mysqli_query($db_server, "SELECT user, First_Name AS 'first Name', Last_Name as 'last Name', pic_filename, ROUND(AVG(score / max) * 100, 0) AS average FROM Results INNER JOIN Users ON (Results.user = Users.id) INNER JOIN Memberships ON Memberships.user_id = Users.id WHERE Memberships.group_id = $groupid AND Results.status = 'active' AND (UPPER(type) = 'DAILY' or UPPER(type) = 'MORNING' OR UPPER(type) = 'AFTERNOON') AND Results.date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND DATE_ADD(CURDATE(), INTERVAL 1 DAY) GROUP BY user ORDER BY average DESC;");
	} else if ($_COOKIE['rankingstype'] == 'month') {
		$queryRankings = mysqli_query($db_server, "SELECT user, First_Name AS 'first Name', Last_Name as 'last Name', pic_filename, ROUND(AVG(score / max) * 100, 0) AS average FROM Results INNER JOIN Users ON (Results.user = Users.id) INNER JOIN Memberships ON Memberships.user_id = Users.id WHERE Memberships.group_id = $groupid AND Results.status = 'active' AND (UPPER(type) = 'DAILY' or UPPER(type) = 'MORNING' OR UPPER(type) = 'AFTERNOON') AND Results.date BETWEEN DATE_SUB(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 1 DAY) GROUP BY user ORDER BY average DESC;");
	} else {
		$queryRankings = mysqli_query($db_server, "SELECT user, First_Name AS 'first Name', Last_Name as 'last Name', pic_filename, ROUND(AVG(score / max) * 100, 0) AS average FROM Results INNER JOIN Users ON (Results.user = Users.id) INNER JOIN Memberships ON Memberships.user_id = Users.id WHERE Memberships.group_id = $groupid AND Results.status = 'active' AND (UPPER(type) = 'DAILY' or UPPER(type) = 'MORNING' OR UPPER(type) = 'AFTERNOON') AND Results.date BETWEEN DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND DATE_ADD(CURDATE(), INTERVAL 1 DAY) GROUP BY user ORDER BY average DESC;");
	}
	
?>

				<a href="javascript:switchRanking('week')" class="NoFormatting">
					<div id="RankingsType" <?php if ($_COOKIE['rankingstype'] != 'month') { echo "class='Selected'"; } ?> >
						Weekly
					</div id="RankingsType">
				</a>
			
				<a href="javascript:switchRanking('month')" class="NoFormatting">
					<div id="RankingsType" <?php if ($_COOKIE['rankingstype'] == 'month') { echo "class='Selected'"; } ?> >
						Monthly
					</div id="RankingsType">
				</a>
			
				<br><br><br>
<?php

	$num = mysqli_num_rows($queryRankings);
	$i = 0;
	while ($i < $num) {
		$name = mysql_result($queryRankings,$i,"first name") . " " . mysql_result($queryRankings,$i,"last name");
		$score = mysql_result($queryRankings, $i, "average");
		$placingnum = $i + 1;
		// Build the ordinal suffix (1st, 2nd, 3rd, Nth) from the last digit of the rank number
		$lastdigit = substr($placingnum, -1);
		if ($lastdigit == "1") {
			$placing = $placingnum . "st";
		} else if ($lastdigit == "2") {
			$placing = $placingnum . "nd";
		} else if ($lastdigit == "3") {
			$placing = $placingnum . "rd";
		} else {
			$placing = $placingnum . "th";
		}
		$photo = mysql_result($queryRankings,$i,'pic_filename');
		 
?>
		<div id='UserRanking'>		
			<div id='RankingPhoto'>
				<img src='<?php echo $photo ?>' class='UserLogo'></img>
				<div id='RankingPlace'><?php echo $placing ?></div id='RankingPlace'>
			</div id='RankingPhoto'>
	
			<div id='RankingName'>
				<?php echo $name ?>
				<br>
				<?php echo $score . '%' ?>
			</div id='RankingName'>				
		</div id='UserRanking'>
		<br>
		<?php
		$i++;
	}
		?>
		
