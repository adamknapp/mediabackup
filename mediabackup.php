#!/usr/bin/php
<?php
#mediabackup.php
#Author: Adam Knapp
#Created: 12/25/2013
#Last Updated: 12/27/2014
#
#
#A program designed to backup up media files such as photos and videos to two locations:
# 1.) a local NAS
# 2.) offsite - now, the only supported offsite feature is GoogleDrive. 
#
# GoogleDrive makes this extremly easy with the native app you can install
#
# 
#
# Recommended crontab configurations
# This will run the program every morning at 12:05 am
# > crontab -e 
# 5 0 * * * /usr/bin/php /Users/KnappleMacMini/Documents/code/mediabackup/mediabackup.php


#Home Directory
define('HOME', '/Users/KnappleMacMini/');
define('HOME_LOG', HOME . 'Documents/logs/');

#Define the logs we're going to write to
define(INFOLOG, HOME_LOG . 'info.log');
define(ERRORLOG,HOME_LOG . 'error.log');

#Destination folders. These are the folders that we will pull from Home/*
#Comma seperated list?
#define('SOURCE_DIRS', 'Pictures/IPhoneDump,Pictures/IPhoneDumpJordan');
define('SOURCE_DIRS', 'Pictures/IPhoneDump');

#Output directories
#The first is the NAS, the location of the NAS
#The second is google drive specific
define('NAS_MEDIA_HOME', '/Volumes/Volume_1-1/media');
define('GOOGLEDRIVEHOME','/Users/KnappleMacMini/Google Drive/Home/media'); 

#Keeping a file for 30 days locally before deleting
#TODO - change this back to 14 or 30
define('DEL_RETENTION_DAYS', 7);

#XAttributes are 'extra attributes' that we're adding to the file to 
#ensure we can make the appropriate decisions to at the right time
#each variable plays an important role.
define('XATTR_BACKUPSTATE', 'backupstatus');
define('XATTR_DEL_EPOCH', 'delepoch'); //The timestamp we'll delete from
define('XATTR_ORIRGINALDTE', 'kMDItemContentCreationDateOriginal'); 

//Global Counters
$G_IPROCESSLIMIT = 0;
$G_I_ERRORED = 0;
$G_I_PROCESSED = 0;

function getCurrentTime()
{
   $mtime = microtime(); 
   $mtime = explode(" ",$mtime); 
   return $mtime[1] + $mtime[0];
}

function getDeleteTime()
{
   //TODO - change this to '-' to allow for imediate deletes of files when epoch is set.
   //This will help remove pressure from the local disk now
   return time() + (intval(DEL_RETENTION_DAYS) * 24 * 60 * 60);
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
         logOutput(ERRORLOG, 'ERROR: file "' . $in_strFileName . '" has an invalid extension.');         
         return false;
      break;
   } 
   
   exec('mdls "' . $in_strFileName . '"', $aMetaOutput);

   $iSearch = 0;

   while($iSearch < count($aMetaOutput)){
     $aMeta = explode("=", $aMetaOutput[$iSearch]);

     if(strstr($aMeta[0], 'kMDItemContentCreationDate')) {
        $aDate = explode(" ", $aMetaOutput[$iSearch]);

        //Let's make sure we capture the orginal creation date and add it as an xattr, we'll always have it
        $strOriginalTime = $aDate[6] . ' ' .  $aDate[7];
        exec('xattr -w ' . XATTR_ORIRGINALDTE . ' "' . $strOriginalTime . '" "' . $in_strFileName . '"', $aOutput);

	    $aTime = explode(':', $aDate[7]);

        //Apple Meta (6)
        $aDate = explode('-', $aDate[6]);

        $iYear  = $aDate[0];
        $iMonth = $aDate[1];
        $strMon = $iMonth;
        $iDay   = $aDate[2];

        switch($iMonth) {
           case '1':
              $strMon .= 'Jan';
           break;
           case '2':
              $strMon .= 'Feb';
           break;
           case '3':
              $strMon .= 'Mar';
           break;
           case '4':
              $strMon .= 'Apr';
           break;
           case '5':
              $strMon .= 'May';
           break;
           case '6':
              $strMon .= 'Jun';
           break;
           case '7':
              $strMon .= 'Jul';
           break;
           case '8':
              $strMon .= 'Aug';
           break;
           case '9':
              $strMon .= 'Sep';
           break;
           case '10':
              $strMon .= 'Oct';
           break;
           case '11':
              $strMon .= 'Nov';
           break;
           case '12':
              $strMon .= 'Dec';
           break;
        }

        if($strMon != '') {
           $strFolder = $strFPrefx . '/' . $iYear . '/' . $strMon;
        }

        $strTouchDt = $iYear . $iMonth . $iDay . $aTime[0] . $aTime[1];
        $iSearch    = count($aMetaOutput);
      }
      $iSearch++;
   }
   $aFolderData['folder']  = $strFolder;
   $aFolderData['touchdt'] = $strTouchDt;
   $aFolderData['day']     = $iDay;
   
   return true;
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

