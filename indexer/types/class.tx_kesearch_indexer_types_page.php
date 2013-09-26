<?php

/* * *************************************************************
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
 * ************************************************************* */

require_once(t3lib_extMgm::extPath('ke_search') . 'indexer/class.tx_kesearch_indexer_types.php');

define('DONOTINDEX', -3);
/**
 * Plugin 'Faceted search' for the 'ke_search' extension.
 *
 * @author	Andreas Kiefer (kennziffer.com) <kiefer@kennziffer.com>
 * @author	Stefan Froemken 
 * @author	Christian Bülter (kennziffer.com) <buelter@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_indexer_types_page extends tx_kesearch_indexer_types {

	var $pids = 0;
	var $pageRecords = array(); // this array contains all data of all pages
	var $cachedPageRecords = array(); // this array contains all data of all pages, but additionally with all available languages
	var $sysLanguages = array();
	var $indexCTypes = array(
	    'text',
	    'textpic',
	    'bullets',
	    'table',
	    'html',
	    'header',
	    'uploads'
	);
	var $fileCTypes = array('uploads');
	var $counter = 0;
	var $fileCounter = 0;
	var $whereClauseForCType = '';
	// Name of indexed elements. Will be overwritten in content element indexer.
	var $indexedElementsName = 'pages';

	/**
	 * @var \TYPO3\CMS\Core\Resource\FileRepository
	 */
	var $fileRepository;

	/**
	 * Constructor of this object
	 */
	public function __construct($pObj) {
		parent::__construct($pObj);

		$this->counter = 0;
		foreach ($this->indexCTypes as $value) {
			$cTypes[] = 'CType="' . $value . '"';
		}
		$this->whereClauseForCType = implode(' OR ', $cTypes);

		// get all available sys_language_uid records
		$this->sysLanguages = t3lib_BEfunc::getSystemLanguages();

		// make file repository instance only if TYPO3 version is >= 6.0
		if ($this->pObj->div->getNumericTYPO3versionNumber() >= 6000000) {
			$this->fileRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\FileRepository');
		}
	}

	/**
	 * This function was called from indexer object and saves content to index table
	 *
	 * @return string content which will be displayed in backend
	 */
	public function startIndexing() {
		// get all pages. Regardeless if they are shortcut, sysfolder or external link
		$indexPids = $this->getPagelist($this->indexerConfig['startingpoints_recursive'], $this->indexerConfig['single_pages']);

		// add complete page record to list of pids in $indexPids
		$this->pageRecords = $this->getPageRecords($indexPids);

		// create a new list of allowed pids
		$indexPids = array_keys($this->pageRecords);

		// index only pages of doktype standard, advanced, shortcut and "not in menu"
		$where = ' (doktype = 1 OR doktype = 2 OR doktype = 4 OR doktype = 5) ';

		// add the tags of each page to the global page array
		$this->addTagsToRecords($indexPids, $where);

		// loop through pids and collect page content and tags
		foreach ($indexPids as $uid) {
			if ($uid)
				$this->getPageContent($uid);
		}

		// show indexer content
		$content .= '<p><b>Indexer "' . $this->indexerConfig['title'] . '": </b><br />'
			. count($this->pageRecords) . ' pages have been found for indexing.<br />' . "\n"
			. $this->counter . ' ' . $this->indexedElementsName . ' have been indexed.<br />' . "\n"
			. $this->fileCounter . ' files have been indexed.<br />' . "\n"
			. '</p>' . "\n";

		if ($this->pObj->div->getNumericTYPO3versionNumber() < 6000000) {
			$content .= '<p><i>For file indexing from content elements you need at least TYPO3 6.0.0!</i></p>';
		}

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

		while ($pageRow = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
			$this->addLocalizedPagesToCache($pageRow);
			$pages[$pageRow['uid']] = $pageRow;
		}
		return $pages;
	}

	/**
	 * add localized page records to a cache/globalArray
	 * This is much faster than requesting the DB for each tt_content-record
	 *
	 * @param array $pageRow
	 * @return void
	 */
	public function addLocalizedPagesToCache($pageRow) {
		$this->cachedPageRecords[0][$pageRow['uid']] = $pageRow;
		foreach ($this->sysLanguages as $sysLang) {
			list($pageOverlay) = t3lib_BEfunc::getRecordsByField(
					'pages_language_overlay', 'pid', $pageRow['uid'], 'AND sys_language_uid=' . intval($sysLang[1])
			);
			if ($pageOverlay) {
				$this->cachedPageRecords[$sysLang[1]][$pageRow['uid']] = t3lib_div::array_merge(
						$pageRow, $pageOverlay
				);
			}
		}
	}

	/**
	 * creates a rootline and searches for valid feGroups
	 *
	 * @param integer $uid
	 * @return array
	 */
	public function getRecursiveFeGroups($uid) {
		$tempRootline[] = $this->cachedPageRecords[0][$uid]['uid'];
		while ($this->cachedPageRecords[0][$uid]['pid']) {
			$uid = $this->cachedPageRecords[0][$uid]['pid'];
			if (is_array($this->cachedPageRecords[0][$uid])) {
				$tempRootline[] = $this->cachedPageRecords[0][$uid]['uid'];
			}
		}
		krsort($tempRootline);
		foreach ($tempRootline as $page) {
			$rootline[] = $page;
		}
		// now we have a full rootline of the current page. 0 = level 0, 1 = level 1 and so on
		$extendToSubpages = false;
		foreach ($rootline as $uid) {
			if ($this->cachedPageRecords[0][$uid]['extendToSubpages'] || $extendToSubpages) {
				if (!empty($this->cachedPageRecords[0][$uid]['fe_group'])) {
					$tempFeGroups = explode(',', $this->cachedPageRecords[0][$uid]['fe_group']);
					foreach ($tempFeGroups as $group) {
						$feGroups[] = $group;
					}
				}
				$extendToSubpages = true;
			} else {
				if (!empty($this->cachedPageRecords[0][$uid]['fe_group'])) {
					$feGroups = explode(',', $this->cachedPageRecords[0][$uid]['fe_group']);
				} else {
					$feGroups = array();
				}
			}
		}
		return $feGroups;
	}

	/**
	 * get content of current page and save data to db
	 * @param $uid page-UID that has to be indexed
	 */
	public function getPageContent($uid) {
		// get content elements for this page
		$fields = 'uid, header, bodytext, CType, sys_language_uid, header_layout, fe_group';
		$table = 'tt_content';
		$where = 'pid = ' . intval($uid);
		$where .= ' AND (' . $this->whereClauseForCType . ')';
		$where .= t3lib_BEfunc::BEenableFields($table);
		$where .= t3lib_BEfunc::deleteClause($table);

		// if indexing of content elements with restrictions is not allowed
		// get only content elements that have empty group restrictions
		if ($this->indexerConfig['index_content_with_restrictions'] != 'yes') {
			$where .= ' AND (fe_group = "" OR fe_group = "0") ';
		}

		// get frontend groups for this page
		$feGroupsPages = t3lib_div::uniqueList(implode(',', $this->getRecursiveFeGroups($uid)));

		// get Tags for current page
		$tags = $this->pageRecords[intval($uid)]['tags'];

		$ttContentRows = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($fields, $table, $where);
		if (count($ttContentRows)) {
			foreach ($ttContentRows as $ttContentRow) {
				$content = '';

				// index header
				// add header only if not set to "hidden"
				if ($ttContentRow['header_layout'] != 100) {
					$content .= strip_tags($ttContentRow['header']) . "\n";
				}

				// index content of this content element and find attached or linked files.
				// Attached files are saved as file references, the RTE links directly to
				// a file, thus we get file objects.
				if (in_array($ttContentRow['CType'], $this->fileCTypes)) {
					$fileObjects = $this->findAttachedFiles($ttContentRow);
				} else {
					$fileObjects = $this->findLinkedFilesInRte($ttContentRow);
					$content .= $this->getContentFromContentElement($ttContentRow) . "\n";
				}

				// index the files fond
				$this->indexFiles($fileObjects, $ttContentRow, $feGroupsPages, $tags) . "\n";

				// add content from this content element to page content
				$pageContent[$ttContentRow['sys_language_uid']] .= $content;
			}
			$this->counter++;
		} else {
			return;
		}

		// make it possible to modify the indexerConfig via hook
		$indexerConfig = $this->indexerConfig;

		// hook for custom modifications of the indexed data, e. g. the tags
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyPagesIndexEntry'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyPagesIndexEntry'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->modifyPagesIndexEntry(
					$uid, $pageContent, $tags, $this->cachedPageRecords, $additionalFields, $indexerConfig
				);
			}
		}

		// store record in index table
		foreach ($pageContent as $langKey => $content) {
			$this->pObj->storeInIndex(
				$indexerConfig['storagepid'],                          // storage PID
				$this->cachedPageRecords[$langKey][$uid]['title'],     // page title
				'page',                                                // content type
				$uid,                                                  // target PID: where is the single view?
				$content,                                              // indexed content, includes the title (linebreak after title)
				$tags,                                                 // tags
				'',                                                    // typolink params for singleview
				$this->cachedPageRecords[$langKey][$uid]['abstract'],  // abstract
				$langKey,                                              // language uid
				$this->cachedPageRecords[$langKey][$uid]['starttime'], // starttime
				$this->cachedPageRecords[$langKey][$uid]['endtime'],   // endtime
				$feGroupsPages,                                        // fe_group
				false,                                                 // debug only?
				$additionalFields				       // additional fields added by hooks
			);
		}

		return;
	}

	/**
	 * combine group access restrictons from page(s) and content element
	 * 
	 * @param string $feGroupsPages comma list
	 * @param string $feGroupsContentElement comma list
	 * @return type
	 * @author Christian Bülter <buelter@kennziffer.com>
	 * @since 26.09.13
	 */
	public function getCombinedFeGroupsForContentElement($feGroupsPages, $feGroupsContentElement) {

		// combine frontend groups from page(s) and content elemenet as follows
		// 1. if page has no groups, but ce has groups, use ce groups
		// 2. if ce has no groups, but page has grooups, use page groups
		// 3. if page has "show at any login" (-2) and ce has groups, use ce groups
		// 4. if ce has "show at any login" (-2) and page has groups, use page groups
		// 5. if page and ce have explicit groups (not "hide at login" (-1), merge them (use only groups both have)
		// 6. if page or ce has "hide at login" and the other
		// has an expclicit group the element will never be shown and we must not index it.
		// So which group do we set here? Let's use a constant for that and check in the calling function for that.

		if (!$feGroupsPages && $feGroupsContentElement) {
			$feGroups = $feGroupsContentElement;
		}

		if ($feGroupsPages && !$feGroupsContentElement) {
			$feGroups = $feGroupsPages;
		}

		if ($feGroupsPages == '-2' && $feGroupsContentElement) {
			$feGroups = $feGroupsContentElement;
		}

		if ($feGroupsPages && $feGroupsContentElement == '-2') {
			$feGroups = $feGroupsPages;
		}

		if ($feGroupsPages && $feGroupsContentElement && $feGroupsPages != '-1' && $feGroupsContentElement != '-1') {
			$feGroupsContentElementArray = t3lib_div::intExplode(',', $feGroupsContentElement);
			$feGroupsPagesArray = t3lib_div::intExplode(',', $feGroupsPages);
			$feGroups = implode(',', array_intersect($feGroupsContentElementArray,$feGroupsContentElementArray));
		}

		if (
			($feGroupsContentElement && $feGroupsContentElement != '-1' && $feGroupsContentElement != -2 && $feGroupsPages == '-1')
			||
			($feGroupsPages && $feGroupsPages != '-1' && $feGroupsPages != -2 && $feGroupsContentElement == '-1')
			) {
			$feGroups = DONOTINDEX;
		}

		return $feGroups;
	}

	/**
	 *
	 * Extracts content from files given (as array of file objects or file reference objects)
	 * and writes the content to the index
	 *
	 * @param array $fileObjects
	 * @param array $ttContentRow
	 * @param string $feGroupsPages comma list
	 * @param string $tags string
	 * @author Christian Bülter <buelter@kennziffer.com>
	 * @since 25.09.13
	 */
	public function indexFiles($fileObjects, $ttContentRow, $feGroupsPages, $tags) {
		// combine group access restrictons from page(s) and content element
		$feGroups = $this->getCombinedFeGroupsForContentElement($feGroupsPages, $ttContentRow['fe_group']);

		if (count($fileObjects) && $feGroups != DONOTINDEX) {

			// loop through files
			foreach ($fileObjects as $fileObject) {

				// check if the file extension fits in the list of extensions
				// to index defined in the indexer configuration
				if (t3lib_div::inList($this->indexerConfig['fileext'], $fileObject->getExtension())) {

					// get file path and URI
					$fileUri = $fileObject->getStorage()->getPublicUrl($fileObject);
					$filePath = PATH_site . $fileUri;

					/* @var $fileIndexerObject tx_kesearch_indexer_types_file  */
					$fileIndexerObject = t3lib_div::makeInstance('tx_kesearch_indexer_types_file', $this->pObj);

					// add tag to identify this index record as file
					if (!empty($tags)) {
						$tags .= ',';
					}
					$tagChar = $this->pObj->extConf['prePostTagChar'];
					$tags .= $tagChar . 'file' . $tagChar;

					// get file information and  file content (using external tools)
					// write file data to the index as a seperate index entry
					// count indexed files, add it to the indexer output
					if ($fileIndexerObject->fileInfo->setFile($filePath)) {
						if (($content = $fileIndexerObject->getFileContent($filePath))) {
							$this->storeFileContentToIndex($fileObject, $content, $fileIndexerObject, $feGroups, $tags, $ttContentRow);
							$this->fileCounter++;
						}
					}

				}
			}
		}
	}

	/**
	 * Finds files attached to "uploads" content elements
	 * returns them as file reference objects array
	 *
	 * @author Christian Bülter <buelter@kennziffer.com>
	 * @since 24.09.13
	 * @param array $ttContentRow content element
	 * @return array
	 */
	public function findAttachedFiles($ttContentRow) {
		if ($this->pObj->div->getNumericTYPO3versionNumber() >= 6000000) {
			// get files attached to the content element
			$fileReferenceObjects = $this->fileRepository->findByRelation('tt_content', 'media', $ttContentRow['uid']);
		} else {
			$fileReferenceObjects = array();
		}
		return $fileReferenceObjects;
	}


	/**
	 * Finds files linked in rte text
	 * returns them as array of file objects
	 *
	 * @param array $ttContentRow content element
	 * @return array
	 * @author Christian Bülter <buelter@kennziffer.com>
	 * @since 24.09.13
	 */
	public function findLinkedFilesInRte($ttContentRow) {
		$fileObjects = array();
		// check if there are links to files in the rte text
		if ($this->pObj->div->getNumericTYPO3versionNumber() >= 6000000) {
			/* @var $rteHtmlParser \TYPO3\CMS\Core\Html\RteHtmlParser */
			$rteHtmlParser = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Html\\RteHtmlParser');
			$blockSplit = $rteHtmlParser->splitIntoBlock('link', $ttContentRow['bodytext'], 1);
			foreach ($blockSplit as $k => $v) {
				if ($k % 2) {
					$tagCode = \TYPO3\CMS\Core\Utility\GeneralUtility::unQuoteFilenames(trim(substr($rteHtmlParser->getFirstTag($v), 0, -1)), TRUE);
					$link_param = $tagCode[1];
					list($linkHandlerKeyword, $linkHandlerValue) = explode(':', trim($link_param), 2);
					if ($linkHandlerKeyword === 'file') {
						//debug($this->fileRepository->findFileReferenceByUid($linkHandlerValue), 'file found: ' . $linkHandlerValue);
						$fileObjects[] = $this->fileRepository->findByUid($linkHandlerValue);
					}
				}
			}
		}
		return $fileObjects;
	}


	/**
	 *
	 * Store the file content and additional information to the index
	 *
	 * @param $fileObject file reference object or file object
	 * @param string $content file text content
	 * @param tx_kesearch_indexer_types_file $fileIndexerObject
	 * @param string $feGroups comma list of groups to assign
	 * @param array $ttContentRow tt_content element the file was assigned to
	 * @author Christian Bülter <buelter@kennziffer.com>
	 * @since 25.09.13
	 */
	public function storeFileContentToIndex($fileObject, $content, $fileIndexerObject, $feGroups, $tags, $ttContentRow) {

		// if the gifen file is a file reference, we can use the description as abstract
		if ($fileObject instanceof TYPO3\CMS\Core\Resource\FileReference) {
			$abstract = $fileObject->getDescription() ? $fileObject->getDescription() : '';
		} else {
			$abstract = '';
		}
		if ($abstract) {
			$content = $abstract . "\n" . $content;
		}

		$title = $fileIndexerObject->fileInfo->getName();
		$storagePid = $this->indexerConfig['storagepid'];
		$type = 'file:' . $fileObject->getExtension();

		$additionalFields = array(
			'sortdate' => $fileIndexerObject->fileInfo->getModificationTime(),
			'orig_uid' => 0,
			'orig_pid' => 0,
			'directory' => $fileIndexerObject->fileInfo->getPath(),
			'hash' => $fileIndexerObject->getUniqueHashForFile()
		);

		//hook for custom modifications of the indexed data, e. g. the tags
		if(is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFileIndexEntryFromContentIndexer'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['modifyFileIndexEntryFromContentIndexer'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->modifyFileIndexEntryFromContentIndexer($fileObject, $content, $fileIndexerObject, $feGroups, $ttContentRow, $storagePid, $title, $tags, $abstract, $additionalFields);
			}
		}

		// Store record in index table:
		// Add usergroup restrictions of the page and the
		// content element to the index data.
		// Add time restrictions to the index data.
		$this->pObj->storeInIndex(
			$storagePid,                             // storage PID
			$title,                                  // file name
			$type,                                   // content type
			1,                                       // target PID: where is the single view?
			$content,                                // indexed content
			$tags,                                   // tags
			'',                                      // typolink params for singleview
			$abstract,                               // abstract
			$ttContentRow['sys_language_uid'],       // language uid
			$ttContentRow['starttime'],              // starttime
			$ttContentRow['endtime'],                // endtime
			$feGroups,                               // fe_group
			false,                                   // debug only?
			$additionalFields                        // additional fields added by hooks
		);
	}

	/**
	 *
	 * Extracts content from content element and returns it as plain text
	 * for writing it directly to the index
	 *
	 * @author Christian Bülter <buelter@kennziffer.com>
	 * @since 24.09.13
	 * @param array $ttContentRow content element
	 * @return string
	 */
	public function getContentFromContentElement($ttContentRow) {
		// bodytext
		$bodytext = $ttContentRow['bodytext'];

		// following lines prevents having words one after the other like: HelloAllTogether
		$bodytext = str_replace('<td', ' <td', $bodytext);
		$bodytext = str_replace('<br', ' <br', $bodytext);
		$bodytext = str_replace('<p', ' <p', $bodytext);
		$bodytext = str_replace('<li', ' <li', $bodytext);

		if ($ttContentRow['CType'] == 'table') {
			// replace table dividers with whitespace
			$bodytext = str_replace('|', ' ', $bodytext);
		}
		$bodytext = strip_tags($bodytext);
		return $bodytext;
	}

}

if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/types/class.tx_kesearch_indexer_types_page.php']) {
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/ke_search/indexer/types/class.tx_kesearch_indexer_types_page.php']);
}
?>