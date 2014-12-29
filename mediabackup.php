#!/usr/bin/php
<?php
#Author: Adam Knapp
#Created: 12/25/2013
#Last Updated: 12/27/2014
#mediabackup.php
#
#A program designed to backup up media files such as photos and videos to two locations:
# 1.) a local NAS
# 2.) offsite - now, the only supported offsite feature is GoogleDrive. 
#
# GoogleDrive makes this extremly easy with the native app you can install
#
#
# to run: php mediabackup.php
# Run nightly in crontab -e
# current cron setting:
# */5 * * * * /usr/bin/php /Users/KnappleMacMini/Documents/code/mediabackup.php  - every 5 minutes
# 5 0 * * * /usr/bin/php /Users/KnappleMacMini/Documents/code/mediabackup.php - once a night kickoff


//1200 per hour
$G_IPROCESSTARGET = 2000;
$G_I_ERRORED = 0;
$G_I_SKIPPED = 0;
$G_I_PROCESSED = 0;

if(!date_default_timezone_set('America/Phoenix')) {
   echo "\nDUDE! Bad timezone!";
   exit;
}

define('LOG', '/Users/KnappleMacMini/Documents/code/mediabackup.log');


// file locations
define('HOME', '/Users/KnappleMacMini/');
//TODO Make sure we get all errors
define('IPHONE_DUMP', 'Pictures/IPhoneDump');
//define('IPHONE_DUMP', 'Pictures/IPhoneDumpJordan');

define('NAS_MEDIA_HOME', '/Volumes/Volume_1/media');
define('GOOGLEDRIVEHOME','/Users/KnappleMacMini/Google Drive/Home/media'); 
define('XATTR', 'backupstatus');


function logOutput($in_strOutput, $in_bNewLine = true)
{
   // Open file to write
   $strTime = date("Y-m-d H:i:s");
   $strNL = "\n" . $strTime . ' ';
   if(!$in_bNewLine){
      $strNL = '';
   }
   
   file_put_contents(LOG, $strNL . $in_strOutput, FILE_APPEND | LOCK_EX);
}

function getDestinationFolder($in_strFileName, &$aFolderData)
{
   global $G_I_ERRORED;

   $strFolder  = '';
   $strTouchDt = '';
   $strFPrefx  = '';

   switch(substr($in_strFileName, strlen($in_strFileName) - 4, 4)){
      case '.JPG':
      case '.jpg':
         $strFPrefx = '/pictures';      
      break;
      case '.mov':
      case '.MOV':
         $strFPrefx = '/movies';         
      break;
      default:
         $G_I_ERRORED++;
         logOutput('ERROR: file "' . $in_strFileName . '" has an invalid extension.');
         return;
      break;
   } 
   exec('mdls "' . $in_strFileName . '"', $aMetaOutput);

   $iSearch = 0;


   while($iSearch < count($aMetaOutput)){
     $aMeta = explode("=", $aMetaOutput[$iSearch]);

     if(strstr($aMeta[0], 'kMDItemContentCreationDate')) {
        $aDate = explode(" ", $aMetaOutput[$iSearch]);

        //Let's make sure we capture the orginal creation date for a later date
        $strOriginalTime = $aDate[6] . ' ' .  $aDate[7];
        exec('xattr -w kMDItemContentCreationDateOriginal "' . $strOriginalTime . '" "' . $in_strFileName . '"', $aOutput);

	$aTime = explode(':', $aDate[7]);

        //Apple Meta (6)
        $aDate = explode('-', $aDate[6]);

        $iYear  = $aDate[0];
        $iMonth = $aDate[1];
        $strMon = '';
        $iDay   = $aDate[2];

        switch($iMonth) {
           case '1':
              $strMon = 'Jan';
           break;
           case '2':
              $strMon = 'Feb';
           break;
           case '3':
              $strMon = 'Mar';
           break;
           case '4':
              $strMon = 'Apr';
           break;
           case '5':
              $strMon = 'May';
           break;
           case '6':
              $strMon = 'Jun';
           break;
           case '7':
              $strMon = 'Jul';
           break;
           case '8':
              $strMon = 'Aug';
           break;
           case '9':
              $strMon = 'Sep';
           break;
           case '10':
              $strMon = 'Oct';
           break;
           case '11':
              $strMon = 'Nov';
           break;
           case '12':
              $strMon = 'Dec';
           break;
        }


        if($strMon != '') {
           $strFolder = $strFPrefx . '/' . $iYear . '/' . $strMon;
        }

        $strTouchDt = $iYear . $iMonth . $iDay . $aTime[0] . $aTime[1];

        $iSearch = count($aMetaOutput);
      }
      $iSearch++;
   }

   $aFolderData['folder']  = $strFolder;
   $aFolderData['touchdt'] = $strTouchDt;
   $aFolderData['day']     = $iDay;
}

