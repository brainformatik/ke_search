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
class tx_kesearch_indexer_types {
	var $startMicrotime = 0;
	var $indexerConfig = array(); // current indexer configuration

	/**
	 * @var tx_kesearch_indexer
	 */
	var $pObj;

	/**
	 * needed to get all recursive pids
	 *
	 * @var t3lib_queryGenerator
	 */
	var $queryGen;





	/**
	 * Constructor of this object
	 *
	 * @param $pObj
	 */
	public function __construct($pObj) {
		$this->startMicrotime = microtime(true);
		$this->pObj = $pObj;
		$this->indexerConfig = $this->pObj->indexerConfig;
		$this->queryGen = t3lib_div::makeInstance('t3lib_queryGenerator');
	}


	/**
	 * get all recursive contained pids of given Page-UID
	 * regardless if we need them or if they are sysfolders, links or what ever
	 *
	 * @param string $startingPointsRecursive comma-separated list of pids of recursive start-points
	 * @param string $singlePages comma-separated list of pids of single pages
	 * @return array List of page UIDs
	 */
	public function getPagelist($startingPointsRecursive = '', $singlePages = '') {
		// make array from list
		$pidsRecursive = t3lib_div::trimExplode(',', $startingPointsRecursive, true);
		$pidsNonRecursive = t3lib_div::trimExplode(',', $singlePages, true);

		// add recursive pids
		foreach($pidsRecursive as $pid) {
			$pageList .= $this->queryGen->getTreeList($pid, 99, 0, '1=1');
		}

		// add non-recursive pids
		foreach($pidsNonRecursive as $pid) {
			$pageList .= $pid . ',';
		}
		return t3lib_div::trimExplode(',', $pageList, true);
	}


	/**
	 * get array with all pages
	 * but remove all pages we don't want to have
	 *
	 * @param array $uids Array with all page uids
	 * @param string $whereClause Additional where clause for the query
	 * @param string $table The table to select the fields from
	 * @param fields $fields The requested fields
	 * @return array Array containing page records with all available fields
	 */
	public function getPageRecords(array $uids, $whereClause = '', $table = 'pages', $fields = 'pages.*' ) {
		$where = 'pages.uid IN (' . implode(',', $uids) . ') ';
		// index only pages which are searchable
		// index only page which are not hidden
		$where .= ' AND pages.no_search <> 1 AND pages.hidden=0 AND pages.deleted=0';

		// additional where clause
		$where .= $whereClause;

		$pages = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
			$fields,
			$table,
			$where,
			'', '', '', 'uid'
		);

		return $pages;
	}


	/**
	 * get a list of pids
	 *
	 * @param string $startingPointsRecursive
	 * @param string $singlePages
	 * @param string $table
	 */
	public function getPidList($startingPointsRecursive = '', $singlePages = '', $table = 'pages') {
		// get all pages. Regardless if they are shortcut, sysfolder or external link
		$indexPids = $this->getPagelist($startingPointsRecursive, $singlePages);
		// add complete page record to list of pids in $indexPids
		$where = ' AND ' . $table .'.pid = pages.uid ';
		$where .= t3lib_befunc::BEenableFields($table);
		$where .= t3lib_befunc::deleteClause($table);
		$this->pageRecords = $this->getPageRecords($indexPids, $where, 'pages,' . $table, 'DISTINCT pages.uid' );
		// create a new list of allowed pids
		return array_keys($this->pageRecords);
	}


	/**
	 * shows time used
	 *
	 * @author  Christian Buelter <buelter@kennziffer.com>
	 * @return  string
 	*/
	public function showTime() {
		// calculate duration of indexing process
		$endMicrotime = microtime(true);
		$duration = ceil(($endMicrotime - $this->startMicrotime) * 1000);

		// show sec or ms?
		if ($duration > 10000) {
			$duration /= 1000;
			$duration = intval($duration);
			return '<p><i>Indexing process for "' . $this->indexerConfig['title'] . '" took '.$duration.' s.</i> </p>'."\n\n";
		} else {
			return '<p><i>Indexing process for "' . $this->indexerConfig['title'] . '" took '.$duration.' ms.</i> </p>'."\n\n";
		}
	}


	/*
	 * function getTag
	 * @param int $tagUid
	 * @param bool $clearText
	 */
	public function getTag($tagUid, $clearText=false) {
		$fields = 'title,tag';
		$table = 'tx_kesearch_filteroptions';
		$where = 'uid="'.intval($tagUid).'" ';
		$where .= t3lib_befunc::BEenableFields($table,$inv=0);
		$where .= t3lib_befunc::deleteClause($table,$inv=0);
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='1');
		$anz = $GLOBALS['TYPO3_DB']->sql_num_rows($res);
		$row=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);

		if ($clearText) {
			return $row['title'];
		} else {
			return $row['tag'];
		}
	}
}