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
class tx_kesearch_indexer_types_tt_address extends tx_kesearch_indexer_types {

	/**
	 * Initializes indexer for tt_address
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

		// get all address records from pid set in indexerConfig
		$fields = '*';
		$table = 'tt_address';
		$indexPids = $this->getPidList($this->indexerConfig['startingpoints_recursive'], $this->indexerConfig['sysfolder'], $table);
		if ( $this->indexerConfig[ 'index_use_page_tags' ] ) {
			// add the tags of each page to the global page array
			$this->pageRecords = $this->getPageRecords($indexPids);
			$this->addTagsToRecords($indexPids);
		}
		$where = 'pid IN (' . implode(',', $indexPids) . ') ';
		$where .= t3lib_befunc::BEenableFields($table,$inv=0);
		$where .= t3lib_befunc::deleteClause($table,$inv=0);
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where);
		$resCount = $GLOBALS['TYPO3_DB']->sql_num_rows($res);

			// no address records found
		if (!$resCount) {
			$content = '<p>No address records found!</p>';
			return $content;
		}

			// if records found: process them
		while ($addressRow=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			$abstract = '';
			$content = '';
			$targetPID = $this->indexerConfig['targetpid'];

				// set title
			$title = !empty($addressRow['company']) ? $addressRow['company'] : (!empty($addressRow['name']) ? $addressRow['name'] : ($addressRow['first_name'].' '.$addressRow['last_name']));

				// use description as abstract if set
			if (!empty($addressRow['description'])) $abstract = $addressRow['description'];

				// build content
			if (!empty($addressRow['company'])) $content .= $addressRow['company']."\n"; // company
			if (!empty($addressRow['name'])) {
				$content .= $addressRow['name']."\n"; // name
			} else {
				$content .= $addressRow['title'].' '.$addressRow['first_name'].' '.$addressRow['middle_name'].' '.$addressRow['last_name']."\n"; // splitted naming fields
			}
			if (!empty($addressRow['address'])) $content .=$addressRow['address']."\n";
			if (!empty($addressRow['zip']) || !empty($addressRow['city'])) $content .= $addressRow['zip'].' '.$addressRow['city']."\n";
			if (!empty($addressRow['country'])) $content .= $addressRow['country']."\n";
			if (!empty($addressRow['region'])) $content .=$addressRow['region']."\n";
			if (!empty($addressRow['email'])) $content .=$addressRow['email']."\n";
			if (!empty($addressRow['phone'])) $content .=$addressRow['phone']."\n";
			if (!empty($addressRow['fax'])) $content .=$addressRow['fax']."\n";
			if (!empty($addressRow['mobile'])) $content .=$addressRow['mobile']."\n";
			if (!empty($addressRow['www'])) $content .=$addressRow['www'];

				// put content together
			$fullContent = $title . "\n" . $abstract . "\n" . $content;

				// there is no tt_address default param like this; you have to modify this by hook to fit your needs
			$params = '&tt_address[showUid]='.$addressRow['uid'];

				// no tags yet
			if($this->indexerConfig['index_use_page_tags']) {
				$tagContent = $this->pageRecords[intval($addressRow['pid'])]['tags'];
			} else $tagContent = '';

				// set additional fields for sorting
			$additionalFields = array(
				'sortdate' => $addressRow['tstamp'],
			);

				// make it possible to modify the indexerConfig via hook
			$indexerConfig = $this->indexerConfig;

				// hook for custom modifications of the indexed data, e. g. the tags
			if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyAddressIndexEntry'])) {
				foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyAddressIndexEntry'] as $_classRef) {
					$_procObj = & t3lib_div::getUserObj($_classRef);
					$_procObj->modifyAddressIndexEntry(
						$title,
						$abstract,
						$fullContent,
						$params,
						$tagContent,
						$addressRow,
						$additionalFields,
						$indexerConfig
					);
				}
			}

			// store in index
			$this->pObj->storeInIndex(
				$indexerConfig['storagepid'],     		// storage PID
				$title,                                 // page/record title
				'tt_address',              				// content type
				$targetPID,                   			// target PID: where is the single view?
				$fullContent,                 			// indexed content, includes the title (linebreak after title)
				$tagContent,                 			// tags
				$params,                      			// typolink params for singleview
				$abstract,                    			// abstract
				0,                                      // language uid
				0,                                      // starttime
				0,                                      // endtime
				0,                                      // fe_group
				false,                                  // debug only?
				$additionalFields                       // additional fields added by hooks
			);
		}

		$content = '<p><b>Indexer "' . $this->indexerConfig['title'] . '": ' . $resCount . ' address records have been indexed.</b></p>'."\n";
		return $content;
	}
}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/types/class.tx_kesearch_indexer_types_tt_address.php'])	{
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/types/class.tx_kesearch_indexer_types_tt_address.php']);
}
?>