<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="main.css">
<title>Lego Manager</title>
</head>
<?php
$storage_path="/downloads";
global $error;
$error=false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$set_id=$_POST['set_id'];
	$cmd="./fetch.sh";
	if(!empty($set_id)){
		$cmd=$cmd." \"".$set_id."\""." \"".$storage_path."\""; 
	} else {
		$errorText="ERROR! No set ID given!";
		$error=true;
	}

	if ($_POST['action'] == 'download') {
		exec("$cmd >log.txt 2>&1", $output, $retval);
		if($retval != 0) {
			$errorText="ERROR! Download(s) failed! See <a href='/log.php'>log</a> for details";
			$error=true;
		}
	}
}	
?>
<body>
	<div class="form">
		<?php
			if($error) {
		?>
			<div class="title" style="margin-bottom:30px"><?php print($errorText); ?></div>
		<?php 
			} 
			else {
		?>
			<div class="title">Lego Manager</div>
			<div class="subtitle">Enter Set ID to download</div>
			<div class="cut cut-long"></div>
			<form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
	    			<div class="input-container ic1">
	      				<input class="input" type="text" id="set_id" name="set_id" onload='this.click();' value="<?php print($set_id); ?>" placeholder="" />
					<div class="cut"></div>
		      			<label for="set_id" class="placeholder">Set ID</label>
	    			</div>
				<div class="cut cut-short"></div>
				<button type="submit" name="action" value="download" class="submit">Download</button>
			</form>
		<?php } ?>
	</div>
</body>
</html>