function fileStatus($in_strFileName)
{
   $aFolderData = array('folder' => '', 'touchdt' => '', 'day' => '');

   exec('xattr -l "' . $in_strFileName . '"', $aOutput);
   $aStatus = explode(" ", $aOutput[0]);
   if(count($aStatus) > 0) {
      //TODO - remove me when ready to delete files
      if($aStatus[1] == 'DEL') {
         return $aStatus[1];
      }      
   }
   getDestinationFolder($in_strFileName, $aFolderData);

   if($aFolderData['folder'] == '') {
      logOutput('ERROR: file "' . $in_strFileName . '" does not have a valid folder name.');
      return;
   }



   exec('xattr -l "' . $in_strFileName . '"', $aOutput);
   $aStatus = explode(" ", $aOutput[0]);


   switch($aStatus[1]) {
      case 'DEL':
         //ready for delete
      break;
      case 'OFF':
         //in NAS & offsite - delete ready
         //echo 'In NaS send to OFF';
         //sendToOFF
         sendToNAS($aFolderData, $in_strFileName, $aStatus[1], 'DEL');
      break;
      case 'SKP':
         //Skip this file - an error occurred - logs should be able to tell you what's happening
      break;
      default:
        //empty - needs to be copied
        sendToNAS($aFolderData, $in_strFileName, 'NAS', 'OFF');
      break;
   }
   return $aStatus[1];
}

function removeFile($in_strFileName) 
{
   //rm file
}

function getNewFileName($in_strPath, $in_strFileName) 
{
   $bFoundNew = false;
   $iTrace = 1;
   $strFileName = '';

   $aFile = explode('.', $in_strFileName);
   
   while(!$bFoundNew) {
      $strFileName = $aFile[0] . '_' . $iTrace . '.' . $aFile[1];
      if(!file_exists($in_strPath . $strFileName)) {
         $bFoundNew = true;
      }
      $iTrace++;
   }
   return $strFileName;
}

function sendToNAS($aFolderData, $in_strFileName, $in_strType, $in_strNext) 
{
   $strFolder       = $aFolderData['folder'];
   $strTouchDt      = $aFolderData['touchdt'];
   $strHomeDir      = NAS_MEDIA_HOME;
   $strDestination  = "NAS";
   $strBackupStatus = $in_strNext;
 
   switch($in_strType) {
      case 'OFF':
         $strHomeDir      = GOOGLEDRIVEHOME;
         $strDestination  = 'Google Drive';
         //Uncomment when ready to delete
         //$strBackupStatus = 'DEL';
      break; 
   }

   $strFile = '';
   $strPath = $strHomeDir  . $strFolder . '/';

   $aFilePath = explode('/', $in_strFileName);
   $strFile = $aFilePath[count($aFilePath) - 1];

   //make sure we don't have spaces
   $strFile = str_replace(' ', '_', $strFile);

   //Make directory at destination
   logOutput('INFO: Send "' . $in_strFileName . '" to ' . $strDestination . ': ' . $strPath . ' => Day:' . $aFolderData['day']. ' Size: ' . round((filesize($in_strFileName)/1024/1024),2) . ' MB Time: ');

   //Make directory if it doesn't exist
   exec('mkdir -p "' . $strPath . '"', $aOutput);
  
 
   if(file_exists($strPath . $strFile)) {
      logOutput('INFO: File "' . $strFile. '" already exists  … creating new name');
      $strFile = getNewFileName($strPath, $strFile); 
      logOutput('INFO: New File Name "' . $strFile);
   }
   $strNewFile = $strPath . $strFile;

   //GOOGLE Specific formatting
   if($in_strType == 'OFF') {
      //if the OFF is google then they don't like the file name being used
     // $strNewFile = $strPath . ".";
   }

 
   $mtime = microtime(); 
   $mtime = explode(" ",$mtime); 
   $mtime = $mtime[1] + $mtime[0]; 
   $starttime = $mtime; 

   //make copy
   $strCommand = 'cp "' . $in_strFileName . '" "' . $strNewFile . '"';
   
   exec($strCommand, $aOutput);
   //echo "\n$strCommand \n";

   $mtime = microtime(); 
   $mtime = explode(" ",$mtime); 
   $mtime = $mtime[1] + $mtime[0]; 
   $endtime = $mtime; 
   $totaltime = ($endtime - $starttime); 
   logOutput(round($totaltime, 2) . ' seconds', false);

   //make sure the date is accurate
   exec('touch -t ' . $strTouchDt  . ' "' . $strNewFile . '"', $aOutput);

   //set to next step
   exec('xattr -w backupstatus ' . $strBackupStatus . ' "' . $in_strFileName . '"', $aOutput);
}

