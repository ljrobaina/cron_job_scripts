<?php

/**
 * Automatic sript to delete a web app from the server when a client cancel subcription.
 */

echo "\nCront Started on: ".date("Y-m-d H:i:s")."\n";

$thisFile = __FILE__;
$thisFileToMatch = basename(__FILE__);
$thisFile .= ".running";

if(file_exists($thisFile)){
	$strFile     = file_get_contents($thisFile);
	$arrFileData = json_decode(trim($strFile),true);
	$a           = `ps -ef | grep $thisFileToMatch`;
	preg_match("!".$arrFileData['pid'].".*?php.*?$thisFileToMatch!i",$a,$arrMatch);
	
	// Is Script in Waiting Mode
	$timeDiff = time() - strtotime($arrFileData['started_on']);	
	$isHang   = (($timeDiff/3600) >24)?true:false;
	
	if($isHang && !empty($arrFileData['pid'])){
		$kill = "kill -9 ".$arrFileData['pid'];
		`$kill`;
	}
	if((0 < count($arrMatch) && "" != $arrFileData['pid']) && !$isHang){
		die("One instance of script is already running.\n".$thisFile);
		exit;
	}
}

$fd = fopen($thisFile,"w");
$arrFileData = array('pid'=>getmypid(),'started_on'=>date("Y-m-d H:i:s"));
fwrite($fd,json_encode($arrFileData));
fclose($fd);

require_once '../libs/cron_sql_connect.php';

class RemoveSite{
	
	function execute(){
		
		$serverId = php_uname("n");
		$serverId = explode(".",$serverId);
		$serverId = trim($serverId[0]);
		$sqlWhere = "";
		
		if(in_array($serverId,array("srv1166","srv1081"))){
			$sqlWhere = " AND db_server='".$serverId."'";
		}
		
		// Get all deleted sites from master admin DB. 
		$domainCmd = mysql_query("SELECT * FROM psl_deleted_domains d WHERE d.deleted = 0 ".$sqlWhere);
		
		if($domainCmd){
			
			echo "\nTotal Sites:".mysql_num_rows($domainCmd)."\n==========================\n";
			$cntdel=0;
			$currentbasepath=dirname(__FILE__);
			
			while($domain = mysql_fetch_assoc($domainCmd)){
				
				// Backup DB
				$domain_database 	= $domain['db_name'];
				$domain_host 		= $domain['db_host'];
				$domain_user		= $domain['db_username'];
				$domain_pass 		= $domain['db_password'];
				$sitepath 			= $domain['site_path'];
				$tmpdomain          = $domain['site_url'];	
				$input              = trim($tmpdomain, '/');
				
				// If scheme not included, prepend it
				if (!preg_match('#^http(s)?://#', $tmpdomain)) {
					$input = 'http://' . $tmpdomain;
				}
				
				$urlParts = parse_url($tmpdomain);
				
				// Remove www
				$domainname = preg_replace('/^www\./', '', $urlParts['host']);

				// Dump the Database for Installer
				$backupPath  = $currentbasepath.'/deleted_domain/'.$domainname.'_'.$domain['domain_id'].'/';

				if(!file_exists($backupPath) && !is_dir($backupPath)){
					mkdir($backupPath,0777,true);
				}
				
				$sqlFile = $backupPath. $domain_database.'.sql.gz';
				exec( 'mysqldump --skip-comments --host="127.0.0.1" --user="root" --password="root" '.$domain_database.' | gzip -c > "'.$sqlFile.'"');
				
				// Back Up Upload folder
				$arrDirOwners = getUserAndGroup($sitepath);
				RecursiveCopy($sitepath.'app/webroot/uploads/'/*Source dir*/, $backupPath.'uploads'/*Destination dir*/, $arrDirOwners /*Source dir owner*/, $arrIgnoerList = array("themes"));
				
				// Delete Database
				if(substr($domain_database,0,4)=='psl_'){
					exec('mysqladmin -f -h 127.0.0.1 -u root -root drop '.$domain_database.' ');
				}

				// Delete DB User 
				## DO NOT DELETE ROOT USER root 
				if($domain['db_username']!='root'){
					$strDopUser_1 = 'DROP USER "'.$domain['db_username'].'"@"localhost"';
					mysql_query($strDopUser_1);
				}
				
				// Delete Site data from de app server
				if(false !== strpos($sitepath,"home/vhosts/") && false !== strpos($sitepath,"/httpdocs/")){
					$cmdRmove = "rm -f ".$sitepath."/.htaccess";
					`$cmdRmove`;
					$cmdRmove = "rm -f ".$sitepath."/index.php";
					`$cmdRmove`;					
					$cmdRmove = "rm -rf ".$sitepath."/lib";
					`$cmdRmove`;
					$cmdRmove = "rm -rf ".$sitepath."/database";
					`$cmdRmove`;
					$cmdRmove = "rm -rf ".$sitepath."/app";
					`$cmdRmove`;
					$cmdRmove = "rm -rf ".$sitepath."/plugins";
					`$cmdRmove`;
					$cmdRmove = "rm -rf ".$sitepath."/cronscript";
					`$cmdRmove`;
				}

				if(mysql_query("UPDATE `psl_deleted_domains` SET `deleted` = '".time()."' WHERE `psl_deleted_domains`.`id` = ".$domain['id'])){
					$cntdel+=1;
				}
				
				// Add deafult page on site
				RecursiveCopy($currentbasepath."/SiteNotAvilable/"/*Source dir*/, $sitepath/*Destination dir*/, $arrDirOwners /*Source dir owner*/);
			}
			// Show how many sites was deleted.
			echo $cntdel." domains deleted permanently";
		}
	}
}
// Create an instance of the class and call executive method.
$RemoveSite = new RemoveSite();
$RemoveSite->execute();

