<?php
//    MyDMS. Document Management System
//    Copyright (C) 2002-2005  Markus Westphal
//    Copyright (C) 2006-2008 Malcolm Cowe
//
//    This program is free software; you can redistribute it and/or modify
//    it under the terms of the GNU General Public License as published by
//    the Free Software Foundation; either version 2 of the License, or
//    (at your option) any later version.
//
//    This program is distributed in the hope that it will be useful,
//    but WITHOUT ANY WARRANTY; without even the implied warranty of
//    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//    GNU General Public License for more details.
//
//    You should have received a copy of the GNU General Public License
//    along with this program; if not, write to the Free Software
//    Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.

include("../inc/inc.Settings.php");
include("../inc/inc.LogInit.php");
include("../inc/inc.Language.php");
include("../inc/inc.Init.php");
include("../inc/inc.Extension.php");
include("../inc/inc.DBInit.php");
include("../inc/inc.ClassUI.php");
include("../inc/inc.Authentication.php");

if (!isset($_POST["documentid"]) || !is_numeric($_POST["documentid"]) || intval($_POST["documentid"])<1) {
	UI::exitError(getMLText("document_title", array("documentname" => getMLText("invalid_doc_id"))),getMLText("invalid_doc_id"));
}

$documentid = $_POST["documentid"];
$document = $dms->getDocument($documentid);
$folder = $document->getFolder();

if (!is_object($document)) {
	UI::exitError(getMLText("document_title", array("documentname" => getMLText("invalid_doc_id"))),getMLText("invalid_doc_id"));
}

if ($document->getAccessMode($user) < M_READWRITE) {
	UI::exitError(getMLText("document_title", array("documentname" => htmlspecialchars($document->getName()))),getMLText("access_denied"));
}

if($settings->_quota > 0) {
	$remain = checkQuota($user);
	if ($remain < 0) {
		UI::exitError(getMLText("document_title", array("documentname" => htmlspecialchars($document->getName()))),getMLText("quota_exceeded", array('bytes'=>SeedDMS_Core_File::format_filesize(abs($remain)))));
	}
}

if ($document->isLocked()) {
	$lockingUser = $document->getLockingUser();
	if (($lockingUser->getID() != $user->getID()) && ($document->getAccessMode($user) != M_ALL)) {
		UI::exitError(getMLText("document_title", array("documentname" => htmlspecialchars($document->getName()))),getMLText("no_update_cause_locked"));
	}
	else $document->setLocked(false);
}

if(isset($_POST["comment"]))
	$comment  = $_POST["comment"];
else
	$comment = "";

// Only accept documents, pdfs, and presentations as source files
$acceptedFileTypes = array('pdf', 'doc', 'docx', 'odt', 'rtf', 'ppt', 'pptx', 'odp');

// In addition to source file types, accept spreadsheet, visio, images and text types
$acceptedExtensions = array('txt', 'csv', 'xls', 'xlt', 'xlsm', 'xlsx', 'xlsb', 'mp4',
						    'xltx', 'xltm', 'ods', 'bmp', 'gif', 'jpeg', 'jpg', 
						    'png', 'tiff', 'vsd');

$acceptedAttachTypes = array_merge($acceptedFileTypes, $acceptedExtensions);

