<?php 

$command = escapeshellcmd('/var/www/fetch.py');
$output = shell_exec($command);
echo $output;

?>
