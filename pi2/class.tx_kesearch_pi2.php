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
class tx_kesearch_pi2 extends tx_kesearch_lib {
	var $scriptRelPath      = 'pi1/class.tx_kesearch_pi2.php';	// Path to this script relative to the extension dir.
	
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

		// init XAJAX?
		if ($this->conf['renderMethod'] != 'static') $this->initXajax();

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


		// show text instead of results if no searchparams set and activated in ff
		if($this->isEmptySearch() && $this->conf['showTextInsteadOfResults']) {
			$content = '<div id="textmessage">'.$this->pi_RTEcssText($this->conf['textForResults']).'</div>';
			$content .= '<div id="kesearch_results"></div>';
			$content .= '<div id="kesearch_updating_results"></div>';
			$content .= '<div id="kesearch_pagebrowser_top"></div>';
			$content .= '<div id="kesearch_pagebrowser_bottom"></div>';
			$content .= '<div id="kesearch_query_time"></div>';
			return $content;
		}

		$content = $this->cObj->getSubpart($this->templateCode, '###RESULT_LIST###');
		
		if($this->conf['renderMethod'] == 'ajax_after_reload') {
			$this->getSearchResults(); //TODO We have to call it again, to get the numberOfResults. Maybe it's better to change it in Template and refreshResultsOnLoad
			$content = $this->cObj->substituteMarker($content,'###MESSAGE###', '');
			$content = $this->cObj->substituteMarker($content, '###NUMBER_OF_RESULTS###', $this->numberOfResults);
			$content = $this->cObj->substituteMarker($content,'###ORDERING###', $this->renderOrdering());
			$content = $this->cObj->substituteMarker($content,'###SPINNER###', $this->spinnerImageResults);
			$content = $this->cObj->substituteMarker($content,'###LOADING###',$this->pi_getLL('loading'));
			$content = $this->cObj->substituteMarker($content,'###QUERY_TIME###', '');
			$content = $this->cObj->substituteMarker($content,'###PAGEBROWSER_TOP###', '');
			$content = $this->cObj->substituteMarker($content,'###PAGEBROWSER_BOTTOM###', '');
			return $this->pi_wrapInBaseClass($content);
		}
		
		// render pagebrowser
		if ($GLOBALS['TSFE']->id == $this->conf['resultPage']) {
			if ($this->conf['pagebrowserOnTop'] || $this->conf['pagebrowserAtBottom']) {
				$pagebrowserContent = $this->renderPagebrowser();
			}
			if ($this->conf['pagebrowserOnTop']) {
				$content = $this->cObj->substituteMarker($content,'###PAGEBROWSER_TOP###', $pagebrowserContent);
			} else {
				$content = $this->cObj->substituteMarker($content,'###PAGEBROWSER_TOP###', '');
			}
			if ($this->conf['pagebrowserAtBottom']) {
				$content = $this->cObj->substituteMarker($content,'###PAGEBROWSER_BOTTOM###', $pagebrowserContent);
			} else {
				$content = $this->cObj->substituteMarker($content,'###PAGEBROWSER_BOTTOM###','');
			}
		}

		$content = $this->cObj->substituteMarker($content, '###MESSAGE###', $this->getSearchResults());
		$content = $this->cObj->substituteMarker($content, '###NUMBER_OF_RESULTS###', $this->numberOfResults);
		$content = $this->cObj->substituteMarker($content, '###ORDERING###', $this->renderOrdering());
		$content = $this->cObj->substituteMarker($content, '###SPINNER###', $this->spinnerImageResults);
		$content = $this->cObj->substituteMarker($content, '###LOADING###', $this->pi_getLL('loading'));
		$content = $this->cObj->substituteMarker($content, '###QUERY_TIME###', '');

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
	}
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ke_search/pi1/class.tx_kesearch_pi1.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/ke_search/pi1/class.tx_kesearch_pi1.php']);
}
?>
