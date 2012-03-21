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

require_once(t3lib_extMgm::extPath('ke_search').'indexer/class.tx_kesearch_indexer_types.php');

/**
 * Plugin 'Faceted search' for the 'ke_search' extension.
 *
 * @author	Andreas Kiefer (kennziffer.com) <kiefer@kennziffer.com>
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_indexer_types_page extends tx_kesearch_indexer_types {
	var $pids              = 0;
	var $pageRecords       = array(); // this array contains all data of all pages
	var $cachedPageRecords = array(); // this array contains all data of all pages, but additionally with all available languages
	var $sysLanguages      = array();
	var $indexCTypes       = array(
		'text',
		'textpic',
		'bullets',
		'table',
		'html'
	);
	var $counter = 0;
	var $whereClauseForCType = '';

	// Name of indexed elements. Will be overwritten in content element indexer.
	var $indexedElementsName = 'pages';

	/**
	 * @var t3lib_queryGenerator
	 */
	var $queryGen;



	/**
	 * Initializes indexer for pages
	 */
	public function __construct($pObj) {
		parent::__construct($pObj);

		$this->counter = 0;
		foreach($this->indexCTypes as $value) {
			$cTypes[] = 'CType="' . $value . '"';
		}
		$this->whereClauseForCType = implode(' OR ', $cTypes);

		// get all available sys_language_uid records
		$this->sysLanguages = t3lib_BEfunc::getSystemLanguages();


		// we need this object to get all contained pids
		$this->queryGen = t3lib_div::makeInstance('t3lib_queryGenerator');
	}


	/**
	 * This function was called from indexer object and saves content to index table
	 *
	 * @return string content which will be displayed in backend
	 */
	public function startIndexing() {
		// get all pages. Regardeless if they are shortcur, sysfolder or external link
		$indexPids = $this->getPagelist();

		// add complete page record to list of pids in $indexPids
		// and remove all page of type shortcut, sysfolder and external link
		$this->pageRecords = $this->getPageRecords($indexPids);

		// create a new list of allowed pids
		$indexPids = array_keys($this->pageRecords);

		// add the tags of each page to the global page array
		$this->addTagsToPageRecords($indexPids);

		// loop through pids and collect page content and tags
		foreach($indexPids as $uid) {
			if($uid) $this->getPageContent($uid);
		}

		// show indexer content?
		$content .= '<p><b>Indexer "' . $this->indexerConfig['title'] . '": </b><br />'
			. count($this->pageRecords) . ' pages have been found for indexing.<br />' . "\n"
			. $this->counter . ' ' . $this->indexedElementsName . ' have been indexed.<br />' . "\n"
			. '</p>' . "\n";

		$content .= $this->showTime();

		return $content;
	}


	/**
	 * get all recursive contained pids of given Page-UID
	 *
	 * @return array List of page UIDs
	 */
	public function getPagelist() {
		// make array from list
		$pidsRecursive = t3lib_div::trimExplode(',', $this->indexerConfig['startingpoints_recursive'], true);
		$pidsNonRecursive = t3lib_div::trimExplode(',', $this->indexerConfig['single_pages'], true);

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
	 * additionally generates a cachedPageArray
	 *
	 * @param array Array with all page cols
	 */
	public function getPageRecords($uids) {
		$fields = '*';
		$table = 'pages';
		$where = 'uid IN (' . implode(',', $uids) . ')';

		// index only pages of doktype standard, advanced and "not in menu"
		$where .= ' AND (doktype = 1 OR doktype = 2 OR doktype = 5) ';

		// index only pages which are searchable
		// index only page which are not hidden
		$where .= ' AND no_search <> 1 AND hidden=0';

		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields, $table, $where);

		while($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			$this->addLocalizedPagesToCache($row);
			$pages[$row['uid']] = $row;
		}
		return $pages;
	}


	/**
	 * add localized page records to a cache/globalArray
	 * This is much faster than requesting the DB for each tt_content-record
	 *
	 * @param array $row
	 * @return void
	 */
	public function addLocalizedPagesToCache($row) {
		$this->cachedPageRecords[0][$row['uid']] = $row;
		foreach($this->sysLanguages as $sysLang) {
			list($pageOverlay) = t3lib_BEfunc::getRecordsByField(
				'pages_language_overlay',
				'pid',
				$row['uid'],
				'AND sys_language_uid=' . intval($sysLang[1])
			);
			if($pageOverlay) {
				$this->cachedPageRecords[$sysLang[1]][$row['uid']] = t3lib_div::array_merge(
					$row,
					$pageOverlay
				);
			}
		}
	}


	/**
	 * Add Tags to pages array
	 *
	 * @param array Simple array with uids of pages
	 * @return array extended array with uids and tags for pages
	 */
	public function addTagsToPageRecords($uids) {
		$tagChar = $this->pObj->extConf['prePostTagChar'];
		// add tags which are defined by page properties
		$fields = 'pages.*, GROUP_CONCAT(CONCAT("' . $tagChar . '", tx_kesearch_filteroptions.tag, "' . $tagChar . '")) as tags';
		$table = 'pages, tx_kesearch_filteroptions';
		$where = 'pages.uid IN (' . implode(',', $uids) . ')';
		$where .= ' AND pages.tx_kesearch_tags <> "" ';
		$where .= ' AND FIND_IN_SET(tx_kesearch_filteroptions.uid, pages.tx_kesearch_tags)';
		$where .= t3lib_befunc::BEenableFields('tx_kesearch_filteroptions');
		$where .= t3lib_befunc::deleteClause('tx_kesearch_filteroptions');

		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields, $table, $where, 'pages.uid', '', '');
		while($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			$this->pageRecords[$row['uid']]['tags'] = $row['tags'];
		}

		// add tags which are defined by filteroption records

		$fields = 'automated_tagging, tag';
		$table = 'tx_kesearch_filteroptions';
		$where = 'automated_tagging <> "" ';
		$where .= t3lib_befunc::BEenableFields('tx_kesearch_filteroptions');
		$where .= t3lib_befunc::deleteClause('tx_kesearch_filteroptions');

		$rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($fields, $table, $where);

		// index only pages of doktype standard, advanced and "not in menu"
		$where = ' (doktype = 1 OR doktype = 2 OR doktype = 5) ';
		// index only pages which are searchable
		$where .= ' AND no_search <> 1 ';

		foreach($rows as $row) {
			$tempTags = array();
			$pageList = t3lib_div::trimExplode(',', $this->queryGen->getTreeList($row['automated_tagging'], 99, 0, $where));
			foreach($pageList as $uid) {
				if($this->pageRecords[$uid]['tags']) {
					$this->pageRecords[$uid]['tags'] .= ',' . $tagChar . $row['tag'] . $tagChar;
				} else $this->pageRecords[$uid]['tags'] = $tagChar . $row['tag'] . $tagChar;
			}
		}
	}


	/**
	 * get content of current page and save data to db
	 * @param $uid page-UID that has to be indexed
	 */
	public function getPageContent($uid) {

		// TODO: index all language versions of this page
		// pages.uid <=> pages_language_overlay.pid
		// language id = pages_language_overlay.sys_language_uid

		// get content elements for this page
		$fields = 'header, bodytext, CType, sys_language_uid';
		$table = 'tt_content';
		$where = 'pid = ' . intval($uid);
		$where .= ' AND (' . $this->whereClauseForCType. ')';
		$where .= t3lib_BEfunc::BEenableFields($table);
		$where .= t3lib_BEfunc::deleteClause($table);

		// if indexing of content elements with restrictions is not allowed
		// get only content elements that have empty group restrictions
		if($this->indexerConfig['index_content_with_restrictions'] != 'yes') {
			$where .= ' AND (fe_group = "" OR fe_group = "0") ';
		}

		$rows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($fields, $table, $where);
		if(count($rows)) {
			foreach($rows as $row) {
				// header
				$pageContent[$row['sys_language_uid']] .= strip_tags($row['header']) . "\n";

				// bodytext
				$bodytext = $row['bodytext'];

				// following lines prevents having words one after the other like: HelloAllTogether
				$bodytext = str_replace('<td', ' <td', $bodytext);
				$bodytext = str_replace('<br', ' <br', $bodytext);
				$bodytext = str_replace('<p', ' <p', $bodytext);
				$bodytext = str_replace('<li', ' <li', $bodytext);

				if ($row['CType'] == 'table') {
					// replace table dividers with whitespace
					$bodytext = str_replace('|', ' ', $bodytext);
				}
				$bodytext = strip_tags($bodytext);

				$pageContent[$row['sys_language_uid']] .= $bodytext."\n";
			}
			$this->counter++;
		} else {
			return;
		}

			// get Tags for current page
		$tags = $this->pageRecords[intval($uid)]['tags'];

			// make it possible to modify the indexerConfig via hook
		$indexerConfig = $this->indexerConfig;

			// hook for custom modifications of the indexed data, e. g. the tags
		if(is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyPagesIndexEntry'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyPagesIndexEntry'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->modifyPagesIndexEntry(
					$uid,
					$pageContent,
					$tags,
					$this->cachedPageRecords,
					$additionalFields,
					$indexerConfig
				);
			}
		}

		// store record in index table
		foreach($pageContent as $langKey => $content) {
			$this->pObj->storeInIndex(
				$indexerConfig['storagepid'],                    		// storage PID
				$this->cachedPageRecords[$langKey][$uid]['title'],     	// page title
				'page',                                                	// content type
				$uid,                                                  	// target PID: where is the single view?
				$content,                                             	// indexed content, includes the title (linebreak after title)
				$tags,                                                 	// tags
				'',                                                    	// typolink params for singleview
				'',                                                    	// abstract
				$langKey,                                              	// language uid
				$this->cachedPageRecords[$langKey][$uid]['starttime'], 	// starttime
				$this->cachedPageRecords[$langKey][$uid]['endtime'],   	// endtime
				$this->cachedPageRecords[$langKey][$uid]['fe_group'],  	// fe_group
				false,                                                 	// debug only?
				$additionalFields                                      	// additional fields added by hooks
			);
		}

		return;
	}
}
?>