function initialize()
{
   fclose(STDERR);
   $STDERR = fopen(ERRORLOG, "a");

   if(!date_default_timezone_set('America/Phoenix')) {
      echo "\nBad timezone!";
      exit;
   }   
}

function logOutput($in_strLog, $in_strOutput, $in_bNewLine = true)
{
   // Open file to write
   $strTime = date("Y-m-d H:i:s");
   $strNL = "\n" . $strTime . ' ';
   if(!$in_bNewLine){
      $strNL = '';
   }
   if(file_put_contents($in_strLog, $strNL . $in_strOutput, FILE_APPEND | LOCK_EX) <= 0) {
      echo "\nEGADS! log writing failed! ... maybe out of disk space?";
      exit;
   }
}

function processFile($in_strFileName)
{
   $aFileData = array('status' => '', 'size' => 0.0);
   $strStatus = '';
   $dFileSize = 0;
   
   exec('xattr -p ' . XATTR_BACKUPSTATE . ' "' . $in_strFileName . '"', $aOutput);
   
   if(count($aOutput) > 0) {
      //a file we've processed before
      $aFileData['status'] = $aOutput[0];
   }
   
   switch($aFileData['status']) {
      case 'DEL':
         //ready for delete
         removeFile($in_strFileName);
      break;
      case 'OFF':
      case 'GOOGLE':
         //CHANGE 'OFF' to 'GOOGLE'
         //Send to Google 
         $aFileData['size'] = sendToNAS($in_strFileName, $aFileData['status'], 'DEL');
      break;
      case 'SKP':
         //Skip this file - an error occurred - logs should be able to tell you what's happening
      break;
      default:
        //empty - needs to be copied
        $aFileData['status'] = 'NAS';
        $aFileData['size'] = sendToNAS($in_strFileName, 'NAS', 'GOOGLE');
        if($aFileData['size'] == -1) {
           $aFileData['size']   = 0;
           $aFileData['status'] = 'ERROR';
        } 
      break;
   }
   return $aFileData;
}

function removeFile($in_strFileName) 
{
   $iDelEpoch = 0;
   
   $strMessage = 'INFO: Attempting to delete  ' . $in_strFileName . '...';

   exec('xattr -p ' . XATTR_DEL_EPOCH . ' "' . $in_strFileName . '"', $aOutput);
   
   if(count($aOutput) == 0) {
      //No Epoch found
      $iDelEpoch = getDeleteTime();
      exec('xattr -w ' . XATTR_DEL_EPOCH . ' ' . $iDelEpoch . ' "' . $in_strFileName . '"', $aOutput);
      $strMessage .= 'no epoch found - added: xattr ' . XATTR_DEL_EPOCH . ' ' . $iDelEpoch;
      
   } else {
      $iDelEpoch     = $aOutput[0];
      $iCurrentEpoch = time();
   
      if($iCurrentEpoch >= $iDelEpoch) {
         exec('rm "' . $in_strFileName . '"', $aOutput);
         $strMessage .= 'now (' . $iCurrentEpoch . ') >= ' . $iDelEpoch . ' .. DELETED!';
      } else {
         $strMessage .= 'now (' . $iCurrentEpoch . ') < ' . $iDelEpoch . ' .. Waiting!';
      }
   }
   
   logOutput(INFOLOG, $strMessage);
}

