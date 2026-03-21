

<?php

	// Log into the database
	require_once 'dblogin.php';
	require_once 'getsettings.php';


?>

<script src="jquery.js"></script>
<script src="main.js"></script>



<!DOCTYPE php>
<html>
	
	<title>Quizzical</title>
	
	<head>
		<link href="styles-main.css?v=<?=time();?>" type="text/css" rel="stylesheet" />
		<link href="styles-profile.css?v=<?=time();?>" type="text/css" rel="stylesheet" />
		<link rel="icon" type="image/png" href="quizzical_logo.png">
	</head>

	<body>
	
	<div id="MainBorder">
	
		<div id="LoadingNotifyResult">
			Submitting Result...<br><br>
			<img src="loading.gif" class="Loading"></img>
		</div id="LoadingNotify">
		
		<div id="LoadingNotifyPost">
			Posting Message...<br><br>
			<img src="loading.gif" class="Loading"></img>
		</div id="LoadingNotify">
		
		<div id="LoadingNotifyComment">
			Posting Comment...<br><br>
			<img src="loading.gif" class="Loading"></img>
		</div id="LoadingNotify">
		
		<div id="DeletingNotifyPost">
			Deleting Post...<br><br>
			<img src="loading.gif" class="Loading"></img>
		</div id="LoadingNotify">
		
		<div id="SavingNotifyProfile">
			Saving Profile...<br><br>
			<img src="loading.gif" class="Loading"></img>
		</div id="LoadingNotify">
		
		<div id="SavingDig">
			Digging...<br><br>
			<img src="loading.gif" class="Loading"></img>
		</div id="LoadingNotify">
		
		
		<div id="GroupPanel">
		
			
			<div id="GroupTitle">
			
			
				<div id="GroupLogoName">
			
					<div id="GroupLogo">
						<img src = "GroupIcon.png" class="GroupLogo"></img>
					</div id="GroupLogo">
				
					<div id="GroupName">
						<?php 
							//session_start();
							echo $_SESSION["groupname"]; 
						?>
						<a href='javascript:showGroups()'> <div id="GroupChange"> Change </div id="GroupChange"></a>
					</div id="GroupName">
				
				</div id="GroupLogoName">
				
			</div id="GroupTitle">
			
			<div id="Groups">
				<?php
					$queryGroups = mysql_query("SELECT name,id FROM Groups;");
					$num = mysql_numrows($queryGroups);
					$i = 0;
					while ($i < $num) {
						$name = mysql_result($queryGroups,$i,"name");
						$id = mysql_result($queryGroups,$i,"id");
						echo "<a href='action-changegroup.php?id=" . $id . "&name=" . $name ."' id='GroupOption' class='NoFormatting'>" . $name . "<a><br>";
						$i++;
					} 
					 					?>
			</div id="Groups">
			
			
			<div id="RankingsTitle">
				Rankings
			</div id="RankingsTitle">
			
			<div id="UserRankings">
				<?php require 'div-userrankings.php' ?>
			</div id="UserRankings">
			
			
		
		</div id="GroupPanel">
		
							
			<span>
			
					<img src="quizzical_logo.png" class="QuizzicalLogo"></img>
				
				<a href=http://www.stuff.co.nz/national/quizzes?label=Quizzes target="_blank" style="text-decoration:none;">
					<img src ="http://upload.wikimedia.org/wikipedia/commons/4/44/Stuff.co.nz_logo.png" class="StuffLogo"></img>
				</a>
			
				<a href="javascript:profileShow();">
				<div id="MenuButton">
					<div id="MenuButtonGraphic">
						<img src = "profileicon.png" class="menu">
					</div id="MenuButtonGraphic">	
					<div id="MenuButtonText">
						Profile
					</div id>
				</div id="MenuButton">
				</a>
			
			
			</span>
		
			
			<a href="action-logout.php">
				<div id="MenuButton" class="right">
					<div id="MenuButtonGraphic">
						<img src = "logouticon.png" class="menu">
					</div id="MenuButtonGraphic">	
					<div id="MenuButtonText">
						Logout
					</div id>
				</div id="MenuButton">
			</a>
		
				
			<div id="MainContent">
			
				<div id="NewPost">		
					<?php require 'div-newpost.php' ?>		
				</div id="NewPost">					
				<div id="QuizFeed" onscroll="expandFeed();">		
					<?php require 'div-quizfeed.php' ?>		
				</div id="QuizFeed">
				
			
			
				</div id="MainPostPaneL">
		
			</div id="MainContent">
					

			
	</div id="MainBorder">
												

			