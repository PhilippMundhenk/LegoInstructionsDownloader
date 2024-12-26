<?php 

$command = escapeshellcmd('python /var/www/fetch.py');
$output = shell_exec($command);
echo $output;

?>
