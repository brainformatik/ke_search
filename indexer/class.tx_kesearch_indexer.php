<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2010 Andreas Kiefer (kennziffer.com) <kiefer@kennziffer.com>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/


/**
 * Plugin 'Faceted search' for the 'ke_search' extension.
 *
 * @author	Andreas Kiefer (kennziffer.com) <kiefer@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_indexer {
	var $counter;
	var $extConf; // extension configuration
	var $indexerConfig = array(); // saves the indexer configuration of current loop
	
	
	/**
	 * Constructor of this class
	 */
	public function __construct() {
		$this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ke_search']);
	}
	
	/*
	 * function startIndexing
	 * @param $verbose boolean 	if set, information about the indexing process is returned, otherwise processing is quiet
	 * @param $extConf array			extension config array from EXT Manager
	 * @param $mode string				"CLI" if called from command line, otherwise empty
	 * @return string							only if param $verbose is true
	 */
	function startIndexing($verbose=true, $extConf, $mode='')  {
		// get configurations
		$configurations = $this->getConfigurations();
		
		foreach($configurations as $indexerConfig) {
			$this->indexerConfig = $indexerConfig;
			
			$path = t3lib_extMgm::extPath('ke_search').'indexer/types/class.tx_kesearch_indexer_types_' . $this->indexerConfig['type'] . '.php';
			if(is_file($path)) {
				require_once($path);
				$searchObj = t3lib_div::makeInstance('tx_kesearch_indexer_types_' . $this->indexerConfig['type'], $this);
				$content .= $searchObj->startIndexing();
			}

			// hook for custom indexer
			if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['customIndexer'])) {
				foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['customIndexer'] as $_classRef) {
					$_procObj = & t3lib_div::getUserObj($_classRef);
					$content .= $_procObj->customIndexer($indexerConfig, $this);
				}
			}
		}

		// process index cleanup?
		if ($extConf['cleanupInterval']) {
			$content .= '<p><b>Index cleanup processed</b></p>'."\n";
			$content .= $this->cleanUpIndex($extConf['cleanupInterval']);
		}

		// send notification in CLI mode
		if ($mode == 'CLI') {

			// send finishNotification
			if ($extConf['finishNotification'] && t3lib_div::validEmail($extConf['notificationRecipient'])) {

				// build message
				$msg = 'Indexing process was finished:'."\n";
				$msg .= "==============================\n\n";
				$msg .= strip_tags($content);
				// send the notification message
				mail($extConf['notificationRecipient'], $extConf['notificationSubject'], $msg);
			}

		}

		// verbose or quiet output? as set in function call!
		if ($verbose) return $content;

	}


	/**
	* Delete all index elements that are older than $interval (in hours)
	* @param	int		$interval
	*/
	function cleanUpIndex($interval) {

		$startMicrotime = microtime(true);

		$interval = time() - ($interval * 60 * 60);
		$table = 'tx_kesearch_index';
		$where = 'tstamp <= "'.$interval.'" ';
		$GLOBALS['TYPO3_DB']->exec_DELETEquery($table,$where);

		// calculate duration of indexing process
		$endMicrotime = microtime(true);
		$duration = ceil(($endMicrotime - $startMicrotime) * 1000);
		$content .= '<p><i>Cleanup process took '.$duration.' ms.</i></p>';
		return $content;

	}



	/*
	 * function storeInIndex
	 */
	function storeInIndex($storagepid, $title, $type, $targetpid, $content, $tags='', $params='', $abstract='', $language=0, $starttime=0, $endtime=0, $fe_group, $debugOnly=false, $additionalFields=array()) {

		// check for errors
		$errors = array();
		if ($type != 'xtypocommerce' && empty($storagepid)) $errors[] = 'No storage PID set';
		if (empty($title)) $errors[] = 'No title set';
		if (empty($type)) $errors[] = 'No type set';
		if (empty($targetpid)) $errors[] = 'No target PID set';

		// stop executing if errors occured
		if (sizeof($errors)) {
			foreach ($errors as $error) {
				$errorMessage .= $error.chr(10).chr(13);
			}
			t3lib_div::debug($errorMessage,'ERROR WHILE STORING INDEX FOR PAGE '.$targetpid.' - '.$params);
		}


		$table = 'tx_kesearch_index';
		$now = time();
		$fields_values = array(
			'pid' => intval($storagepid),
			'title' => $title,
			'type' => $type,
			'targetpid' => $targetpid,
			'content' => $content,
			'tags' => $tags,
			'params' => $params,
			'abstract' => $abstract,
			'language' => $language,
			'starttime' => $starttime,
			'endtime' => $endtime,
			'fe_group' => $fe_group,
			'tstamp' => $now,
			'crdate' => $now,
		);

		if (count($additionalFields)) {
			// merge arrays
			$fields_values = array_merge($fields_values, $additionalFields);
		}

		// check if record already exists
		$existingRecordUid = $this->indexRecordExists($storagepid, $targetpid, $type, $params);
		if ($existingRecordUid) {
			// update existing record
			$where = 'uid="'.intval($existingRecordUid).'" ';
			unset($fields_values['crdate']);
			if ($debugOnly) { // do not process - just debug query
				t3lib_div::debug($GLOBALS['TYPO3_DB']->UPDATEquery($table,$where,$fields_values),1);
			} else { // process storing of index record and return uid
				if ($GLOBALS['TYPO3_DB']->exec_UPDATEquery($table,$where,$fields_values)) {

					// count record for periodic notification?
					if ($this->extConf['periodicNotification']) $this->periodicNotificationCount();

					return true;
				}
			}
		} else {
			// insert new record
			if ($debugOnly) { // do not process - just debug query
				t3lib_div::debug($GLOBALS['TYPO3_DB']->INSERTquery($table,$fields_values,$no_quote_fields=FALSE),1);
			} else { // process storing of index record and return uid
				$GLOBALS['TYPO3_DB']->exec_INSERTquery($table,$fields_values,$no_quote_fields=FALSE);

				// count record for periodic notification?
				if ($this->extConf['periodicNotification']) $this->periodicNotificationCount();

				return $GLOBALS['TYPO3_DB']->sql_insert_id();
			}
		}
	}


	/*
	 * function clearIndex
	 * @param $storagepid			storage pid
	 * @param $targetpid			target pid for index record
	 * @param $type string		type ("page" or "ke_yac" yet)
	 * @param $params string		needed if other elements than pages are used
	 */
	function clearIndex($storagepid, $targetpid, $type, $params='') {
		$table = 'tx_kesearch_index';
		$where = 'pid="'.intval($storagepid).'" ';
		$where .= 'AND targetpid="'.intval($targetpid).'" ';
		$where .= 'AND type="'.$type.'" ';
		if (!empty($params))$where .= 'AND params="'.$params.'" ';
		$GLOBALS['TYPO3_DB']->exec_DELETEquery($table,$where);
	}


	/*
	 * function indexRecordExists
	 */
	function indexRecordExists($storagepid, $targetpid, $type, $params='') {
		$fields = 'uid';
		$table = 'tx_kesearch_index';
		$where = 'pid="'.intval($storagepid).'" ';
		$where .= 'AND targetpid="'.intval($targetpid).'" ';
		$where .= 'AND type="'.$type.'" ';
		if (!empty($params)) $where .= ' AND params="'.$params.'" ';
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='1');
		while ($row=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			return $row['uid'];
		}
		return false;
	}


	/**
	 * this function returns all indexer configurations found in DB
	 * independant of PID
	 */
	function getConfigurations() {
		$fields = '*';
		$table = 'tx_kesearch_indexerconfig';
		$where = '1=1 ';
		$where .= t3lib_befunc::BEenableFields($table);
		$where .= t3lib_befunc::deleteClause($table);
		return $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($fields, $table, $where);
	}
	

	/*
	 * function setRootlineTags
	 * @param $pid
	 */
	function setRootlineTags($pid, $rootlineTags, $tagsContent) {
		if (count($this->rootlineTags)) {
			foreach($this->rootlineTags as $key => $data) {
				if ($this->checkPIDUpInRootline($pid, $data['foreign_pid']) && !strstr($tagsContent,'#'.$data['tag'].'#')) $tagsContent .= '#'.$data['tag'].'#';
			}
		}

		return $tagsContent;
	}


	/*
	 * function checkPIDUpInRootline
	 * @param $localPid
	 * @param $foreignPid
	 */
	function checkPIDUpInRootline($localPid, $foreignPid) {

		if ($localPid == $foreignPid) return true;

		// make instance of t3lib_pageSelect if not exists
		if (!is_object($this->sys_page)) {
			$this->sys_page = t3lib_div::makeInstance('t3lib_pageSelect');
		}
		// Get the root line
		$rootline = $this->sys_page->getRootLine($localPid);
		// run through pids and check for parent pid in rootline
		foreach ($rootline as $rootlineNode) {
			if ($rootlineNode['pid'] == $foreignPid) return true;
		}
		return false;
	}


	/*
	 * function periodicNotificationCount
	 * @return void
	 */
	function periodicNotificationCount() {
		// increase counter
		$this->counter += 1;

		// check if number of records configured reached
		if (($this->counter % $this->extConf['periodicNotification']) == 0) {
			// send the notification message
			if (t3lib_div::validEmail($this->extConf['notificationRecipient'])) {
				$msg = $this->counter.' records have been indexed.';
				mail($this->extConf['notificationRecipient'], $this->extConf['notificationSubject'], $msg);
			}
		}
	}
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/class.tx_kesearch_indexer.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/class.tx_kesearch_indexer.php']);
}
?>
