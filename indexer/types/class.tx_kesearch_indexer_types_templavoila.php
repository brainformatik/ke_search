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
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/

require_once(t3lib_extMgm::extPath('ke_search') . 'indexer/class.tx_kesearch_indexer_types.php');

/**
 * Plugin 'Faceted search' for the 'ke_search' extension.
 *
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_indexer_types_templavoila extends tx_kesearch_indexer_types {
	var $pids              = 0;
	var $pageRecords       = array(); // this array contains all data of all pages
	var $cachedPageRecords = array(); // this array contains all data of all pages, but additionally with all available languages
	var $sysLanguages      = array();
	var $indexCTypes       = array(
		'text',
		'textpic',
		'bullets',
		'table',
		'html',
		'header'
	);
	var $counter = 0;
	var $whereClauseForCType = '';

	// Name of indexed elements. Will be overwritten in content element indexer.
	var $indexedElementsName = 'pages';

	/**
	 * @var tx_templavoila_api
	 */
	var $tv;





	/**
	 * Construcor of this object
	 */
	public function __construct($pObj) {
		parent::__construct($pObj);

		if(t3lib_extMgm::isLoaded('templavoila')) {
			$this->tv = t3lib_div::makeInstance('tx_templavoila_api');
		}

		$this->counter = 0;
		foreach($this->indexCTypes as $value) {
			$cTypes[] = 'CType="' . $value . '"';
		}
		$this->whereClauseForCType = implode(' OR ', $cTypes);

		// get all available sys_language_uid records
		$this->sysLanguages = t3lib_BEfunc::getSystemLanguages();
	}


	/**
	 * This function was called from indexer object and saves content to index table
	 *
	 * @return string content which will be displayed in backend
	 */
	public function startIndexing() {
		if(!t3lib_extMgm::isLoaded('templavoila')) {
			return 'TemplaVoila was not installed';
		}

		// get all pages. Regardeless if they are shortcut, sysfolder or external link
		$indexPids = $this->getPagelist($this->indexerConfig['startingpoints_recursive'], $this->indexerConfig['single_pages']);

		// add complete page record to list of pids in $indexPids
		$this->pageRecords = $this->getPageRecords($indexPids);

		// create a new list of allowed pids
		$indexPids = array_keys($this->pageRecords);

		// index only pages of doktype standard, advanced, shortcut and "not in menu"
		$where = ' (doktype = 1 OR doktype = 2 OR doktype = 4 OR doktype = 5) ';

		// add the tags of each page to the global page array
		$this->addTagsToPageRecords($indexPids, $where);

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
	 * get content of current page and save data to db
	 * @param $uid page-UID that has to be indexed
	 */
	public function getPageContent($uid) {
		// Define the root element record:
		$this->rootElementTable = 'pages';
		$this->rootElementUid = $uid;
		$this->rootElementRecord = t3lib_BEfunc::getRecordWSOL($this->rootElementTable, $this->rootElementUid, '*');
		if($this->rootElementRecord['t3ver_swapmode']==0 && $this->rootElementRecord['_ORIG_uid'] ) {
			$this->rootElementUid_pidForContent = $this->rootElementRecord['_ORIG_uid'];
		} elseif($this->rootElementRecord['t3ver_swapmode']==-1 && $this->rootElementRecord['t3ver_oid'] && $this->rootElementRecord['pid'] < 0) {
			if($this->rootElementTable == 'pages') {
				$this->rootElementUid_pidForContent = $this->rootElementRecord['t3ver_oid'];
			}
		} else $this->rootElementUid_pidForContent = $this->rootElementRecord['uid'];

		// header
		// add header only if not set to "hidden"
		if ($row['header_layout'] != 100) {
			$pageContent[$row['sys_language_uid']] .= strip_tags($row['header']) . "\n";
		}

		$contentTreeData = $this->tv->getContentTree($this->rootElementTable, $this->rootElementRecord);

		// bodytext
		t3lib_utility_Debug::debug($contentTreeData, 'content');

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
