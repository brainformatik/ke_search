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

require_once(t3lib_extMgm::extPath('ke_search').'indexer/types/class.tx_kesearch_indexer_types_page.php');

/**
 * Plugin 'Faceted search' for the 'ke_search' extension.
 *
 * @author	Andreas Kiefer (kennziffer.com) <kiefer@kennziffer.com>
 * @author	Stefan Froemken (kennziffer.com) <froemken@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_indexer_types_tt_content extends tx_kesearch_indexer_types_page {
	var $indexedElementsName = 'content elements';

	/**
	 * get content of current page and save data to db
	 * @param $uid page-UID that has to be indexed
	 */
	function getPageContent($uid) {
		// get content elements for this page
		$fields = '*';
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
				// add header only if not set to "hidden"
				if ($row['header_layout'] != 100) {
					$row['header'] = strip_tags($row['header']);
				}

				// bodytext
				$bodytext = $row['bodytext'];
				$bodytext = str_replace('<td', ' <td', $bodytext);
				$bodytext = str_replace('<br', ' <br', $bodytext);
				$bodytext = str_replace('<p', ' <p', $bodytext);
				$bodytext = str_replace('<li', ' <li', $bodytext);

				if ($row['CType'] == 'table') {
					// replace table dividers with whitespace
					$bodytext = str_replace('|', ' ', $bodytext);
				}
				$bodytext = strip_tags($bodytext);

				$tags = $this->pageRecords[$uid]['tags'];

				$additionalFields = array();

					// make it possible to modify the indexerConfig via hook
				$indexerConfig = $this->indexerConfig;

				// hook for custom modifications of the indexed data, e. g. the tags
				if(is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyContentIndexEntry'])) {
					foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyContentIndexEntry'] as $_classRef) {
						$_procObj = & t3lib_div::getUserObj($_classRef);
						$_procObj->modifyContentIndexEntry(
							$row['header'],
							$row,
							$tags,
							$row['uid'],
							$additionalFields,
							$indexerConfig
						);
					}
				}

				$title = $this->pageRecords[$uid]['title'];
				if ($row['header'] && $row['header_layout'] != 100) {
					$title = $title . ' - ' . $row['header'];
				}

				// add page rights to tt_content record and make list unique
				$feGroups = $row['fe_group'] . ',' . $this->pageRecords[$uid]['fe_group'];
				$feGroups = t3lib_div::uniqueList($feGroups);

				// save record to index
				$this->pObj->storeInIndex(
					$indexerConfig['storagepid'],    		// storage PID
					$title,                             	// page title inkl. tt_content-title
					'content',                        		// content type
					$row['pid'] . '#c' . $row['uid'],      	// target PID: where is the single view?
					$bodytext,                             	// indexed content, includes the title (linebreak after title)
					$tags,                                	// tags
					'',                                    	// typolink params for singleview
					'',                                   	// abstract
					$row['sys_language_uid'],              	// language uid
					$row['starttime'],                     	// starttime
					$row['endtime'],                       	// endtime
					$feGroups,                             	// fe_group
					false,                                 	// debug only?
					$additionalFields                      	// additional fields added by hooks
				);

				// count elements written to the index
				$this->counter++;
			}
		} else {
			return;
		}

		return;
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/types/class.tx_kesearch_indexer_types_tt_content.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/types/class.tx_kesearch_indexer_types_tt_content.php']);
}
?>