if ($_FILES['userfile']['error'] == 0) {
	if(!is_uploaded_file($_FILES["userfile"]["tmp_name"]))
		UI::exitError(getMLText("document_title", array("documentname" => $document->getName())),getMLText("error_occured"));
	// Checking for a filesize of 0
	if($_FILES["userfile"]["size"] == 0) {
		UI::exitError(getMLText("document_title", array("documentname" => $document->getName())),getMLText("uploading_zerosize"));
	}

	// Checking for max file size
	if($_FILES["userfile"]["size"] > 60*1024*1024) {
		UI::exitError(getMLText("document_title", array("documentname" => $document->getName())),$_FILES["userfile"]["name"] . " " . getMLText("uploading_maxsize"));
	}

	$sourceFilePath = $_FILES["userfile"]["tmp_name"];
	$sourceFileName = $_FILES["userfile"]["name"];
	$sourceFileExt = strtolower(pathinfo($sourceFileName, PATHINFO_EXTENSION));
	$sourceMimeType = $_FILES["userfile"]["type"];

	$match = 0;
	for ($i=0; $i<count($acceptedFileTypes); $i++) {
		if ($sourceFileExt == $acceptedFileTypes[$i]) $match = 1;
	}
	if (!$match) UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),getMLText("source_type_error"));
	$sourceFileExt = ".".$sourceFileExt;


	if($settings->_overrideMimeType) {
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$sourceFileExt = finfo_file($finfo, $sourceFilePath);
	}
} elseif($settings->_dropFolderDir) {
	if($_POST['dropfolderfileform1']) {
		$fullfile = $settings->_dropFolderDir.'/'.$user->getLogin().'/'.$_POST["dropfolderfileform1"];
		if(file_exists($fullfile)) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mimetype = finfo_file($finfo, $fullfile);
			$userfiletmp = $fullfile;
			$userfiletype = $mimetype;
			$userfilename= $_POST["dropfolderfileform1"];
		} else {
			UI::exitError(getMLText("document_title", array("documentname" => $document->getName())),getMLText("error_occured"));
		}
	} else {
		UI::exitError(getMLText("document_title", array("documentname" => $document->getName())),getMLText("error_occured"));
	}
} else {
	UI::exitError(getMLText("document_title", array("documentname" => $document->getName())),getMLText("uploading_failed"));
}

/* Check if the uploaded file is identical to last version */
$lc = $document->getLatestContent();
/*if($lc->getChecksum() == SeedDMS_Core_File::checksum($userfiletmp)) {
	UI::exitError(getMLText("document_title", array("documentname" => $document->getName())),getMLText("identical_version"));
}*/

// Get the list of reviewers and approvers for this document.
$reviewers = array();
$approvers = array();
$reviewers["i"] = array();
$reviewers["g"] = array();
$approvers["i"] = array();
$approvers["g"] = array();
$workflow = null;

if($settings->_workflowMode == 'traditional' || $settings->_workflowMode == 'traditional_only_approval') {
	if($settings->_workflowMode == 'traditional') {
		// Retrieve the list of individual reviewers from the form.
		$reviewers["i"] = array();
		if (isset($_POST["indReviewers"])) {
			foreach ($_POST["indReviewers"] as $ind) {
				$reviewers["i"][] = $ind;
			}
		}
		// Retrieve the list of reviewer groups from the form.
		$reviewers["g"] = array();
		if (isset($_POST["grpReviewers"])) {
			foreach ($_POST["grpReviewers"] as $grp) {
				$reviewers["g"][] = $grp;
			}
		}
	}

	// Retrieve the list of individual approvers from the form.
	$approvers["i"] = array();
	if (isset($_POST["indApprovers"])) {
		foreach ($_POST["indApprovers"] as $ind) {
			$approvers["i"][] = $ind;
		}
	}
	// Retrieve the list of approver groups from the form.
	$approvers["g"] = array();
	if (isset($_POST["grpApprovers"])) {
		foreach ($_POST["grpApprovers"] as $grp) {
			$approvers["g"][] = $grp;
		}
	}

	// add mandatory reviewers/approvers
	$docAccess = $folder->getReadAccessList($settings->_enableAdminRevApp, $settings->_enableOwnerRevApp);
	if($settings->_workflowMode == 'traditional') {
		$res=$user->getMandatoryReviewers();
		foreach ($res as $r){

			if ($r['reviewerUserID']!=0){
				foreach ($docAccess["users"] as $usr)
					if ($usr->getID()==$r['reviewerUserID']){
						$reviewers["i"][] = $r['reviewerUserID'];
						break;
					}
			}
			else if ($r['reviewerGroupID']!=0){
				foreach ($docAccess["groups"] as $grp)
					if ($grp->getID()==$r['reviewerGroupID']){
						$reviewers["g"][] = $r['reviewerGroupID'];
						break;
					}
			}
		}
	}
	$res=$user->getMandatoryApprovers();
	foreach ($res as $r){

		if ($r['approverUserID']!=0){
			foreach ($docAccess["users"] as $usr)
				if ($usr->getID()==$r['approverUserID']){
					$approvers["i"][] = $r['approverUserID'];
					break;
				}
		}
		else if ($r['approverGroupID']!=0){
			foreach ($docAccess["groups"] as $grp)
				if ($grp->getID()==$r['approverGroupID']){
					$approvers["g"][] = $r['approverGroupID'];
					break;
				}
		}
	}
} elseif($settings->_workflowMode == 'advanced') {
	if(!$workflows = $user->getMandatoryWorkflows()) {
		if(isset($_POST["workflow"]))
			$workflow = $dms->getWorkflow($_POST["workflow"]);
		else
			$workflow = null;
	} else {
		/* If there is excactly 1 mandatory workflow, then set no matter what has
		 * been posted in 'workflow', otherwise check if the posted workflow is in the
		 * list of mandatory workflows. If not, then take the first one.
		 */
		$workflow = array_shift($workflows);
		foreach($workflows as $mw)
			if($mw->getID() == $_POST['workflow']) {$workflow = $mw; break;}
	}
}

