<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2012 Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/


/**
 * Plugin 'Faceted search - searchbox and filters' for the 'ke_search' extension.
 *
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_filters {

	/**
	 * @var tx_kesearch_lib
	 */
	protected $pObj;

	/**
	 * @var tslib_cObj
	 */
	protected $cObj;

	/**
	 * @var tx_kesearch_db
	 */
	protected $db;

	protected $tagChar = '#';
	protected $filters = array();
	protected $conf = array();
	protected $piVars = array();
	protected $extConf = array();
	protected $extConfPremium = array();





	/**
	 * Initializes this object
	 *
	 * @param tx_kesearch_lib $pObj
	 * @return void
	 */
	public function initialize(tx_kesearch_lib $pObj) {
		$this->pObj = $pObj;
		$this->cObj = $pObj->cObj;
		$this->db = t3lib_div::makeInstance('tx_kesearch_db');

		$this->conf = $this->pObj->conf;
		$this->piVars = $this->pObj->piVars;
		$this->startingPoints = $this->pObj->startingPoints;
		$this->tagChar = $this->extConf['prePostTagChar'];
		$this->filters = $this->getFiltersFromUidList($this->conf['filters']);
	}


	/**
	 * get filters and options as associative array
	 *
	 * @return array Filters with including Options
	 */
	public function getFilters() {
		return $this->filters;
	}


	/**
	 * get the filter records from DB which are configured in FlexForm
	 *
	 * @param string $filterUids A commaseperated list of filter uids
	 * @return array Array with filter records
	 */
	public function getFiltersFromUidList($filterUids) {
		if(empty($filterUids)) return array();
		$fields = '*';
		$table = 'tx_kesearch_filters';
		$where = 'pid in (' . $GLOBALS['TYPO3_DB']->quoteStr($this->startingPoints, $table) . ')';
		$where .= ' AND find_in_set(uid, "' . $GLOBALS['TYPO3_DB']->quoteStr($this->conf['filters'], 'tx_kesearch_filters') . '")';
		$where .= $this->cObj->enableFields($table);
		$rows = $this->languageOverlay(
			$GLOBALS['TYPO3_DB']->exec_SELECTgetRows($fields, $table, $where, '', 'find_in_set(uid, "' . $GLOBALS['TYPO3_DB']->quoteStr($this->conf['filters'], 'tx_kesearch_filters') . '")', '', 'uid'),
			$table
		);
		return $this->addOptionsToFilters($rows);
	}


	/**
	 * get the option records from DB which are configured as commaseperate list within the filter records
	 *
	 * @param string $optionUids A commaseperated list of option uids
	 * @return array Array with option records
	 */
	public function getOptionsFromUidList($optionUids) {
		if(empty($optionUids)) return array();
		$fields = '*';
		$table = 'tx_kesearch_filteroptions';
		$where = 'FIND_IN_SET(uid, "' . $GLOBALS['TYPO3_DB']->quoteStr($optionUids, $table) . '")';
		$where .= ' AND pid in (' . $GLOBALS['TYPO3_DB']->quoteStr($this->startingPoints, $table) . ')';
		$where .= $this->cObj->enableFields($table);
		return $this->languageOverlay(
			$GLOBALS['TYPO3_DB']->exec_SELECTgetRows(
				$fields, $table, $where,
				'', 'FIND_IN_SET(uid, "' . $GLOBALS['TYPO3_DB']->quoteStr($optionUids, $table) . '")', '', 'uid'
			),
			$table
		);
	}


	/**
	 * replace the commaseperated option list with the original option records from DB
	 *
	 * @param array $rows The filter records as array
	 * @return array The filter records where the option value was replaced with the option records as array
	 */
	public function addOptionsToFilters(array $rows) {
		if(is_array($rows) && count($rows))  {
			foreach($rows as $key => $row) {
				if(!empty($row['options'])) {
					$rows[$key]['options'] = $this->getOptionsFromUidList($row['options']);
				} else $rows[$key]['options'] = array();
			}
			return $rows;
		} else return array();
	}


	/**
	 * Translate the given records
	 *
	 * @param array $rows The records which have to be translated
	 * @param string $table Define the table from where the records come from
	 * @return array The localized records
	 */
	public function languageOverlay(array $rows, $table) {
		if(is_array($rows) && count($rows))  {
			foreach($rows as $key => $row) {
				if(is_array($row) && $GLOBALS['TSFE']->sys_language_contentOL) {
					$row = $GLOBALS['TSFE']->sys_page->getRecordOverlay(
						$table,	$row,
						$GLOBALS['TSFE']->sys_language_content,
						$GLOBALS['TSFE']->sys_language_contentOL
					);
					$rows[$key] = $row;
				}
			}
			return $rows;
		} else return array();
	}


	/**
	 * check if an allowed tag (defined in a filteroption) was found in the current result list
	 *
	 * @param string $tag The tag to match against the searchresult
	 * @return boolean TRUE if tag was found. Else FALSE
	 */
	public function checkIfTagMatchesRecords($tag) {
		$tagsInSearchResult = $this->pObj->tagsInSearchResult = $this->db->getTagsFromSearchResult();
		$GLOBALS['TSFE']->fe_user->setKey('ses', 'ke_search.tagsInSearchResults', $tagsInSearchResult);
		return array_key_exists($this->tagChar . $tag . $this->tagChar, $tagsInSearchResult);
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/lib/class.tx_kesearch_filters.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/lib/class.tx_kesearch_filters.php']);
}
?>