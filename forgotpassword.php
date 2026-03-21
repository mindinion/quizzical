<?php
/**
 * forgotpassword.php
 *
 * Renders the "Forgot Password" page. Displays a form that accepts the user's email address
 * and POSTs it to action-forgotpassword.php, which generates and emails a new password.
 * Displays "Incorrect Details" if mode=1 is present in the query string (set on failure
 * by action-forgotpassword.php in a former version — the current action always redirects).
 */
?>
<html>

	<head>
		<title>Quiz Quantification Quotient</title>
		<link href="styles-login.css" type="text/css" rel="stylesheet" />
	</head>

	<body>
	<div id="forgotpasswordblock">
		<br>
		<br> 
		<div id="center">
			<span id="TitleText"z>
				<img src="quizzical_logo.png" id="logo"></img>
				u i z z i c a l
			</span>
		</div>
		
		<br>
		<div id="title">
			<br><br<br>
			<h3>Forgot Password</h3>
			<br>
			<i>Please enter your email address</i>
		</div>
		<br>
		<p> 
			<form method="post" action="action-forgotpassword.php">
				<fieldset class="largeform">				
					<label for="email" class="title">Email:</label>
					<input type="text"; class = "field" name="email"><br>
					<br>
										<?php 
						if (isset($_GET['mode'])) $mode = $_GET['mode'];
						else $mode = 0;
						if ($mode == 1) echo "Incorrect Details";
					?>	
				</fieldset>
				<div id="center"> 
					<input class="submit" type="submit" value="Forgot Password" id="forgotpasswordbutton"><br><br>
				</div>
			</form>
		<div id="title">
			<br>
			Site Designed by <a href="mailto:widdakay@gmail.com"> Kris Weyling</a> 2013<br> 
		</div>
	</body>
	
</html>