if(isset($_POST["attributes"]) && $_POST["attributes"]) {
	$attributes = $_POST["attributes"];
	foreach($attributes as $attrdefid=>$attribute) {
		$attrdef = $dms->getAttributeDefinition($attrdefid);
		if($attribute) {
			if($attrdef->getRegex()) {
				if(!preg_match($attrdef->getRegex(), $attribute)) {
					UI::exitError(getMLText("document_title", array("documentname" => $folder->getName())),getMLText("attr_no_regex_match"));
				}
			}
			if(is_array($attribute)) {
				if($attrdef->getMinValues() > count($attribute)) {
					UI::exitError(getMLText("document_title", array("documentname" => $document->getName())),getMLText("attr_min_values", array("attrname"=>$attrdef->getName())));
				}
				if($attrdef->getMaxValues() && $attrdef->getMaxValues() < count($attribute)) {
					UI::exitError(getMLText("document_title", array("documentname" => $document->getName())),getMLText("attr_max_values", array("attrname"=>$attrdef->getName())));
				}
			}
		}
	}
} else {
	$attributes = array();
}

$pdfData = array();
if (is_uploaded_file($_FILES["userfilePDF"]["tmp_name"])){

	$filename = $_FILES['userfilePDF']['name'];
	$pdfExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

	// Check for a size of 0
    if ($_FILES["userfilePDF"]["size"] == 0) {
        UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),getMLText("uploading_zerosize"));
    }
    // Check for max file size of 60MB
    if ($_FILES["userfilePDF"]["size"] > 60*1024*1024) {
        UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),$_FILES["userfilePDF"]["name"] . " " . getMLText("uploading_maxsize"));
    }
    // Check for any logged errors
    if ($_FILES["userfilePDF"]["error"] != 0){
        UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),getMLText("uploading_failed"));
    }
    // Check for any logged errors
    if ($pdfExt != "pdf"){
        UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),getMLText("pdf_filetype_error"));
    }
    /*
    	If checks pass add the pdf file
    	Location of file in tmp directory
 	*/
	$pdfData['name'] = null;
	$pdfData['pdfFileTmp'] = $_FILES['userfilePDF']['tmp_name'];
	// MIME type of file
	$pdfData['pdfFileType'] = $_FILES['userfilePDF']['type'];
	// Original file name
	$pdfData['fileType'] = ".".$pdfExt;
	$pdfData['pdfFileName'] = basename($filename);

	if($settings->_overrideMimeType) {
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$pdfData['pdfFileType'] = finfo_file($finfo, $pdfData['pdfFileTmp']);
	}
}
if(count($pdfData) == 0) {
	$pdfData = null;
}
	
