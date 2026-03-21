<?php

	//If the login is stored in the local cookie, then redirect to the main site
	if(isset($_COOKIE['session'])) {
		header( "Location: main.php");
	}; 
	
		
		
	
?>
	
<html>
	<head>
		<title>Quizzical</title>
		<link href="styles-login.css" type="text/css" rel="stylesheet" />
		<link rel="icon" type="image/png" href="quizzical_logo.png">
	</head>
	
	<div id="loginblock">
		<br>
		<div id="center">
			<span id="TitleText"z>
				<img src="quizzical_logo.png" id="logo"></img>
				u i z z i c a l
			</span>
			<br><br>
			<a href="create_account.php">Create Account?</a>
			<br><br>
			Login Details
		</div>
		<form method="post" action="login.php">
			<fieldset class="largeform">
				<br>
				<label for="email" class="title">Email</label>
				<input type="text"; class = "field" name="email"> <br>
				<br>
				<label for="password" class="title">Password</label>
				<input type="password"; class = "field" name="userpass" value="">
			</fieldset>	
			
			<div id="center">
				<input class="submit" type="submit" value="Login" id="loginbutton">
				<br><br><br><br> 
				<a href="forgotpassword.php">Forgot Password?</a>
				<br>
				<?php 
					if (isset($_GET['mode'])) $mode = $_GET['mode'];
					else $mode = 0;
					if ($mode == 1) echo "Incorrect Password";
					if ($mode == 2) echo "No such email";
					if ($mode == 3) echo "Account created, you may now log in";
					if ($mode == 4) echo "Check your email for your password";
				?>	
			</div>
		</form>
		<div id="title">
			Site Designed by <a href="mailto:widdakay@gmail.com">Kris Weyling</a> 2013<br>
		</div>
		</div id="loginblock">
		
		<!--	
		<div id="disclaimer">
			<h1> Update History </h1>
			Any suggestions/comments please provide in the messages section of the "Add Result" Page<br>
			<br>
			<b>Latest Changes: 23 February, 2014</b><br> 
			- Pepe identified a problem with timezones, where the timestamp was wrong for different timezones, changed it to a profile setting<br>
			- Completed the adding of new quizzes. They are added with JavaScript into an array, then when the user chooses that array is saved into the database<br>
			- Improved the adding of new quizzes so instead of the ugly alerts when missing questions details the box is outlined red. Added the list of quizzes that shows each quiz, how many questions and how many times it's been taken. When the quiz is clicked the quiz is loaded into a javascript array of objects and the page to take the quiz loads. You can't go any further than this, is seemed like a logical place to stop for the weekend.<br>
			- Quizzes are complete, in function. The result gets written to the DB and appears in the tickertape, now shows icon indicating the type of quiz. 
			<br>
			<b>Historical Changes</b><br> 
			Made a good start on adding quizzes:<br>
			Did a lot on creating quizzes. You can now specify a quiz name with tags and create questions with four possible choices, each of which with a correct choice. All the questions are added into a JS array, still need to actually write it to the database when they're done
			  - Clicking on Quizzes/New Result in menu switches the middle panel between 'New Result' and 'Quizzes' <br>
			  - Click on 'Add New Quiz' to create a new quiz <br>
			  - Provide the quiz title and tags, and click to add the questions <br>
			This is as far as it goes. There's no transactions with the database and nothing is retained. All of the above uses AJAX with javascript logic to avoid frame reloads<br>
			- Fixed problem with Join Groups not showing any groups<br>
			- Removed overall averages and replaced with monthly averages<br>
			- When you add a result it's added for all groups you're in<br>
			- Groups are completely finished. You can now switch between groups you belong too on the main nav bar</br>
			- Changed nav bar to accomodate new groups selector, changed it so the navbar is a php script called by all interface files, rather than having the same code in all of them<br>
			- You can create new groups, by going to your profile. Click on the + to join other groups, then click on 'Create New Group'. Any groups you create you automatically join<br>
			- More progress on groups. When a user creates account they're automatically in the public group. A user can also go into the profile, click on the plus beside their groups and join groups, providing a password if necesssary<br>
			- Made a start on group management. When a user creates an account they're automatically put into the public group. They can go into their profile settings to change what group they're automatically logged into (currently this is the only group they have access to). Started the ability to subscribe to new groups. They can click on the plus icon to bring up a list of groups they're not in, so they can add them (with the password if required by the group). This is unfinished. Users cannot yet switch between groups or create memberships, all they can do is change their default groups if they're signed into one or more groups, which everyone is (Public and Quiz People)<br>
			- Pressing enter when typing a message should submit it, not line feed<br>
			- Fixed problem with wrong photo appearing in ranking list<br>
			- Implemented new layout and navigation to profile sceeen<br>
			- Completely changed interface. Now has navigation bar at top and changes to layout<br>
			- Made Some of the new result fields bigger<br>
			- Had to make the photos smaller to work for smaller screens, so now the last 11 results can be displayed<br>
			- Made the login/new account and forgot password screens more attractive and keeping with the theme<br>
			- More general layout changes<br>
			- The date field should only show when 'Other' is clicked, and disappear when it isn't<br>
			- Added groups functionality. Every user is in a group, Public by default. Every group has a group code, which they enter into their profile to join that group. When you post a message or result, you post it for that group, so if you leave, the message remains, and if you join a group they don't come with you, they just save for that group from now on.<br>
			- Rankings table is now scrollable<br>
			- New result panel is now vertically centered<br>
			- Designed official Quizzical logo<br>
			- Placed logo with navigation icons in middle panel, along with name of group<br>
			- Put message box above messages so it flows more logically<br>
			- Added 'dig' mechanism. You can click to dig a message. The poster gets emailed telling them, and below the messages it states the people that have digged that message. This can be clicked if you've digged that message, and this undigs it<br>
			- Messages now show in a scrollable area<br>
			- Messages refresh every 5 seconds<br>
			- Results and rankings refresh every minute<br>
			- Completely changed the sites look and feel<br>
			- Ditched the table layout, and went with a more CSS-oriented layout<br>
			- Made the site more tablet friendly, if you access it on an ipad, it will reduce the number of results it shows up top, and it won't show the final column in the rankings table<br>
			- Changing the below revealed that every message was being recorded as being posted on the 7th minute of the hour. Fixed that<br>
			- Changed the way the time/date of messages appears, so it doesn't murder the eyes. Bit easier to read/understand<br>
			- Added column to rankings to show differential between this weeks average and last weeks average<br>
			- Added column to rankings to show results from last week, the rankings are ordered by this rather than overall<br>
			- Notification emails are no longer sent to the person that posted the message/result<br>
			- Notification emails now state who posted the message/result<br>
			- Profile Management: Upload a new profile pic, email notifications, number of results up top, number of messages to display<br>		
			- Ability to delete results<br>
			- Get it hosted on a proper domain<br>
			- Changed upper results bar to include photos<br>
			- Added number of results to rankings table<br>
			- Messages should now appear in the right order <br>
			- Quizzes can now be entered for today, yesterday or any day in the past<br>
			- Everybody now has a static photo, shows with rankings<br>
			- Small UI enhancements. Ditched title, increased white-space to make it easier on the eyes, etc<br>
			- Changed adding results so you can only enter one result for a type of quiz a day, to avoid the double clicking problem<br>
			- Changed results bar so the result date is more descriptive<br>
			- Changed login and creating an account. You now login with an email address and password<br>
			- Added forgot password feature. You provide your email address and your password is emailed to you<br>
			- Completely re-did main interface to fit on one page<br>
			- Results should now show in the right order<br> 
			- Date is now local, not sure how that works for you aussies<br> 
			- I moved the messaging to the add result page, seems more fitting<br> 
			- Deleted test data, including results that didn't look right. Let me know if I got rid of any real results or missed any pretend ones<br> 
			- Clicking the link to open the quiz page now opens in a new tab<br> 
			- Added link to stuffs list of quizzes on 'Add Result' page<br> 
			- Added list of everyones results for the last week to the 'Add Result' page<br> 
			- Users can now delete their own messages<br> 
			- Users can now post messages up to 1000 characters and with apostrophes<br>
			- Messages are now listed in the order they're posted (unfortunately since the database is being hosted in America, it's currently American time)<br>
			<br>
			<b>Upcoming Changes</b><br> 
			- Alternative tab for taking quizzes (next version)<br>
		</div>
		-->
		
		
		
	</body>
	
</html>