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

require_once(t3lib_extMgm::extPath('ke_search').'lib/class.tx_kesearch_lib.php');

/**
 * Plugin 'Faceted search - searchbox and filters' for the 'ke_search' extension.
 *
 * @author	Andreas Kiefer (kennziffer.com) <kiefer@kennziffer.com>
 * @package	TYPO3
 * @subpackage	tx_kesearch
 */
class tx_kesearch_pi1 extends tx_kesearch_lib {
	var $scriptRelPath      = 'pi1/class.tx_kesearch_pi1.php';	// Path to this script relative to the extension dir.
	
	/**
	 * The main method of the PlugIn
	 *
	 * @param	string		$content: The PlugIn content
	 * @param	array		$conf: The PlugIn configuration
	 * @return	The content that is displayed on the website
	 */
	function main($content, $conf) {

		$this->ms = t3lib_div::milliseconds();
		$this->conf = $conf;
		$this->pi_setPiVarDefaults();
		$this->pi_loadLL();
		$this->pi_USER_INT_obj = 1;	// Configuring so caching is not expected. This value means that no cHash params are ever set. We do this, because it's a USER_INT object!

		// initializes plugin configuration
		$this->init();
		t3lib_div::devLog('conf', 'conf', -1, array($this->conf));
		
		// add header parts when in searchbox mode
		$this->addHeaderParts();
		
		// init XAJAX?
		if ($this->conf['renderMethod'] != 'static') $this->initXajax();

		// get css file (include only in searchbox -> avoid duplicate css)
		$cssFile = $this->conf['cssFile'] ? $this->conf['cssFile'] : t3lib_extMgm::siteRelPath($this->extKey).'res/ke_search_pi1.css';
		$GLOBALS['TSFE']->additionalHeaderData[$this->prefixId.'_css'] = '<link rel="stylesheet" type="text/css" href="'.$cssFile.'" / >';

		// get preselected filter from rootline
		$this->getFilterPreselect();

		// Spinner Image
		if ($this->conf['spinnerImageFile']) {
			$spinnerSrc = $this->conf['spinnerImageFile'];
		} else {
			$spinnerSrc = t3lib_extMgm::siteRelPath($this->extKey).'res/img/spinner.gif';
		}
		$this->spinnerImageFilters = '<img id="kesearch_spinner_filters" src="'.$spinnerSrc.'" alt="'.$this->pi_getLL('loading').'" />';
		$this->spinnerImageResults = '<img id="kesearch_spinner_results" src="'.$spinnerSrc.'" alt="'.$this->pi_getLL('loading').'" />';

		// get javascript onclick actions
		$this->initOnclickActions();

		// init onclick image
		$this->initOnloadImage();

		// hook for initials
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['initials'])) {
			foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['ke_search']['initials'] as $_classRef) {
				$_procObj = & t3lib_div::getUserObj($_classRef);
				$_procObj->addInitials($this);
			}
		}


		// get content
		$content = $this->getSearchboxContent();
		$content = $this->cObj->substituteMarker($content,'###SPINNER###',$this->spinnerImageFilters);
		$content = $this->cObj->substituteMarker($content,'###LOADING###',$this->pi_getLL('loading'));

		return $this->pi_wrapInBaseClass($content);
	}

	
	/*
	 * function getFilterPreselect
	 */
	protected function getFilterPreselect() {

		// get definitions from filter records (for pages only)
		$rootlineArray = $GLOBALS['TSFE']->sys_page->getRootLine($GLOBALS['TSFE']->id);
		$rootlineTags = $this->getRootlineTags();

		if (count($rootlineArray)) {
			foreach ($rootlineArray as $level => $data) {
				if (count($rootlineTags)) {
					foreach ($rootlineTags as $tagKey => $tagData) {
						if ($data['pid'] == $tagData['foreign_pid']) {
							$fields = '*';
							$table = 'tx_kesearch_filters';
							$where = $GLOBALS['TYPO3_DB']->listQuery('options', $tagData['uid'], 'tx_kesearch_filters');
							$where .= $this->cObj->enableFields('tx_kesearch_filters');
							$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='1');
							while ($row=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
								$this->preselectedFilter[$row['uid']] = $tagData['tag'];
							}
						}
					}
				}
			}
		}

		// get definitions from plugin settings
		if($this->conf['preselected_filters']) {
			$preselectedArray = t3lib_div::trimExplode(',', $this->conf['preselected_filters'], true);
			foreach ($preselectedArray as $key => $option) {
				$fields = '*, tx_kesearch_filters.uid as filteruid';
				$table = 'tx_kesearch_filters, tx_kesearch_filteroptions';
				$where = $GLOBALS['TYPO3_DB']->listQuery('options', $option, 'tx_kesearch_filters');
				$where .= ' AND tx_kesearch_filteroptions.uid = '.$option;
				$where .= $this->cObj->enableFields('tx_kesearch_filters');
				$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($fields,$table,$where,$groupBy='',$orderBy='',$limit='');
				while ($row=$GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
					$this->preselectedFilter[$row['filteruid']] = $row['tag'];
				}
			}
		}
	}
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ke_search/pi1/class.tx_kesearch_pi1.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ke_search/pi1/class.tx_kesearch_pi1.php']);
}
?>
