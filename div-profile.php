<?php
session_start();
	
	require_once 'dblogin.php';
	require_once 'security.php';
	require_once 'getsettings.php';
	
		$queryDefaultGroup = mysqli_query($db_server, "SELECT group_code FROM Users INNER JOIN Groups ON (Users.default_group = Groups.id) WHERE Users.id = $userid;");
	$queryMemberships = mysqli_query($db_server, "SELECT group_code FROM Users INNER JOIN Memberships ON (Users.id = Memberships.user_id) INNER JOIN Groups ON (Memberships.group_id = Groups.id) WHERE Users.id = $userid;"); 
	$defaultGroup = mysql_result($queryDefaultGroup,0,"group_code");
	
	
?>
	
<html>
	<head>
		<title>Quizzical</title>
		<link rel="icon" type="image/png" href="quizzical_logo.png">
	</head>
	
	<div id="Title">Your Profile</id><br><br><br>
		<form method="post" action="action-saveprofile.php" enctype="multipart/form-data">
			<fieldset class="settings">
				<br>
				<label for="newemail" class="titlelong">Email Address</label>
				<input type="text" class = "fieldlong" name="newemail" id="email" value=<?php echo $email ?>> <br> 
				<br>
				<label for="newfirstname" class="titlelong">First Name</label>
				<input type="text" class = "fieldlong" name="newfirstname" id="namefirst" value=<?php echo $nameFirst ?>> <br>
				<br>
				<label for="newlastname" class="titlelong">Last Name</label>
				<input type="text" class = "fieldlong" name="newlastname" id="namelast" value=<?php echo $nameLast ?>> <br>
				<br>
				<label for="passwordA" class="titlelong">New Password</label>
				<input type="password" class = "fieldlong" name="passwordA" id="PassA"><br>
				<br>
				<label for="passwordB" class="titlelong">Confirm New Password</label>
				<input type="password" class = "fieldlong" name="passwordB" id="PassB"><br><br>
				<label for="defaultgroup" class="titlelong">Default Group</label>
				<select size="2"; name = "defaultgroup" class = "listlong" id="defaultgroup" >
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
				<br><br>
				<br><br>
				<br>
				<label for="notifyresults" class="titlelong">Email Results Notifications</label>
				<input type="checkbox" class = "fieldlong" id="notifyresult" name="notifyresults" value="1" <?php if ($notifyResults==1) echo "checked" ?>> <br>
				<br>
				<label for="notifymessages" class="titlelong">Email Message Notifications</label>
				<input type="checkbox" class = "fieldlong" id="notifymessage" name="notifymessages" value="1" <?php if ($notifyMessages==1) echo "checked" ?>><br>
				<br>
				<label for="timezone" class="titlelong">Timezone</label>
				<select name="timezone" class ="fieldlong" id="timezone" onchange="">
					<option <?php if ($timezone=="NZ") echo 'selected'?> value="NZ">New Zealand</option>
					<option <?php if ($timezone=="Australia/Melbourne") echo 'selected'?> value="Australia/Melbourne">Melbourne</option>
					<option <?php if ($timezone=="Australia/Brisbane") echo 'selected'?> value="Australia/Brisbane">Brisbane</option>
				</select>
				<br>
			</fieldset>
			<div style="text-align:center">
			<b>Upload Profile Photo</b><br>
			<input type="file" name="profilepic"> <br><br>
			<input class="submit" type="submit" value="Save Changes" id="ButtonSave" >

	</div>
		</form>
	<br><br>	
	
	
	<input class="submit" type="button" value="Cancel" id="ButtonCancel" onclick="script:profileHide();">

</html>