function sendToNAS($in_strFileName, $in_strType, $in_strNext) 
{
   $aFolderData = array('folder' => '', 'touchdt' => '', 'day' => '');
   
   if(!getDestinationFolder($in_strFileName, $aFolderData)) {
      //failed prepping file
      return -1;
   }

   $dFileSizeMB     = 0.0;
   $strFolder       = $aFolderData['folder'];
   $strTouchDt      = $aFolderData['touchdt'];
   $strHomeDir      = NAS_MEDIA_HOME;
   $strDestination  = "NAS";
   $strBackupStatus = $in_strNext;
 
   switch($in_strType) {
      case 'OFF':
      case 'GOOGLE':
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

   $dFileSizeMB = round((filesize($in_strFileName)/1024/1024),2);

   //Make directory if it doesn't exist
   exec('mkdir -p "' . $strPath . '"', $aOutput);
  
  
   $bSkip = false;
   if(file_exists($strPath . $strFile)) {
      if(filesize($strPath . $strFile) > 0) {
         logOutput(INFOLOG,'WARNING: [' . $in_strType . '] File: "' . $strPath . $strFile . '" already exists skipping ...');
         $bSkip = true;
      } else {
         logOutput(INFOLOG,'ERROR: [' . $in_strType . '] File: "' . $strPath . $strFile . '" is size 0 ABORT! ...');
         logOutput(ERRORLOG,'ERROR: [' . $in_strType . '] File: "' . $strPath . $strFile . '" is size 0 ABORT! ...');
         exit;
      }
      //logOutput(INFOLOG,'INFO: File "' . $strFile. '" already exists  … creating new name');
      //$strFile = getNewFileName($strPath, $strFile); 
      //logOutput(INFOLOG,'INFO: New File Name "' . $strFile);
   }

if(!$bSkip) {
   logOutput(INFOLOG,'INFO: SendTo[' . $in_strType . '] File:"' . $in_strFileName . '" to ' . $strDestination . ': ' . $strPath . $strFile . ' =>  Size: ' . $dFileSizeMB . ' MB Time: ');
   

   $strNewFile = $strPath . $strFile;

   $strCommand = 'cp "' . $in_strFileName . '" "' . $strNewFile . '"';

   $starttime = getCurrentTime(); 
   exec($strCommand, $aOutput);
   $endtime   = getCurrentTime();
   $totaltime = ($endtime - $starttime); 

   $iTime = round($totaltime, 2);
   
   logOutput(INFOLOG,round($totaltime, 2) . ' seconds', false);

   if($iTime == 0) {
      //ABORT
      logOutput(ERRORLOG, 'ERROR: time took 0 seconds, something is fishy - stopping program');         
      exit;
   }
}   
   //make sure the date is accurate
   //helps on NAS, not on GoogleDrive
   exec('touch -t ' . $strTouchDt  . ' "' . $strNewFile . '"', $aOutput);

   //set to next step
   exec('xattr -w ' . XATTR_BACKUPSTATE . ' ' . $strBackupStatus . ' "' . $in_strFileName . '"', $aOutput);
   
   return $dFileSizeMB;
}

function traverseDirectories()
{
   global $G_I_PROCESSED, $G_IPROCESSLIMIT;

   $iTypeCount    = 0;
   $iCurrentCount = 0; 
   $dTotalSize    = 0.0;
   $dCurrentSize  = 0.0;
   $iNumToProcess = $G_IPROCESSLIMIT;

   $aDirectories = explode(",",SOURCE_DIRS); 
   for($iDirTrace = 0; $iDirTrace < count($aDirectories); $iDirTrace++) {
      $strDir = HOME . $aDirectories[$iDirTrace];
      
      exec('ls ' . $strDir, $aOutput);

      if(count($aOutput) > 0) {
         logOutput(INFOLOG,'INFO: Starting to process "' . count($aOutput) . '" files from directory: ' . $strDir);
      } else {
         logOutput(INFOLOG,'INFO: No files to process in directory: ' . $strDir);
      }
      
      for($i = 0; $i < count($aOutput); $i++) {
      
         $strFileName = $strDir  . '/' . $aOutput[$i];
         
         //Go through the whole lifecycle of a file and set the delete
         $bDone = false;

         //Uncomment this to wipe out all attributes in the directories you're looking for. 
         //This is a good way to re-run the transferrs over and over again to ensure all files have been captured
         //to the locations desired
         //exec('xattr -c "'  . $strFileName . '"', $aOutput); echo "\nFilename: " . $strFileName;$bDone = true;
         
         while(!$bDone) {
            $aFileData   = processFile($strFileName);

            //DEBUG    
            //echo "\nFilename: " . $strFileName . "; Status: " . $aFileData['status'];
         
            $G_I_PROCESSED++;
            $iTypeCount++;
            $iCurrentCount++;

            $dCurrentSize += $aFileData['size'];
            $dTotalSize   += $dCurrentSize;

            //There are two reasons to pause or 'sleep' the program
            //1.) Crushing the system (either local) or fast NAS by ripping
            //    through a ton of files quickly or
            //2.) Processing a ton of data. Especially for Google Drive, it takes
            //    time for it to sync large files. We'll use file size to determine sleep
	 
            //GoTo sleep once we hit 300MB transferred or 100 files processed 
            if($iCurrentCount >= 100 || $dCurrentSize > 300) {
              //Rule - sleep 1 second for every 10MB transfered (10 seconds default for DELs)
              $iSleep = 10;
              if($dCurrentSize > 100) {
                 round($dCurrentSize/10, 2);
              }
              logOutput(INFOLOG,'INFO: SLEEPING for (' . $iSleep . ') seconds ... this run processed (' . $iCurrentCount . ') files and transferred (' . $dCurrentSize . ')MBs'); 
              logOutput(INFOLOG,'INFO: progress ... processed ' . $G_I_PROCESSED . '/' . count($aOutput) . ' ' . (round(($G_I_PROCESSED/count($aOutput))*100,2)) . '% complete ... transferred (' . $dTotalSize . ')MBs'); 
           
              sleep($iSleep);

              $iCurrentCount = 0;
              $dCurrentSize  = 0;
            }

            if($iTypeCount == $iNumToProcess) {
               $i = count($aOutput) + 1;
               $bDone = true;
            }

            if($aFileData['status'] == 'DEL' || $aFileData['status'] == 'ERROR') {
               $bDone = true;
            }
         
         }
      }
   }
   return $dTotalSize;
}

initialize();

$starttime = getCurrentTime(); 

logOutput(INFOLOG,'START: Script Starting …');

$dSizeTransferred = traverseDirectories();

$endtime = getCurrentTime();

$totaltime = ($endtime - $starttime); 
logOutput(INFOLOG,'COMPLETE: Stats … Processed: ' . $G_I_PROCESSED . ' ERRORED: ' . $G_I_ERRORED . ' Transferred: ' . $dSizeTransferred . ' Time: ' . round($totaltime/60, 2) . ' minutes');
?>
