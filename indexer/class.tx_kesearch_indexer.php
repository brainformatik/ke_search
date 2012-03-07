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
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_indexer {
	var $counter;
	var $extConf; // extension configuration
	var $indexerConfig = array(); // saves the indexer configuration of current loop
	var $lockFile = '';
	var $additionalFields = '';
	var $indexingErrors = array();
	var $startTime;
	var $currentRow = array(); // current row which have to be inserted/updated to database

	// We collect some records before saving
	// this is faster than waiting till the next record was builded
	var $tempArrayForUpdatingExistingRecords = array();
	var $tempArrayForInsertNewRecords = array();
	var $amountOfRecordsToSaveInMem = 100;

	/**
	 * @var t3lib_Registry
	 */
	var $registry;


	/**
	 * Constructor of this class
	 */
	public function __construct() {
		$this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ke_search']);
		// sphinx has problems with # in query string.
		// so you have the possibility to change # against some other char
		if(t3lib_extMgm::isLoaded('ke_search_premium')) {
			$extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ke_search_premium']);
			if(!$extConf['prePostTagChar']) $extConf['prePostTagChar'] = '_';
			$this->extConf['prePostTagChar'] = $extConf['prePostTagChar'];
		} else {
			// MySQL has problems also with #
			// but we have wrapped # with " and it works.
			$this->extConf['prePostTagChar'] = '#';
		}
		$this->registry = t3lib_div::makeInstance('t3lib_Registry');
	}

	/*
	 * function startIndexing
	 * @param $verbose boolean 	if set, information about the indexing process is returned, otherwise processing is quiet
	 * @param $extConf array			extension config array from EXT Manager
	 * @param $mode string				"CLI" if called from command line, otherwise empty
	 * @return string							only if param $verbose is true
	 */
	function startIndexing($verbose=true, $extConf, $mode='')  {
		// write starting timestamp into registry
		// this is a helper to delete all records which are older than starting timestamp in registry
		// this also prevents starting the indexer twice
		if($this->registry->get('tx_kesearch', 'startTimeOfIndexer') === null) {
			$this->registry->set('tx_kesearch', 'startTimeOfIndexer', time());
		} else {
			return 'You can\'t start the indexer twice. Please wait while first indexer process is currently running';
		}

		// set indexing start time
		$this->startTime = time();

		// get configurations
		$configurations = $this->getConfigurations();

		$this->amountOfRecordsToSaveInMem = intval($this->extConf['periodicNotification']);
		if(empty($this->amountOfRecordsToSaveInMem)) $this->amountOfRecordsToSaveInMem = 100;

		// register additional fields which should be written to DB
		if(is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['registerAdditionalFields'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['registerAdditionalFields'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->registerAdditionalFields($this->additionalFields);
			}
		}

		// set some prepare statements
		$this->prepareStatements();

		foreach($configurations as $indexerConfig) {
			$this->indexerConfig = $indexerConfig;

			$path = t3lib_extMgm::extPath('ke_search') . 'indexer/types/class.tx_kesearch_indexer_types_' . $this->indexerConfig['type'] . '.php';
			if(is_file($path)) {
				require_once($path);
				$searchObj = t3lib_div::makeInstance('tx_kesearch_indexer_types_' . $this->indexerConfig['type'], $this);
				$content .= $searchObj->startIndexing();
			}

			// hook for custom indexer
			if(is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['customIndexer'])) {
				foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['customIndexer'] as $_classRef) {
					$_procObj = & t3lib_div::getUserObj($_classRef);
					$content .= $_procObj->customIndexer($indexerConfig, $this);
				}
			}

			// In most cases there are some records waiting in ram to be written to db
			$this->storeTempRecordsToIndex('both');
		}

		// process index cleanup?
		$content .= "\n".'<p><b>Index cleanup processed</b></p>'."\n";
		$content .= $this->cleanUpIndex();

		// clean up process after indezing to free memory
		$this->cleanUpProcessAfterIndexing();

		// print indexing errors
		if (sizeof($this->indexingErrors)) {
			$content .= "\n\n".'<br /><br /><br /><b>INDEXING ERRORS (' . sizeof($this->indexingErrors) . ')<br /><br />'.CHR(10);
			foreach ($this->indexingErrors as $error) {
				$content .= $error . '<br />' . CHR(10);
			}
		}

		// send notification in CLI mode
		if ($mode == 'CLI') {
			// send finishNotification
			if ($extConf['finishNotification'] && t3lib_div::validEmail($extConf['notificationRecipient'])) {

				// calculate and format indexing time
				$indexingTime = time() - $this->startTime;
				if ($indexingTime > 3600) {
					// format hours
					$indexingTime = $indexingTime / 3600;
					$indexingTime = number_format($indexingTime, 2, ',', '.');
					$indexingTime .= ' hours';
				} else if ($indexingTime > 60) {
					// format minutes
					$indexingTime = $indexingTime / 60;
					$indexingTime = number_format($indexingTime, 2, ',', '.');
					$indexingTime .= ' minutes';
				} else {
					$indexingTime .= ' seconds';
				}

				// build message
				$msg = 'Indexing process was finished:'."\n";
				$msg .= "==============================\n\n";
				$msg .= strip_tags($content);
				$msg .= "\n\n".'Indexing process ran '.$indexingTime;

				// send the notification message
				mail($extConf['notificationRecipient'], $extConf['notificationSubject'], $msg);
			}
		}

		// verbose or quiet output? as set in function call!
		if($verbose) return $content;
	}


	/**
	 * prepare sql-statements for indexer
	 *
	 * @return void
	 */
	public function prepareStatements() {
		// create vars to keep statements dynamic
		foreach($this->additionalFields as $value) {
			$addUpdateQuery .= ', ' . $value . ' = ?';
			$addInsertQueryFields .= ', ' . $value;
			$addInsertQueryValues .= ', ?';
		}

		// Statement to check if record already exists in db
		$GLOBALS['TYPO3_DB']->sql_query('PREPARE searchStmt FROM "
			SELECT *
			FROM tx_kesearch_index
			WHERE orig_uid = ?
			AND pid = ?
			AND type = ?
			AND language = ?
			LIMIT 1
		"');

		// Statement to update an existing record in indexer table
		$GLOBALS['TYPO3_DB']->sql_query('PREPARE updateStmt FROM "
			UPDATE tx_kesearch_index
			SET pid=?,
			title=?,
			type=?,
			targetpid=?,
			content=?,
			tags=?,
			params=?,
			abstract=?,
			language=?,
			starttime=?,
			endtime=?,
			fe_group=?,
			tstamp=?' . $addUpdateQuery . '
			WHERE uid=?
		"');

		// Statement to insert a new records to index table
		$GLOBALS['TYPO3_DB']->sql_query('PREPARE insertStmt FROM "
			INSERT INTO tx_kesearch_index
			(pid, title, type, targetpid, content, tags, params, abstract, language, starttime, endtime, fe_group, tstamp, crdate' . $addInsertQueryFields . ')
			VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?' . $addInsertQueryValues . ', ?)
		"');

		// disable keys only if indexer table was truncated (has 0 records)
		// this speeds up the first indexing process
		// don't use this for updating index table
		// if you activate this for updating 40.000 existing records, indexing process needs 1 hour longer
		$countIndex = $GLOBALS['TYPO3_DB']->exec_SELECTcountRows('*', 'tx_kesearch_index', '');
		if($countIndex == 0) $GLOBALS['TYPO3_DB']->sql_query('ALTER TABLE tx_kesearch_index DISABLE KEYS');
	}


	/**
	 * clean up statements
	 *
	 * @return void
	 */
	public function cleanUpProcessAfterIndexing() {
		// enable keys again if this was the first indexing process
		if($countIndex == 0) $GLOBALS['TYPO3_DB']->sql_query('ALTER TABLE tx_kesearch_index ENABLE KEYS');

		$GLOBALS['TYPO3_DB']->sql_query('DEALLOCATE PREPARE searchStmt');
		$GLOBALS['TYPO3_DB']->sql_query('DEALLOCATE PREPARE updateStmt');
		$GLOBALS['TYPO3_DB']->sql_query('DEALLOCATE PREPARE insertStmt');

		// remove all entries from ke_search registry
		$this->registry->removeAllByNamespace('tx_kesearch');
	}


	/**
	 * Delete all index elements that are older than starting timestamp in temporary file
	 *
	 * @return string content for BE
	*/
	function cleanUpIndex() {
		$startMicrotime = microtime(true);
		$table = 'tx_kesearch_index';
		$where = 'tstamp < ' . $this->registry->get('tx_kesearch', 'startTimeOfIndexer');
		$GLOBALS['TYPO3_DB']->exec_DELETEquery($table, $where);

		// check if ke_search_premium is loaded
		// in this case we have to update sphinx index, too.
		if(t3lib_extMgm::isLoaded('ke_search_premium')) {
			$extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ke_search_premium']);
			if(!$extConf['sphinxIndexerName']) $extConf['sphinxIndexerConf'] = '--all';
			if(is_file($extConf['sphinxIndexerPath']) && is_executable($extConf['sphinxIndexerPath']) && file_exists($extConf['sphinxSearchdPath']) && is_executable($extConf['sphinxIndexerPath'])) {
				$found = preg_match_all('/exec|system/', ini_get('disable_functions'), $match);
				if($found === 0) { // executables are allowed
					$ret = system($extConf['sphinxIndexerPath'] . ' --rotate ' . $extConf['sphinxIndexerName']);
					$content .= $ret;
				} elseif($found === 1) { // one executable is allowed
					if($match[0] == 'system') {
						$ret = system($extConf['sphinxIndexerPath'] . ' --rotate ' . $extConf['sphinxIndexerName']);
					} else { // use exec
						exec($extConf['sphinxIndexerPath'] . ' --rotate ' . $extConf['sphinxIndexerName'], $retArr);
						$ret = implode(';', $retArr);
					}
					$content .= $ret;
				} else {
					$content .= 'Check your php.ini configuration for disable_functions. For now it is not allowed to execute a shell script.';
				}
			} else {
				$content .= 'We can\'t find the sphinx executables or execution permission is missing.';
			}
		}

		// calculate duration of indexing process
		$duration = ceil((microtime(true) - $startMicrotime) * 1000);
		$content .= '<p><i>Cleanup process took ' . $duration . ' ms.</i></p>'."\n";
		return $content;
	}


	/**
	 * store collected data of defined indexers to db
	 *
	 * @param integer $storagepid
	 * @param string $title
	 * @param string $type
	 * @param string $targetpid
	 * @param string $content
	 * @param string $tags
	 * @param string $params
	 * @param string $abstract
	 * @param string $language
	 * @param integer $starttime
	 * @param integer $endtime
	 * @param string $fe_group
	 * @param boolean $debugOnly
	 * @param array $additionalFields
	 */
	function storeInIndex($storagePid, $title, $type, $targetPid, $content, $tags='', $params='', $abstract='', $language=0, $starttime=0, $endtime=0, $fe_group, $debugOnly=false, $additionalFields=array()) {
		// if there are errors found in current record return false and break processing
		if(!$this->checkIfRecordHasErrorsBeforeIndexing($storagePid, $title, $type, $targetPid)) return false;
		$table = 'tx_kesearch_index';
		$fieldValues = $this->createFieldValuesForIndexing($storagePid, $title, $type, $targetPid, $content, $tags, $params, $abstract, $language, $starttime, $endtime, $fe_group, $additionalFields);

		// check if record already exists. Average speed: 1-2ms
		if($fieldValues['type'] == 'file') {
			$recordExists = $this->checkIfFileWasIndexed($fieldValues['pid'], $fieldValues['hash']);
		} else {
			$recordExists = $this->checkIfRecordWasIndexed($fieldValues['orig_uid'], $fieldValues['pid'], $fieldValues['type'], $fieldValues['language']);
		}
		if($recordExists) { // update existing record
			$where = 'uid=' . intval($this->currentRow['uid']);
			unset($fieldValues['crdate']);
			if ($debugOnly) { // do not process - just debug query
				t3lib_utility_Debug::debug($GLOBALS['TYPO3_DB']->UPDATEquery($table,$where,$fieldValues),1);
			} else { // process storing of index record and return uid
				$this->prepareRecordForUpdate($fieldValues);
				return true;
			}
		} else { // insert new record
			if($debugOnly) { // do not process - just debug query
				t3lib_utility_Debug::debug($GLOBALS['TYPO3_DB']->INSERTquery($table, $fieldValues, FALSE));
			} else { // process storing of index record and return uid
				$this->prepareRecordForInsert($fieldValues);
				return $GLOBALS['TYPO3_DB']->sql_insert_id();
			}
		}
	}


	/**
	 * Return the query part for additional fields to get prepare statements dynamic
	 *
	 * @param array $fieldValues
	 * @return array containing two query parts
	 */
	public function getQueryPartForAdditionalFields(array $fieldValues) {
		$queryForSet = '';
		$queryForExecute = '';

		foreach($this->additionalFields as $value) {
			$queryForSet .= ', @' . $value . ' = ' . $fieldValues[$value];
			$queryForExecute .= ', @' . $value;
		}
		return array('set' => $queryForSet, 'execute' => $queryForExecute);
	}


	/**
	 * concatnate each query one after the other before executing
	 *
	 * @param array $fieldValues
	 */
	public function prepareRecordForInsert($fieldValues) {
		$addQueryPartFor = $this->getQueryPartForAdditionalFields($fieldValues);

		$queryArray = array();
		$queryArray['set'] = 'SET
			@pid = ' . $fieldValues['pid'] . ',
			@title = ' . $fieldValues['title'] . ',
			@type = ' . $fieldValues['type'] . ',
			@targetpid = ' . $fieldValues['targetpid'] . ',
			@content = ' . $fieldValues['content'] . ',
			@tags = ' . $fieldValues['tags'] . ',
			@params = ' . $fieldValues['params'] . ',
			@abstract = ' . $fieldValues['abstract'] . ',
			@language = ' . $fieldValues['language'] . ',
			@starttime = ' . $fieldValues['starttime'] . ',
			@endtime = ' . $fieldValues['endtime'] . ',
			@fe_group = ' . $fieldValues['fe_group'] . ',
			@tstamp = ' . $fieldValues['tstamp'] . ',
			@crdate = ' . $fieldValues['crdate'] . $addQueryPartFor['set'] . '
		;';
		$queryArray['execute'] = '
			EXECUTE insertStmt USING @pid, @title, @type, @targetpid, @content, @tags, @params, @abstract, @language, @starttime, @endtime, @fe_group, @tstamp, @crdate' . $addQueryPartFor['execute'] . ';
		';

		$this->tempArrayForInsertNewRecords[] = $queryArray;

		// if a defined maximum is reached...store temp records into database
		if(count($this->tempArrayForInsertNewRecords) >= $this->amountOfRecordsToSaveInMem) {
			$this->storeTempRecordsToIndex('insert');
		}
	}


	/**
	 * concatnate each query one after the other before executing
	 *
	 * @param array $fieldValues
	 */
	public function prepareRecordForUpdate($fieldValues) {
		$addQueryPartFor = $this->getQueryPartForAdditionalFields($fieldValues);

		$queryArray = array();
		$queryArray['set'] = 'SET
			@pid = ' . $fieldValues['pid'] . ',
			@title = ' . $fieldValues['title'] . ',
			@type = ' . $fieldValues['type'] . ',
			@targetpid = ' . $fieldValues['targetpid'] . ',
			@content = ' . $fieldValues['content'] . ',
			@tags = ' . $fieldValues['tags'] . ',
			@params = ' . $fieldValues['params'] . ',
			@abstract = ' . $fieldValues['abstract'] . ',
			@language = ' . $fieldValues['language'] . ',
			@starttime = ' . $fieldValues['starttime'] . ',
			@endtime = ' . $fieldValues['endtime'] . ',
			@fe_group = ' . $fieldValues['fe_group'] . ',
			@tstamp = ' . $fieldValues['tstamp'] . $addQueryPartFor['set'] . ',
			@uid = ' . $this->currentRow['uid'] . '
		';

		$queryArray['execute'] = '
			EXECUTE updateStmt USING @pid, @title, @type, @targetpid, @content, @tags, @params, @abstract, @language, @starttime, @endtime, @fe_group, @tstamp' . $addQueryPartFor['execute'] . ', @uid;
		';

		$this->tempArrayForUpdatingExistingRecords[] = $queryArray;

		// if a defined maximum is reached...store temp records into database
		if(count($this->tempArrayForUpdatingExistingRecords) >= $this->amountOfRecordsToSaveInMem) {
			$this->storeTempRecordsToIndex('update');
		}
	}


	/**
	 * This method decides which temp array will be saved
	 *
	 * @param string $which
	 */
	public function storeTempRecordsToIndex($which = 'both') {
		switch($which) {
			case 'insert':
				$this->insertRecordsToIndexer();
				break;
			case 'update':
				$this->updateRecordsInIndexer();
				break;
			case 'both':
			default:
				$this->insertRecordsToIndexer();
				$this->updateRecordsInIndexer();
		}
	}


	/**
	 * Update temporary saved records to db
	 */
	public function updateRecordsInIndexer() {
		$startTime = t3lib_div::milliseconds();
		foreach($this->tempArrayForUpdatingExistingRecords as $query) {
			$GLOBALS['TYPO3_DB']->sql_query($query['set']);
			$GLOBALS['TYPO3_DB']->sql_query($query['execute']);
		}
		if($this->amountOfRecordsToSaveInMem) $this->periodicNotificationCount('update');
		$this->tempArrayForUpdatingExistingRecords = array();
	}


	/**
	 * Insert temporary saved records to db
	 */
	public function insertRecordsToIndexer() {
		$startTime = t3lib_div::milliseconds();
		foreach($this->tempArrayForInsertNewRecords as $query) {
			$GLOBALS['TYPO3_DB']->sql_query($query['set']);
			$GLOBALS['TYPO3_DB']->sql_query($query['execute']);
		}

		if($this->amountOfRecordsToSaveInMem) $this->periodicNotificationCount('insert');
		$this->tempArrayForInsertNewRecords = array();
	}


	/**
	 * try to find an allready indexed record
	 * This function also sets $this->currentRow
	 * parameters should be already fullQuoted. see storeInIndex
	 *
	 * TODO: We should create an index to column type
	 *
	 * @param integer $uid
	 * @param integer $pid
	 * @param string $type
	 * @param integer $language
	 * @return boolean true if record was found, false if not
	 */
	function checkIfRecordWasIndexed($uid, $pid, $type, $language) {
		$GLOBALS['TYPO3_DB']->sql_query('SET @orig_uid = ' . $uid . ', @pid = ' . $pid . ', @type = ' . $type . ', @language = ' . $language);
		$res = $GLOBALS['TYPO3_DB']->sql_query('EXECUTE searchStmt USING @orig_uid, @pid, @type, @language;');
		if(is_resource($res)) {
			if($this->currentRow = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
				return true;
			} return false;
		} else {
			$this->currentRow = array();
			return false;
		}
	}


	/**
	 * try to find an allready indexed record
	 * This function also sets $this->currentRow
	 * parameters should be already fullQuoted. see storeInIndex
	 *
	 * TODO: We should create an index to column type
	 *
	 * @param integer $uid
	 * @param integer $pid
	 * @param string $type
	 * @return boolean true if record was found, false if not
	 */
	function checkIfFileWasIndexed($pid, $hash) {
		$GLOBALS['TYPO3_DB']->sql_query('SET @pid = ' . $pid . ', @hash = ' . $hash);
		$res = $GLOBALS['TYPO3_DB']->sql_query('EXECUTE searchStmt USING @pid, @hash;');
		if(is_resource($res)) {
			if($this->currentRow = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
				return true;
			} return false;
		} else {
			$this->currentRow = array();
			return false;
		}
	}


	/**
	 * Create fieldValues to save them in db later on
	 * sets some default values, too
	 *
	 * @param integer $storagepid
	 * @param string $title
	 * @param string $type
	 * @param string $targetpid
	 * @param string $content
	 * @param string $tags
	 * @param string $params
	 * @param string $abstract
	 * @param string $language
	 * @param integer $starttime
	 * @param integer $endtime
	 * @param string $fe_group
	 * @param array $additionalFields
	 */
	public function createFieldValuesForIndexing($storagepid, $title, $type, $targetpid, $content, $tags='', $params='', $abstract='', $language=0, $starttime=0, $endtime=0, $fe_group, $additionalFields=array()) {
		$now = time();
		$fieldsValues = array(
			'pid' => intval($storagepid),
			'title' => $title,
			'type' => $type,
			'targetpid' => $targetpid,
			'content' => $content,
			'tags' => $tags,
			'params' => $params,
			'abstract' => $abstract,
			'language' => intval($language),
			'starttime' => intval($starttime),
			'endtime' => intval($endtime),
			'fe_group' => $fe_group,
			'tstamp' => $now,
			'crdate' => $now,
		);

		// add all registered additional fields to field value
		// TODO: for no default is string. but in future it should select type automatically (string/int)
		foreach($this->additionalFields as $fieldName) {
			$fieldsValues[$fieldName] = '';
		}

		// merge filled additionalFields with ke_search fields
		if(count($additionalFields)) {
			$fieldsValues = array_merge($fieldsValues, $additionalFields);
		}

		// full quoting record. Average speed: 0-1ms
		$fieldsValues = $GLOBALS['TYPO3_DB']->fullQuoteArray($fieldsValues, 'tx_kesearch_index');

		return $fieldsValues;
	}


	/**
	 * check if there are errors found in record before storing to db
	 *
	 * @param integer $storagePid
	 * @param string $title
	 * @param string $type
	 * @param string $targetPid
	 */
	public function checkIfRecordHasErrorsBeforeIndexing($storagePid, $title, $type, $targetPid) {
		$errors = array();

		// check for empty values
		if (empty($storagePid)) $errors[] = 'No storage PID set';
		if (empty($type)) $errors[] = 'No type set';
		if (empty($targetPid)) $errors[] = 'No target PID set';

		// collect error messages if an error was found
		if (count($errors)) {
			$errormessage = '';
			$errormessage = implode(',', $errors);
			if (!empty($type)) $errormessage .= 'TYPE: ' . $type . '; ';
			if (!empty($targetPid)) $errormessage .= 'TARGET PID: ' . $targetPid . '; ';
			if (!empty($storagePid)) $errormessage .= 'STORAGE PID: ' . $storagePid . '; ';
			$this->indexingErrors[] = ' (' . $errormessage . ')';

			// break indexing and wait for next record to store
			return false;
		} else return true;
	}


	/**
	 * this function returns all indexer configurations found in DB
	 * independant of PID
	 */
	public function getConfigurations() {
		$fields = '*';
		$table = 'tx_kesearch_indexerconfig';
		$where = '1=1 ';
		$where .= t3lib_befunc::BEenableFields($table);
		$where .= t3lib_befunc::deleteClause($table);
		return $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($fields, $table, $where);
	}


	/**
	 * send a periodic notification to the admin
	 *
	 * @param string $which
	 * @return void
	 */
	public function periodicNotificationCount($which = 'both') {
		switch($which) {
			case 'insert':
				$this->counter += count($this->tempArrayForInsertNewRecords);
				break;
			case 'update':
				$this->counter += count($this->tempArrayForUpdatingExistingRecords);
				break;
			case 'both':
			default:
				$this->counter += (count($this->tempArrayForInsertNewRecords) + count($this->tempArrayForUpdatingExistingRecords));
				break;
		}
		// send the notification message
		if(t3lib_div::validEmail($this->extConf['notificationRecipient'])) {
			$msg = $this->counter . ' records have been indexed.' . "\n";
			$msg .= 'Indexer runs ' . (time() - $this->registry->get('tx_kesearch', 'startTimeOfIndexer')) . ' seconds \'til now.';
			mail($this->extConf['notificationRecipient'], $this->extConf['notificationSubject'], $msg);
		}
	}
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/class.tx_kesearch_indexer.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/class.tx_kesearch_indexer.php']);
}
?>