$attachFileData = array();
for ($file_num=0; $file_num<count($_FILES['attachfile']['tmp_name']); $file_num++){
	/*
		Perform some checks before proceeding with storage
		Ensure files were uploaded to the server via HTTP POST
	*/

	if (is_uploaded_file($_FILES['attachfile']['tmp_name'][$file_num])){
		// Check for a size of 0
	    if ($_FILES['attachfile']['size'][$file_num] == 0) {
	        UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),getMLText("uploading_zerosize"));
	    }
	    // Check for max file size of 60MB
	    if ($_FILES['attachfile']['size'][$file_num] > 60*1024*1024) {
	        UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),$_FILES['attachfile']['name'][$file_num] . " " . getMLText("uploading_maxsize"));
	    }
	    // Check for any logged errors
	    if ($_FILES['attachfile']['error'][$file_num] != 0){
	        UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),getMLText("uploading_failed"));
	    }
		$match = 0;
		$filepath = $_FILES['attachfile']['tmp_name'][$file_num];
		$fileType = $_FILES['attachfile']['type'][$file_num];
		$filename = $_FILES['attachfile']['name'][$file_num];
		$fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
		for ($i=0; $i<count($acceptedAttachTypes); $i++) {
			if ($fileExt == $acceptedAttachTypes[$i]) $match = 1;
		}	

		if (!$match) UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),getMLText("attach_type_error"));

	    /*
	    	If checks pass add the attachment file(s)
	    	Location of file in tmp directory
	 	*/
	   	$attachInfoFile = array();
	   	$attachInfoFile['name'] = null;
		$attachInfoFile['attachFileTmp'] = $filepath;
		// MIME type of file
		$attachInfoFile['attachFileType'] = $fileType;
		$attachInfoFile['fileType'] = ".".$fileExt;
		$attachInfoFile['attachFileName'] = basename($filename);

		if($settings->_overrideMimeType) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$attachInfoFile['attachFileType'] = finfo_file($finfo, $attachInfoFile['attachFileTmp']);
		}
		if(array_key_exists('attachfilePDF', $_FILES)) {
			if (is_uploaded_file($_FILES['attachfilePDF']['tmp_name'][$file_num])){
				// Check for a size of 0
			    if ($_FILES['attachfilePDF']['size'][$file_num] == 0) {
			        UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),getMLText("uploading_zerosize"));
			    }
			    // Check for max file size of 60MB
			    if ($_FILES['attachfilePDF']['size'][$file_num] > 60*1024*1024) {
			        UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),$_FILES['attachfile']['name'][$file_num] . " " . getMLText("uploading_maxsize"));
			    }
			    // Check for any logged errors
			    if ($_FILES['attachfilePDF']['error'][$file_num] != 0){
			        UI::exitError(getMLText("folder_title", array("foldername" => $folder->getName())),getMLText("uploading_failed"));
			    }

			    /*
			    	If checks pass add the attachment file(s)
			    	Location of file in tmp directory
			 	*/
			   	$attachInfoPDF = array();
			   	$attachInfoPDF['name'] = null;
				$attachInfoFile['attachFileTmp'] = $_FILES['attachfilePDF']['tmp_name'][$file_num];
				// MIME type of file
				$attachInfoFile['attachFileType'] = $_FILES['attachfilePDF']['type'][$file_num];
				

				// Original file name
				$filename = $_FILES['attachfilePDF']['name'][$file_num];

				$attachInfoFile['fileType'] = ".".pathinfo($filename, PATHINFO_EXTENSION);
				$attachInfoFile['attachFileName'] = basename($filename);

				if($settings->_overrideMimeType) {
					$finfo = finfo_open(FILEINFO_MIME_TYPE);
					$attachInfoFile['attachFileType'] = finfo_file($finfo, $attachInfoFile['attachFileTmp']);
				}
				$attachInfo['pdfFile'] = $attachInfoPDF;
			}
		}

		$attachInfo['file'] = $attachInfoFile;
		$attachFileData[] = $attachInfo;
		
	} 
}
if(count($attachFileData) == 0) {
	$attachFileData = null;
}

$contentResult=$document->addContent($comment, $user, $sourceFilePath, $sourceFileName, $sourceFileExt, $sourceMimeType, $reviewers, $approvers, $version=0, $attributes, $workflow, $pdfData, $attachFileData);

