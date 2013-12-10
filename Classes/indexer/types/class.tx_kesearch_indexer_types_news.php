<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2013 Christian Bülter (kennziffer.com) <buelter@kennziffer.com>
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
 * @author	Stefan Frömken 
 * @author	Christian Bülter (kennziffer.com) <buelter@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_indexer_types_news extends tx_kesearch_indexer_types {

	/**
	 * Initializes indexer for tt_news
	 */
	public function __construct($pObj) {
		parent::__construct($pObj);
	}

	/**
	 * This function was called from indexer object and saves content to index table
	 *
	 * @return string content which will be displayed in backend
	 */
	public function startIndexing() {
		$content = '';
		$table = 'tx_news_domain_model_news';

		// get the pages from where to index the news
		$indexPids = $this->getPidList($this->indexerConfig['startingpoints_recursive'], $this->indexerConfig['sysfolder'], $table);

		// add the tags of each page to the global page array
		if($this->indexerConfig['index_use_page_tags']) {
			$this->pageRecords = $this->getPageRecords($indexPids);
			$this->addTagsToRecords($indexPids);
		}

		// get all the news entries to index, don't index hidden or 
		// deleted news, BUT  get the news with frontend user group 
		// access restrictions or time (start / stop) restrictions.
		// Copy those restrictions to the index.
		$fields = '*';
		$where = 'pid IN (' . implode(',', $indexPids) . ') ';
		$where .= t3lib_befunc::BEenableFields($table);
		$where .= t3lib_befunc::deleteClause($table);
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields, $table, $where);
		$indexedNewsCounter = 0;
		$resCount = $GLOBALS['TYPO3_DB']->sql_num_rows($res);
		if ($resCount) {
			while (($newsRecord = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res))) {

				// get category data for this news record (list of 
				// assigned categories and single view from category, if it exists)
				$categoryData = $this->getCategoryData($newsRecord);

				// If mode equals 2 ('choose categories for indexing') 
				// check if the current news record has one of the categories
				// assigned that should be indexed.
				// mode 1 means 'index all news no matter what category
				// they have'
				if ($this->indexerConfig['index_news_category_mode'] == '2') {

					$isInList = false;
					foreach ($categoryData['uid_list'] as $catUid) {
						// if category was found in list, set isInList 
						// to true and break further processing.
						if(t3lib_div::inList($this->indexerConfig['index_extnews_category_selection'], $catUid)) {
							$isInList = true;
							break;
						}
					}

					// if category was not fount stop further processing 
					// and continue with next news record
					if(!$isInList) {
						continue ;
					}
				}

				// compile the information which should go into the index:
				// title, teaser, bodytext 
				$title = strip_tags($newsRecord['title']);
				$abstract = strip_tags($newsRecord['teaser']);
				$content = strip_tags($newsRecord['bodytext']);

				// add additional fields to the content:
				// alternative_title, author, author_email, keywords
				if (isset($newsRecord['author'])) {
					$content .= "\n" . strip_tags($newsRecord['author']);
				}
				if (isset($newsRecord['author_email'])) {
					$content .= "\n" . strip_tags($newsRecord['author_email']);
				}
				if (!empty($newsRecord['keywords'])) {
					$content .= "\n" . $newsRecord['keywords'];
				}

				// TODO:
				// in ext:news it is possible to assign content elements
				// to news elements. This content elements should be indexed.

				// create content
				$fullContent = '';
				if (isset($abstract)) {
					$fullContent .= $abstract . "\n";
				}
				$fullContent .= $content;

				// compile params for single view, example:
				// index.php?id=123&tx_news_pi1[news]=9&tx_news_pi1[controller]=News&tx_news_pi1[action]=detail
				$paramsSingleView['tx_news_pi1']['news'] = $newsRecord['uid'];
				$paramsSingleView['tx_news_pi1']['controller'] = 'News';
				$paramsSingleView['tx_news_pi1']['action'] = 'detail';
				$params = '&' . http_build_query($paramsSingleView, NULL, '&');
				$params = rawurldecode($params);

				// add tags from pages
				if ($this->indexerConfig['index_use_page_tags']) {
					$tags = $this->pageRecords[intval($newsRecord['pid'])]['tags'];
				} else {
					$tags = '';
				}

				// add keywords from ext:news as tags
				$tags = $this->addTagsFromNewsKeywords($tags, $newsRecord);

				// add tags from ext:news as tags
				$tags = $this->addTagsFromNewsTags($tags, $newsRecord);

				// add categories from from ext:news as tags
				$tags = $this->addTagsFromNewsCategories($tags, $categoryData);

				// make it possible to modify the indexerConfig via hook
				$indexerConfig = $this->indexerConfig;

				// overwrite the targetpid if there is a category assigned
				// which has its own single view page
				if ($categoryData['single_pid']) {
					$indexerConfig['targetpid'] = $categoryData['single_pid'];
				}

				// set additional fields
				$additionalFields = array();
				$additionalFields['orig_uid'] = $newsRecord['uid'];
				$additionalFields['orig_pid'] = $newsRecord['pid'];
				$additionalFields['sortdate'] = $newsRecord['crdate'];
				if(isset($newsRecord['datetime']) && $newsRecord['datetime'] > 0) {
					$additionalFields['sortdate'] = $newsRecord['datetime'];
				}

				// hook for custom modifications of the indexed data, e.g. the tags
				if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyExtNewsIndexEntry'])) {
					foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyExtNewsIndexEntry'] as $_classRef) {
						$_procObj = & t3lib_div::getUserObj($_classRef);
						$_procObj->modifyExtNewsIndexEntry(
							$title,
							$abstract,
							$fullContent,
							$params,
							$tags,
							$newsRecord,
							$additionalFields,
							$indexerConfig,
							$categoryData,
							$this
						);
					}
				}

				// store this record to the index
				$this->pObj->storeInIndex(
					$indexerConfig['storagepid'],	// storage PID
					$title,                         // page title
					'news',                       	// content type
					$indexerConfig['targetpid'],    // target PID: where is the single view?
					$fullContent,                   // indexed content, includes the title (linebreak after title)
					$tags,                          // tags
					$params,                        // typolink params for singleview
					$abstract,                      // abstract
					$newsRecord['sys_language_uid'],// language uid
					$newsRecord['starttime'],       // starttime
					$newsRecord['endtime'],         // endtime
					$newsRecord['fe_group'],        // fe_group
					false,                          // debug only?
					$additionalFields               // additional fields added by hooks
				);
				$indexedNewsCounter++;
			}

			$content = '<p><b>Indexer "' . $this->indexerConfig['title'] . '":</b><br />' . "\n"
					. $indexedNewsCounter . ' News have been indexed.</p>' . "\n";

			$content .= $this->showErrors();
			$content .= $this->showTime();
		}
		return $content;
	}


	/**
	 * checks if there is a category assigned to the $newsRecord which has
	 * its own single view page and if yes, returns the uid of the page
	 * in $catagoryData['single_pid'].
	 * It also compiles a list of all assigned categories and returns
	 * it as an array in $categoryData['uid_list']. The titles of the
	 * categories are returned in $categoryData['title_list'] (array)
	 * 
	 * @author Christian Bülter <buelter@kennziffer.com>
	 * @since 26.06.13 14:34
	 * @param type $newsRecord
	 * @return int
	 */
	private function getCategoryData($newsRecord) {
		$categoryData = array(
		    'single_pid' => 0,
		    'uid_list' => array(),
		    'title_list' => array()
		);

		$resCat = $GLOBALS['TYPO3_DB']->exec_SELECT_mm_query(
			'tx_news_domain_model_category.uid, tx_news_domain_model_category.single_pid, tx_news_domain_model_category.title',
			'tx_news_domain_model_news',
			'tx_news_domain_model_news_category_mm',
			'tx_news_domain_model_category',
			' AND tx_news_domain_model_news.uid = ' . $newsRecord['uid'] .
			t3lib_befunc::BEenableFields('tx_news_domain_model_category') .
			t3lib_befunc::deleteClause('tx_news_domain_model_category'),
			'', // groupBy
			'tx_news_domain_model_news_category_mm.sorting' // orderBy
			
		);

		while (($newsCat = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($resCat))) {
			$categoryData['uid_list'][] = $newsCat['uid'];
			$categoryData['title_list'][] = $newsCat['title'];
			if ($newsCat['single_pid'] && !$categoryData['single_pid']) {
				$categoryData['single_pid'] = $newsCat['single_pid'];
			}
		}

		return $categoryData;
	}

	/**
	 * adds tags from the ext:news "keywords" field to the index entry
	 * 
	 * @author Christian Bülter <buelter@kennziffer.com>
	 * @since 26.06.13 14:27
	 * @param string $tags
	 * @param array $newsRecord
	 * @return string
	 */
	private function addTagsFromNewsKeywords($tags, $newsRecord) {
		if (!empty($newsRecord['keywords'])) {
			$keywordsList = t3lib_div::trimExplode(',', $newsRecord['keywords']);
			foreach ($keywordsList as $keyword) {
				if (!empty($tags)) {
					$tags .= ',';
				}
				$tags .= $this->pObj->extConf['prePostTagChar'] . $keyword . $this->pObj->extConf['prePostTagChar'];
			}
		}

		return $tags;
	}

	/**
	 * Adds tags from the ext:news table "tags" as ke_search tags to the index entry
	 * 
	 * @author Christian Bülter <buelter@kennziffer.com>
	 * @since 26.06.13 14:25
	 * @param string $tags
	 * @param array $newsRecord
	 * @return string comma-separated list of tags
	 */
	private function addTagsFromNewsTags($tags, $newsRecord) {
		$resTag = $GLOBALS['TYPO3_DB']->exec_SELECT_mm_query(
			'tx_news_domain_model_tag.title',
			'tx_news_domain_model_news',
			'tx_news_domain_model_news_tag_mm',
			'tx_news_domain_model_tag',
			' AND tx_news_domain_model_news.uid = ' . $newsRecord['uid'] .
			t3lib_befunc::BEenableFields('tx_news_domain_model_tag') .
			t3lib_befunc::deleteClause('tx_news_domain_model_tag')
		);
			
		while (($newsTag = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($resTag))) {
			if (!empty($tags)) {
				$tags .= ',';
			}
			$tags .= $this->pObj->extConf['prePostTagChar'] . $newsTag['title'] . $this->pObj->extConf['prePostTagChar'];
		}

		return $tags;
	}

	/**
	 * creates tags from category titles
	 * removes # and , and space
	 * 
	 * @author Christian Bülter <buelter@kennziffer.com>
	 * @since 26.06.13 15:49
	 * @param string $tags
	 * @param array $categoryData
	 * @return string
	 */
	private function addTagsFromNewsCategories($tags, $categoryData) {
		foreach ($categoryData['title_list'] as $catTitle) {
			if (!empty($tags)) {
				$tags .= ',';
			}
			$catTitle = str_replace('#', '', $catTitle);
			$catTitle = str_replace(',', '', $catTitle);
			$catTitle = str_replace(' ', '', $catTitle);
			$catTitle = str_replace('(', '', $catTitle);
			$catTitle = str_replace(')', '', $catTitle);
			$catTitle = str_replace('_', '', $catTitle);
			$tags .= $this->pObj->extConf['prePostTagChar'] . $catTitle . $this->pObj->extConf['prePostTagChar'];
		}

		return $tags;
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/Classes/indexer/types/class.tx_kesearch_indexer_types_ttnews.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/Classes/indexer/types/class.tx_kesearch_indexer_types_ttnews.php']);
}
?>