//$thisFile .= ".running";
$rm = "rm -f ".$thisFile;
`$rm`;
echo "\nCront Ended on: ".date("Y-m-d H:i:s")."\n";

/**
 * RecursiveCopy: Copy files recursive in to a directory
 * @param [type] $source        [files do you want to copy]
 * @param [type] $dest          [destination of the files in teh server]
 * @param [type] $arrDirOwners  [Group and Owner of the directory]
 * @param array  $arrIgnoerList [description]
 */
function RecursiveCopy($source, $dest, $arrDirOwners, $arrIgnoerList=array()){
    
    $sourceHandle = opendir($source);
    
    while($strDirOrFile = readdir($sourceHandle)){
    
        if($strDirOrFile == '.' || $strDirOrFile == '..' || in_array($strDirOrFile,$arrIgnoerList))
            continue;
    
        if(is_dir($source . '/' . $strDirOrFile)){
            
            RecursiveCopy($source . '/' . $strDirOrFile, $dest. '/' . $strDirOrFile, $arrDirOwners);
			
			$checkDir=$dest. '/' . $strDirOrFile;
			
			if(is_dir($checkDir)){
			
				if($arrDirOwners["user"] && $arrDirOwners["group"]){
					$chown = "chown -R ".$arrDirOwners["user"].":".$arrDirOwners["group"]." ".$checkDir;
				}else{
					$chown = "chown apache:apache ".$checkDir;
				}
				`$chown`;
			}

		} else {
			
			$filename = trim($dest ."/". $strDirOrFile);
			
			$checkDir = dirname($dest ."/". $strDirOrFile);
			
			if(!is_dir($checkDir)){
				mkdir($checkDir,0755,true);
				if($arrDirOwners["user"] && $arrDirOwners["group"]){
					$chown = "chown -R ".$arrDirOwners["user"].":".$arrDirOwners["group"]." ".$checkDir;
				}else{
					$chown = "chown apache:apache ".$checkDir;
				}
				`$chown`;
			}
			
			$currFileName = strtolower(basename($source));
			
			$cmdCP = "cp -f ".$source . "/" . $strDirOrFile." ".$dest ."/". $strDirOrFile;
			`$cmdCP`;
			
			if(is_dir($dest)){
				if($arrDirOwners["user"] && $arrDirOwners["group"]){
					$chown = "chown -R ".$arrDirOwners["user"].":".$arrDirOwners["group"]." ".$dest;
				}
				`$chown`;
			}
        }
    }
}

/**
 * [getUserAndGroup description]
 * @param  string $dirName [description]
 * @return [type]          [description]
 */
function getUserAndGroup($dirName=""){
	
	if(empty($dirName)){
		return false;
	}
	
	if(!is_dir($dirName)){
		return false;
	}
	
	$cmd_ls = "ls -ld ".$dirName;
	
	$cmd_ls_out = `$cmd_ls`;
	
	$cmd_ls_out = preg_replace("![\n\r\t ]+!"," ",$cmd_ls_out);
	
	$arrOut = explode(" ",$cmd_ls_out);
	
	return array("user"=>$arrOut[2],"group"=>$arrOut[3]);
}