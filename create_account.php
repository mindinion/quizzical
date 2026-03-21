<?php
/**
 * create_account.php
 *
 * Renders the account creation form for the traditional (non-AJAX) login flow.
 * On submission, POSTs to action-createaccount.php.
 * Displays an error message if mode=1 is in the query string, which indicates the
 * email address submitted is already registered.
 */
?>
<html>

	<head>
		<title>Quizzical</title>
		<link href="styles-login.css" type="text/css" rel="stylesheet" />
		<link rel="icon" type="image/png" href="quizzical_logo.png">
	</head>

	<body>
	<div id="newaccountblock">
		<br>
		<br> 
		<div id="center">
			<span id="TitleText"z>
				<img src="quizzical_logo.png" id="logo"></img>
				u i z z i c a l
			</span>
		</div>
		<form method="post" action="action-createaccount.php">
			<fieldset class="largeform">
				<h3 id="title">Create Account</h3>
				<br>	
				
				<label for="firstname" class="title">First Name:</label>
				<input type="text"; class = "field" name="firstname"><br>
				<br>
				
				<label for="lastname" class="title">Last Name:</label>
				<input type="text"; class = "field" name="lastname"><br>
				<br>
				
				<label for="email" class="title">Email:</label>
				<input type="text"; class = "field" name="email"><br>
				<br>
				
				<label for="password" class="title">Password:</label>
				<input type="password"; class = "field" name="password"><br>
				<br>
				<div id="title">
					<input class="submit" type="submit" value="Create Account" id="newaccountbutton"><br><br>
				</div>	
				<div id="title">
					<?php 
						if (isset($_GET['mode'])) $mode = $_GET['mode'];
						else $mode = 0;
						if ($mode == 1) echo "Username already exists";
					?>
				</div>
				<br><br>
				<div id="title">
					Site Designed by <a href="mailto:widdakay@gmail.com">Kris Weyling</a> 2013
				</div>
				
			</fieldset>
		</form>
	</div>
	</body>
	
</html>