if (is_bool($contentResult[0]) && !$contentResult[0]) {
	UI::exitError(getMLText("document_title", array("documentname" => $document->getName())),$contentResult[1]);
}
else {
	// Temporary fix to update doc links using remove all. 
	// Next version will update database to version document links.
	$content = $contentResult[1];
	if(!$document->removeAllDocumentLinks()){
		UI::exitError(getMLText("document_title", array("documentname" => $document->getID())),$linkID);
	}
	// Remove old links and add new ones
	if(isset($_POST["linkInputs"])) {
		$linkInputs = $_POST["linkInputs"];
		foreach ($linkInputs as $linkInput) {
			//Extract the document number only <number title>
			$docNumber = explode(" ", $linkInput);
			$docNumber = $docNumber[0];
			$linkID = $dms->getDocumentIDByNumber($docNumber);

			if (!$document->addDocumentLink($linkID, $user->getID(), true)){
				UI::exitError(getMLText("document_title", array("documentname" => $document->getID())),$linkID);
			}
		}
	}

	// Temporary fix to update doc links using remove all. 
	// Next version will update database to version document links.
	if(!$document->removeAllDocumentNotifications()){
		UI::exitError(getMLText("document_title", array("documentname" => $document->getID())),$linkID);
	}
	/* Check if additional notification shall be added */
	if(isset($_POST['notifyInputsUsers'])) {
		/* Add a default notification for the owner of the document */
		if($settings->_enableOwnerNotification) {
			$res = $document->addNotify($user->getID(), true);
		}
		foreach($_POST['notifyInputsUsers'] as $login) {
			// Remove the period character from doc number for jQuery compatibility
			str_replace('-', '.', $login);
			$empID = $dms->getUserByLogin($login)->getID();
			if($empID) {
				if($document->getAccessMode($user) >= M_READ)
					$res = $document->addNotify($empID, true);
			}
		}
	}

	// Send notification to subscribers.
	if ($notifier){
		$notifyList = $document->getNotifyList();
		$folder = $document->getFolder();
		$content = $document->getLatestContent();
		$subject = "document_updated_email_subject";
		$message = "document_updated_email_body";
		$params = array();
		$params['status'] = "document_updated_email_status";
		$params['name'] = $document->getName();
		$params['folder_path'] = $folder->getFolderPathPlain();
		$params['username'] = $user->getFullName();
		$params['comment'] = $document->getComment();
		$params['date'] = $content->getDate();
		$params['version_comment'] = $content->getComment();
		$params['version'] = $content->getCustomVersion();
		$params['doc_number'] = $document->getDocNum();
		$params['notify_list'] = $notifyList["users"];
		$params['url'] = "http".((isset($_SERVER['HTTPS']) && (strcmp($_SERVER['HTTPS'],'off')!=0)) ? "s" : "")."://".$_SERVER['HTTP_HOST'].$settings->_httpRoot."out/out.ViewDocument.php?docnum=".$document->getDocNum();
		$params['sitename'] = $settings->_siteName;
		$params['http_root'] = $settings->_httpRoot;
		$notifier->toList($user, $notifyList["users"], $subject, $message, $params);
		foreach ($notifyList["groups"] as $grp) {
			$notifier->toGroup($user, $grp, $subject, $message, $params);
		}
		// if user is not owner send notification to owner
		if ($user->getID() != $document->getOwner()->getID()) 
			$notifier->toIndividual($user, $document->getOwner(), $subject, $message, $params);

		if($workflow && $settings->_enableNotificationWorkflow) {
			$subject = "request_workflow_action_email_subject";
			$message = "request_workflow_action_email_body";
			$params = array();
			$params['name'] = $document->getName();
			$params['version'] = $contentResult->getContent()->getVersion();
			$params['workflow'] = $workflow->getName();
			$params['folder_path'] = $folder->getFolderPathPlain();
			$params['current_state'] = $workflow->getInitState()->getName();
			$params['username'] = $user->getFullName();
			$params['sitename'] = $settings->_siteName;
			$params['http_root'] = $settings->_httpRoot;
			$params['url'] = "http".((isset($_SERVER['HTTPS']) && (strcmp($_SERVER['HTTPS'],'off')!=0)) ? "s" : "")."://".$_SERVER['HTTP_HOST'].$settings->_httpRoot."out/out.ViewDocument.php?docnum=".$document->getDocNum();

			foreach($workflow->getNextTransitions($workflow->getInitState()) as $ntransition) {
				foreach($ntransition->getUsers() as $tuser) {
					$notifier->toIndividual($user, $tuser->getUser(), $subject, $message, $params);
				}
				foreach($ntransition->getGroups() as $tuser) {
					$notifier->toGroup($user, $tuser->getGroup(), $subject, $message, $params);
				}
			}
		}

		if($settings->_enableNotificationAppRev) {
			/* Reviewers and approvers will be informed about the new document */
			if($reviewers['i'] || $reviewers['g']) {
				$subject = "review_request_email_subject";
				$message = "review_request_email_body";
				$params = array();
				$params['name'] = $document->getName();
				$params['folder_path'] = $folder->getFolderPathPlain();
				$params['version'] = $contentResult->getContent()->getVersion();
				$params['comment'] = $contentResult->getContent()->getComment();
				$params['username'] = $user->getFullName();
				$params['url'] = "http".((isset($_SERVER['HTTPS']) && (strcmp($_SERVER['HTTPS'],'off')!=0)) ? "s" : "")."://".$_SERVER['HTTP_HOST'].$settings->_httpRoot."out/out.ViewDocument.php?docnum=".$document->getDocNum();
				$params['sitename'] = $settings->_siteName;
				$params['http_root'] = $settings->_httpRoot;

				foreach($reviewers['i'] as $reviewerid) {
					$notifier->toIndividual($user, $dms->getUser($reviewerid), $subject, $message, $params);
				}
				foreach($reviewers['g'] as $reviewergrpid) {
					$notifier->toGroup($user, $dms->getGroup($reviewergrpid), $subject, $message, $params);
				}
			}

			if($approvers['i'] || $approvers['g']) {
				$subject = "approval_request_email_subject";
				$message = "approval_request_email_body";
				$params = array();
				$params['name'] = $document->getName();
				$params['folder_path'] = $folder->getFolderPathPlain();
				$params['version'] = $contentResult->getContent()->getVersion();
				$params['comment'] = $contentResult->getContent()->getComment();
				$params['username'] = $user->getFullName();
				$params['url'] = "http".((isset($_SERVER['HTTPS']) && (strcmp($_SERVER['HTTPS'],'off')!=0)) ? "s" : "")."://".$_SERVER['HTTP_HOST'].$settings->_httpRoot."out/out.ViewDocument.php?docnum=".$document->getDocNum();
				$params['sitename'] = $settings->_siteName;
				$params['http_root'] = $settings->_httpRoot;

				foreach($approvers['i'] as $approverid) {
					$notifier->toIndividual($user, $dms->getUser($approverid), $subject, $message, $params);
				}
				foreach($approvers['g'] as $approvergrpid) {
					$notifier->toGroup($user, $dms->getGroup($approvergrpid), $subject, $message, $params);
				}
			}
		}
	}

	$expires = false;
	// Keeping backward compatibilty with original SeedDMS verison.
	if(isset($_POST['expires'])) {
	    if (!isset($_POST['expires']) || $_POST["expires"] != "false") {
	        if($_POST["expdate"]) {
	            $tmp = explode('-', $_POST["expdate"]);
	            $expires = mktime(0,0,0, $tmp[1], $tmp[2], $tmp[0]);
	        } else {
	            $expires = mktime(0,0,0, $_POST["expmonth"], $_POST["expday"], $_POST["expyear"]);
	        }
	    }
	}

	if ($expires) {
		if($document->setExpires($expires)) {
			if($notifier) {
				$notifyList = $document->getNotifyList();
				$folder = $document->getFolder();

				// Send notification to subscribers.
				$subject = "expiry_changed_email_subject";
				$message = "expiry_changed_email_body";
				$params = array();
				$params['name'] = $document->getName();
				$params['folder_path'] = $folder->getFolderPathPlain();
				$params['username'] = $user->getFullName();
				$params['url'] = "http".((isset($_SERVER['HTTPS']) && (strcmp($_SERVER['HTTPS'],'off')!=0)) ? "s" : "")."://".$_SERVER['HTTP_HOST'].$settings->_httpRoot."out/out.ViewDocument.php?docnum=".$document->getDocNum();
				$params['sitename'] = $settings->_siteName;
				$params['http_root'] = $settings->_httpRoot;
				$notifier->toList($user, $notifyList["users"], $subject, $message, $params);
				foreach ($notifyList["groups"] as $grp) {
					$notifier->toGroup($user, $grp, $subject, $message, $params);
				}
			}
		} else {
			UI::exitError(getMLText("document_title", array("documentname" => $document->getName())),getMLText("error_occured"));
		}
	}
}

add_log_line("?documentid=".$documentid);
header("Location:../out/out.ViewDocument.php?docnum=".$document->getDocNum());

?>
