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
class tx_kesearch_indexer_types_dam extends tx_kesearch_indexer_types {

	/**
	 * Initializes indexer for dam
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

		// get categories
		$this->catList = $this->indexerConfig['index_dam_categories'].',';

		// add recursive categories if set in indexer config
		if ($this->indexerConfig['index_dam_categories_recursive']) {
			$categoriesArray = t3lib_div::trimExplode(',', $this->indexerConfig['index_dam_categories'], true);
			foreach ($categoriesArray as $key => $catUid) {
				$this->getRecursiveDAMCategories($catUid);
			}
		}
		// make unique list values
		$this->catList = t3lib_div::uniqueList($this->catList);

		// get dam records from categories
		$fields = 'tx_dam.*';
		$table = 'tx_dam_mm_cat, tx_dam';
		$where = 'uid_foreign IN ('.$this->catList.')';
		$where .= ' AND tx_dam_mm_cat.uid_local = tx_dam.uid ';
		$where .= t3lib_befunc::BEenableFields('tx_dam',$inv=0);
		$where .= t3lib_befunc::deleteClause('tx_dam',$inv=0);
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='');

		$resCount = $GLOBALS['TYPO3_DB']->sql_num_rows($res);
		if ($resCount) {
			while ($damRecord=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {

				$additionalFields = array();

				// prepare content for storing in index table
				$title = strip_tags($damRecord['title']);
				$params = '&tx_kedownloadshop_pi1[showUid]='.intval($damRecord['uid']);
				$abstract = str_replace('<br />', chr(13), $damRecord['tx_kedownloadshop_teaser']);
				$abstract = str_replace('<br>', chr(13), $abstract);
				$abstract = str_replace('</p>', chr(13), $abstract);
				$abstract = strip_tags($abstract);
				$content = strip_tags($damRecord['tx_kedownloadshop_description']);
				$pagetitle = strip_tags($damRecord['tx_kedownloadshop_pagetitle']);
				$keywords = strip_tags($damRecord['keywords']);
				$filename = strip_tags($damRecord['file_name']);
				$fullContent = $pagetitle . "\n" . $abstract . "\n" . $content . "\n" . $keywords . "\n" . $filename;
				$targetPID = $this->indexerConfig['targetpid'];

				// get tags for this record
				// needs extension ke_search_dam_tags
				if (t3lib_extMgm::isLoaded('ke_search_dam_tags')) {
					$damRecordTags = t3lib_div::trimExplode(',',$damRecord['tx_kesearchdamtags_tags'], true);
					$tags = '';
					$clearTextTags = '';
					if (count($damRecordTags)) {
						foreach ($damRecordTags as $key => $tagUid)  {
							$tags .= '#'.$this->getTag($tagUid).'#';
							$clearTextTags .= chr(13).$this->getTag($tagUid, true);
						}
					}

				} else {
					$tags = '';
				}

				// hook for custom modifications of the indexed data, e. g. the tags
				if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyDAMIndexEntry'])) {
					foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyDAMIndexEntry'] as $_classRef) {
						$_procObj = & t3lib_div::getUserObj($_classRef);
						$_procObj->modifyDAMIndexEntry(
							$title,
							$abstract,
							$fullContent,
							$params,
							$tags,
							$damRecord,
							$targetPID,
							$clearTextTags,
							$additionalFields
						);
					}
				}

					// add clearText Tags to content, make them searchable
					// by fulltext search
				if (!empty($clearTextTags)) $fullContent .= $clearTextTags;

				// store data in index table
				$this->pObj->storeInIndex(
					$this->indexerConfig['storagepid'],   // storage PID
					$title,                         // page/record title
					'dam',                          // content type
					$this->indexerConfig['targetpid'],    // target PID: where is the single view?
					$fullContent,                   // indexed content, includes the title (linebreak after title)
					$tags,                          // tags
					$params,                        // typolink params for singleview
					$abstract,                      // abstract
					$damRecord['sys_language_uid'], // language uid
					$damRecord['starttime'],        // starttime
					$damRecord['endtime'],          // endtime
					$damRecord['fe_group'],         // fe_group
					false,                          // debug only?
					$additionalFields               // additional fields added by hooks
				);

			}
		}

		$content = '<p><b>Indexer "' . $this->indexerConfig['title'] . '": ' . $resCount . ' DAM records have been indexed.</b></p>'."\n";
		return $content;
	}


	/*
	 * function getDAMSubcategories
	 * @param $arg
	 */
	function getRecursiveDAMCategories($catUid) {
		$fields = 'uid, parent_id';
		$table = 'tx_dam_cat';
		$where = 'parent_id="'.intval($catUid).'" ';
		$where .= t3lib_befunc::BEenableFields($table,$inv=0);
		$where .= t3lib_befunc::deleteClause($table,$inv=0);
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='');
		$anz = $GLOBALS['TYPO3_DB']->sql_num_rows($res);
		while ($row=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			$this->catList .= $row['uid'].',';
			$this->getRecursiveDAMCategories($row['uid']);
		}
	}
}
