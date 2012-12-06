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
class tx_kesearch_indexer_types_ttnews extends tx_kesearch_indexer_types {

	/**
	 * Initializes indexer for tt_news
	 */
	public function __construct($pObj) {
		parent::__construct($pObj);
	}


	/**
	 * converts the datetime of a record into variables you can use in realurl
	 *
	 * @param	integer the timestamp to convert into a HR date
	 * @return array
	 */
	function getParamsForHrDateSingleView($tstamp) {

		if($this->conf['useHRDatesSingle']) {
			$params = array('tx_ttnews' => array(
				'year' => date('Y', $tstamp),
				'month' => date('m', $tstamp),
			));
			if(!$this->conf['useHRDatesSingleWithoutDay']) {
				$params['tx_ttnews']['day'] = date('d', $tstamp);
			}
		} else {
			return array();
		}
		return $params;
	}


	/**
	 * This function was called from indexer object and saves content to index table
	 *
	 * @return string content which will be displayed in backend
	 */
	public function startIndexing() {
		$content = '';

		$this->conf['useHRDatesSingle'] = $this->indexerConfig['index_news_useHRDatesSingle'];
		$this->conf['useHRDatesSingleWithoutDay'] = $this->indexerConfig['index_news_useHRDatesSingleWithoutDay'];

			// get all the tt_news entries to index
			// don't index hidden or deleted news, BUT
			// get the news with frontend user group access restrictions
			// or time (start / stop) restrictions.
			// Copy those restrictions to the index.
		$fields = '*';
		$table = 'tt_news';
		$indexPids = $this->getPidList($this->indexerConfig['startingpoints_recursive'], $this->indexerConfig['sysfolder'], $table);
		if($this->indexerConfig['index_use_page_tags']) {
			// add the tags of each page to the global page array
			$this->pageRecords = $this->getPageRecords($indexPids);
			$this->addTagsToRecords($indexPids);
		}

		$where = 'pid IN (' . implode(',', $indexPids) . ') ';
		$where .= t3lib_befunc::BEenableFields($table,$inv=0);
		$where .= t3lib_befunc::deleteClause($table,$inv=0);
		$groupBy = '';
		$orderBy = '';
		$limit = '';
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy,$orderBy,$limit);
		$resCount = $GLOBALS['TYPO3_DB']->sql_num_rows($res);
		if ($resCount) {
			while ( ($newsRecord = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) ) {

				// if mode equals 'choose categories for indexing' (2). 1 = All
				if($this->indexerConfig['index_news_category_mode'] == '2') {
					$resCat = $GLOBALS['TYPO3_DB']->exec_SELECT_mm_query(
						'tt_news_cat.uid',
						'tt_news',
						'tt_news_cat_mm',
						'tt_news_cat',
						' AND tt_news.uid = ' . $newsRecord['uid'] .
						t3lib_befunc::BEenableFields('tt_news_cat') .
						t3lib_befunc::deleteClause('tt_news_cat'),
						'', '', ''
					);
					if(is_resource($resCat)) {
						$isInList = false;
						while($newsCat = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($resCat)) {
							// if category was found in list, set isInList to true and break further processing.
							if(t3lib_div::inList($this->indexerConfig['index_news_category_selection'], $newsCat['uid'])) {
								$isInList = true;
								break;
							}
						}
						// if category was not fount stop further processing and loop with next news record
						if(!$isInList) {
							continue ;
						}
					}
				}

				// compile the information which should go into the index
				$title = strip_tags($newsRecord['title']);
				$abstract = strip_tags($newsRecord['short']);
				$content = strip_tags($newsRecord['bodytext']);

					// add keywords to content if not empty
				if (!empty($newsRecord['keywords'])) {
					$content .= "\n".$newsRecord['keywords'];
				}

				// create content
				$fullContent = $abstract . "\n" . $content;

				// create params
				$paramsSingleView = $this->getParamsForHrDateSingleView($newsRecord['datetime']);
				$paramsSingleView['tx_ttnews']['tt_news'] = $newsRecord['uid'];
				$params = '&' . http_build_query($paramsSingleView, NULL, '&');
				$params = rawurldecode($params);

				// create tags
				if($this->indexerConfig['index_use_page_tags']) {
					$tags = $this->pageRecords[intval($newsRecord['pid'])]['tags'];
				} else $tags = '';

				$additionalFields = array();

					// make it possible to modify the indexerConfig via hook
				$indexerConfig = $this->indexerConfig;

					// hook for custom modifications of the indexed data, e. g. the tags
				if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyNewsIndexEntry'])) {
					foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyNewsIndexEntry'] as $_classRef) {
						$_procObj = & t3lib_div::getUserObj($_classRef);
						$_procObj->modifyNewsIndexEntry(
							$title,
							$abstract,
							$fullContent,
							$params,
							$tags,
							$newsRecord,
							$additionalFields,
							$indexerConfig
						);
					}
				}

				// ... and store them
				$this->pObj->storeInIndex(
					$indexerConfig['storagepid'],    // storage PID
					$title,                          // page title
					'tt_news',                       // content type
					$indexerConfig['targetpid'],     // target PID: where is the single view?
					$fullContent,                    // indexed content, includes the title (linebreak after title)
					$tags,                           // tags
					$params,                         // typolink params for singleview
					$abstract,                       // abstract
					$newsRecord['sys_language_uid'], // language uid
					$newsRecord['starttime'],        // starttime
					$newsRecord['endtime'],          // endtime
					$newsRecord['fe_group'],         // fe_group
					false,                           // debug only?
					$additionalFields                // additional fields added by hooks
				);
			}
			$content = '<p><b>Indexer "' . $this->indexerConfig['title'] . '":</b><br />' . "\n"
					. $resCount . ' News have been indexed.</p>' . "\n";
			$content .= $this->showTime();
		}
		return $content;
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/types/class.tx_kesearch_indexer_types_ttnews.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/types/class.tx_kesearch_indexer_types_ttnews.php']);
}
?>