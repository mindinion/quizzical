<?php
/**
 * profile.php
 *
 * Standalone server-rendered profile edit page (not part of the AJAX SPA flow).
 * Fetches the user's current default group and all group memberships to populate
 * the group selector. On form submission, POSTs to saveprofile.php.
 * Displays a "Passwords do not match" error if mode=1 is in the query string,
 * which saveprofile.php sets when the two password fields differ.
 *
 * This is functionally equivalent to backup_profile.php — both appear to be
 * older server-rendered alternatives to the div-profile.php AJAX panel.
 */

session_start();

	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';

	// Fetch the user's current default group code for pre-selection in the group dropdown
	$queryDefaultGroup = $conn->query("SELECT group_code FROM Users INNER JOIN Groups ON (Users.default_group = Groups.id) WHERE Users.id = $userid;");
	// Fetch all group memberships to populate the full group selector
	$queryMemberships = $conn->query("SELECT group_code FROM Users INNER JOIN Memberships ON (Users.id = Memberships.user_id) INNER JOIN Groups ON (Memberships.group_id = Groups.id) WHERE Users.id = $userid;");
	$defaultGroup = mysql_result($queryDefaultGroup,0,"group_code");
	
	
?>
	
<html>
	<head>
		<title>Quizzical</title>
		<link href="styles-profile.css" type="text/css" rel="stylesheet" />
		<link rel="icon" type="image/png" href="quizzical_logo.png">
	</head>
	
	<div id="mainborder">
		<form method="post" action="saveprofile.php" enctype="multipart/form-data">
			<br>
			<?php 
				if (isset($_GET['mode']) and (isset($_GET['mode'])) == "1") {
					echo "<h4 style=\"text-align:center; color:red \">Passwords do not match<h4>";
				}
			?>
			
			<table style="width:70%; margin:auto">
				<tr>
					<td>	
						<fieldset class="profiledetails">
							<legend>Account Details</legend>
							<br>
							<label for="newemail" class="titlelong">Email Address</label>
							<input type="text"; class = "fieldlong" name="newemail" value=<?php echo $email ?>> <br> 
							<br>
							<label for="newfirstname" class="titlelong">First Name</label>
							<input type="text"; class = "fieldlong" name="newfirstname" value=<?php echo $nameFirst ?>> <br>
							<br>
							<label for="newlastname" class="titlelong">Last Name</label>
							<input type="text"; class = "fieldlong" name="newlastname" value=<?php echo $nameLast ?>> <br>
							<br>
							<label for="passwordA" class="titlelong">New Password</label>
							<input type="password"; class = "fieldlong" name="passwordA"><br>
							<br>
							<label for="passwordB" class="titlelong">Confirm New Password</label>
							<input type="password"; class = "fieldlong" name="passwordB"><br><br>
						</fieldset>
					</td>
					<td>
						<fieldset class="profilesettings">
							<legend>Settings</legend>
							<br>
							<label for="defaultgroup" class="titleshort">Default Group</label>
							<select size="2"; name = "defaultgroup"; class = "listlong">
								<option selected><?php echo $defaultGroup;
								$i = 0;
								$num = mysqli_num_rows($queryMemberships);
								while ($i < $num) {	
									$group = mysql_result($queryMemberships,$i,"group_code");
									if ($group != $defaultGroup) {
										echo "<option>". $group;
									}
									$i++;
								} 
								 								?>
							</select>
							<a href="add_group.php"><img src="add.png" id="add"></img></a>
							<br>
							<br>
							<label for="nummessages" class="titleshort">Number of Messages to Show</label>
							<input type="number"; class = "fieldshort" name="nummessages" min="1" value=<?php echo $numMessages ?>> <br>
							<br>
							<label for="notifyresults" class="titleshort">Email Results Notifications</label>
							<input type="checkbox"; class = "fieldtiny" name="notifyresults" value="1" <?php if ($notifyResults==1) echo "checked" ?> <br>
							<br>
							<label for="notifymessages" class="titleshort">Email Message Notifications</label>
							<input type="checkbox"; class = "fieldtiny" name="notifymessages" value="1" <?php if ($notifyMessages==1) echo "checked" ?><br>
							<br>
							<label for="timezone" class="titleshort">Timezone</label>
							<select name="timezone"; class ="timezones" onchange="">
								<option <?php if ($timezone=="NZ") echo 'selected'?> value="NZ">New Zealand</option>
								<option <?php if ($timezone=="Australia/Melbourne") echo 'selected'?> value="Australia/Melbourne">Melbourne</option>
								<option <?php if ($timezone=="Australia/Brisbane") echo 'selected'?> value="Australia/Brisbane">Brisbane</option>
							</select>
							<br>
						</fieldset>
					</td>
				</tr>
			</table>
				
			<div style="text-align:center">
				<b>Upload Profile Photo</b><br>
				<input type="file" class = "fieldlong" name="profilepic" id="profilepic"> <br><br>
				<input class="submit" type="submit" value="Save Changes" id="orangebutton">
			</div>
		</form>
	</div>
	
</html>