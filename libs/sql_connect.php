<?php
if(count($argv)>1){
	$host 		= isset($argv[1])?$argv[1]:'';
	$database 	= isset($argv[2])?$argv[2]:'';
	$username 	= isset($argv[3])?$argv[3]:'';
	$password 	= isset($argv[4])?$argv[4]:'';
	$connection = mysql_connect($host, $username, $password) or die ("Could not connect to " . $host . " as " . $username);
	$sql = 'USE ' . $database;
	$result = mysql_query($sql)	or die("Could not select database " . $database);
	echo "Connection success...\n".date('Y-m-d H:i:s');
}
?>
