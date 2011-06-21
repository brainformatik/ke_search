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

	public function __construct($pObj) {
		$this->startMicrotime = microtime(true);
		$this->pObj = $pObj;
		$this->indexerConfig = $this->pObj->indexerConfig;
	}

	/**
	 * shows time used
	 *
	 * @author  Christian Buelter <buelter@kennziffer.com>
	 * @return  string
 	*/
	function showTime() {
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
	function getTag($tagUid, $clearText=false) {
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
