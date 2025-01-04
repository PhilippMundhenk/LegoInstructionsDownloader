<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="main.css">
<title>Lego Manager</title>
</head>
<?php
global $error;
$error=false;

function fetch($set_id) {
	$cmd="./fetch.sh";
	$storage_path="/downloads";
	$cmd=$cmd." \"".$set_id."\""." \"".$storage_path."\"";
	#TODO: Avoid oversize logs!
	exec("$cmd >>log.txt 2>&1", $output, $retval);
	return $retval;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$set_id=$_POST['set_id'];
	
	if ($_POST['action'] == 'download') {
		if(!empty($set_id)){
			if(str_contains($set_id, ",")){
				$set_list = explode(',', $set_id);
				foreach($set_list as $set){
					if(fetch($set) != 0){
						$errorText="ERROR! Download(s) failed! See <a href='/log.php'>log</a> for details";
						$error=true;
						break;
					}
				}
			}
			else
			{
				if(fetch($set_id) != 0){
					$errorText="ERROR! Download(s) failed! See <a href='/log.php'>log</a> for details";
					$error=true;
				}
			}
		} else {
			$errorText="ERROR! No set ID given!";
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
			<div class="subtitle">Enter Set ID to download (separate multiple by comma)</div>
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
	
<br/><br/>
<?php include("list.php"); ?>
</body>
</html>