function sendToOFF($aFolderData, $in_strFileName) 
{
echo "\nAfter After";
echo "\nFile: " . $in_strFileName;
echo "\n";
exit;

   //Make directory on OFF
   //Copy file to OFF
   //Set Meta to DEL

   $ftp_server = "onlinefilefolder.com";
   $ftp_user_name = "back@knappus.com";
   $ftp_user_pass = "Knapple1";

   // open some file for reading 
   $file = $in_strFileName;


  // set up basic connection
  $conn_id = ftp_connect($ftp_server);
echo "\nCon = " . $conn_id;
$remote_file = "file.JPG";
  // login with username and password
  $login_result = ftp_login($conn_id, $ftp_user_name, $ftp_user_pass);

  // try to upload $file
  if(ftp_put($conn_id, $remote_file, $in_strFileName, FTP_BINARY)) {
     echo "\nSuccessfully uploaded $file\n";
  } else {
     echo "\nThere was a problem while uploading $file\n";
  }

  // close the connection and the file handler
  ftp_close($conn_id);

exit;
}

function traverseDirectories()
{
   global $G_I_SKIPPED, $G_I_PROCESSED, $G_IPROCESSTARGET;

   $iTypeCount = 0;
   $iNumToProcess = $G_IPROCESSTARGET;

   $strDir = HOME . IPHONE_DUMP;
   exec('ls ' . $strDir, $aOutput);
   for($i = 0; $i < count($aOutput); $i++) {
      $strFileName = $strDir  . '/' . $aOutput[$i];
      $strStatus = fileStatus($strFileName);
      //echo "\n" . $strFileName . "\n";

      //if special on
      if($strStatus == 'DEL') {
         //skip
         //logOutput('INFO: SKIPPING FILE - "' . $strFileName . '" - marked as DEL ');
         $G_I_SKIPPED++;
      } else {
         $G_I_PROCESSED++;
         $iTypeCount++;
         if($iTypeCount % 10 == 0) {
            $iSleep = 90; //to let old sync catch up
            logOutput('INFO: Sleeping for ' . $iSleep);
            sleep($iSleep);
         }
      }

      if($iTypeCount == $iNumToProcess) {
         $i = count($aOutput) + 1;
      }
   }
}

function menu()
{
   echo "\n";
   echo 'Welcome to the media backup program. This program will ensure that all files that have yet to be backedup to the NAS are, uploaded to the third party offsite, and remove any file needing to be removed.';
   echo "\n";
}

menu();
echo "\n";

$mtime = microtime(); 
$mtime = explode(" ",$mtime); 
$mtime = $mtime[1] + $mtime[0]; 
$starttime = $mtime; 

logOutput('START: Script Starting … attempting to process: ' . $G_IPROCESSTARGET . ' files.');

traverseDirectories();
$mtime = microtime(); 
$mtime = explode(" ",$mtime); 
$mtime = $mtime[1] + $mtime[0]; 
$endtime = $mtime; 
$totaltime = ($endtime - $starttime); 
logOutput('COMPLETE: Stats … Processed: ' . $G_I_PROCESSED . ' Skipped: ' . $G_I_SKIPPED . ' ERRORED: ' . $G_I_ERRORED . ' Time: ' . round($totaltime/60, 2) . ' minutes');

?>
